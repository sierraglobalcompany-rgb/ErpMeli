<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

final class UpdateManifestService
{
    /** @return array<string,mixed> */
    public function fromDirectory(string $directory): array
    {
        $path = rtrim($directory, '/\\') . '/update-manifest.json';
        if (is_file($path)) {
            return $this->decode((string) file_get_contents($path));
        }

        $versionFile = rtrim($directory, '/\\') . '/VERSION';
        if (!is_file($versionFile)) {
            throw new RuntimeException('La carpeta no contiene update-manifest.json ni VERSION.');
        }
        $version = trim((string) file_get_contents($versionFile));
        if ($version === '' || preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9._-]+)?$/', $version) !== 1) {
            throw new RuntimeException('La carpeta no contiene una versión válida.');
        }
        return [
            'manifest_version' => 1,
            'product_id' => 'erp-meli',
            'release_id' => $version . '-manual-' . substr(hash('sha256', realpath($directory) ?: $directory), 0, 12),
            'version' => $version,
            'sequence' => 0,
            'channel' => 'manual',
            'source_trust' => 'local_admin',
            'upgrade_from' => ['*'],
            'files' => [],
            'migrations' => [],
            'health_checks' => ['bootstrap', 'database', 'routes'],
            'signature_status' => 'local_unsigned',
        ];
    }

    /** @return array<string,mixed> */
    public function decode(string $json): array
    {
        try {
            $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('El manifiesto de actualización no es JSON válido.', 0, $e);
        }
        if (!is_array($manifest)) {
            throw new RuntimeException('El manifiesto no tiene un objeto válido.');
        }
        foreach (['manifest_version', 'product_id', 'release_id', 'version', 'channel'] as $field) {
            if (!isset($manifest[$field]) || trim((string) $manifest[$field]) === '') {
                throw new RuntimeException('El manifiesto no contiene el campo obligatorio: ' . $field . '.');
            }
        }
        if ((string) $manifest['product_id'] !== 'erp-meli') {
            throw new RuntimeException('El paquete pertenece a otro producto.');
        }
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9._-]+)?$/', (string) $manifest['version']) !== 1) {
            throw new RuntimeException('La versión del manifiesto no es válida.');
        }
        (new UpdateFilesystemService())->safeReleaseId((string) $manifest['release_id']);
        if (isset($manifest['expires_at']) && strtotime((string) $manifest['expires_at']) < time()) {
            throw new RuntimeException('El manifiesto de actualización está vencido.');
        }
        $this->assertRuntime($manifest);
        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    public function verifySignature(array $manifest): string
    {
        if (($manifest['source_trust'] ?? '') === 'local_admin' && !isset($manifest['signature'])) {
            return 'local_unsigned';
        }
        $signature = base64_decode((string) ($manifest['signature'] ?? ''), true);
        $keyId = trim((string) ($manifest['signing_key_id'] ?? ''));
        if ($signature === false || $signature === '' || $keyId === '') {
            throw new RuntimeException('El paquete remoto o .erpupd no tiene una firma válida.');
        }
        $stmt = Database::connectionFresh()->prepare(
            "SELECT public_key_pem,channels_json FROM system_update_trusted_keys
             WHERE key_id=:key_id AND status='active'
             AND (valid_from IS NULL OR valid_from<=UTC_TIMESTAMP())
             AND (valid_until IS NULL OR valid_until>=UTC_TIMESTAMP())
             LIMIT 1"
        );
        $stmt->execute(['key_id' => $keyId]);
        $key = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$key) {
            throw new RuntimeException('La clave firmante no está registrada o no está vigente.');
        }
        $publicKey = $key['public_key_pem'] ?? null;
        if (!is_string($publicKey) || trim($publicKey) === '') {
            throw new RuntimeException('La clave firmante no está registrada o no está vigente.');
        }
        $channelsRaw = trim((string) ($key['channels_json'] ?? ''));
        $channels = $channelsRaw === '' ? [] : json_decode($channelsRaw, true);
        if (!is_array($channels) || !array_is_list($channels)) {
            throw new RuntimeException('La política de canales de la clave firmante no es válida.');
        }
        if ($channels !== [] && !in_array((string) $manifest['channel'], $channels, true)) {
            throw new RuntimeException('La clave firmante no está autorizada para el canal de esta release.');
        }

        $signed = $manifest;
        unset($signed['signature'], $signed['signature_status']);
        $payload = $this->canonicalJson($signed);
        $result = openssl_verify($payload, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            throw new RuntimeException('La firma criptográfica del paquete no coincide.');
        }
        return 'verified';
    }

    /** @param array<string,mixed> $manifest */
    public function verifyFiles(array $manifest, string $releaseDirectory): void
    {
        $files = $manifest['files'] ?? [];
        if (!is_array($files) || $files === []) {
            if (($manifest['source_trust'] ?? '') === 'local_admin') {
                return;
            }
            throw new RuntimeException('El manifiesto firmado no contiene el inventario de archivos.');
        }
        foreach ($files as $file) {
            if (!is_array($file)) {
                throw new RuntimeException('El inventario de archivos no es válido.');
            }
            $relative = str_replace('\\', '/', ltrim((string) ($file['path'] ?? ''), '/'));
            if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '.')) {
                throw new RuntimeException('El manifiesto contiene una ruta de archivo insegura.');
            }
            $full = rtrim($releaseDirectory, '/\\') . '/' . $relative;
            if (!is_file($full)) {
                throw new RuntimeException('Falta un archivo declarado en el paquete: ' . $relative);
            }
            $expected = strtolower((string) ($file['sha256'] ?? ''));
            if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1 || !hash_equals($expected, hash_file('sha256', $full))) {
                throw new RuntimeException('El hash no coincide para el archivo: ' . $relative);
            }
        }
    }

    /** @param array<string,mixed> $manifest */
    public function manifestHash(array $manifest): string
    {
        return hash('sha256', $this->canonicalJson($manifest));
    }

    /** @param array<string,mixed> $manifest */
    private function assertRuntime(array $manifest): void
    {
        $requirements = is_array($manifest['requirements'] ?? null) ? $manifest['requirements'] : [];
        $minPhp = (string) ($requirements['php_min'] ?? '8.3.0');
        $maxPhp = (string) ($requirements['php_max_exclusive'] ?? '8.6.0');
        if (version_compare(PHP_VERSION, $minPhp, '<') || version_compare(PHP_VERSION, $maxPhp, '>=')) {
            throw new RuntimeException("La release requiere PHP {$minPhp} o superior y menor que {$maxPhp}.");
        }
        foreach ((array) ($requirements['extensions'] ?? []) as $extension) {
            if (!extension_loaded((string) $extension)) {
                throw new RuntimeException('Falta la extensión PHP requerida: ' . $extension . '.');
            }
        }
    }

    private function canonicalJson(array $value): string
    {
        $sort = function (array &$item) use (&$sort): void {
            if (!array_is_list($item)) {
                ksort($item);
            }
            foreach ($item as &$child) {
                if (is_array($child)) {
                    $sort($child);
                }
            }
        };
        $sort($value);
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
