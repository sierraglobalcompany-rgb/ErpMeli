<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "ASSERTION FAILED: {$message}\n");
        exit(1);
    }
};

$version = trim((string) file_get_contents($root . '/VERSION'));
$manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true, 64, JSON_THROW_ON_ERROR);

$assert(version_compare($version, '2.33.0', '>='), 'VERSION must be 2.33.0 or certified cumulative successor');
$assert(($manifest['version'] ?? '') === $version, 'manifest version must match VERSION');
$assert(in_array((string) ($manifest['build_id'] ?? ''), [
    'erp-meli-2.33.0-cron-v3-fifo-rampas-20260804',
    'erp-meli-2.34.0-cron-v3-fifo-ritmo-humano-20260804',
    'erp-meli-2.34.1-clean-release-api-health-429-20260804',
    'erp-meli-2.34.2-recover-274-api-health-429-20260804',
    'erp-meli-2.34.3-v3-handbrake-direct-api-20260804',
    'erp-meli-2.34.4-release-integrity-text-lf-20260804',
    'erp-meli-2.35.0-cron-v3-fifo-drenaje-real-20260804',
    'erp-meli-2.35.1-cron-v3-fifo-legacy-finalizer-20260804',
], true), 'build id must match 2.33.0 or certified cumulative successor');
$assert(in_array((string) ($manifest['minimum_migration'] ?? ''), [
    '268_cron_v3_fifo_operational_snapshot_2_33_0.sql',
    '273_cron_v3_parking_action_center_2_34_0.sql',
    '274_clean_release_api_health_429_2_34_1.sql',
    '275_recover_274_app_settings_key_2_34_2.sql',
    '276_emergency_v3_api_start_without_canary_2_34_3.sql',
    '277_release_integrity_text_hash_recovery_2_34_4.sql',
    '278_cron_v3_fifo_drainage_truth_2_35_0.sql',
    '279_cron_v3_fifo_legacy_finalizer_2_35_1.sql',
], true), 'minimum migration must be 268 or certified cumulative successor');

foreach ([
    'migration_266_cron_v3_fifo_arrival_queue',
    'migration_267_cron_v3_editable_ramps',
    'migration_268_cron_v3_fifo_operational_snapshot',
    'cron_v3_repository',
    'cron_v3_enqueuer',
    'cron_v3_kernel',
    'cron_v3_work_type_registry',
    'cron_v3_legacy_queue_adapter',
    'cron_v3_legacy_import_coordinator',
    'cron_v3_legacy_source_finalizer',
    'cron_v3_operational_read',
    'cron_v3_operational_snapshot',
    'cron_v3_capability_matrix',
    'cron_v3_runner',
    'cron_v3_cli',
    'api_rhythm_policy_service',
    'settings_controller',
    'public_router',
    'cron_shell',
    'api_workload_view',
    'cron_v3_parked_view',
    'api_health_view',
    'api_health_shell',
] as $component) {
    $path = $manifest['components'][$component]['path'] ?? null;
    $sha = $manifest['components'][$component]['sha256'] ?? null;
    $assert(is_string($path) && is_file($root . '/' . $path), "{$component} path must exist");
    $assert(is_string($sha) && hash_file('sha256', $root . '/' . $path) === $sha, "{$component} hash must match manifest");
}

echo "release_2330_contract: OK\n";









