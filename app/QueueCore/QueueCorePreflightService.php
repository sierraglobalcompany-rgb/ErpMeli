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
        'queue_core_jobs', 'queue_core_attempts', 'queue_core_dispatch_journal',
        'queue_core_producer_checkpoints', 'queue_core_pending_capabilities',
        'queue_core_execution_leases', 'queue_engine_control',
        'queue_core_runs', 'queue_core_readiness_receipts',
        'queue_core_health_snapshots', 'queue_core_feature_flags',
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
                        t.expires_at,t.refresh_version
                 FROM meli_accounts a
                 LEFT JOIN meli_tokens t ON t.meli_account_id=a.id
                 WHERE a.status IN ("conectado","connected")
                 ORDER BY a.company_id,a.id'
            );
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $valid = (int) $row['identity_present'] === 1 && (int) $row['token_row_present'] === 1;
                if (!$valid) {
                    $issues[] = 'account_identity_or_token_missing';
                }
                $accounts[] = [
                    'company_id' => (int) $row['company_id'],
                    'meli_account_id' => (int) $row['meli_account_id'],
                    'identity_present' => (bool) $row['identity_present'],
                    'token_row_present' => (bool) $row['token_row_present'],
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

    private function databaseTime(): ?string
    {
        try {
            $value = $this->pdo->query('SELECT DATE_FORMAT(UTC_TIMESTAMP(3),"%Y-%m-%d %H:%i:%s.%f")')?->fetchColumn();
            return is_string($value) && $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}
