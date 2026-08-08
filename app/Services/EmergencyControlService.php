<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Env;
use RuntimeException;
use Throwable;

/**
 * Autoridad local del freno de mano.
 *
 * Las paradas se resuelven por archivos para seguir funcionando sin MariaDB.
 * Toda escritura usa archivo temporal + rename y nunca contiene secretos.
 */
final class EmergencyControlService
{
    public const API_MARKER = 'PAUSE_MELI_API';
    public const AUTOMATION_MARKER = 'PAUSE_ERP_AUTOMATION';
    public const CANARY_MARKER = 'MELI_API_CANARY.json';
    public const LAST_CHANGE_MARKER = 'ERP_SAFETY_STATE.json';
    public const USERNAME = 'admin-emergencia';

    private string $root;
    private string $privateDirectory;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? AppPaths::installationRoot(), '/\\');
        $this->privateDirectory = $this->resolvePrivateDirectory();
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $api = $this->readJson($this->markerPath(self::API_MARKER));
        $automation = $this->readJson($this->markerPath(self::AUTOMATION_MARKER));
        $canary = $this->readJson($this->markerPath(self::CANARY_MARKER));
        $lastChange = $this->readJson($this->markerPath(self::LAST_CHANGE_MARKER));
        $apiStopped = is_file($this->markerPath(self::API_MARKER));
        $automationStopped = is_file($this->markerPath(self::AUTOMATION_MARKER));
        $canaryActive = !$apiStopped
            && is_array($canary)
            && (int) ($canary['expires_at'] ?? 0) >= time();

        return [
            'api' => $apiStopped ? 'stopped' : ($canaryActive ? 'canary' : 'enabled'),
            'automation' => $automationStopped ? 'stopped' : 'enabled',
            'maintenance' => $this->maintenanceStatus(),
            'writes' => Env::bool('ML_WRITE_ENABLED', false) ? 'enabled' : 'disabled',
            'credential_ready' => $this->credentialExists(),
            'source' => 'filesystem',
            'changed_at' => $this->latestChangedAt([$lastChange, $api, $automation, $canary]),
            'reason' => $this->latestReason([$lastChange, $api, $automation, $canary]),
            'canary' => $canaryActive ? $canary : null,
            'storage_degraded' => str_contains(str_replace('\\', '/', $this->privateDirectory), '/storage/'),
        ];
    }

    /** @return array{username:string,password:string} */
    public function provision(string $actor = 'administrator'): array
    {
        $this->ensurePrivateDirectory();
        $password = $this->generatedPassword();
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);
        $payload = [
            'version' => 1,
            'username' => self::USERNAME,
            'password_hash' => $hash,
            'created_at' => gmdate(DATE_ATOM),
            'rotated_by' => mb_substr($actor, 0, 120),
        ];
        $this->atomicJson($this->credentialPath(), $payload);
        $this->audit('credential_rotated', $actor, 'Acceso de emergencia preparado.');

        return ['username' => self::USERNAME, 'password' => $password];
    }

    public function revoke(string $actor = 'administrator'): void
    {
        if (is_file($this->credentialPath()) && !@unlink($this->credentialPath())) {
            throw new RuntimeException('No fue posible revocar el acceso de emergencia.');
        }
        $this->audit('credential_revoked', $actor, 'Acceso de emergencia revocado.');
    }

    public function credentialExists(): bool
    {
        $credential = $this->readJson($this->credentialPath());
        return is_array($credential)
            && hash_equals(self::USERNAME, (string) ($credential['username'] ?? ''))
            && is_string($credential['password_hash'] ?? null);
    }

    /** @return array{ok:bool,reason:string,retry_after:int} */
    public function authenticate(string $username, string $password, string $ip): array
    {
        $username = strtolower(trim($username));
        $accountKey = 'account:' . hash('sha256', $username);
        $ipKey = 'ip:' . hash('sha256', trim($ip));
        return $this->withAttemptsLock(function () use ($username, $password, $accountKey, $ipKey): array {
            $attempts = $this->readAttempts();
            $accountRecord = is_array($attempts[$accountKey] ?? null) ? $attempts[$accountKey] : [];
            $ipRecord = is_array($attempts[$ipKey] ?? null) ? $attempts[$ipKey] : [];
            $lockedUntil = max(
                (int) ($accountRecord['locked_until'] ?? 0),
                (int) ($ipRecord['locked_until'] ?? 0)
            );
            if ($lockedUntil > time()) {
                return ['ok' => false, 'reason' => 'locked', 'retry_after' => $lockedUntil - time()];
            }

            $credential = $this->readJson($this->credentialPath());
            $valid = is_array($credential)
                && hash_equals((string) ($credential['username'] ?? ''), $username)
                && is_string($credential['password_hash'] ?? null)
                && password_verify($password, (string) $credential['password_hash']);

            if ($valid) {
                unset($attempts[$accountKey], $attempts[$ipKey]);
                $this->writeAttempts($attempts);
                $this->audit('emergency_login', $username, 'Inicio de sesión de emergencia correcto.');
                return ['ok' => true, 'reason' => 'ok', 'retry_after' => 0];
            }

            $accountFailures = max(0, (int) ($accountRecord['failures'] ?? 0)) + 1;
            $ipFailures = max(0, (int) ($ipRecord['failures'] ?? 0)) + 1;
            $accountLock = $accountFailures >= 5 ? min(3600, 900 * (2 ** min(2, $accountFailures - 5))) : 0;
            $ipLock = $ipFailures >= 20 ? 900 : 0;
            $attempts[$accountKey] = [
                'failures' => $accountFailures,
                'locked_until' => $accountLock > 0 ? time() + $accountLock : 0,
                'updated_at' => time(),
            ];
            $attempts[$ipKey] = [
                'failures' => $ipFailures,
                'locked_until' => $ipLock > 0 ? time() + $ipLock : 0,
                'updated_at' => time(),
            ];
            $this->writeAttempts($attempts);
            $this->audit('emergency_login_failed', 'anonymous', 'Intento de acceso rechazado.');
            return ['ok' => false, 'reason' => 'invalid', 'retry_after' => max($accountLock, $ipLock)];
        });
    }

    public function stopApi(string $actor, string $reason): void
    {
        $this->writeMarker(self::API_MARKER, $actor, $reason ?: 'Parada preventiva de Mercado Libre.');
        @unlink($this->markerPath(self::CANARY_MARKER));
        $this->recordChange('api_stopped', $actor, $reason ?: 'Parada preventiva de Mercado Libre.');
        $this->audit('api_stopped', $actor, $reason);
    }

    public function stopAutomation(string $actor, string $reason): void
    {
        $this->writeMarker(self::AUTOMATION_MARKER, $actor, $reason ?: 'Automatización detenida preventivamente.');
        $this->recordChange('automation_stopped', $actor, $reason ?: 'Automatización detenida preventivamente.');
        $this->audit('automation_stopped', $actor, $reason);
    }

    public function stopAll(string $actor, string $reason): void
    {
        $this->stopApi($actor, $reason ?: 'Freno de mano activado.');
        $this->stopAutomation($actor, $reason ?: 'Freno de mano activado.');
        $this->recordChange('handbrake_stopped', $actor, $reason ?: 'Freno de mano activado.');
        $this->audit('handbrake_stopped', $actor, $reason);
    }

    public function startAutomation(string $actor, string $reason): void
    {
        $this->removeMarker(self::AUTOMATION_MARKER);
        $this->recordChange('automation_started', $actor, $reason ?: 'Automatización habilitada.');
        $this->audit('automation_started', $actor, $reason ?: 'Automatización habilitada.');
    }

    public function startApiWithoutCanary(string $actor, string $reason): void
    {
        @unlink($this->markerPath(self::CANARY_MARKER));
        $this->removeMarker(self::API_MARKER);
        $message = $reason ?: 'Mercado Libre habilitado para lecturas sin prueba canaria desde freno de mano.';
        $this->recordChange('api_started_without_canary', $actor, $message);
        $this->audit('api_started_without_canary', $actor, $message);
    }

    /** @return array{maintenance_removed:int,markers:list<string>} */
    public function startLocalSite(string $actor, string $reason): array
    {
        $clear = $this->clearLocalMaintenance($actor, $reason ?: 'Preparar sitio local.');
        $this->removeMarker(self::AUTOMATION_MARKER);
        $this->recordChange('local_site_started', $actor, $reason ?: 'Sitio local habilitado con Mercado Libre bloqueado.');
        $this->audit('local_site_started', $actor, $reason ?: 'Sitio local habilitado con Mercado Libre bloqueado.');
        return [
            'maintenance_removed' => (int) $clear['removed'],
            'markers' => $clear['markers'],
        ];
    }

    /** @return array{removed:int,markers:list<string>} */
    public function clearLocalMaintenance(string $actor, string $reason): array
    {
        $removed = 0;
        $markers = [];
        foreach ($this->maintenanceMarkerPaths() as $label => $path) {
            if (!is_file($path)) {
                continue;
            }
            if (!@unlink($path) && is_file($path)) {
                throw new RuntimeException('No fue posible retirar el modo de solo lectura local.');
            }
            $removed++;
            $markers[] = $label;
        }
        foreach ($this->maintenanceTemporaryPatterns() as $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                if (is_file($path) && @unlink($path)) {
                    $removed++;
                }
            }
        }
        $this->audit(
            'local_maintenance_cleared',
            $actor,
            ($reason ?: 'Modo de solo lectura local retirado por emergencia.')
                . ' Marcadores: ' . implode(',', $markers)
        );
        $this->recordChange('local_maintenance_cleared', $actor, $reason ?: 'Modo de solo lectura local retirado por emergencia.');
        return ['removed' => $removed, 'markers' => $markers];
    }

    public function prepareApiStart(string $actor, string $reason): void
    {
        $canary = [
            'version' => 1,
            'state' => 'ready',
            'max_calls' => 1,
            'used_calls' => 0,
            'created_at' => gmdate(DATE_ATOM),
            'expires_at' => time() + 3600,
            'actor' => mb_substr($actor, 0, 120),
            'reason' => mb_substr(trim($reason) ?: 'Reactivación canaria solicitada.', 0, 500),
        ];
        $this->atomicJson($this->markerPath(self::CANARY_MARKER), $canary);
        $this->removeMarker(self::API_MARKER);
        $this->recordChange('api_canary_ready', $actor, (string) $canary['reason']);
        $this->audit('api_canary_ready', $actor, (string) $canary['reason']);
    }

    public function confirmApiStart(string $actor, string $reason): void
    {
        $canary = $this->readJson($this->markerPath(self::CANARY_MARKER));
        if (!is_array($canary) || ($canary['last_result'] ?? '') !== 'success') {
            throw new RuntimeException('El canario todavía no confirmó una consulta correcta.');
        }
        @unlink($this->markerPath(self::CANARY_MARKER));
        $this->removeMarker(self::API_MARKER);
        $this->recordChange('api_started', $actor, $reason ?: 'Mercado Libre habilitado después del canario.');
        $this->audit('api_started', $actor, $reason ?: 'Mercado Libre habilitado después del canario.');
    }

    /**
     * Se llama en la última barrera, inmediatamente antes de abrir cURL.
     */
    public function claimCanaryTransport(): void
    {
        $path = $this->markerPath(self::CANARY_MARKER);
        if (!is_file($path)) {
            return;
        }
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            throw new ApiManualPauseException('app', null, null, 'No se pudo comprobar el canario de Mercado Libre.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new ApiManualPauseException('app', null, null, 'Otra consulta canaria está en curso.');
            }
            rewind($handle);
            $raw = stream_get_contents($handle);
            $canary = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($canary) || (int) ($canary['expires_at'] ?? 0) < time()) {
                throw new ApiManualPauseException('app', null, null, 'El permiso canario venció. Active nuevamente la prueba.');
            }
            $used = (int) ($canary['used_calls'] ?? 0);
            $maximum = max(1, (int) ($canary['max_calls'] ?? 1));
            if ($used >= $maximum || ($canary['state'] ?? '') === 'in_flight') {
                throw new ApiManualPauseException('app', null, null, 'La consulta canaria ya fue utilizada. Revise su resultado.');
            }
            $canary['used_calls'] = $used + 1;
            $canary['state'] = 'in_flight';
            $canary['dispatched_at'] = gmdate(DATE_ATOM);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($canary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function completeCanaryTransport(bool $success, ?int $status = null): void
    {
        $path = $this->markerPath(self::CANARY_MARKER);
        if (!is_file($path)) {
            return;
        }
        $canary = $this->readJson($path);
        if (!is_array($canary) || ($canary['state'] ?? '') !== 'in_flight') {
            return;
        }
        $canary['state'] = 'complete';
        $canary['last_result'] = $success ? 'success' : 'failed';
        $canary['http_status'] = $status;
        $canary['completed_at'] = gmdate(DATE_ATOM);
        $this->atomicJson($path, $canary);
    }

    public function apiStopped(): bool
    {
        return is_file($this->markerPath(self::API_MARKER));
    }

    public function automationStopped(): bool
    {
        return is_file($this->markerPath(self::AUTOMATION_MARKER));
    }

    /** @return array{active:bool,count:int,markers:list<array{name:string,changed_at:?string,reason:string}>} */
    private function maintenanceStatus(): array
    {
        $markers = [];
        foreach ($this->maintenanceMarkerPaths() as $label => $path) {
            if (!is_file($path)) {
                continue;
            }
            $document = $this->readJson($path);
            $markers[] = [
                'name' => $label,
                'changed_at' => is_array($document)
                    ? (string) ($document['heartbeat_at'] ?? $document['created_at'] ?? $document['activated_at'] ?? '')
                    : null,
                'reason' => is_array($document)
                    ? mb_substr((string) ($document['reason'] ?? $document['purpose'] ?? $document['task'] ?? 'Mantenimiento local'), 0, 160)
                    : 'Marcador local no legible',
            ];
        }
        return [
            'active' => $markers !== [],
            'count' => count($markers),
            'markers' => $markers,
        ];
    }

    private function writeMarker(string $name, string $actor, string $reason): void
    {
        $this->atomicJson($this->markerPath($name), [
            'version' => 1,
            'active' => true,
            'changed_at' => gmdate(DATE_ATOM),
            'actor' => mb_substr($actor, 0, 120),
            'reason' => mb_substr(trim($reason), 0, 500),
        ]);
    }

    private function removeMarker(string $name): void
    {
        $path = $this->markerPath($name);
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('No fue posible cambiar el estado de seguridad.');
        }
    }

    private function recordChange(string $event, string $actor, string $reason): void
    {
        $this->atomicJson($this->markerPath(self::LAST_CHANGE_MARKER), [
            'version' => 1,
            'event' => mb_substr($event, 0, 80),
            'changed_at' => gmdate(DATE_ATOM),
            'actor' => mb_substr($actor, 0, 120),
            'reason' => mb_substr(trim($reason), 0, 500),
        ]);
    }

    private function markerPath(string $name): string
    {
        return $this->root . '/' . $name;
    }

    /** @return array<string,string> */
    private function maintenanceMarkerPaths(): array
    {
        return [
            'snapshot de copia' => AppPaths::storage('cache/database-snapshot-active.json'),
            'modo lectura' => AppPaths::storage('cache/database-mutation-freeze.json'),
            'solicitud de copia' => AppPaths::storage('cache/backup-maintenance-request.json'),
            'solicitud de restauración' => AppPaths::storage('cache/restore-maintenance-request.json'),
            'coordinador local' => AppPaths::storage('cache/local-maintenance-state.json'),
        ];
    }

    /** @return list<string> */
    private function maintenanceTemporaryPatterns(): array
    {
        return [
            AppPaths::storage('cache/database-snapshot-active.json.tmp-*'),
            AppPaths::storage('cache/database-mutation-freeze.json.tmp-*'),
            AppPaths::storage('cache/backup-maintenance-request.json.tmp-*'),
            AppPaths::storage('cache/restore-maintenance-request.json.tmp-*'),
            AppPaths::storage('cache/local-maintenance-state.json.tmp-*'),
        ];
    }

    private function credentialPath(): string
    {
        return $this->privateDirectory . '/credential.json';
    }

    private function attemptsPath(): string
    {
        return $this->privateDirectory . '/attempts.json';
    }

    private function secretPath(): string
    {
        return $this->privateDirectory . '/audit.key';
    }

    private function auditPath(): string
    {
        return $this->privateDirectory . '/audit.jsonl';
    }

    private function resolvePrivateDirectory(): string
    {
        $configured = trim((string) (getenv('ERP_EMERGENCY_STORAGE_DIR') ?: ($_ENV['ERP_EMERGENCY_STORAGE_DIR'] ?? '')));
        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }
        $home = trim((string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')));
        if ($home !== '' && is_dir($home) && is_writable($home)) {
            return rtrim($home, '/\\') . '/.erp-meli/emergency-control';
        }
        $shared = $this->root . '/shared/emergency-control';
        if (is_dir(dirname($shared)) || @mkdir(dirname($shared), 0770, true)) {
            return $shared;
        }
        return $this->root . '/storage/emergency-control';
    }

    private function ensurePrivateDirectory(): void
    {
        if (!is_dir($this->privateDirectory) && !@mkdir($this->privateDirectory, 0770, true) && !is_dir($this->privateDirectory)) {
            throw new RuntimeException('El almacenamiento privado de emergencia no está disponible.');
        }
        @chmod($this->privateDirectory, 0700);
    }

    private function generatedPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_';
        $bytes = random_bytes(24);
        $password = '';
        for ($index = 0; $index < strlen($bytes); $index++) {
            $password .= $alphabet[ord($bytes[$index]) % strlen($alphabet)];
        }
        return $password;
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $payload */
    private function atomicJson(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento de seguridad.');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (@file_put_contents($temporary, $json, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible guardar el estado de seguridad.');
        }
        @chmod($path, 0600);
    }

    /** @return array<string,mixed> */
    private function readAttempts(): array
    {
        return $this->readJson($this->attemptsPath()) ?? [];
    }

    /** @param array<string,mixed> $attempts */
    private function writeAttempts(array $attempts): void
    {
        $this->ensurePrivateDirectory();
        $cutoff = time() - 86400;
        foreach ($attempts as $key => $record) {
            if (!is_array($record) || (int) ($record['updated_at'] ?? 0) < $cutoff) {
                unset($attempts[$key]);
            }
        }
        $this->atomicJson($this->attemptsPath(), $attempts);
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withAttemptsLock(callable $callback): mixed
    {
        $this->ensurePrivateDirectory();
        $handle = @fopen($this->attemptsPath() . '.lock', 'c');
        if (!is_resource($handle)) {
            throw new RuntimeException('No fue posible proteger el control de intentos.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('No fue posible bloquear el control de intentos.');
            }
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function audit(string $event, string $actor, string $reason): void
    {
        try {
            $this->ensurePrivateDirectory();
            if (!is_file($this->secretPath())) {
                $this->atomicJson($this->secretPath(), ['key' => base64_encode(random_bytes(32))]);
            }
            $secretDoc = $this->readJson($this->secretPath());
            $secret = base64_decode((string) ($secretDoc['key'] ?? ''), true);
            if (!is_string($secret) || strlen($secret) < 32) {
                return;
            }
            $previous = '';
            if (is_file($this->auditPath())) {
                $lines = @file($this->auditPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $last = is_array($lines) && $lines !== [] ? json_decode((string) end($lines), true) : null;
                $previous = is_array($last) ? (string) ($last['hash'] ?? '') : '';
            }
            $record = [
                'at' => gmdate(DATE_ATOM),
                'event' => $event,
                'actor_hash' => hash('sha256', strtolower(trim($actor))),
                'reason' => mb_substr(trim($reason), 0, 500),
                'previous_hash' => $previous,
            ];
            $canonical = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $record['hash'] = hash_hmac('sha256', $canonical, $secret);
            @file_put_contents(
                $this->auditPath(),
                json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
            @chmod($this->auditPath(), 0600);
        } catch (Throwable) {
            // La auditoría secundaria nunca impide activar el freno.
        }
    }

    /** @param list<array<string,mixed>|null> $documents */
    private function latestChangedAt(array $documents): ?string
    {
        $values = [];
        foreach ($documents as $document) {
            if (is_array($document)) {
                $value = (string) ($document['changed_at'] ?? $document['created_at'] ?? '');
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }
        rsort($values);
        return $values[0] ?? null;
    }

    /** @param list<array<string,mixed>|null> $documents */
    private function latestReason(array $documents): string
    {
        foreach ($documents as $document) {
            if (is_array($document) && trim((string) ($document['reason'] ?? '')) !== '') {
                return (string) $document['reason'];
            }
        }
        return 'Sin cambios de seguridad registrados.';
    }
}
