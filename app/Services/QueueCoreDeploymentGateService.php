<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use PDO;

final class QueueCoreDeploymentGateService
{
    private const REQUIRED_MIGRATIONS = [
        '280_queue_core_cron_v4_phase_b1.sql',
        '281_queue_core_reaudit1_fifo_fencing.sql',
        '282_queue_core_architecture_closeout_b1_2.sql',
        '283_queue_engine_control_oauth_supervisor_b1_4.sql',
        '286_queue_core_historical_deploy_b2.sql',
    ];
    private const REQUIRED_TABLES = [
        'queue_core_jobs',
        'queue_core_attempts',
        'queue_core_dispatch_journal',
        'queue_core_historical_checkpoints',
        'queue_core_historical_receipts',
        'queue_core_historical_reviews',
        'queue_engine_control',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{ok:bool,issues:list<string>,checks:array<string,mixed>} */
    public function inspect(?string $backupPath = null, ?string $expectedSha256 = null): array
    {
        $issues = [];
        $checks = [
            'ml_write_enabled' => Env::bool('ML_WRITE_ENABLED', false),
            'tables' => [],
            'migrations' => [],
            'active_engine' => null,
            'active_runtime_leases' => null,
            'uncertain_dispatches' => null,
            'historical_enabled' => null,
            'backup' => null,
        ];
        if ($checks['ml_write_enabled']) {
            $issues[] = 'ml_write_enabled';
        }
        foreach (self::REQUIRED_TABLES as $table) {
            $present = $this->tableExists($table);
            $checks['tables'][$table] = $present;
            if (!$present) {
                $issues[] = 'missing_table:' . $table;
            }
        }
        $schemaMigrations = $this->tableExists('schema_migrations');
        foreach (self::REQUIRED_MIGRATIONS as $migration) {
            $applied = $schemaMigrations && $this->migrationApplied($migration);
            $checks['migrations'][$migration] = $applied;
            if (!$applied) {
                $issues[] = 'missing_migration:' . $migration;
            }
        }
        if ($this->tableExists('queue_engine_control')) {
            $engine = $this->pdo->query(
                "SELECT active_engine FROM queue_engine_control WHERE control_key='primary' LIMIT 1"
            )->fetchColumn();
            $checks['active_engine'] = is_string($engine) ? $engine : null;
            if ($engine !== 'disabled') {
                $issues[] = 'engine_not_disabled';
            }
        }
        if ($this->tableExists('queue_core_execution_leases')) {
            $checks['active_runtime_leases'] = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM queue_core_execution_leases
                 WHERE launcher IS NOT NULL AND expires_at>UTC_TIMESTAMP(3)'
            )->fetchColumn();
            if ($checks['active_runtime_leases'] > 0) {
                $issues[] = 'runtime_lease_active';
            }
        }
        if ($this->tableExists('queue_core_jobs')) {
            $checks['uncertain_dispatches'] = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM queue_core_jobs
                 WHERE dispatch_state='DISPATCHED_RESULT_UNCERTAIN'"
            )->fetchColumn();
            if ($checks['uncertain_dispatches'] > 0) {
                $issues[] = 'uncertain_dispatch_present';
            }
        }
        if ($this->tableExists('queue_core_historical_checkpoints')) {
            $checks['historical_enabled'] = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM queue_core_historical_checkpoints WHERE enabled=1'
            )->fetchColumn();
            if ($checks['historical_enabled'] > 0) {
                $issues[] = 'historical_import_already_enabled';
            }
        }
        if ($backupPath !== null || $expectedSha256 !== null) {
            $checks['backup'] = $this->backupCheck((string) $backupPath, (string) $expectedSha256);
            if (!$checks['backup']['ok']) {
                $issues[] = 'backup_not_verified';
            }
        }
        return ['ok' => $issues === [], 'issues' => array_values(array_unique($issues)), 'checks' => $checks];
    }

    /** @return array{ok:bool,exists:bool,sha256:?string,bytes:int} */
    public function backupCheck(string $path, string $expectedSha256): array
    {
        $exists = $path !== '' && is_file($path) && is_readable($path);
        $sha = $exists ? hash_file('sha256', $path) : false;
        $expected = strtolower(trim($expectedSha256));
        $ok = is_string($sha) && preg_match('/^[a-f0-9]{64}$/', $expected) === 1
            && hash_equals($expected, strtolower($sha));
        return [
            'ok' => $ok,
            'exists' => $exists,
            'sha256' => is_string($sha) ? strtoupper($sha) : null,
            'bytes' => $exists ? max(0, (int) filesize($path)) : 0,
        ];
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name=? LIMIT 1'
        );
        $statement->execute([$table]);
        return $statement->fetchColumn() !== false;
    }

    private function migrationApplied(string $migration): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1');
        $statement->execute([$migration]);
        return $statement->fetchColumn() !== false;
    }
}
