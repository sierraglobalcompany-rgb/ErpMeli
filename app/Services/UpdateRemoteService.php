<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use RuntimeException;

final class UpdateRemoteService
{
    /** @return list<array<string,mixed>> */
    public function releases(bool $force = false): array
    {
        $settings = new AppSettingsService();
        $base = rtrim((string) $settings->get('update.remote_url', ''), '/');
        if ($base === '') {
            return [];
        }
        $channel = rawurlencode((string) $settings->get('update.channel', 'stable'));
        $installationId = rawurlencode($this->installationId());
        $current = rawurlencode((new AppVersionService())->installedVersion());
        $cache = AppPaths::storage('cache/update-releases.json');
        $etagFile = AppPaths::storage('cache/update-releases.etag');
        if (!$force && is_file($cache) && filemtime($cache) > time() - 300) {
            return $this->releaseRows((string) file_get_contents($cache));
        }
        $headers = ['Accept: application/json'];
        if (is_file($etagFile)) {
            $headers[] = 'If-None-Match: ' . trim((string) file_get_contents($etagFile));
        }
        [$status, $body, $responseHeaders] = $this->request(
            $base . '/api/erp-updates/v1/releases?channel=' . $channel . '&current=' . $current . '&installation_id=' . $installationId,
            $base,
            $headers
        );
        if ($status === 304 && is_file($cache)) {
            @touch($cache);
            return $this->releaseRows((string) file_get_contents($cache));
        }
        if ($status !== 200) {
            throw new RuntimeException('El servidor privado de actualizaciones respondió HTTP ' . $status . '.');
        }
        $directory = dirname($cache);
        if (!is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }
        file_put_contents($cache, $body, LOCK_EX);
        if (isset($responseHeaders['etag'])) {
            file_put_contents($etagFile, $responseHeaders['etag'], LOCK_EX);
        }
        return $this->releaseRows($body);
    }

    public function download(string $url, string $expectedSha256): string
    {
        $settings = new AppSettingsService();
        $base = rtrim((string) $settings->get('update.remote_url', ''), '/');
        $this->assertAllowedUrl($url, $base);
        if (preg_match('/^[a-f0-9]{64}$/i', $expectedSha256) !== 1) {
            throw new RuntimeException('El servidor no informó un hash de paquete válido.');
        }
        (new UpdateFilesystemService())->ensureDirectories();
        $target = AppPaths::updateInbox() . '/remote-' . strtolower($expectedSha256) . '.erpupd';
        $partial = $target . '.part';
        $offset = is_file($partial) ? (int) filesize($partial) : 0;
        $handle = fopen($partial, $offset > 0 ? 'ab' : 'wb');
        if ($handle === false) {
            throw new RuntimeException('No fue posible abrir el archivo de descarga.');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            fclose($handle);
            throw new RuntimeException('No fue posible iniciar la descarga.');
        }
        $headers = [];
        if ($offset > 0) {
            $headers[] = 'Range: bytes=' . $offset . '-';
        }
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'ERP-Meli-Updater/' . AppVersionService::fileVersion(),
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        fclose($handle);
        if ($ok !== true || !in_array($status, [200, 206], true)) {
            throw new RuntimeException('La descarga quedó incompleta: ' . ($error !== '' ? $error : 'HTTP ' . $status) . '.');
        }
        if ($offset > 0 && $status === 200) {
            @unlink($partial);
            return $this->download($url, $expectedSha256);
        }
        if (!hash_equals(strtolower($expectedSha256), hash_file('sha256', $partial))) {
            throw new RuntimeException('El hash del paquete descargado no coincide.');
        }
        if (!rename($partial, $target)) {
            throw new RuntimeException('No fue posible activar el paquete descargado.');
        }
        return $target;
    }

    /** @return list<array<string,mixed>> */
    public function upgradePath(string $fromVersion, string $toVersion): array
    {
        $base = rtrim((string) (new AppSettingsService())->get('update.remote_url', ''), '/');
        if ($base === '') {
            return [];
        }
        $url = $base . '/api/erp-updates/v1/upgrade-path?from=' . rawurlencode($fromVersion) . '&to=' . rawurlencode($toVersion);
        [$status, $body] = $this->request($url, $base, ['Accept: application/json']);
        if ($status !== 200) {
            throw new RuntimeException('No fue posible resolver la ruta de actualización remota.');
        }
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('La ruta de actualización remota no es válida.', 0, $e);
        }
        $steps = $decoded['steps'] ?? [];
        return is_array($steps) && array_is_list($steps)
            ? array_values(array_filter($steps, 'is_array'))
            : [];
    }

    /** @param array<string,mixed> $payload */
    public function report(array $payload): void
    {
        $settings = new AppSettingsService();
        if (!$settings->bool('update.telemetry_enabled', false)) {
            return;
        }
        $base = rtrim((string) $settings->get('update.remote_url', ''), '/');
        if ($base === '') {
            return;
        }
        $safe = [
            'product_id' => 'erp-meli',
            'installation_id' => $this->installationId(),
            'version_from' => (string) ($payload['version_from'] ?? ''),
            'version_to' => (string) ($payload['version_to'] ?? ''),
            'result' => (string) ($payload['result'] ?? ''),
            'duration_seconds' => (int) ($payload['duration_seconds'] ?? 0),
            'error_code' => (string) ($payload['error_code'] ?? ''),
            'failed_stage' => (string) ($payload['failed_stage'] ?? ''),
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
        ];
        $url = $base . '/api/erp-updates/v1/installations/report';
        $this->assertAllowedUrl($url, $base);
        $curl = curl_init($url);
        if ($curl === false) {
            return;
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'ERP-Meli-Updater/' . AppVersionService::fileVersion(),
        ]);
        curl_exec($curl);
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    private function request(string $url, string $base, array $headers): array
    {
        $this->assertAllowedUrl($url, $base);
        $responseHeaders = [];
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('No fue posible iniciar la consulta de actualizaciones.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'ERP-Meli-Updater/' . AppVersionService::fileVersion(),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if (!is_string($body)) {
            throw new RuntimeException('No fue posible consultar el servidor de actualizaciones.');
        }
        return [$status, $body, $responseHeaders];
    }

    private function assertAllowedUrl(string $url, string $base): void
    {
        $candidate = parse_url($url);
        $allowed = parse_url($base);
        if (
            ($candidate['scheme'] ?? '') !== 'https'
            || strtolower((string) ($candidate['host'] ?? '')) !== strtolower((string) ($allowed['host'] ?? ''))
        ) {
            throw new RuntimeException('La URL remota no pertenece al servidor HTTPS autorizado.');
        }
    }

    /** @return list<array<string,mixed>> */
    private function releaseRows(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('El servidor devolvió un catálogo de releases inválido.', 0, $e);
        }
        $rows = $decoded['releases'] ?? $decoded;
        return is_array($rows) && array_is_list($rows)
            ? array_values(array_filter($rows, 'is_array'))
            : [];
    }

    private function installationId(): string
    {
        $settings = new AppSettingsService();
        $id = trim((string) $settings->get('update.installation_id', ''));
        if (preg_match('/^[a-f0-9-]{36}$/', $id) === 1) {
            return $id;
        }
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        $id = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
        $settings->set('update.installation_id', $id, 'update');
        return $id;
    }
}
