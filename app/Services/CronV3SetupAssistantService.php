<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class CronV3SetupAssistantService
{
    private const CONFIG_KEYS = [
        'CRON_V3_ENABLED',
        'CRON_V3_SHADOW_ENABLED',
        'CRON_V3_RATE_LIMIT',
        'CRON_V3_API_TIMEOUT',
        'CRON_V3_API_CONNECT_TIMEOUT',
    ];

    private const SAFE_CONFIG = [
        'CRON_V3_ENABLED' => 'false',
        'CRON_V3_SHADOW_ENABLED' => 'false',
        'CRON_V3_RATE_LIMIT' => '10',
        'CRON_V3_API_TIMEOUT' => '8',
        'CRON_V3_API_CONNECT_TIMEOUT' => '3',
    ];

    private const OPERATIONAL_CONFIG = [
        'CRON_V3_ENABLED' => 'true',
        'CRON_V3_SHADOW_ENABLED' => 'false',
        'CRON_V3_RATE_LIMIT' => '10',
        'CRON_V3_API_TIMEOUT' => '8',
        'CRON_V3_API_CONNECT_TIMEOUT' => '3',
    ];

    public function __construct(
        private readonly ?PDO $pdo = null,
        private readonly ?string $configPath = null,
        private readonly ?string $releaseRoot = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $config = $this->readConfigFile();
        $processOverrides = $this->processOverrides($config['values']);
        $localDoctor = (new CronV3DoctorService($this->pdo))->snapshot('local');
        $remoteDoctor = (new CronV3DoctorService($this->pdo))->snapshot('remote');
        $shadow = $this->shadowSignals();
        $mlWriteEnabled = Env::bool('ML_WRITE_ENABLED', false);
        $safeApplied = $this->safeConfigApplied($config['values'])
            && !$mlWriteEnabled
            && $processOverrides === [];
        $doctorReady = !empty($localDoctor['ok']) && !empty($remoteDoctor['ok']);
        $shadowEnabled = filter_var($this->effectiveValue('CRON_V3_SHADOW_ENABLED', 'false'), FILTER_VALIDATE_BOOL);
        $activeEnabled = filter_var($this->effectiveValue('CRON_V3_ENABLED', 'false'), FILTER_VALIDATE_BOOL);
        $canaryActiveByEvidence = $this->canaryOperationalEvidence();
        $blocking = [];
        if ($mlWriteEnabled) {
            $blocking[] = 'ML_WRITE_ENABLED debe permanecer en false para la prueba.';
        }
        foreach ($processOverrides as $override) {
            $blocking[] = 'Variable externa sobrescribe config.env: ' . $override['key'];
        }
        if (!$doctorReady) {
            $blocking[] = 'Doctor V3 todavía no aprueba local y remoto.';
        }

        return [
            'ok' => $safeApplied && $doctorReady,
            'read_only' => true,
            'state' => $this->state($safeApplied, $doctorReady, $shadowEnabled, $shadow, $activeEnabled || $canaryActiveByEvidence),
            'config_path' => $this->safePath($this->configPath()),
            'safe_config_applied' => $safeApplied,
            'ml_write_enabled' => $mlWriteEnabled,
            'shadow_enabled' => $shadowEnabled,
            'active_enabled' => $activeEnabled,
            'canary_active_by_evidence' => $canaryActiveByEvidence,
            'process_overrides' => $processOverrides,
            'blocking' => $blocking,
            'doctors' => [
                'local' => $this->compactDoctor($localDoctor),
                'remote' => $this->compactDoctor($remoteDoctor),
            ],
            'shadow' => $shadow,
            'commands' => $this->hostingerCommands(),
            'steps' => $this->steps($safeApplied, $doctorReady, $shadowEnabled, $shadow, $blocking, $activeEnabled || $canaryActiveByEvidence),
        ];
    }

    /** @return array<string,mixed> */
    public function prepareSafeConfig(?int $userId = null): array
    {
        $this->assertMlWritesDisabled();
        $this->assertNoContradictingProcessOverrides(self::SAFE_CONFIG);
        $this->writeConfig(self::SAFE_CONFIG);
        $this->syncDatabaseFlags(self::SAFE_CONFIG, $userId);
        foreach (array_keys(self::SAFE_CONFIG) as $key) {
            AppSettingsService::clearCache($this->databaseKeyFor($key) ?? $key);
        }

        return [
            'ok' => true,
            'message' => 'Configuración segura de Cron V3 preparada. V3 real sigue apagado.',
            'config' => self::SAFE_CONFIG,
        ];
    }

    /** @return array<string,mixed> */
    public function prepareOperationalConfig(?int $userId = null): array
    {
        $this->assertMlWritesDisabled();
        $this->assertNoContradictingProcessOverrides(self::OPERATIONAL_CONFIG);
        $localDoctor = (new CronV3DoctorService($this->pdo))->snapshot('local');
        $remoteDoctor = (new CronV3DoctorService($this->pdo))->snapshot('remote');
        if (empty($localDoctor['ok']) || empty($remoteDoctor['ok'])) {
            throw new \RuntimeException('cron_v3_doctor_blocked');
        }

        $this->writeConfig(self::OPERATIONAL_CONFIG);
        $this->syncDatabaseFlags(self::OPERATIONAL_CONFIG, $userId);
        $settings = new AppSettingsService();
        $settings->set('cron_v3.operational_mode', '1', 'cron_v3');
        $settings->set('cron_v3.v2_runtime_disabled', '1', 'cron_v3');
        $settings->set('cron_v3.rollback_enabled', '0', 'cron_v3');
        $settings->set('cron_v3.operational_phase', 'pending_hostinger', 'cron_v3');
        $settings->set('cron_v3.operational_release', '2.30.0', 'cron_v3');
        $settings->set('cron_v3.operational.last_prepared_at', gmdate('Y-m-d H:i:s'), 'cron_v3');
        if ($userId !== null && $userId > 0) {
            $settings->set('cron_v3.operational.last_prepared_by', (string) $userId, 'cron_v3');
        }
        foreach (array_merge(array_keys(self::OPERATIONAL_CONFIG), [
            'cron_v3.operational_mode',
            'cron_v3.v2_runtime_disabled',
            'cron_v3.rollback_enabled',
        ]) as $key) {
            AppSettingsService::clearCache($this->databaseKeyFor($key) ?? $key);
        }

        return [
            'ok' => true,
            'message' => 'Cron V3 quedó preparado como motor operativo. V2 se saltará solo; mantenga solo las dos tareas V3 en Hostinger.',
            'config' => self::OPERATIONAL_CONFIG,
        ];
    }

    /** @return array<string,mixed> */
    public function enableShadow(?int $userId = null): array
    {
        $desired = self::SAFE_CONFIG;
        $desired['CRON_V3_SHADOW_ENABLED'] = 'true';
        $this->assertMlWritesDisabled();
        $this->assertNoContradictingProcessOverrides($desired);
        $localDoctor = (new CronV3DoctorService($this->pdo))->snapshot('local');
        $remoteDoctor = (new CronV3DoctorService($this->pdo))->snapshot('remote');
        if (empty($localDoctor['ok']) || empty($remoteDoctor['ok'])) {
            throw new \RuntimeException('cron_v3_doctor_blocked');
        }
        $this->writeConfig($desired);
        $this->syncDatabaseFlags($desired, $userId);

        return [
            'ok' => true,
            'message' => 'Shadow V3 activado. V3 real y ownership continúan apagados.',
            'config' => $desired,
        ];
    }

    /** @return array<string,string> */
    public static function desiredSafeConfig(): array
    {
        return self::SAFE_CONFIG;
    }

    /** @return array{values:array<string,string>,exists:bool} */
    private function readConfigFile(): array
    {
        $path = $this->configPath();
        if (!is_file($path)) {
            return ['values' => [], 'exists' => false];
        }
        return ['values' => $this->parseConfig((string) file_get_contents($path)), 'exists' => true];
    }

    /** @return array<string,string> */
    private function parseConfig(string $body): array
    {
        $values = [];
        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $trimmed, 2));
            if ($key !== '') {
                $values[$key] = $this->decodeValue($value);
            }
        }
        return $values;
    }

    private function decodeValue(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2 && $value[0] === "'" && $value[$length - 1] === "'") {
            return substr($value, 1, -1);
        }
        if ($length >= 2 && $value[0] === '"' && $value[$length - 1] === '"') {
            return (string) preg_replace_callback('/\\\\([\\\\"])/', static fn (array $m): string => $m[1], substr($value, 1, -1));
        }
        return $value;
    }

    /** @param array<string,string> $values @return list<array{key:string,process_value:string,config_value:?string}> */
    private function processOverrides(array $values): array
    {
        $overrides = [];
        foreach (self::CONFIG_KEYS as $key) {
            $process = $this->processValue($key);
            if ($process === null) {
                continue;
            }
            $configValue = $values[$key] ?? null;
            if ($configValue === null || $this->normalize($process) !== $this->normalize($configValue)) {
                $overrides[] = [
                    'key' => $key,
                    'process_value' => $this->redactValue($process),
                    'config_value' => $configValue === null ? null : $this->redactValue($configValue),
                ];
            }
        }
        return $overrides;
    }

    /** @param array<string,string> $desired */
    private function assertNoContradictingProcessOverrides(array $desired): void
    {
        foreach ($desired as $key => $value) {
            $process = $this->processValue($key);
            if ($process !== null && $this->normalize($process) !== $this->normalize($value)) {
                throw new \RuntimeException('cron_v3_process_env_override:' . $key);
            }
        }
    }

    private function processValue(string $key): ?string
    {
        if (array_key_exists($key, $_ENV)) {
            return (string) $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER)) {
            return (string) $_SERVER[$key];
        }

        $value = getenv($key);
        return $value === false ? null : (string) $value;
    }

    private function normalize(string $value): string
    {
        $lower = strtolower(trim($value));
        return match ($lower) {
            '1', 'true', 'yes', 'on' => 'true',
            '0', 'false', 'no', 'off', '' => 'false',
            default => $lower,
        };
    }

    private function effectiveValue(string $key, string $default): string
    {
        $file = $this->readConfigFile();
        $process = $this->processValue($key);
        return $process ?? ($file['values'][$key] ?? $default);
    }

    /** @param array<string,string> $values */
    private function safeConfigApplied(array $values): bool
    {
        foreach (self::SAFE_CONFIG as $key => $value) {
            if ($key === 'CRON_V3_ENABLED' || $key === 'CRON_V3_SHADOW_ENABLED') {
                continue;
            }
            if (!array_key_exists($key, $values) || $this->normalize($values[$key]) !== $this->normalize($value)) {
                return false;
            }
        }
        if (!array_key_exists('CRON_V3_ENABLED', $values) || !array_key_exists('CRON_V3_SHADOW_ENABLED', $values)) {
            return false;
        }
        return true;
    }

    /** @param array<string,string> $updates */
    private function writeConfig(array $updates): void
    {
        $path = $this->configPath();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('cron_v3_config_directory_unavailable');
        }
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $lines = $existing === '' ? [] : preg_split('/\R/u', $existing);
        if ($lines === false) {
            $lines = [];
        }
        $seen = [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', (string) $line, $match) !== 1) {
                continue;
            }
            $key = $match[1];
            if (!array_key_exists($key, $updates)) {
                continue;
            }
            $lines[$index] = $key . '=' . $updates[$key];
            $seen[$key] = true;
        }
        if ($lines === [] || trim((string) end($lines)) !== '') {
            $lines[] = '';
        }
        foreach ($updates as $key => $value) {
            if (!isset($seen[$key])) {
                $lines[] = $key . '=' . $value;
            }
        }
        $body = rtrim(implode(PHP_EOL, $lines), "\r\n") . PHP_EOL;
        $temporary = $path . '.next';
        if (@file_put_contents($temporary, $body, LOCK_EX) === false) {
            throw new \RuntimeException('cron_v3_config_write_failed');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($path);
            if (!@rename($temporary, $path)) {
                @unlink($temporary);
                throw new \RuntimeException('cron_v3_config_replace_failed');
            }
        }
        @chmod($path, 0600);
    }

    /** @param array<string,string> $values */
    private function syncDatabaseFlags(array $values, ?int $userId): void
    {
        try {
            $settings = new AppSettingsService();
            foreach ($values as $key => $value) {
                $databaseKey = $this->databaseKeyFor($key);
                if ($databaseKey === null) {
                    continue;
                }
                $settings->set($databaseKey, $this->databaseValue($key, $value), 'cron_v3');
            }
            $settings->set('cron_v3.assistant.last_prepared_at', gmdate('Y-m-d H:i:s'), 'cron_v3');
            if ($userId !== null && $userId > 0) {
                $settings->set('cron_v3.assistant.last_prepared_by', (string) $userId, 'cron_v3');
            }
        } catch (Throwable) {
            // config.env es la autoridad real de los launchers. La base queda
            // como espejo diagnóstico y no debe impedir una preparación segura.
        }
    }

    private function databaseKeyFor(string $envKey): ?string
    {
        return match ($envKey) {
            'CRON_V3_ENABLED' => 'cron_v3.enabled',
            'CRON_V3_SHADOW_ENABLED' => 'cron_v3.shadow_enabled',
            'CRON_V3_RATE_LIMIT' => 'cron_v3.remote_rate_limit',
            default => null,
        };
    }

    private function databaseValue(string $key, string $value): string
    {
        if ($key === 'CRON_V3_RATE_LIMIT') {
            return (string) max(1, min(300, (int) $value));
        }
        return filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0';
    }

    private function assertMlWritesDisabled(): void
    {
        if (Env::bool('ML_WRITE_ENABLED', false)) {
            throw new \RuntimeException('cron_v3_ml_write_enabled');
        }
    }

    /** @return array<string,mixed> */
    private function shadowSignals(): array
    {
        try {
            $pdo = $this->pdo ?? Database::connectionFresh();
            $rows = $pdo->query(
                "SELECT lane,generation,observed_at,payload_json
                 FROM cron_v3_snapshots
                 WHERE snapshot_key IN ('run:local','run:remote')"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [
                'available' => false,
                'cycles' => 0,
                'required_cycles' => 60,
                'complete' => false,
                'lanes' => [],
            ];
        }
        $lanes = [];
        $cycles = 0;
        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
            $payload = is_array($payload) ? $payload : [];
            $lane = (string) ($row['lane'] ?? '');
            $generation = max(0, (int) ($row['generation'] ?? 0));
            $lanes[$lane] = [
                'generation' => $generation,
                'observed_at' => $row['observed_at'] ?? null,
                'mode' => (string) ($payload['mode'] ?? 'unknown'),
                'http_calls' => (int) ($payload['http_calls'] ?? 0),
                'source_mutations' => (int) ($payload['source_mutations'] ?? 0),
            ];
            if (($payload['mode'] ?? '') === 'shadow'
                && (int) ($payload['http_calls'] ?? 0) === 0
                && (int) ($payload['source_mutations'] ?? 0) === 0) {
                $cycles += $generation;
            }
        }
        return [
            'available' => $lanes !== [],
            'cycles' => $cycles,
            'required_cycles' => 60,
            'complete' => $cycles >= 60,
            'lanes' => $lanes,
        ];
    }

    /** @param array<string,mixed> $doctor @return array<string,mixed> */
    private function compactDoctor(array $doctor): array
    {
        return [
            'ok' => (bool) ($doctor['ok'] ?? false),
            'state' => (string) ($doctor['state'] ?? 'unavailable'),
            'issues' => array_values((array) ($doctor['issues'] ?? [])),
            'warnings' => array_values((array) ($doctor['warnings'] ?? [])),
            'http_calls' => (int) ($doctor['http_calls'] ?? 0),
            'read_only' => (bool) ($doctor['read_only'] ?? false),
        ];
    }

    /** @return array<string,string> */
    private function hostingerCommands(): array
    {
        $root = str_replace('\\', '/', $this->releaseRoot());
        return [
            'v2_real' => '/usr/bin/php ' . $root . '/jobs/process_sync_queue.php',
            'v3_local_shadow' => '/usr/bin/php ' . $root . '/jobs/cron_v3_local.php --shadow --runtime=45 --max-items=50',
            'v3_remote_shadow' => '/usr/bin/php ' . $root . '/jobs/cron_v3_remote.php --shadow --delay=10 --runtime=35 --max-http=12',
            'v3_local_active' => '/usr/bin/php ' . $root . '/jobs/cron_v3_local.php --runtime=45 --max-items=50',
            'v3_remote_active' => '/usr/bin/php ' . $root . '/jobs/cron_v3_remote.php --delay=10 --runtime=35 --max-http=12',
        ];
    }

    /** @param list<string> $blocking @return list<array<string,mixed>> */
    private function steps(bool $safeApplied, bool $doctorReady, bool $shadowEnabled, array $shadow, array $blocking, bool $activeEnabled = false): array
    {
        $shadowApprovedOrSuperseded = !empty($shadow['complete']) || $activeEnabled;
        return [
            ['key' => 'installation', 'label' => 'Instalación lista', 'done' => $doctorReady || $safeApplied],
            ['key' => 'safe_config', 'label' => 'Configuración segura aplicada', 'done' => $safeApplied],
            ['key' => 'doctor', 'label' => 'Doctor local y remoto aprobado', 'done' => $doctorReady],
            ['key' => 'hostinger', 'label' => 'Comandos Hostinger disponibles', 'done' => true],
            ['key' => 'shadow', 'label' => 'Shadow habilitado', 'done' => $shadowEnabled],
            ['key' => 'cycles', 'label' => $activeEnabled ? 'Shadow histórico cerrado por canario activo' : '60 ciclos shadow verificados', 'done' => $shadowApprovedOrSuperseded],
            ['key' => 'blocked', 'label' => $blocking === [] ? 'Sin bloqueos críticos' : implode(' · ', $blocking), 'done' => $blocking === []],
        ];
    }

    /** @param array<string,mixed> $shadow */
    private function state(bool $safeApplied, bool $doctorReady, bool $shadowEnabled, array $shadow, bool $activeEnabled = false): string
    {
        if (!$safeApplied) {
            return 'needs_safe_config';
        }
        if (!$doctorReady) {
            return 'doctor_blocked';
        }
        if ($activeEnabled) {
            return 'canary_controlled';
        }
        if (!$shadowEnabled) {
            return 'ready_for_shadow';
        }
        return !empty($shadow['complete']) ? 'shadow_complete' : 'shadow_running';
    }

    private function canaryOperationalEvidence(): bool
    {
        try {
            $pdo = $this->pdo ?? Database::connectionFresh();
            $types = ['financial_recalc', 'pack_exact', 'shipment_exact'];
            $placeholders = implode(',', array_fill(0, count($types), '?'));
            $ownership = $pdo->prepare(
                "SELECT COUNT(*) AS total
                 FROM cron_v3_queue_ownership
                 WHERE queue_key IN (" . $placeholders . ")
                   AND owner_engine='v3'
                   AND enabled=1"
            );
            $ownership->execute($types);
            if ((int) (($ownership->fetch(PDO::FETCH_ASSOC) ?: [])['total'] ?? 0) < count($types)) {
                return false;
            }

            $snapshot = $pdo->query(
                "SELECT COUNT(*) AS total
                 FROM cron_v3_snapshots
                 WHERE snapshot_key IN ('run:local','run:remote')
                   AND generation > 0
                   AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.mode'))='active'"
            );
            if ((int) (($snapshot->fetch(PDO::FETCH_ASSOC) ?: [])['total'] ?? 0) > 0) {
                return true;
            }

            $attempts = $pdo->prepare(
                "SELECT COALESCE(SUM(a.physical_http_calls),0) AS http_calls,
                        SUM(CASE WHEN a.outcome='completed' THEN 1 ELSE 0 END) AS completed
                 FROM cron_v3_attempts a
                 INNER JOIN cron_v3_work w ON w.id=a.work_id
                 WHERE w.work_type IN (" . $placeholders . ")
                   AND a.started_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)"
            );
            $attempts->execute($types);
            $row = $attempts->fetch(PDO::FETCH_ASSOC) ?: [];
            return (int) ($row['http_calls'] ?? 0) > 0 || (int) ($row['completed'] ?? 0) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function configPath(): string
    {
        return $this->configPath ?? AppPaths::configFile();
    }

    private function releaseRoot(): string
    {
        return $this->releaseRoot ?? AppPaths::releaseRoot();
    }

    private function safePath(string $path): string
    {
        return basename(dirname($path)) . '/' . basename($path);
    }

    private function redactValue(string $value): string
    {
        return preg_match('/token|secret|password|key/i', $value) === 1 ? '[redactado]' : $value;
    }
}
