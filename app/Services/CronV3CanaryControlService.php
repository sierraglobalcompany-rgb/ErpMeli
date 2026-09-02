<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class CronV3CanaryControlService
{
    private const CONFIG_KEYS = [
        'CRON_V3_ENABLED',
        'CRON_V3_SHADOW_ENABLED',
        'CRON_V3_RATE_LIMIT',
        'CRON_V3_API_TIMEOUT',
        'CRON_V3_API_CONNECT_TIMEOUT',
    ];

    private const CANARY_CONFIG = [
        'CRON_V3_ENABLED' => 'true',
        'CRON_V3_SHADOW_ENABLED' => 'true',
        'CRON_V3_RATE_LIMIT' => '10',
        'CRON_V3_API_TIMEOUT' => '8',
        'CRON_V3_API_CONNECT_TIMEOUT' => '3',
    ];

    private const ROLLBACK_CONFIG = [
        'CRON_V3_ENABLED' => 'false',
        'CRON_V3_SHADOW_ENABLED' => 'true',
        'CRON_V3_RATE_LIMIT' => '10',
        'CRON_V3_API_TIMEOUT' => '8',
        'CRON_V3_API_CONNECT_TIMEOUT' => '3',
    ];

    private const LOCAL_CANARY_TYPES = ['financial_recalc'];
    private const REMOTE_CANARY_TYPES = ['pack_exact', 'shipment_exact'];
    private const CERTIFIED_CUTOVER_TYPES = ['financial_recalc', 'pack_exact', 'shipment_exact', 'sale_billing_capture'];

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
        $localDoctor = (new CronV3DoctorService($this->pdo))->snapshot('local');
        $remoteDoctor = (new CronV3DoctorService($this->pdo))->snapshot('remote');
        $doctorReady = !empty($localDoctor['ok']) && !empty($remoteDoctor['ok']);
        $shadow = $this->shadowSignals();
        $mlWriteEnabled = Env::bool('ML_WRITE_ENABLED', false);
        $activeEnabled = $this->boolValue('CRON_V3_ENABLED', false);
        $shadowEnabled = $this->boolValue('CRON_V3_SHADOW_ENABLED', false);
        $processOverrides = $this->processOverrides($config['values']);
        $ownership = $this->ownershipSnapshot();
        $localEnabled = $this->allOwned($ownership, self::LOCAL_CANARY_TYPES);
        $remoteEnabled = $this->allOwned($ownership, self::REMOTE_CANARY_TYPES);
        $certifiedCutover = (new CronV3CertifiedCutoverService($this->pdo))->snapshot();
        $metrics = $this->canaryMetrics();
        $canaryHasEvidence = (int) ($metrics['http_calls'] ?? 0) > 0
            || (int) ($metrics['resources_finalized'] ?? 0) > 0
            || (int) ($metrics['cycles']['local'] ?? 0) > 0
            || (int) ($metrics['cycles']['remote'] ?? 0) > 0;
        $canaryActive = ($activeEnabled || $canaryHasEvidence) && $localEnabled && $remoteEnabled;
        $canaryHasSignal = $canaryActive && (
            (int) ($metrics['http_calls'] ?? 0) > 0
            || (int) ($metrics['resources_finalized'] ?? 0) > 0
            || (int) ($metrics['cycles']['local'] ?? 0) > 0
            || (int) ($metrics['cycles']['remote'] ?? 0) > 0
        );
        $canarySafety = $this->canarySafety($metrics);
        $shadowApproved = !empty($shadow['complete']) || $canaryHasSignal;
        $shadow['approved'] = $shadowApproved;
        $shadow['approval_source'] = !empty($shadow['complete'])
            ? 'shadow_cycles'
            : ($canaryHasSignal ? 'active_canary_evidence' : 'none');
        $canaryConfigApplied = ($this->configMatches($config['values'], self::CANARY_CONFIG) || $canaryActive)
            && $processOverrides === [];

        $blocking = [];
        if ($mlWriteEnabled) {
            $blocking[] = 'ML_WRITE_ENABLED debe permanecer en false. El canario V3 solo hace lectura.';
        }
        foreach ($processOverrides as $override) {
            $blocking[] = 'Variable externa sobrescribe config.env: ' . $override['key'];
        }
        if (!$doctorReady) {
            $blocking[] = 'Doctor V3 local y remoto debe aprobar antes de cualquier canario.';
        }
        if (!$shadowApproved) {
            $blocking[] = 'Faltan ciclos Shadow limpios: ' . (int) ($shadow['cycles'] ?? 0) . '/60.';
        }
        if ($canaryActive && $canarySafety['state'] === 'attention') {
            foreach ($canarySafety['issues'] as $issue) {
                $blocking[] = $issue;
            }
        }

        return [
            'ok' => $blocking === [],
            'read_only' => true,
            'state' => $this->state($blocking, $canaryConfigApplied, $activeEnabled, $localEnabled, $remoteEnabled, $canaryActive),
            'config_path' => $this->safePath($this->configPath()),
            'ml_write_enabled' => $mlWriteEnabled,
            'active_enabled' => $activeEnabled,
            'canary_active' => $canaryActive,
            'canary_active_by_evidence' => $canaryActive && !$activeEnabled,
            'shadow_enabled' => $shadowEnabled,
            'canary_config_applied' => $canaryConfigApplied,
            'shadow' => $shadow,
            'blocking' => $blocking,
            'process_overrides' => $processOverrides,
            'doctors' => [
                'local' => $this->compactDoctor($localDoctor),
                'remote' => $this->compactDoctor($remoteDoctor),
            ],
            'ownership' => $ownership,
            'metrics' => $metrics,
            'canary_health' => $canarySafety,
            'certified_cutover' => $certifiedCutover,
            'commands' => $this->hostingerCommands(),
            'steps' => $this->steps($blocking, $canaryConfigApplied, $activeEnabled, $localEnabled, $remoteEnabled, $shadow, $shadowApproved, $canaryActive),
        ];
    }

    /** @return array<string,mixed> */
    public function prepare(?int $userId = null): array
    {
        $this->assertCanaryPrerequisites(self::CANARY_CONFIG);
        $this->writeConfig(self::CANARY_CONFIG);
        $this->syncDatabaseFlags(self::CANARY_CONFIG, $userId, 'prepared');

        return [
            'ok' => true,
            'message' => 'Canario V3 preparado. V3 queda encendido, pero sin ownership real hasta habilitar el carril local.',
            'config' => self::CANARY_CONFIG,
        ];
    }

    /** @return array<string,mixed> */
    public function enableLocal(?int $userId = null): array
    {
        $this->assertCanaryPrerequisites(self::CANARY_CONFIG);
        $this->assertConfigEffective(self::CANARY_CONFIG);
        $this->setOwnership(self::LOCAL_CANARY_TYPES, 'local', true, $userId, 'canary_local_2_29_4');
        $this->setCanaryPhase('local_enabled', $userId);

        return [
            'ok' => true,
            'message' => 'Canario local habilitado solo para financial_recalc. No hará HTTP.',
        ];
    }

    /** @return array<string,mixed> */
    public function enableRemote(?int $userId = null): array
    {
        $this->assertCanaryPrerequisites(self::CANARY_CONFIG);
        $this->assertConfigEffective(self::CANARY_CONFIG);
        $ownership = $this->ownershipSnapshot();
        if (!$this->allOwned($ownership, self::LOCAL_CANARY_TYPES)) {
            throw new \RuntimeException('cron_v3_canary_local_required');
        }
        $this->setOwnership(self::REMOTE_CANARY_TYPES, 'remote', true, $userId, 'canary_remote_2_29_4');
        $this->setCanaryPhase('remote_enabled', $userId);

        return [
            'ok' => true,
            'message' => 'Canario remoto habilitado solo para pack_exact y shipment_exact con límite 10 rpm. Las escrituras Mercado Libre siguen bloqueadas.',
        ];
    }

    /** @return array<string,mixed> */
    public function rollback(?int $userId = null): array
    {
        $this->assertNoContradictingProcessOverrides(self::ROLLBACK_CONFIG);
        $this->writeConfig(self::ROLLBACK_CONFIG);
        $this->syncDatabaseFlags(self::ROLLBACK_CONFIG, $userId, 'rollback');
        $this->setOwnership(self::CERTIFIED_CUTOVER_TYPES, null, false, $userId, 'canary_rollback_2_29_12');
        $this->setCanaryPhase('rolled_back', $userId);

        return [
            'ok' => true,
            'message' => 'V3 real apagado y ownership devuelto a V2. La evidencia V3 se conserva.',
            'config' => self::ROLLBACK_CONFIG,
        ];
    }

    /** @param array<string,string> $desired */
    private function assertCanaryPrerequisites(array $desired): void
    {
        if (Env::bool('ML_WRITE_ENABLED', false)) {
            throw new \RuntimeException('cron_v3_ml_write_enabled');
        }
        $this->assertNoContradictingProcessOverrides($desired);
        $localDoctor = (new CronV3DoctorService($this->pdo))->snapshot('local');
        $remoteDoctor = (new CronV3DoctorService($this->pdo))->snapshot('remote');
        if (empty($localDoctor['ok']) || empty($remoteDoctor['ok'])) {
            throw new \RuntimeException('cron_v3_doctor_blocked');
        }
        $shadow = $this->shadowSignals();
        if (empty($shadow['complete'])) {
            throw new \RuntimeException('cron_v3_shadow_required');
        }
    }

    /** @param array<string,string> $desired */
    private function assertConfigEffective(array $desired): void
    {
        $config = $this->readConfigFile();
        if (!$this->configMatches($config['values'], $desired)) {
            throw new \RuntimeException('cron_v3_canary_config_required');
        }
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

    /** @param array<string,string> $desired */
    private function configMatches(array $values, array $desired): bool
    {
        foreach ($desired as $key => $value) {
            if (!array_key_exists($key, $values) || $this->normalize($values[$key]) !== $this->normalize($value)) {
                return false;
            }
        }
        return true;
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

    private function boolValue(string $key, bool $default): bool
    {
        return filter_var($this->effectiveValue($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOL);
    }

    private function effectiveValue(string $key, string $default): string
    {
        $file = $this->readConfigFile();
        $process = $this->processValue($key);
        return $process ?? ($file['values'][$key] ?? $default);
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
    private function syncDatabaseFlags(array $values, ?int $userId, string $phase): void
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
            $settings->set('cron_v3.canary.phase', $phase, 'cron_v3');
            $settings->set('cron_v3.canary.last_changed_at', gmdate('Y-m-d H:i:s'), 'cron_v3');
            if ($userId !== null && $userId > 0) {
                $settings->set('cron_v3.canary.last_changed_by', (string) $userId, 'cron_v3');
            }
        } catch (Throwable) {
            // config.env y ownership son la autoridad operativa. app_settings
            // queda como espejo diagnóstico; nunca debe dejar un canario a medias.
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

    /** @return array<string,array<string,mixed>> */
    private function ownershipSnapshot(): array
    {
        try {
            $pdo = $this->pdo ?? Database::connectionFresh();
            $keys = array_merge(self::LOCAL_CANARY_TYPES, self::REMOTE_CANARY_TYPES);
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare(
                'SELECT queue_key,lane,owner_engine,enabled,changed_by,changed_at
                 FROM cron_v3_queue_ownership
                 WHERE queue_key IN (' . $placeholders . ')
                 ORDER BY lane,queue_key'
            );
            $stmt->execute($keys);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
        $ownership = [];
        foreach ($rows as $row) {
            $ownership[(string) $row['queue_key']] = [
                'lane' => (string) $row['lane'],
                'owner_engine' => (string) $row['owner_engine'],
                'enabled' => (int) $row['enabled'] === 1,
                'changed_by' => $row['changed_by'] ?? null,
                'changed_at' => $row['changed_at'] ?? null,
            ];
        }
        return $ownership;
    }

    /** @param array<string,array<string,mixed>> $ownership @param list<string> $types */
    private function allOwned(array $ownership, array $types): bool
    {
        foreach ($types as $type) {
            if (($ownership[$type]['owner_engine'] ?? null) !== 'v3' || empty($ownership[$type]['enabled'])) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $types */
    private function setOwnership(array $types, ?string $lane, bool $enabled, ?int $userId, string $changedBy): void
    {
        $pdo = $this->pdo ?? Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            foreach ($types as $type) {
                $rowLane = $lane;
                if ($rowLane === null) {
                    $rowLane = in_array($type, self::LOCAL_CANARY_TYPES, true) ? 'local' : 'remote';
                }
                $stmt = $pdo->prepare(
                    "INSERT INTO cron_v3_queue_ownership (queue_key,lane,owner_engine,enabled,changed_by,changed_at)
                     VALUES (:queue_key,:lane,:owner_engine,:enabled,:changed_by,UTC_TIMESTAMP(3))
                     ON DUPLICATE KEY UPDATE
                        lane=VALUES(lane),
                        owner_engine=VALUES(owner_engine),
                        enabled=VALUES(enabled),
                        changed_by=VALUES(changed_by),
                        changed_at=VALUES(changed_at)"
                );
                $stmt->execute([
                    'queue_key' => $type,
                    'lane' => $rowLane,
                    'owner_engine' => $enabled ? 'v3' : 'disabled',
                    'enabled' => $enabled ? 1 : 0,
                    'changed_by' => $changedBy . ($userId !== null && $userId > 0 ? ':user:' . $userId : ''),
                ]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function setCanaryPhase(string $phase, ?int $userId): void
    {
        try {
            $settings = new AppSettingsService();
            $settings->set('cron_v3.canary.phase', $phase, 'cron_v3');
            $settings->set('cron_v3.canary.last_changed_at', gmdate('Y-m-d H:i:s'), 'cron_v3');
            if ($userId !== null && $userId > 0) {
                $settings->set('cron_v3.canary.last_changed_by', (string) $userId, 'cron_v3');
            }
        } catch (Throwable) {
            // La acción de ownership ya es la evidencia fuerte.
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
            return ['available' => false, 'cycles' => 0, 'required_cycles' => 60, 'complete' => false, 'lanes' => []];
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

    /** @return array<string,mixed> */
    private function canaryMetrics(): array
    {
        $types = array_merge(self::LOCAL_CANARY_TYPES, self::REMOTE_CANARY_TYPES);
        $metrics = [
            'cycles' => ['local' => 0, 'remote' => 0],
            'http_calls' => 0,
            'known_responses' => 0,
            'resources_finalized' => 0,
            'errors' => 0,
            'rate_429' => 0,
            'lease_lost' => 0,
            'duplicates' => 0,
        ];
        try {
            $pdo = $this->pdo ?? Database::connectionFresh();
            $snapshots = $pdo->query(
                "SELECT lane,generation,payload_json
                 FROM cron_v3_snapshots
                 WHERE snapshot_key IN ('run:local','run:remote')"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($snapshots as $row) {
                $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
                $payload = is_array($payload) ? $payload : [];
                if (($payload['mode'] ?? '') !== 'active') {
                    continue;
                }
                $lane = (string) ($row['lane'] ?? '');
                if (isset($metrics['cycles'][$lane])) {
                    $metrics['cycles'][$lane] = max(0, (int) ($row['generation'] ?? 0));
                }
            }

            $placeholders = implode(',', array_fill(0, count($types), '?'));
            $stmt = $pdo->prepare(
                "SELECT
                    COALESCE(SUM(a.physical_http_calls),0) AS http_calls,
                    COALESCE(SUM(a.known_response_count),0) AS known_responses,
                    SUM(CASE WHEN a.outcome='completed' THEN 1 ELSE 0 END) AS resources_finalized,
                    SUM(CASE WHEN a.outcome IN ('review','dead') THEN 1 ELSE 0 END) AS errors,
                    SUM(CASE WHEN a.outcome='lease_lost' THEN 1 ELSE 0 END) AS lease_lost
                 FROM cron_v3_attempts a
                 INNER JOIN cron_v3_work w ON w.id=a.work_id
                 WHERE w.work_type IN (" . $placeholders . ")
                   AND a.started_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)"
            );
            $stmt->execute($types);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $metrics['http_calls'] = (int) ($row['http_calls'] ?? 0);
            $metrics['known_responses'] = (int) ($row['known_responses'] ?? 0);
            $metrics['resources_finalized'] = (int) ($row['resources_finalized'] ?? 0);
            $metrics['errors'] = (int) ($row['errors'] ?? 0);
            $metrics['lease_lost'] = (int) ($row['lease_lost'] ?? 0);

            $duplicateStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(duplicates),0) AS duplicates
                 FROM (
                   SELECT COUNT(*) - 1 AS duplicates
                   FROM cron_v3_work
                   WHERE work_type IN (" . $placeholders . ")
                   GROUP BY work_type,company_id,meli_account_id,dedupe_key,input_version
                   HAVING COUNT(*) > 1
                 ) d"
            );
            $duplicateStmt->execute($types);
            $metrics['duplicates'] = (int) (($duplicateStmt->fetch(PDO::FETCH_ASSOC) ?: [])['duplicates'] ?? 0);
        } catch (Throwable) {
            $metrics['unavailable'] = true;
        }
        return $metrics;
    }

    /** @param array<string,mixed> $metrics @return array{state:string,issues:list<string>} */
    private function canarySafety(array $metrics): array
    {
        if (!empty($metrics['unavailable'])) {
            return ['state' => 'attention', 'issues' => ['No se pudo comprobar la evidencia del canario V3.']];
        }
        $issues = [];
        if ((int) ($metrics['errors'] ?? 0) > 0) {
            $issues[] = 'El canario V3 tiene errores reales recientes.';
        }
        if ((int) ($metrics['rate_429'] ?? 0) > 0) {
            $issues[] = 'Mercado Libre respondió 429 durante el canario V3.';
        }
        if ((int) ($metrics['lease_lost'] ?? 0) > 0) {
            $issues[] = 'El canario V3 perdió leases; revise concurrencia antes de ampliar.';
        }
        if ((int) ($metrics['duplicates'] ?? 0) > 0) {
            $issues[] = 'El canario V3 detectó trabajos duplicados.';
        }
        return [
            'state' => $issues === [] ? 'healthy' : 'attention',
            'issues' => $issues,
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
        return [];
    }

    /** @param list<string> $blocking @return list<array<string,mixed>> */
    private function steps(array $blocking, bool $config, bool $activeEnabled, bool $localEnabled, bool $remoteEnabled, array $shadow, bool $shadowApproved = false, bool $canaryActive = false): array
    {
        return [
            ['key' => 'shadow', 'label' => $shadowApproved ? 'Shadow aprobado o cerrado por evidencia' : 'Shadow pendiente', 'done' => $shadowApproved],
            ['key' => 'real_off_or_controlled', 'label' => ($activeEnabled || $canaryActive) ? 'V3 real encendido de forma controlada' : 'V3 real apagado', 'done' => true],
            ['key' => 'prepare', 'label' => 'Canario V3 preparado', 'done' => $config || $canaryActive],
            ['key' => 'local', 'label' => 'Canario local: financial_recalc', 'done' => $localEnabled],
            ['key' => 'remote', 'label' => 'Canario remoto: pack y shipment exactos', 'done' => $remoteEnabled],
            ['key' => 'guardrails', 'label' => $blocking === [] ? 'Sin bloqueos críticos' : implode(' · ', $blocking), 'done' => $blocking === []],
        ];
    }

    /** @param list<string> $blocking */
    private function state(array $blocking, bool $config, bool $activeEnabled, bool $localEnabled, bool $remoteEnabled, bool $canaryActive = false): string
    {
        if ($blocking !== []) {
            return 'blocked';
        }
        if ($canaryActive && $localEnabled && $remoteEnabled) {
            return 'remote_canary_running';
        }
        if (!$config || !$activeEnabled) {
            return 'ready_for_prepare';
        }
        if (!$localEnabled) {
            return 'ready_for_local';
        }
        if (!$remoteEnabled) {
            return 'ready_for_remote';
        }
        return 'remote_canary_running';
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
