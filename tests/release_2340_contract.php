<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$version = trim((string) file_get_contents($root . '/VERSION'));
$manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true, 64, JSON_THROW_ON_ERROR);
$assert(version_compare($version, '2.34.0', '>='), 'VERSION debe ser 2.34.0 o posterior.');
$assert(version_compare((string) ($manifest['version'] ?? ''), '2.34.0', '>='), 'Manifiesto debe declarar 2.34.0 o posterior.');
$assert((str_starts_with((string) ($manifest['build_id'] ?? ''), 'erp-meli-2.34.') || str_starts_with((string) ($manifest['build_id'] ?? ''), 'erp-meli-2.35.')), 'Build ID inesperado.');
$assert(in_array((string) ($manifest['minimum_migration'] ?? ''), [
    '273_cron_v3_parking_action_center_2_34_0.sql',
    '274_clean_release_api_health_429_2_34_1.sql',
    '275_recover_274_app_settings_key_2_34_2.sql',
    '276_emergency_v3_api_start_without_canary_2_34_3.sql',
    '277_release_integrity_text_hash_recovery_2_34_4.sql',
    '278_cron_v3_fifo_drainage_truth_2_35_0.sql',
    '279_cron_v3_fifo_legacy_finalizer_2_35_1.sql',
], true), 'Migración mínima inesperada.');

foreach ([
    'cron_v3_rate_policy_service',
    'cron_v3_cli',
    'cron_v3_legacy_queue_adapter',
    'cron_v3_operational_snapshot',
    'settings_controller',
    'meli_api_client',
    'api_workload_view',
    'frontend_app_css',
    'frontend_app_js',
    'migration_269_cron_v3_single_rate_authority',
    'migration_270_cron_v3_fifo_producers_import_repair',
    'migration_271_cron_v3_complete_producers',
    'migration_272_cron_v3_operational_truth_snapshot',
    'migration_273_cron_v3_parking_action_center',
] as $component) {
    $path = $manifest['components'][$component]['path'] ?? null;
    $sha = $manifest['components'][$component]['sha256'] ?? null;
    $assert(is_string($path) && is_file($root . '/' . $path), 'No existe componente ' . $component);
    $assert(is_string($sha) && hash_file('sha256', $root . '/' . $path) === $sha, 'Hash no coincide para ' . $component);
}

echo "release_2340_contract_ok\n";




