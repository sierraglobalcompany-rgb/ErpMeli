<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use App\Core\Env;
use PDO;
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
        $canaryVisible = is_array($canary);
        $canaryActive = !$apiStopped
            && $canaryVisible
            && (int) ($canary['expires_at'] ?? 0) >= time();
        $publicCanary = $canary;
        if (is_array($publicCanary)) {
            // La evidencia es visible para el panel, pero el nonce permanece
            // exclusivamente en el marcador privado y en el contexto del transporte.
            unset($publicCanary['reservation_nonce']);
        }

        return [
            'api' => $apiStopped ? 'stopped' : ($canaryActive ? 'canary' : 'enabled'),
            'automation' => $automationStopped ? 'stopped' : 'enabled',
            'maintenance' => $this->maintenanceStatus(),
            'writes' => Env::bool('ML_WRITE_ENABLED', false) ? 'enabled' : 'disabled',
            'credential_ready' => $this->credentialExists(),
            'source' => 'filesystem',
            'changed_at' => $this->latestChangedAt([$lastChange, $api, $automation, $canary]),
            'reason' => $this->latestReason([$lastChange, $api, $automation, $canary]),
            // La evidencia de una prueba fallida debe seguir visible incluso
            // después de restaurar el bloqueo físico PAUSE_MELI_API.
            'canary' => $canaryVisible ? $publicCanary : null,
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
            'version' => 2,
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
        // Fail closed: primero impedir que cualquier launcher reclame trabajo y
        // solo después cerrar la autoridad de lecturas remotas.
        $this->stopAutomation($actor, $reason ?: 'Freno de mano activado.');
        $this->stopApi($actor, $reason ?: 'Freno de mano activado.');
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
        $this->assertAutomationStoppedForApiEnable();
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
        // El procesamiento local y la automatización son autoridades distintas.
        // Retirar un freeze nunca debe retirar PAUSE_ERP_AUTOMATION.
        $this->recordChange('local_site_started', $actor, $reason ?: 'Sitio local habilitado sin cambiar API ni automatización.');
        $this->audit('local_site_started', $actor, $reason ?: 'Sitio local habilitado sin cambiar API ni automatización.');
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
        $this->assertAutomationStoppedForApiEnable();
        $canary = [
            'version' => 2,
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
        $this->assertAutomationStoppedForApiEnable();
        $canary = $this->readJson($this->markerPath(self::CANARY_MARKER));
        if (!is_array($canary)
            || ($canary['state'] ?? '') !== 'complete'
            || ($canary['last_result'] ?? '') !== 'success'
            || empty($canary['identity_verified'])) {
            throw new RuntimeException('El canario todavía no confirmó una consulta correcta.');
        }
        @unlink($this->markerPath(self::CANARY_MARKER));
        $this->removeMarker(self::API_MARKER);
        $this->recordChange('api_started', $actor, $reason ?: 'Mercado Libre habilitado después del canario.');
        $this->audit('api_started', $actor, $reason ?: 'Mercado Libre habilitado después del canario.');
    }

    /**
     * Vincula una prueba preparada con una sola cuenta y una sola operación.
     * El nonce nunca sale del servidor ni se muestra en la interfaz.
     */
    public function reserveApiCanary(int $accountId, string $expectedMeliUserId): string
    {
        $this->assertAutomationStoppedForApiEnable();
        if (Env::bool('ML_WRITE_ENABLED', false)) {
            throw new RuntimeException('Las escrituras remotas deben permanecer deshabilitadas. Código: EMERGENCY_WRITES_ENABLED.');
        }
        if ($accountId < 1 || trim($expectedMeliUserId) === '') {
            throw new RuntimeException('La cuenta seleccionada no tiene una identidad Mercado Libre válida.');
        }
        $nonce = bin2hex(random_bytes(32));
        $this->mutateCanary(function (array $canary) use ($accountId, $expectedMeliUserId, $nonce): array {
            if ((int) ($canary['expires_at'] ?? 0) < time()) {
                throw new RuntimeException('La prueba canaria venció. Prepare una nueva prueba.');
            }
            if (($canary['state'] ?? '') !== 'ready' || (int) ($canary['used_calls'] ?? 0) !== 0) {
                throw new RuntimeException('La prueba canaria ya fue reservada o utilizada. Revise su resultado.');
            }
            $canary['state'] = 'reserved';
            $canary['meli_account_id'] = $accountId;
            $canary['expected_meli_user_id'] = trim($expectedMeliUserId);
            $canary['method'] = 'GET';
            $canary['endpoint'] = '/users/me';
            $canary['operation'] = 'users_me';
            $canary['source'] = 'manual_emergency_canary';
            $canary['reservation_nonce'] = $nonce;
            $canary['reserved_at'] = gmdate(DATE_ATOM);
            return $canary;
        });
        return $nonce;
    }

    /** Se llama en la última barrera, inmediatamente antes de abrir cURL. */
    public function claimCanaryTransport(string $method, string $endpoint): void
    {
        $path = $this->markerPath(self::CANARY_MARKER);
        if (!is_file($path)) {
            return;
        }
        $context = ApiExecutionMetadataContext::current();
        $this->mutateCanary(function (array $canary) use ($context, $method, $endpoint): array {
            if ((int) ($canary['expires_at'] ?? 0) < time()) {
                throw new ApiManualPauseException('app', null, null, 'El permiso canario venció. Prepare una nueva prueba.');
            }
            if (($canary['state'] ?? '') !== 'reserved' || (int) ($canary['used_calls'] ?? 0) !== 0) {
                throw new ApiManualPauseException('app', null, null, 'La consulta canaria ya fue utilizada o no está reservada.');
            }
            $actual = [
                'meli_account_id' => (string) ($context['meli_account_id'] ?? ''),
                'transport_meli_account_id' => (string) ($context['transport_meli_account_id'] ?? ''),
                'expected_meli_user_id' => (string) ($context['expected_meli_user_id'] ?? ''),
                'method' => strtoupper($method),
                'endpoint' => '/' . ltrim($endpoint, '/'),
                'source' => (string) ($context['source'] ?? ''),
                'reservation_nonce' => (string) ($context['canary_reservation_nonce'] ?? ''),
            ];
            $expected = [
                'meli_account_id' => (string) ($canary['meli_account_id'] ?? ''),
                'transport_meli_account_id' => (string) ($canary['meli_account_id'] ?? ''),
                'expected_meli_user_id' => (string) ($canary['expected_meli_user_id'] ?? ''),
                'method' => (string) ($canary['method'] ?? ''),
                'endpoint' => (string) ($canary['endpoint'] ?? ''),
                'source' => (string) ($canary['source'] ?? ''),
                'reservation_nonce' => (string) ($canary['reservation_nonce'] ?? ''),
            ];
            foreach ($expected as $key => $value) {
                if ($value === '' || !hash_equals($value, $actual[$key])) {
                    throw new ApiManualPauseException('app', null, null, 'La solicitud no coincide con la reserva canaria autorizada.');
                }
            }
            $canary['used_calls'] = 1;
            $canary['state'] = 'in_flight';
            $canary['dispatched_at'] = gmdate(DATE_ATOM);
            return $canary;
        });
    }

    public function completeCanaryTransport(bool $success, ?int $status = null): void
    {
        if (!is_file($this->markerPath(self::CANARY_MARKER))) {
            return;
        }
        $this->mutateCanary(function (array $canary) use ($success, $status): array {
            if (($canary['state'] ?? '') !== 'in_flight') {
                return $canary;
            }
            $canary['http_status'] = $status;
            $canary['response_at'] = gmdate(DATE_ATOM);
            if ($success) {
                // Un 2xx solo certifica transporte. La identidad se valida
                // después, usando el cuerpo ya decodificado de /users/me.
                $canary['state'] = 'response_known';
                $canary['transport_result'] = 'success';
                return $canary;
            }
            $canary['state'] = 'complete';
            $canary['last_result'] = 'failed';
            $canary['identity_verified'] = false;
            $canary['failure_class'] = 'transport_or_http_failure';
            $canary['completed_at'] = gmdate(DATE_ATOM);
            return $canary;
        });
    }

    public function completeApiCanarySuccess(string $nonce, int $accountId, string $actualMeliUserId): void
    {
        $this->mutateCanary(function (array $canary) use ($nonce, $accountId, $actualMeliUserId): array {
            $expectedId = (string) ($canary['expected_meli_user_id'] ?? '');
            if (($canary['state'] ?? '') !== 'response_known'
                || !hash_equals((string) ($canary['reservation_nonce'] ?? ''), $nonce)
                || (int) ($canary['meli_account_id'] ?? 0) !== $accountId
                || $expectedId === ''
                || !hash_equals($expectedId, trim($actualMeliUserId))) {
                throw new RuntimeException('La identidad de Mercado Libre no coincide con la cuenta seleccionada.');
            }
            $canary['state'] = 'complete';
            $canary['last_result'] = 'success';
            $canary['identity_verified'] = true;
            $canary['completed_at'] = gmdate(DATE_ATOM);
            return $canary;
        });
    }

    public function failApiCanaryAndBlock(
        string $actor,
        string $failureClass,
        string $reference,
        ?int $status = null
    ): void {
        // Fail closed primero. No se usa stopApi(), porque borraría la evidencia.
        $this->writeMarker(self::API_MARKER, $actor, 'Prueba canaria fallida. Referencia: ' . $reference . '.');
        if (is_file($this->markerPath(self::CANARY_MARKER))) {
            $this->mutateCanary(function (array $canary) use ($failureClass, $reference, $status): array {
                $canary['state'] = 'complete';
                $canary['last_result'] = 'failed';
                $canary['identity_verified'] = false;
                $canary['failure_class'] = mb_substr($failureClass, 0, 80);
                $canary['failure_reference'] = mb_substr($reference, 0, 80);
                if ($status !== null) {
                    $canary['http_status'] = $status;
                }
                $canary['completed_at'] = gmdate(DATE_ATOM);
                return $canary;
            });
        }
        $this->recordChange('api_canary_failed', $actor, 'Prueba canaria fallida. Referencia: ' . $reference . '.');
        $this->audit('api_canary_failed', $actor, 'failure_class=' . mb_substr($failureClass, 0, 80) . ' reference=' . mb_substr($reference, 0, 80));
    }

    public function apiStopped(): bool
    {
        return is_file($this->markerPath(self::API_MARKER));
    }

    public function automationStopped(): bool
    {
        return is_file($this->markerPath(self::AUTOMATION_MARKER));
    }

    public function assertAutomationStoppedForApiEnable(): void
    {
        if (!$this->automationStopped()) {
            throw new RuntimeException(
                'Primero detenga la automatización. Código: EMERGENCY_AUTOMATION_NOT_STOPPED.'
            );
        }
    }

    /**
     * Diagnóstico estrictamente local. No retira marcadores, no escribe DB y no
     * construye transporte Mercado Libre.
     *
     * @return array{ready:bool,checks:array<string,array{status:string,detail:string}>}
     */
    public function diagnoseApiReactivation(): array
    {
        $checks = [];
        $add = static function (array &$target, string $key, string $status, string $detail): void {
            $target[$key] = ['status' => $status, 'detail' => $detail];
        };

        $add(
            $checks,
            'installation_root',
            is_dir($this->root) && is_readable($this->root) ? 'PASS' : 'FAIL',
            is_dir($this->root) && is_readable($this->root)
                ? 'Raíz de instalación legible.'
                : 'No se pudo leer la raíz de instalación.'
        );
        $add(
            $checks,
            'marker_writability',
            is_writable($this->root) ? 'PASS' : 'FAIL',
            is_writable($this->root)
                ? 'La raíz permite cambios atómicos de marcadores.'
                : 'La raíz no permite cambiar marcadores de seguridad.'
        );
        $config = AppPaths::configFile();
        $add(
            $checks,
            'config',
            is_file($config) && is_readable($config) ? 'PASS' : 'FAIL',
            is_file($config) && is_readable($config)
                ? 'Configuración local legible.'
                : 'Configuración local ausente o no legible.'
        );
        $writesDisabled = !Env::bool('ML_WRITE_ENABLED', false);
        $add(
            $checks,
            'remote_writes',
            $writesDisabled ? 'PASS' : 'FAIL',
            $writesDisabled ? 'ML_WRITE_ENABLED permanece false.' : 'ML_WRITE_ENABLED está habilitado.'
        );
        $add(
            $checks,
            'transport_extension',
            function_exists('curl_init') ? 'PASS' : 'FAIL',
            function_exists('curl_init') ? 'La extensión cURL está disponible.' : 'La extensión cURL no está disponible.'
        );
        $automationStopped = $this->automationStopped();
        $add(
            $checks,
            'automation',
            $automationStopped ? 'PASS' : 'FAIL',
            $automationStopped
                ? 'La automatización está detenida.'
                : 'La automatización debe detenerse antes de habilitar lecturas.'
        );
        $add(
            $checks,
            'api_marker',
            is_file($this->markerPath(self::API_MARKER)) ? 'PASS' : 'WARNING',
            is_file($this->markerPath(self::API_MARKER))
                ? 'Las lecturas continúan bloqueadas durante el diagnóstico.'
                : 'Las lecturas ya no tienen marcador físico de bloqueo.'
        );

        $pdo = null;
        try {
            Database::useProfile('diagnostic');
            $pdo = Database::connection();
            $pdo->query('SELECT 1')->fetchColumn();
            $add($checks, 'database', 'PASS', 'MariaDB respondió a una lectura local.');
        } catch (Throwable) {
            $add($checks, 'database', 'FAIL', 'No se pudo establecer una conexión local de solo lectura con MariaDB.');
        }

        if ($pdo instanceof PDO) {
            try {
                $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
                $lookup = array_fill_keys(array_map('strval', $applied), true);
                $migrationDirectory = $this->root . '/database/migrations';
                if (!is_dir($migrationDirectory) || !is_readable($migrationDirectory)) {
                    throw new RuntimeException('El directorio de migraciones no es legible.');
                }
                $migrationFiles = glob($migrationDirectory . '/*.sql');
                if ($migrationFiles === false) {
                    throw new RuntimeException('No se pudo enumerar el directorio de migraciones.');
                }
                $pending = 0;
                foreach ($migrationFiles as $file) {
                    if (!isset($lookup[basename($file)])) {
                        $pending++;
                    }
                }
                $add(
                    $checks,
                    'migrations',
                    $pending === 0 ? 'PASS' : 'FAIL',
                    $pending === 0 ? 'Migraciones completas.' : 'Hay migraciones pendientes: ' . $pending . '.'
                );
            } catch (Throwable) {
                $add($checks, 'migrations', 'UNKNOWN', 'No se pudo comprobar la autoridad schema_migrations.');
            }

            try {
                $accounts = (int) $pdo->query(
                    "SELECT COUNT(*) FROM meli_accounts WHERE status='conectado'"
                )->fetchColumn();
                $add(
                    $checks,
                    'accounts_metadata',
                    $accounts > 0 ? 'PASS' : 'FAIL',
                    $accounts > 0 ? 'Cuentas conectadas con metadata local: ' . $accounts . '.' : 'No hay cuentas conectadas.'
                );
            } catch (Throwable) {
                $add($checks, 'accounts_metadata', 'UNKNOWN', 'No se pudo comprobar la autoridad meli_accounts.');
            }

            try {
                $tokens = (int) $pdo->query(
                    "SELECT COUNT(*)
                     FROM meli_accounts a
                     INNER JOIN meli_tokens t ON t.meli_account_id=a.id
                     WHERE a.status='conectado' AND t.access_token_encrypted IS NOT NULL"
                )->fetchColumn();
                $add(
                    $checks,
                    'token_metadata',
                    $tokens > 0 ? 'PASS' : 'FAIL',
                    $tokens > 0 ? 'Token metadata local disponible para ' . $tokens . ' cuenta(s).' : 'No hay token metadata disponible.'
                );
            } catch (Throwable) {
                $add($checks, 'token_metadata', 'UNKNOWN', 'No se pudo comprobar la autoridad meli_tokens.');
            }

            try {
                $activePauses = (int) $pdo->query(
                    "SELECT COUNT(*) FROM api_manual_pauses WHERE status='active'"
                )->fetchColumn();
                $add(
                    $checks,
                    'manual_pause_state',
                    $activePauses === 0 ? 'PASS' : 'FAIL',
                    $activePauses === 0
                        ? 'No hay pausas manuales activas.'
                        : 'Hay pausas manuales activas: ' . $activePauses . '.'
                );
            } catch (Throwable) {
                $add($checks, 'manual_pause_state', 'UNKNOWN', 'No se pudo comprobar la autoridad api_manual_pauses.');
            }

            try {
                $openCircuits = (int) $pdo->query(
                    "SELECT COUNT(*) FROM api_circuit_breakers WHERE status='open'"
                )->fetchColumn();
                $add(
                    $checks,
                    'circuit_state',
                    $openCircuits === 0 ? 'PASS' : 'FAIL',
                    $openCircuits === 0
                        ? 'No hay circuitos API abiertos.'
                        : 'Hay circuitos API abiertos: ' . $openCircuits . '.'
                );
            } catch (Throwable) {
                $add($checks, 'circuit_state', 'UNKNOWN', 'No se pudo comprobar la autoridad api_circuit_breakers.');
            }

            try {
                $guardType = get_debug_type(new ApiGuardService());
                $guardValue = $pdo->prepare(
                    "SELECT setting_value FROM app_settings WHERE setting_key='api.guard.enabled' LIMIT 1"
                );
                $guardValue->execute();
                $guardEnabled = filter_var((string) $guardValue->fetchColumn(), FILTER_VALIDATE_BOOL);
                $add(
                    $checks,
                    'api_guard',
                    $guardEnabled ? 'PASS' : 'FAIL',
                    $guardEnabled
                        ? $guardType . ' y api.guard.enabled están activos.'
                        : 'La protección api.guard.enabled no está activa.'
                );
            } catch (Throwable) {
                $add($checks, 'api_guard', 'UNKNOWN', 'No se pudo inicializar la protección ApiGuardService.');
            }
        } else {
            foreach (['migrations', 'accounts_metadata', 'token_metadata', 'manual_pause_state', 'circuit_state', 'api_guard'] as $key) {
                $add($checks, $key, 'UNKNOWN', 'No se pudo comprobar esta autoridad porque MariaDB no estuvo disponible.');
            }
        }

        $mandatoryChecks = [
            'installation_root',
            'marker_writability',
            'config',
            'remote_writes',
            'transport_extension',
            'automation',
            'database',
            'migrations',
            'accounts_metadata',
            'token_metadata',
            'manual_pause_state',
            'circuit_state',
            'api_guard',
        ];
        $ready = true;
        foreach ($mandatoryChecks as $key) {
            $status = (string) ($checks[$key]['status'] ?? 'UNKNOWN');
            if ($status !== 'PASS') {
                $ready = false;
                break;
            }
        }
        return ['ready' => $ready, 'checks' => $checks];
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

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $callback
     * @return array<string,mixed>
     */
    private function mutateCanary(callable $callback): array
    {
        $path = $this->markerPath(self::CANARY_MARKER);
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException('No se pudo comprobar el canario de Mercado Libre.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Otra acción canaria está en curso.');
            }
            rewind($handle);
            $raw = stream_get_contents($handle);
            $canary = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
            if (!is_array($canary)) {
                throw new RuntimeException('No existe una prueba canaria preparada.');
            }
            $updated = $callback($canary);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($updated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            fflush($handle);
            @chmod($path, 0600);
            return $updated;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
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
            throw new RuntimeException('No fue posible cambiar el marcador de seguridad. Código: EMERGENCY_MARKER_WRITE_FAILED.');
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
            throw new RuntimeException('No se pudo preparar el almacenamiento de seguridad. Código: EMERGENCY_MARKER_WRITE_FAILED.');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (@file_put_contents($temporary, $json, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible guardar el estado de seguridad. Código: EMERGENCY_MARKER_WRITE_FAILED.');
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
