<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class Migrator
{
    private ?MigrationTraceService $trace = null;
    private ?string $currentMigration = null;
    private string $currentStage = 'bootstrap';
    private bool $currentSqlStarted = false;
    private bool $retryBlocked = false;
    private ?string $replacementRejectionReason = null;
    private ?bool $currentServerIsMariaDb = null;

    public function __construct(private readonly PDO $pdo, private readonly string $migrationPath)
    {
    }

    /** @return array<int, array{status:string,version:string}> */
    public function run(?int $maxNewMigrations = null): array
    {
        $this->trace = new MigrationTraceService($this->pdo);
        $this->trace->event('run_started', 'running', null, null, null, [
            'migration_limit' => $maxNewMigrations,
            'emulated_prepares' => $this->emulatedPrepares(),
        ]);
        $owner = bin2hex(random_bytes(20));
        $mysqlLockAcquired = false;
        $persistentLockAcquired = false;

        try {
            $this->currentStage = 'metadata_bootstrap';
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $this->ensureMetadata();
            $this->trace->event('metadata_ready', 'complete');

            $this->currentStage = 'lock_request';
            $this->trace->event('lock_requested', 'running');
            if ((int) $this->pdo->query("SELECT GET_LOCK('erp_meli_schema_migrations',5)")->fetchColumn() !== 1) {
                throw new RuntimeException('Otro proceso está ejecutando migraciones. Intente nuevamente en unos minutos.');
            }
            $mysqlLockAcquired = true;
            $lock = $this->pdo->prepare(
                "INSERT INTO system_update_locks (lock_name,owner_token,heartbeat_at,expires_at)
                 VALUES ('schema_migrations',?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))
                 ON DUPLICATE KEY UPDATE
                   owner_token=IF(expires_at<UTC_TIMESTAMP(),VALUES(owner_token),owner_token),
                   heartbeat_at=IF(expires_at<UTC_TIMESTAMP(),VALUES(heartbeat_at),heartbeat_at),
                   expires_at=IF(expires_at<UTC_TIMESTAMP(),VALUES(expires_at),expires_at)"
            );
            $lock->execute([$owner]);
            $check = $this->pdo->query("SELECT owner_token FROM system_update_locks WHERE lock_name='schema_migrations'");
            if (!hash_equals($owner, (string) $check->fetchColumn())) {
                throw new RuntimeException('Existe un lock persistente de migración todavía vigente.');
            }
            $persistentLockAcquired = true;
            $this->trace->event('lock_acquired', 'complete');

            $results = $this->runLocked($owner, $maxNewMigrations);
            $this->currentStage = 'run_completed';
            $this->trace->event('run_completed', 'complete', null, null, null, [
                'result_count' => count($results),
            ]);
            return $results;
        } catch (Throwable $e) {
            $safeToRetry = !$this->currentSqlStarted && !$this->retryBlocked;
            $this->trace->event(
                'run_failed',
                'failed',
                $this->currentMigration,
                null,
                $e,
                ['failed_stage' => $this->currentStage, 'safe_to_retry' => $safeToRetry]
            );
            if ($e instanceof MigrationExecutionException) {
                throw $e;
            }
            $message = $this->failureMessage($safeToRetry);
            throw new MigrationExecutionException(
                $message,
                $this->trace->diagnosticId(),
                $this->currentMigration,
                $this->currentStage,
                $safeToRetry,
                $e
            );
        } finally {
            if ($persistentLockAcquired) {
                try {
                    $delete = $this->pdo->prepare("DELETE FROM system_update_locks WHERE lock_name='schema_migrations' AND owner_token=?");
                    $delete->execute([$owner]);
                    $this->trace->event('persistent_lock_released', 'complete');
                } catch (Throwable $cleanupError) {
                    $this->trace->event('persistent_lock_release_failed', 'warning', $this->currentMigration, null, $cleanupError);
                }
            }
            if ($mysqlLockAcquired) {
                try {
                    $this->pdo->query("SELECT RELEASE_LOCK('erp_meli_schema_migrations')");
                    $this->trace->event('mysql_lock_released', 'complete');
                } catch (Throwable $cleanupError) {
                    $this->trace->event('mysql_lock_release_failed', 'warning', $this->currentMigration, null, $cleanupError);
                }
            }
        }
    }

    private function executeMigrationSql(string $sql): void
    {
        foreach ($this->splitSqlStatements($sql) as $originalSql) {
            // La adaptación MySQL se calcula justo antes de cada sentencia.
            // Así InformationSchema ve las tablas/columnas creadas por la
            // sentencia anterior de la misma migración y no genera duplicados.
            $compatibleSql = $this->sqlForCurrentServer($originalSql . ';');
            foreach ($this->splitSqlStatements($compatibleSql) as $statementSql) {
                $statement = $this->pdo->query($statementSql);
                if ($statement === false) {
                    $this->pdo->exec($statementSql);
                    continue;
                }

                try {
                    do {
                        while ($statement->fetch(PDO::FETCH_NUM) !== false) {
                            // Drenar filas devueltas por SELECT/SHOW/SET o por
                            // procedimientos dentro de migraciones. En MariaDB con
                            // cursores no bufferizados, dejar filas sin consumir
                            // bloquea el siguiente INSERT en schema_migrations.
                        }
                    } while ($statement->nextRowset());
                } finally {
                    $statement->closeCursor();
                }
            }
        }
    }

    /** @return list<string> */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);

        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($lineComment) {
                $buffer .= $char;
                if ($char === "\n") {
                    $lineComment = false;
                }
                continue;
            }

            if ($blockComment) {
                $buffer .= $char;
                if ($char === '*' && $next === '/') {
                    $buffer .= $next;
                    $index++;
                    $blockComment = false;
                }
                continue;
            }

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote && !$this->isEscaped($sql, $index)) {
                    $quote = null;
                }
                continue;
            }

            if (($char === '-' && $next === '-') || $char === '#') {
                $lineComment = true;
                $buffer .= $char;
                if ($char === '-') {
                    $buffer .= $next;
                    $index++;
                }
                continue;
            }

            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $buffer .= $char . $next;
                $index++;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '' && !preg_match('/^(?:--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$)|\/\*.*?\*\/\s*)*$/s', $statement)) {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $statement = trim($buffer);
        if ($statement !== '' && !preg_match('/^(?:--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$)|\/\*.*?\*\/\s*)*$/s', $statement)) {
            $statements[] = $statement;
        }
        return $statements;
    }

    private function isEscaped(string $sql, int $index): bool
    {
        $slashes = 0;
        for ($cursor = $index - 1; $cursor >= 0 && $sql[$cursor] === '\\'; $cursor--) {
            $slashes++;
        }
        return $slashes % 2 === 1;
    }

    /** @return array<int,array{status:string,version:string}> */
    private function runLocked(string $owner, ?int $maxNewMigrations): array
    {
        $applied = array_flip($this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
        $metadata = [];
        foreach ($this->pdo->query('SELECT * FROM system_update_migrations')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) ($row['migration_key'] ?? '');
            if ($key !== '') {
                $metadata[$key] = $row;
            }
        }
        $files = glob(rtrim($this->migrationPath, '/\\') . '/*.sql') ?: [];
        sort($files);
        $results = [];
        $newMigrations = 0;
        $historicalData = new HistoricalMigrationDataPreserver($this->pdo);

        foreach ($files as $file) {
            $version = basename($file);
            $this->currentMigration = $version;
            $this->currentSqlStarted = false;
            $this->retryBlocked = false;
            $this->replacementRejectionReason = null;
            $this->currentStage = 'migration_selected';
            $checksum = hash_file('sha256', $file);
            if ($checksum === false) {
                throw new RuntimeException("No se pudo calcular el checksum de {$version}.");
            }
            $migrationStartedAt = microtime(true);
            $meta = isset($metadata[$version]) && is_array($metadata[$version])
                ? $metadata[$version]
                : null;
            if (isset($applied[$version])) {
                $metadataState = $meta !== null ? (string) ($meta['state'] ?? '') : '';
                $registeredChecksum = $meta !== null ? (string) ($meta['checksum_sha256'] ?? '') : '';
                if ($meta !== null && !hash_equals($registeredChecksum, $checksum)) {
                    if ($this->isPortableLineEndingChecksum($file, $registeredChecksum)) {
                        $this->currentStage = 'state_adopted';
                        $this->markMigration(
                            $version,
                            $checksum,
                            'adopted',
                            'Checksum equivalente por finales de línea. No se ejecutó SQL.'
                        );
                        $this->trace?->event('checksum_line_ending_equivalent', 'adopted', $version, $checksum, null, [
                            'registered_checksum' => $registeredChecksum,
                            'observed_checksum' => $checksum,
                            'sql_executed' => false,
                        ]);
                        $results[] = ['status' => 'adopted', 'version' => $version];
                        $historicalData->after($version);
                        continue;
                    }
                    if ($this->canReconcileAppliedMigration015Drift($version, $file, $checksum)) {
                        $this->currentStage = 'state_adopted';
                        $this->markMigration(
                            $version,
                            $checksum,
                            'adopted',
                            'Migración 015 ya aplicada; drift histórico reconciliado por contrato de esquema. No se ejecutó SQL.'
                        );
                        $this->trace?->event('applied_015_schema_contract_reconciled', 'adopted', $version, $checksum, null, [
                            'registered_checksum' => $registeredChecksum,
                            'observed_checksum' => $checksum,
                            'sql_executed' => false,
                            'contract' => 'schema_migrations+manifest+schema',
                        ]);
                        $results[] = ['status' => 'adopted', 'version' => $version];
                        $historicalData->after($version);
                        continue;
                    }
                    if ($this->canReconcileAppliedMigration244Drift($version, $file, $checksum)) {
                        $this->currentStage = 'state_adopted';
                        $this->markMigration(
                            $version,
                            $checksum,
                            'adopted',
                            'Migración 244 ya aplicada; drift histórico reconciliado por contrato de ritmo V3 portable. No se ejecutó SQL.'
                        );
                        $this->trace?->event('applied_244_rate_contract_reconciled', 'adopted', $version, $checksum, null, [
                            'registered_checksum' => $registeredChecksum,
                            'observed_checksum' => $checksum,
                            'sql_executed' => false,
                            'contract' => 'schema_migrations+manifest+cron_v3_rate_buckets',
                        ]);
                        $results[] = ['status' => 'adopted', 'version' => $version];
                        $historicalData->after($version);
                        continue;
                    }
                    if ($version === '015_sync_products_claims_2_1.sql') {
                        $this->currentStage = 'state_drifted';
                        $this->retryBlocked = true;
                        $this->replacementRejectionReason = 'applied_015_schema_contract_missing';
                        $this->markDriftPreservingChecksum(
                            $version,
                            'No se puede certificar la 015; se bloquea por seguridad.'
                        );
                        $this->trace?->event('applied_015_schema_contract_rejected', 'drifted', $version, $checksum, null, [
                            'registered_checksum' => $registeredChecksum,
                            'observed_checksum' => $checksum,
                            'reason' => $this->replacementRejectionReason,
                        ]);
                        throw new RuntimeException(
                            'No se puede certificar la 015; se bloquea por seguridad.'
                        );
                    }
                    $this->currentStage = 'state_drifted';
                    $this->markDriftPreservingChecksum($version, 'El archivo aplicado cambió después de su registro.');
                    $this->trace?->event('checksum_drifted', 'drifted', $version, $checksum);
                    throw new RuntimeException("La migración aplicada {$version} fue modificada. Se bloqueó la actualización por seguridad.");
                }
                if ($meta !== null && $metadataState === 'drifted') {
                    $safeError = (string) ($meta['safe_error_message'] ?? '');
                    if (str_contains($safeError, 'archivo aplicado cambió') || str_contains($safeError, 'finales de línea')) {
                        $this->currentStage = 'state_adopted';
                        $this->markMigration(
                            $version,
                            $checksum,
                            'adopted',
                            'Drift anterior reconciliado: la migración ya estaba aplicada y el archivo activo coincide. No se ejecutó SQL.'
                        );
                        $this->trace?->event('checksum_previous_drift_reconciled', 'adopted', $version, $checksum, null, [
                            'registered_checksum' => $registeredChecksum,
                            'observed_checksum' => $checksum,
                            'sql_executed' => false,
                        ]);
                        $results[] = ['status' => 'adopted', 'version' => $version];
                        $historicalData->after($version);
                        continue;
                    }
                }
                if ($meta === null) {
                    $this->currentStage = 'state_adopted';
                    $this->markMigration($version, $checksum, 'adopted', null);
                    $this->trace?->event('migration_adopted', 'adopted', $version, $checksum);
                    $results[] = ['status' => 'adopted', 'version' => $version];
                } else {
                    $results[] = ['status' => 'skip', 'version' => $version];
                }
                $historicalData->after($version);
                continue;
            }
            $this->trace?->event('migration_selected', 'running', $version, $checksum);
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("No se pudo leer la migración {$version}.");
            }
            $registeredChecksum = $meta !== null ? (string) $meta['checksum_sha256'] : '';
            $metadataState = $meta !== null ? (string) ($meta['state'] ?? '') : '';
            $requiresReplacementDecision = $meta !== null
                && ($metadataState === 'drifted' || !hash_equals($registeredChecksum, $checksum));
            if ($requiresReplacementDecision) {
                $replacement = new MigrationReplacementPolicy(
                    $this->pdo,
                    dirname($this->migrationPath, 2) . '/resources/migration-replacements.json'
                );
                $decision = $replacement->evaluate(
                    $version,
                    $registeredChecksum,
                    $checksum,
                    $meta,
                    $this->trace?->diagnosticId() ?? 'unknown'
                );
                if (!$decision['authorized']) {
                    $this->currentStage = 'state_drifted';
                    $this->retryBlocked = true;
                    $this->replacementRejectionReason = (string) $decision['reason'];
                    $this->markDriftPreservingChecksum(
                        $version,
                        'La recuperación certificada fue bloqueada: ' . $this->replacementRejectionReason . '.'
                    );
                    $this->trace?->event('checksum_replacement_rejected', 'drifted', $version, $checksum, null, [
                        'registered_checksum' => $registeredChecksum,
                        'observed_checksum' => $checksum,
                        'reason' => $this->replacementRejectionReason,
                    ]);
                    throw new RuntimeException(
                        "La recuperación segura de {$version} fue bloqueada ({$this->replacementRejectionReason})."
                    );
                }
                $this->trace?->event('checksum_replacement_authorized', 'complete', $version, $checksum, null, [
                    'old_checksum' => $decision['old_checksum'],
                    'new_checksum' => $decision['new_checksum'],
                    'source_diagnostic_id' => $decision['source_diagnostic_id'],
                    'authorization' => $decision['authorization_id'],
                    'recovered_from_state' => $metadataState,
                ]);
            } elseif ($meta !== null && in_array($metadataState, ['applied', 'adopted'], true)) {
                $this->currentStage = 'state_inconsistent';
                $this->retryBlocked = true;
                $this->replacementRejectionReason = 'metadata_terminal_without_schema_record';
                $this->trace?->event('metadata_state_inconsistent', 'failed', $version, $checksum, null, [
                    'metadata_state' => $metadataState,
                    'reason' => $this->replacementRejectionReason,
                ]);
                throw new RuntimeException(
                    "La metadata de {$version} indica {$metadataState}, pero schema_migrations no la registra."
                );
            }
            $this->currentStage = 'state_running';
            $this->trace?->event('state_write_started', 'running', $version, $checksum, null, ['target_state' => 'running']);
            $this->markMigration($version, $checksum, 'running', null);
            $this->trace?->event('state_write_completed', 'complete', $version, $checksum, null, ['target_state' => 'running']);
            try {
                $this->currentStage = 'sql_execution';
                $this->currentSqlStarted = true;
                $historicalData->before($version);
                $this->trace?->event('sql_execution_started', 'running', $version, $checksum);
                $this->executeMigrationSql($sql);
                $historicalData->after($version);
                $this->trace?->event(
                    'sql_execution_completed',
                    'complete',
                    $version,
                    $checksum,
                    null,
                    [],
                    (int) round((microtime(true) - $migrationStartedAt) * 1000)
                );
                $this->currentStage = 'schema_migrations_register';
                $statement = $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
                $statement->execute([$version]);
                $this->trace?->event('schema_migrations_registered', 'complete', $version, $checksum);
                $this->currentStage = 'state_applied';
                $this->markMigration($version, $checksum, 'applied', null);
                $this->trace?->event('migration_applied', 'applied', $version, $checksum);
                $results[] = ['status' => 'applied', 'version' => $version];
                $newMigrations++;
                $this->currentStage = 'heartbeat';
                $heartbeat = $this->pdo->prepare(
                    "UPDATE system_update_locks SET heartbeat_at=UTC_TIMESTAMP(),
                     expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)
                     WHERE lock_name='schema_migrations' AND owner_token=?"
                );
                $heartbeat->execute([$owner]);
                $this->trace?->event('heartbeat_updated', 'complete', $version, $checksum);
                if ($maxNewMigrations !== null && $newMigrations >= max(1, $maxNewMigrations)) {
                    break;
                }
            } catch (Throwable $e) {
                $failedStage = $this->currentStage;
                $this->trace?->event('migration_failed', 'failed', $version, $checksum, $e, ['failed_stage' => $failedStage]);
                try {
                    $this->currentStage = 'state_failed';
                    $this->markMigration($version, $checksum, 'failed', MigrationTraceService::safeMessage($e));
                } catch (Throwable $stateError) {
                    $this->trace?->event('failed_state_write_failed', 'warning', $version, $checksum, $stateError);
                }
                // La escritura secundaria del estado nunca debe ocultar la etapa
                // donde ocurrió el error original.
                $this->currentStage = $failedStage;
                throw $e;
            }
        }

        return $results;
    }

    private function isPortableLineEndingChecksum(string $file, string $registeredChecksum): bool
    {
        if ($registeredChecksum === '' || !preg_match('/^[a-f0-9]{64}$/i', $registeredChecksum)) {
            return false;
        }
        $sql = file_get_contents($file);
        if ($sql === false) {
            return false;
        }
        $lf = preg_replace("/\r\n?|\n/", "\n", $sql);
        $crlf = preg_replace("/\r\n?|\n/", "\r\n", $sql);
        if (!is_string($lf) || !is_string($crlf)) {
            return false;
        }
        foreach ([$sql, $lf, $crlf] as $variant) {
            if (hash_equals(strtolower($registeredChecksum), hash('sha256', $variant))) {
                return true;
            }
        }
        return false;
    }

    private function canReconcileAppliedMigration015Drift(string $version, string $file, string $checksum): bool
    {
        if ($version !== '015_sync_products_claims_2_1.sql') {
            return false;
        }
        if (!$this->activeMigrationFileSigned($file, $checksum)) {
            return false;
        }
        $schema = new InformationSchemaGateway($this->pdo);
        if (!$schema->hasTable('meli_sync_offsets') || !$schema->hasTable('app_settings')) {
            return false;
        }
        foreach ([
            'id',
            'meli_account_id',
            'sync_type',
            'cursor_value',
            'last_resource_id',
            'last_synced_at',
            'status',
            'error_message',
            'updated_at',
        ] as $column) {
            if (!$schema->hasColumn('meli_sync_offsets', $column)) {
                return false;
            }
        }
        if (!$schema->hasIndex('meli_sync_offsets', 'uq_sync_offset')) {
            return false;
        }
        if (!$this->hasForeignKey('meli_sync_offsets', 'meli_account_id', 'meli_accounts', 'id')) {
            return false;
        }
        $settings = [
            'sync.products.page_limit',
            'sync.products.max_items_per_run',
            'sync.claims.page_limit',
            'sync.claims.max_claims_per_run',
            'reports.default_include_returns',
        ];
        $placeholders = implode(',', array_fill(0, count($settings), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT setting_key FROM app_settings WHERE setting_key IN ({$placeholders})"
        );
        $stmt->execute($settings);
        $found = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        foreach ($settings as $setting) {
            if (!isset($found[$setting])) {
                return false;
            }
        }
        return true;
    }

    private function canReconcileAppliedMigration244Drift(string $version, string $file, string $checksum): bool
    {
        if ($version !== '244_cron_v3_rate_scope_authority_2_29_1.sql') {
            return false;
        }
        if (!$this->activeMigrationFileSigned($file, $checksum)) {
            return false;
        }
        $schema = new InformationSchemaGateway($this->pdo);
        if (!$schema->hasTable('cron_v3_rate_buckets')) {
            return false;
        }
        foreach ([
            'scope_key',
            'scope_level',
            'application_id',
            'company_id',
            'meli_account_id',
            'endpoint_key',
            'operation_key',
            'blocked_until',
        ] as $column) {
            if (!$schema->hasColumn('cron_v3_rate_buckets', $column)) {
                return false;
            }
        }
        foreach ([
            'idx_cron_v3_rate_dimensions',
            'idx_cron_v3_rate_operation',
        ] as $index) {
            if (!$schema->hasIndex('cron_v3_rate_buckets', $index)) {
                return false;
            }
        }
        return true;
    }

    private function activeMigrationFileSigned(string $file, string $checksum): bool
    {
        $root = dirname($this->migrationPath, 2);
        $manifestPath = $root . '/resources/runtime-manifest.json';
        $manifest = json_decode((string) @file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            return false;
        }
        $rootPath = realpath($root);
        $filePath = realpath($file);
        if ($rootPath === false || $filePath === false) {
            return false;
        }
        $relative = str_replace('\\', '/', ltrim(substr($filePath, strlen($rootPath)), '\\/'));
        foreach ((array) ($manifest['components'] ?? []) as $component) {
            if (!is_array($component)) {
                continue;
            }
            if ((string) ($component['path'] ?? '') !== $relative) {
                continue;
            }
            return hash_equals((string) ($component['sha256'] ?? ''), $checksum);
        }
        return false;
    }

    private function hasForeignKey(string $table, string $column, string $referencedTable, string $referencedColumn): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
             WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
               AND BINARY TABLE_NAME=BINARY :table_name
               AND BINARY COLUMN_NAME=BINARY :column_name
               AND BINARY REFERENCED_TABLE_NAME=BINARY :referenced_table
               AND BINARY REFERENCED_COLUMN_NAME=BINARY :referenced_column
             LIMIT 1'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
            'referenced_table' => $referencedTable,
            'referenced_column' => $referencedColumn,
        ]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * MariaDB admite ADD COLUMN IF NOT EXISTS dentro de ALTER TABLE; MySQL 8
     * no. Las migraciones ya aplicadas no vuelven a ejecutarse, por lo que en
     * MySQL una instalación o una actualización pendiente puede usar la forma
     * estándar sin alterar el checksum del archivo.
     */
    private function sqlForCurrentServer(string $sql): string
    {
        $sql = $this->sqlForHistoricalMigration122Compatibility($sql);

        if ($this->currentServerIsMariaDb === null) {
            $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
            $this->currentServerIsMariaDb = stripos($version, 'mariadb') !== false;
        }
        if ($this->currentServerIsMariaDb) {
            return $sql;
        }
        // MySQL 8 exige paréntesis para expresiones UTC_TIMESTAMP con
        // precisión usadas como DEFAULT; MariaDB acepta la forma histórica.
        $sql = preg_replace(
            '/\bDEFAULT\s+UTC_TIMESTAMP\s*\(\s*([0-6])\s*\)/i',
            'DEFAULT (UTC_TIMESTAMP($1))',
            $sql
        ) ?? $sql;
        $sql = preg_replace_callback(
            '/\bCREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+(`?[a-zA-Z0-9_]+`?)\s+ON\s+(`?[a-zA-Z0-9_]+`?)(.*?);/is',
            function (array $match): string {
                $index = trim((string) $match[2], '`');
                $table = trim((string) $match[3], '`');
                if ((new InformationSchemaGateway($this->pdo))->hasIndex($table, $index)) {
                    return 'DO 0;';
                }
                return 'CREATE ' . (string) $match[1] . 'INDEX ' . $match[2]
                    . ' ON ' . $match[3] . $match[4] . ';';
            },
            $sql
        ) ?? $sql;
        return preg_replace_callback(
            '/\bALTER\s+TABLE\s+(`?[a-zA-Z0-9_]+`?)\s+(.*?);/is',
            function (array $match): string {
                $table = trim((string) $match[1], '`');
                $clauses = $this->splitAlterClauses((string) $match[2]);
                $kept = [];
                foreach ($clauses as $clause) {
                    if (preg_match(
                        '/^\s*ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+(`?[a-zA-Z0-9_]+`?)/i',
                        $clause,
                        $columnMatch
                    ) === 1) {
                        $column = trim((string) $columnMatch[1], '`');
                        if ((new InformationSchemaGateway($this->pdo))->hasColumn($table, $column)) {
                            continue;
                        }
                        $clause = preg_replace(
                            '/^\s*ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\b/i',
                            'ADD COLUMN',
                            $clause
                        ) ?? $clause;
                    }
                    $kept[] = trim($clause);
                }
                return $kept === []
                    ? 'DO 0;'
                    : 'ALTER TABLE ' . $match[1] . "\n    " . implode(",\n    ", $kept) . ';';
            },
            $sql
        ) ?? $sql;
    }

    private function sqlForHistoricalMigration122Compatibility(string $sql): string
    {
        if ($this->currentMigration !== '122_sale_financial_reconciliation_2_24_0.sql') {
            return $sql;
        }

        return str_replace(
            'AND CAST(o.external_order_id AS CHAR)=expected.external_order_id',
            "AND CONVERT(o.external_order_id USING utf8mb4) COLLATE utf8mb4_unicode_ci\n"
                . "    = CONVERT(expected.external_order_id USING utf8mb4) COLLATE utf8mb4_unicode_ci",
            $sql
        );
    }

    /** @return list<string> */
    private function splitAlterClauses(string $body): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($body);
        for ($index = 0; $index < $length; $index++) {
            $character = $body[$index];
            if ($quote !== null) {
                $buffer .= $character;
                if ($character === $quote && ($index === 0 || $body[$index - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                $buffer .= $character;
                continue;
            }
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth = max(0, $depth - 1);
            }
            if ($character === ',' && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $character;
        }
        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }
        return $parts;
    }

    public function pendingCount(): int
    {
        $applied = array_fill_keys(
            array_map('strval', $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)),
            true
        );
        $pending = 0;
        foreach (glob(rtrim($this->migrationPath, '/\\') . '/*.sql') ?: [] as $file) {
            if (!isset($applied[basename($file)])) {
                $pending++;
            }
        }
        return $pending;
    }

    private function ensureMetadata(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS system_update_migrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration_key VARCHAR(180) NOT NULL,
                checksum_sha256 CHAR(64) NOT NULL,
                release_version VARCHAR(40) NULL,
                state VARCHAR(40) NOT NULL DEFAULT 'pending',
                checkpoint_json LONGTEXT NULL,
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                started_at DATETIME NULL,
                finished_at DATETIME NULL,
                safe_error_message VARCHAR(700) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_system_update_migration_key (migration_key),
                KEY idx_system_update_migrations_state (state,updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS system_update_locks (
                lock_name VARCHAR(120) NOT NULL,
                owner_token VARCHAR(120) NOT NULL,
                run_id BIGINT UNSIGNED NULL,
                heartbeat_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (lock_name),
                KEY idx_system_update_locks_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS system_update_migration_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                diagnostic_id VARCHAR(64) NOT NULL,
                migration_key VARCHAR(180) NULL,
                stage VARCHAR(80) NOT NULL,
                status VARCHAR(30) NOT NULL,
                duration_ms INT UNSIGNED NULL,
                checksum_sha256 CHAR(64) NULL,
                file_version VARCHAR(40) NULL,
                installed_version VARCHAR(40) NULL,
                php_version VARCHAR(40) NULL,
                php_sapi VARCHAR(40) NULL,
                pdo_driver VARCHAR(40) NULL,
                sql_state VARCHAR(20) NULL,
                driver_code VARCHAR(30) NULL,
                exception_class VARCHAR(190) NULL,
                safe_message VARCHAR(700) NULL,
                context_json MEDIUMTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_migration_events_diagnostic (diagnostic_id,created_at),
                KEY idx_migration_events_migration (migration_key,created_at),
                KEY idx_migration_events_stage (stage,status,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS system_update_migration_replacements (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration_key VARCHAR(180) NOT NULL,
                old_checksum_sha256 CHAR(64) NOT NULL,
                new_checksum_sha256 CHAR(64) NOT NULL,
                authorization_id VARCHAR(120) NOT NULL,
                source_diagnostic_id VARCHAR(64) NULL,
                authorized_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_migration_replacement (migration_key,old_checksum_sha256,new_checksum_sha256),
                KEY idx_migration_replacement_authorized (authorized_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function markMigration(string $version, string $checksum, string $state, ?string $error): void
    {
        $started = $state === 'running' ? 1 : 0;
        $finished = in_array($state, ['applied', 'adopted', 'failed', 'drifted'], true) ? 1 : 0;
        $safeError = $error === null ? null : mb_substr($error, 0, 700);
        $lookup = $this->pdo->prepare('SELECT id FROM system_update_migrations WHERE migration_key=? LIMIT 1');
        $lookup->execute([$version]);
        $existingId = $lookup->fetchColumn();

        if ($existingId === false) {
            $startedExpression = $started === 1 ? 'UTC_TIMESTAMP()' : 'NULL';
            $finishedExpression = $finished === 1 ? 'UTC_TIMESTAMP()' : 'NULL';
            $insert = $this->pdo->prepare(
                "INSERT INTO system_update_migrations
                 (migration_key,checksum_sha256,release_version,state,attempts,started_at,finished_at,safe_error_message)
                 VALUES (?,?,?,?,?,{$startedExpression},{$finishedExpression},?)"
            );
            $insert->execute([
                $version,
                $checksum,
                AppVersionService::fileVersion(),
                $state,
                $started,
                $safeError,
            ]);
            return;
        }

        $assignments = [
            'checksum_sha256=?',
            'release_version=?',
            'state=?',
            'safe_error_message=?',
        ];
        if ($started === 1) {
            $assignments[] = 'attempts=attempts+1';
            $assignments[] = 'started_at=UTC_TIMESTAMP()';
            $assignments[] = 'finished_at=NULL';
        }
        if ($finished === 1) {
            $assignments[] = 'finished_at=UTC_TIMESTAMP()';
        }
        $update = $this->pdo->prepare(
            'UPDATE system_update_migrations SET ' . implode(',', $assignments) . ' WHERE id=?'
        );
        $update->execute([
            $checksum,
            AppVersionService::fileVersion(),
            $state,
            $safeError,
            (int) $existingId,
        ]);
    }

    private function markDriftPreservingChecksum(string $version, string $error): void
    {
        $update = $this->pdo->prepare(
            "UPDATE system_update_migrations
             SET release_version=?,state='drifted',safe_error_message=?,finished_at=UTC_TIMESTAMP()
             WHERE migration_key=?"
        );
        $update->execute([
            AppVersionService::fileVersion(),
            mb_substr($error, 0, 700),
            $version,
        ]);
    }

    private function emulatedPrepares(): bool
    {
        try {
            return (bool) $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        } catch (Throwable) {
            return false;
        }
    }

    private function failureMessage(bool $safeToRetry): string
    {
        $migration = $this->currentMigration ?? 'sin seleccionar';
        $diagnosticId = $this->trace?->diagnosticId() ?? 'sin identificador';
        if ($this->currentStage === 'state_running') {
            return sprintf(
                'La migración %s no llegó a ejecutar su SQL. Falló al registrar el estado inicial en PDO/MySQL. Diagnóstico: %s. Es seguro instalar el correctivo y reintentar.',
                $migration,
                $diagnosticId
            );
        }
        if (!$safeToRetry) {
            if ($this->retryBlocked) {
                return sprintf(
                    'La recuperación certificada de %s fue bloqueada en la etapa %s. Motivo: %s. No se modificó la evidencia registrada ni se ejecutó SQL. Diagnóstico: %s.',
                    $migration,
                    $this->currentStage,
                    $this->replacementRejectionReason ?? 'condición no autorizada',
                    $diagnosticId
                );
            }
            return sprintf(
                'La migración %s se interrumpió durante la etapa %s. Revise el diagnóstico %s antes de reintentar. Las operaciones son idempotentes y no se eliminaron datos.',
                $migration,
                $this->currentStage,
                $diagnosticId
            );
        }
        return sprintf(
            'La migración %s falló antes de ejecutar su SQL, en la etapa %s. Diagnóstico: %s. Es seguro instalar el correctivo y reintentar.',
            $migration,
            $this->currentStage,
            $diagnosticId
        );
    }
}
