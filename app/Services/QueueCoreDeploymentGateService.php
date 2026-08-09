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
        '284_queue_core_sales_pipeline_b2.sql',
        '285_queue_core_webhook_ownership_b2.sql',
        '286_queue_core_historical_deploy_b2.sql',
        '287_queue_core_readiness_observability_b2.sql',
        '288_queue_core_readiness_authority_b2_1.sql',
        '289_queue_core_webhook_lifecycle_b2_1.sql',
        '290_queue_core_sales_dependency_graph_b2_1.sql',
        '291_queue_core_release_health_capacity_b2_1.sql',
    ];
    private const REQUIRED_TABLES = [
        'queue_core_jobs',
        'queue_core_attempts',
        'queue_core_dispatch_journal',
        'queue_core_historical_checkpoints',
        'queue_core_historical_receipts',
        'queue_core_historical_reviews',
        'queue_core_pending_capabilities',
        'queue_core_capability_dependencies',
        'queue_core_webhook_triggers',
        'queue_core_webhook_spool_items',
        'queue_core_runs',
        'queue_core_readiness_receipts',
        'queue_core_health_snapshots',
        'queue_core_feature_flags',
        'queue_engine_control',
        'queue_core_release_evidence',
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
            'runtime_manifest' => null,
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
        if ($this->tableExists('queue_core_feature_flags')) {
            $feature = (int) $this->pdo->query(
                "SELECT enabled FROM queue_core_feature_flags
                 WHERE feature_key='historical_importer' LIMIT 1"
            )->fetchColumn();
            if ($feature !== 0) {
                $issues[] = 'historical_feature_enabled';
            }
        }
        if ($backupPath === null || $expectedSha256 === null
            || trim($backupPath) === '' || trim($expectedSha256) === '') {
            $checks['backup'] = ['ok' => false, 'exists' => false, 'sha256' => null, 'bytes' => 0];
            $issues[] = 'backup_evidence_required';
        } else {
            $checks['backup'] = $this->backupCheck($backupPath, $expectedSha256);
            if (!$checks['backup']['ok']) {
                $issues[] = 'backup_not_verified';
            }
        }
        $checks['runtime_manifest'] = $this->runtimeManifestCheck();
        if (!$checks['runtime_manifest']['ok']) {
            $issues[] = 'runtime_manifest_invalid';
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

    /** @return array{ok:bool,version:?string,minimum_migration:?string,invalid_components:int} */
    public function runtimeManifestCheck(): array
    {
        $root = dirname(__DIR__, 2);
        $manifestPath = $root . '/resources/runtime-manifest.json';
        $versionPath = $root . '/VERSION';
        $manifest = is_file($manifestPath)
            ? json_decode((string) file_get_contents($manifestPath), true)
            : null;
        $version = is_file($versionPath) ? trim((string) file_get_contents($versionPath)) : null;
        if (!is_array($manifest) || !is_string($version) || $version === '') {
            return ['ok' => false, 'version' => $version, 'minimum_migration' => null, 'invalid_components' => 1];
        }
        $invalid = 0;
        foreach ((array) ($manifest['components'] ?? []) as $component) {
            if (!is_array($component) || !is_string($component['path'] ?? null)) {
                $invalid++;
                continue;
            }
            $path = $root . '/' . ltrim(str_replace('\\', '/', $component['path']), '/');
            $expected = strtolower((string) ($component['sha256'] ?? ''));
            $expectedLf = strtolower((string) ($component['sha256_lf'] ?? ''));
            $contents = is_file($path) ? file_get_contents($path) : false;
            $exact = is_string($contents) ? hash('sha256', $contents) : '';
            $canonicalLf = is_string($contents)
                ? hash('sha256', str_replace(["\r\n", "\r"], "\n", $contents))
                : '';
            $exactMatch = preg_match('/^[a-f0-9]{64}$/', $expected) === 1
                && hash_equals($expected, $exact);
            $canonicalMatch = preg_match('/^[a-f0-9]{64}$/', $expectedLf) === 1
                && hash_equals($expectedLf, $canonicalLf);
            if (!is_string($contents) || (!$exactMatch && !$canonicalMatch)) {
                $invalid++;
            }
        }
        $minimum = (string) ($manifest['minimum_migration'] ?? '');
        $requiredMinimum = self::REQUIRED_MIGRATIONS[array_key_last(self::REQUIRED_MIGRATIONS)];
        $ok = hash_equals($version, (string) ($manifest['version'] ?? ''))
            && hash_equals($requiredMinimum, $minimum)
            && $invalid === 0
            && count((array) ($manifest['components'] ?? [])) > 0;
        return [
            'ok' => $ok,
            'version' => $version,
            'minimum_migration' => $minimum !== '' ? $minimum : null,
            'invalid_components' => $invalid,
        ];
    }
}
