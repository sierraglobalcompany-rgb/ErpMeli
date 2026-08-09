<?php

declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Env;
use App\Services\EmergencyControlService;
use App\Services\OAuthService;
use App\Services\QueueOAuthDurableRecoveryStore;
use PDO;
use Throwable;

/** Preflight read-only de datos: no produce, reclama ni despacha trabajo. */
final class QueueCorePreflightService
{
    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'schema_migrations',
        'queue_core_jobs', 'queue_core_attempts', 'queue_core_dispatch_journal',
        'queue_core_producer_checkpoints', 'queue_core_pending_capabilities',
        'queue_core_capability_dependencies', 'queue_core_capability_edges', 'queue_core_webhook_triggers',
        'queue_core_webhook_spool_items',
        'queue_core_execution_leases', 'queue_engine_control',
        'queue_core_runs', 'queue_core_readiness_receipts',
        'queue_core_readiness_captures', 'queue_core_readiness_capture_items',
        'queue_core_health_snapshots', 'queue_core_feature_flags', 'queue_core_release_evidence',
        'queue_core_historical_checkpoints', 'queue_core_historical_receipts',
        'queue_core_historical_reviews',
        'api_rhythm_states', 'api_remote_permits', 'meli_accounts', 'meli_tokens',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /** Autoridad mínima usada por el runtime antes de cualquier productor o claim. */
    public function runtimeSchemaReady(): bool
    {
        foreach (self::REQUIRED_TABLES as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    public function check(bool $probeRecoveryStorage = true): array
    {
        $issues = [];
        $missing = [];
        foreach (self::REQUIRED_TABLES as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }
        if ($missing !== []) {
            $issues[] = 'required_schema_missing';
        }

        $requiredMigrations = [
            '280_queue_core_cron_v4_phase_b1.sql',
            '281_queue_core_reaudit1_fifo_fencing.sql',
            '282_queue_core_architecture_closeout_b1_2.sql',
            '283_queue_engine_control_oauth_supervisor_b1_4.sql',
            '284_queue_core_sales_pipeline_b2.sql',
            '285_queue_core_webhook_ownership_b2.sql',
            '286_queue_core_historical_deploy_b2.sql',
            '287_queue_core_readiness_observability_b2.sql',
            '288_queue_core_readiness_authority_b2_1.sql',
            '289_queue_core_webhook_lifecycle_b2_1.sql',
            '290_queue_core_sales_dependency_graph_b2_1.sql',
            '291_queue_core_release_health_capacity_b2_1.sql',
            '292_queue_core_authoritative_convergence_b2_1.sql',
        ];
        $applied = [];
        if (!in_array('schema_migrations', $missing, true)) {
            try {
                $applied = array_map('strval', $this->pdo->query(
                    'SELECT version FROM schema_migrations'
                )->fetchAll(PDO::FETCH_COLUMN));
                if (array_diff($requiredMigrations, $applied) !== []) {
                    $issues[] = 'required_migrations_missing';
                }
            } catch (Throwable) {
                $issues[] = 'schema_migrations_unavailable';
            }
        }

        $requiredIndexes = [
            'queue_core_jobs' => ['idx_queue_core_fifo_domain', 'idx_queue_core_health_depth'],
            'queue_core_attempts' => ['idx_queue_core_attempt_run'],
            'queue_core_pending_capabilities' => ['idx_queue_core_capability_lifecycle'],
            'queue_core_webhook_triggers' => ['idx_queue_core_webhook_pending'],
            'queue_core_historical_checkpoints' => ['idx_qc_historical_enabled'],
            'queue_core_historical_receipts' => ['idx_qc_historical_receipt_job'],
            'queue_core_health_snapshots' => ['idx_queue_core_health_latest'],
            'queue_core_readiness_captures' => ['idx_queue_core_readiness_capture_window'],
        ];
        $missingIndexes = [];
        foreach ($requiredIndexes as $table => $indexes) {
            if (in_array($table, $missing, true)) {
                continue;
            }
            foreach ($indexes as $index) {
                if (!$this->indexExists($table, $index)) {
                    $missingIndexes[] = $table . '.' . $index;
                }
            }
        }
        if ($missingIndexes !== []) {
            $issues[] = 'required_indexes_missing';
        }

        $engine = null;
        if (!in_array('queue_engine_control', $missing, true)) {
            try {
                $engine = (new QueueEngineControlService($this->pdo))->snapshot();
            } catch (Throwable) {
                $issues[] = 'engine_control_unavailable';
            }
        }

        $safety = (new EmergencyControlService())->status();
        if (($safety['automation'] ?? '') !== 'stopped') {
            $issues[] = 'automation_not_stopped';
        }
        if (Env::bool('ML_WRITE_ENABLED', false)) {
            $issues[] = 'ml_write_enabled';
        }
        if (!OAuthService::isConfigured()) {
            $issues[] = 'oauth_configuration_missing';
        }

        $storage = 'not_checked';
        if ($probeRecoveryStorage) {
            try {
                (new QueueOAuthDurableRecoveryStore())->assertStorageReady();
                $storage = 'ready';
            } catch (Throwable) {
                $storage = 'unavailable';
                $issues[] = 'oauth_recovery_storage_unavailable';
            }
        }

        $accounts = [];
        if (!in_array('meli_accounts', $missing, true) && !in_array('meli_tokens', $missing, true)) {
            $query = $this->pdo->query(
                'SELECT a.company_id,a.id meli_account_id,a.status,
                        (a.meli_user_id IS NOT NULL AND a.meli_user_id<>"") identity_present,
                        (t.meli_account_id IS NOT NULL) token_row_present,
                        (t.access_token_encrypted IS NOT NULL AND t.access_token_encrypted<>"") access_present,
                        (t.refresh_token_encrypted IS NOT NULL AND t.refresh_token_encrypted<>"") refresh_present,
                        t.expires_at,t.refresh_version
                 FROM meli_accounts a
                 LEFT JOIN meli_tokens t ON t.meli_account_id=a.id
                 WHERE a.status IN ("conectado","connected")
                 ORDER BY a.company_id,a.id'
            );
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $valid = (int) $row['identity_present'] === 1
                    && (int) $row['token_row_present'] === 1
                    && (int) $row['access_present'] === 1
                    && (int) $row['refresh_present'] === 1
                    && !empty($row['expires_at']);
                if (!$valid) {
                    $issues[] = 'account_identity_or_token_missing';
                }
                $accounts[] = [
                    'company_id' => (int) $row['company_id'],
                    'meli_account_id' => (int) $row['meli_account_id'],
                    'identity_present' => (bool) $row['identity_present'],
                    'token_row_present' => (bool) $row['token_row_present'],
                    'access_present' => (bool) $row['access_present'],
                    'refresh_present' => (bool) $row['refresh_present'],
                    'token_expired' => !empty($row['expires_at'])
                        && strtotime((string) $row['expires_at'] . ' UTC') <= time(),
                    'refresh_version' => max(0, (int) ($row['refresh_version'] ?? 0)),
                ];
            }
            if ($accounts === []) {
                $issues[] = 'no_connected_accounts';
            }
        }

        $capabilities = [];
        try {
            $core = QueueCoreFactory::build($this->pdo);
            $capabilities = $core['capabilities']->certifiedTypes($core['registry']);
        } catch (Throwable) {
            $issues[] = 'capability_registry_unavailable';
        }

        $issues = array_values(array_unique($issues));
        return [
            'ok' => $issues === [],
            'issues' => $issues,
            'database_time_utc' => $this->databaseTime(),
            'missing_tables' => $missing,
            'missing_indexes' => $missingIndexes,
            'required_migrations_applied' => array_values(array_intersect($requiredMigrations, $applied)),
            'engine' => $engine,
            'safety' => [
                'api' => (string) ($safety['api'] ?? 'unknown'),
                'automation' => (string) ($safety['automation'] ?? 'unknown'),
                'ml_write_enabled' => Env::bool('ML_WRITE_ENABLED', false),
            ],
            'runtime' => [
                'cron_v4_enabled' => Env::bool('CRON_V4_ENABLED', false),
                'cron_v3_enabled' => Env::bool('CRON_V3_ENABLED', false),
                'cron_v3_shadow_enabled' => Env::bool('CRON_V3_SHADOW_ENABLED', false),
            ],
            'oauth_recovery_storage' => $storage,
            'accounts' => $accounts,
            'capabilities' => $capabilities,
            'remote_http_calls' => 0,
            'business_db_writes' => 0,
        ];
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name=?'
        );
        $statement->execute([$table]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function indexExists(string $table, string $index): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema=DATABASE() AND table_name=? AND index_name=?'
        );
        $statement->execute([$table, $index]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function databaseTime(): ?string
    {
        try {
            $value = $this->pdo->query('SELECT DATE_FORMAT(UTC_TIMESTAMP(3),"%Y-%m-%d %H:%i:%s.%f")')->fetchColumn();
            return is_string($value) && $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}
