<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use PDO;
use Throwable;

final class MigrationDiagnosticService
{
    private const REQUIRED_ENGINE_TABLES = [
        'system_update_releases',
        'system_update_paths',
        'system_update_runs',
        'system_update_run_steps',
        'system_update_migrations',
        'system_update_schema_fingerprints',
        'system_update_schema_differences',
        'system_update_backups',
        'system_update_locks',
        'system_update_events',
        'system_update_trusted_keys',
        'system_update_migration_events',
    ];

    private const REQUIRED_METADATA_COLUMNS = [
        'migration_key',
        'checksum_sha256',
        'release_version',
        'state',
        'attempts',
        'started_at',
        'finished_at',
        'safe_error_message',
    ];

    private const REQUIRED_INDEXES = [
        'system_update_migrations' => [
            'uq_system_update_migration_key',
            'idx_system_update_migrations_state',
        ],
        'system_update_locks' => [
            'PRIMARY',
            'idx_system_update_locks_expiry',
        ],
        'system_update_migration_events' => [
            'idx_migration_events_diagnostic',
            'idx_migration_events_migration',
            'idx_migration_events_stage',
        ],
    ];

    public function summary(): array
    {
        $pdo = Database::connectionFresh();
        $schema = new InformationSchemaGateway($pdo);
        $migrationPath = dirname(__DIR__, 2) . '/database/migrations';
        $files = glob($migrationPath . '/*.sql') ?: [];
        sort($files);
        $knownTables = array_values(array_unique(array_merge(
            ['schema_migrations', 'app_settings'],
            self::REQUIRED_ENGINE_TABLES,
            array_keys(self::REQUIRED_INDEXES)
        )));
        $tableMap = $schema->tablesExist($knownTables);
        $applied = $this->appliedVersions($pdo, $tableMap);
        $pending = [];
        foreach ($files as $file) {
            $key = basename($file);
            if (!isset($applied[$key])) {
                $pending[] = [
                    'migration_key' => $key,
                    'checksum_sha256' => hash_file('sha256', $file) ?: null,
                ];
            }
        }

        $tableStatus = $this->tableStatus($tableMap);
        $columnMap = $schema->columnNamesForTables(['system_update_migrations']);
        $metadataColumns = array_keys($columnMap['system_update_migrations'] ?? []);
        $missingMetadataColumns = array_values(array_diff(self::REQUIRED_METADATA_COLUMNS, $metadataColumns));
        $indexStatus = $this->indexStatus($schema, $tableMap);
        $metadata = $this->migrationMetadata($pdo, $pending[0]['migration_key'] ?? null, $tableMap);
        $events = $this->events(50, 0, $pdo, $schema);
        $latestFailure = $this->latestFailure($events);
        $lock = $this->lock($pdo, $tableMap);
        $recovery = $this->recoveryDecision($pdo, $pending[0] ?? null, $metadata);
        $safeToRetry = $this->safeToRetry($latestFailure, $recovery);

        return [
            'first_pending' => $pending[0] ?? null,
            'pending_count' => count($pending),
            'applied_count' => count($applied),
            'file_count' => count($files),
            'engine_tables' => $tableStatus,
            'missing_engine_tables' => array_keys(array_filter($tableStatus, static fn(bool $exists): bool => !$exists)),
            'metadata_columns' => $metadataColumns,
            'missing_metadata_columns' => $missingMetadataColumns,
            'required_indexes' => $indexStatus,
            'missing_indexes' => array_keys(array_filter($indexStatus, static fn(bool $exists): bool => !$exists)),
            'first_pending_metadata' => $metadata,
            'collations' => $schema->tableCollations($knownTables),
            'lock' => $lock,
            'latest_event' => $events[0] ?? null,
            'latest_failure' => $latestFailure,
            'events' => $events,
            'recovery' => $recovery,
            'safe_to_retry' => $safeToRetry,
            'recommendation' => $this->recommendation($latestFailure, $safeToRetry, $pending, $recovery),
            'pdo' => [
                'driver' => (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
                'server_version' => (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
                'emulated_prepares' => (bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
            ],
            'generated_at_utc' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function events(
        int $limit = 50,
        int $offset = 0,
        ?PDO $pdo = null,
        ?InformationSchemaGateway $schema = null
    ): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        try {
            $pdo ??= Database::connectionFresh();
            $schema ??= new InformationSchemaGateway($pdo);
            if (!$schema->hasTable('system_update_migration_events')) {
                return $this->fallbackEvents($limit);
            }
            $stmt = $pdo->prepare(
                'SELECT id,diagnostic_id,migration_key,stage,status,duration_ms,checksum_sha256,
                        file_version,installed_version,php_version,php_sapi,pdo_driver,sql_state,
                        driver_code,exception_class,safe_message,context_json,created_at
                 FROM system_update_migration_events
                 ORDER BY id DESC LIMIT ? OFFSET ?'
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array_map([$this, 'sanitizeEvent'], $rows);
        } catch (Throwable) {
            return $this->fallbackEvents($limit);
        }
    }

    public function export(int $limit = 200): array
    {
        $summary = $this->summary();
        unset($summary['events']);
        return [
            'type' => 'erp_meli_migration_diagnostic',
            'schema_version' => 1,
            'generated_at_utc' => gmdate('Y-m-d H:i:s'),
            'file_version' => AppVersionService::fileVersion(),
            'summary' => $summary,
            'events' => $this->events(min(200, max(1, $limit))),
            'security' => [
                'sql_included' => false,
                'parameters_included' => false,
                'credentials_included' => false,
                'tokens_included' => false,
            ],
        ];
    }

    /** @param array<string,bool> $tableMap */
    private function appliedVersions(PDO $pdo, array $tableMap): array
    {
        try {
            if (empty($tableMap['schema_migrations'])) {
                return [];
            }
            $versions = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
            return array_fill_keys(array_map('strval', $versions), true);
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,bool> $tableMap */
    private function tableStatus(array $tableMap): array
    {
        $status = [];
        foreach (self::REQUIRED_ENGINE_TABLES as $table) {
            $status[$table] = !empty($tableMap[$table]);
        }
        return $status;
    }

    /** @param array<string,bool> $tableMap */
    private function indexStatus(InformationSchemaGateway $schema, array $tableMap): array
    {
        $status = [];
        $existingByTable = $schema->indexesForTables(array_keys(self::REQUIRED_INDEXES));
        foreach (self::REQUIRED_INDEXES as $table => $requiredIndexes) {
            $existing = !empty($tableMap[$table]) ? ($existingByTable[$table] ?? []) : [];
            foreach ($requiredIndexes as $index) {
                $status[$table . '.' . $index] = in_array($index, $existing, true);
            }
        }
        return $status;
    }

    /** @param array<string,bool> $tableMap */
    private function migrationMetadata(PDO $pdo, ?string $migrationKey, array $tableMap): ?array
    {
        if ($migrationKey === null || empty($tableMap['system_update_migrations'])) {
            return null;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT migration_key,checksum_sha256,release_version,state,attempts,started_at,
                        finished_at,safe_error_message,updated_at
                 FROM system_update_migrations WHERE migration_key=? LIMIT 1'
            );
            $stmt->execute([$migrationKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $this->sanitizeEvent($row) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,bool> $tableMap */
    private function lock(PDO $pdo, array $tableMap): ?array
    {
        if (empty($tableMap['system_update_locks'])) {
            return null;
        }
        try {
            $row = $pdo->query(
                "SELECT lock_name,run_id,heartbeat_at,expires_at,
                        CASE WHEN expires_at<UTC_TIMESTAMP() THEN 'expired' ELSE 'active' END lock_status
                 FROM system_update_locks WHERE lock_name='schema_migrations' LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $this->sanitizeEvent($row) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function latestFailure(array $events): ?array
    {
        foreach ($events as $event) {
            if (($event['status'] ?? '') === 'failed' || ($event['sqlstate'] ?? null) !== null) {
                return $event;
            }
        }
        return null;
    }

    /** @param array<string,mixed>|null $recovery */
    private function safeToRetry(?array $failure, ?array $recovery): bool
    {
        if (($recovery['authorized'] ?? false) === true) {
            return true;
        }
        if ($recovery !== null && ($recovery['required'] ?? false) === true) {
            return false;
        }
        if ($failure === null) {
            return true;
        }
        $stage = (string) ($failure['stage'] ?? '');
        return !in_array($stage, [
            'sql_execution',
            'sql_execution_started',
            'sql_execution_completed',
            'schema_migrations_register',
            'state_applied',
        ], true);
    }

    /** @param array<string,mixed>|null $recovery */
    private function recommendation(?array $failure, bool $safeToRetry, array $pending, ?array $recovery): string
    {
        if (($recovery['authorized'] ?? false) === true) {
            return 'Fallo conocido de MariaDB confirmado. La recuperación de 087 está autorizada y no requiere SQL manual.';
        }
        if ($recovery !== null && ($recovery['required'] ?? false) === true) {
            return 'Recuperación bloqueada (' . (string) ($recovery['reason'] ?? 'condición no autorizada')
                . '). No se modificó la metadata ni se ejecutó SQL.';
        }
        if ($failure === null) {
            return $pending === []
                ? 'No hay migraciones pendientes.'
                : 'Puede aplicar la siguiente migración pendiente.';
        }
        $diagnosticId = (string) ($failure['diagnostic_id'] ?? 'sin identificador');
        if ($safeToRetry) {
            return "El SQL no alcanzó una etapa crítica. Instale el correctivo y reintente. Diagnóstico: {$diagnosticId}.";
        }
        return "El SQL pudo comenzar. Revise y exporte el diagnóstico {$diagnosticId} antes de reintentar.";
    }

    /**
     * Evalúa la recuperación conocida sin escribir autorizaciones ni alterar metadata.
     *
     * @param array<string,mixed>|null $pending
     * @param array<string,mixed>|null $metadata
     * @return array<string,mixed>|null
     */
    private function recoveryDecision(PDO $pdo, ?array $pending, ?array $metadata): ?array
    {
        if (!is_array($pending) || !is_array($metadata)) {
            return null;
        }
        $migrationKey = (string) ($pending['migration_key'] ?? '');
        $registeredChecksum = (string) ($metadata['checksum_sha256'] ?? '');
        $observedChecksum = (string) ($pending['checksum_sha256'] ?? '');
        if ($migrationKey !== '087_module_runtime_jobs_hardening_2_17_0.sql'
            || $registeredChecksum === ''
            || $observedChecksum === '') {
            return null;
        }

        try {
            $decision = (new MigrationReplacementPolicy(
                $pdo,
                dirname(__DIR__, 2) . '/resources/migration-replacements.json'
            ))->evaluate(
                $migrationKey,
                $registeredChecksum,
                $observedChecksum,
                $metadata,
                'diagnostic-preview',
                false
            );
            $decision['required'] = true;
            return $decision;
        } catch (Throwable $error) {
            return [
                'required' => true,
                'authorized' => false,
                'reason' => 'diagnostic_unavailable',
                'safe_message' => MigrationTraceService::safeMessage($error->getMessage()),
            ];
        }
    }

    private function sanitizeEvent(array $row): array
    {
        if (array_key_exists('sql_state', $row)) {
            $row['sqlstate'] = $row['sql_state'];
            unset($row['sql_state']);
        }
        foreach (['safe_message', 'safe_error_message', 'exception_class'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = MigrationTraceService::safeMessage($row[$key] === null ? null : (string) $row[$key]);
            }
        }
        if (isset($row['context_json']) && is_string($row['context_json'])) {
            $decoded = json_decode($row['context_json'], true);
            $row['context'] = is_array($decoded) ? MigrationTraceService::sanitizeContext($decoded) : [];
            unset($row['context_json']);
        }
        if (isset($row['checksum_sha256']) && is_string($row['checksum_sha256'])) {
            $row['checksum_sha256'] = preg_match('/^[a-f0-9]{64}$/', $row['checksum_sha256']) === 1
                ? $row['checksum_sha256']
                : null;
        }
        return $row;
    }

    private function fallbackEvents(int $limit): array
    {
        $directory = AppPaths::storage('logs/migrations');
        if (!is_dir($directory)) {
            return [];
        }
        $files = glob($directory . '/migration-*.jsonl') ?: [];
        rsort($files);
        $events = [];
        foreach (array_slice($files, 0, 5) as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }
            foreach (array_reverse($lines) as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $events[] = $this->sanitizeEvent($decoded);
                }
                if (count($events) >= $limit) {
                    return $events;
                }
            }
        }
        return $events;
    }
}
