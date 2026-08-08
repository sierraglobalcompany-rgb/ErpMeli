<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string) file_get_contents($root . '/VERSION'));
if (version_compare($version, '2.29.12', '<')) {
    throw new RuntimeException('VERSION debe ser 2.29.12 o superior, actual=' . $version);
}

$manifest = json_decode(
    (string) file_get_contents($root . '/resources/runtime-manifest.json'),
    true,
    64,
    JSON_THROW_ON_ERROR
);

if (version_compare((string) ($manifest['version'] ?? '0'), '2.29.12', '<')) {
    throw new RuntimeException('runtime-manifest.version debe ser 2.29.12 o superior.');
}
if (!in_array((string) ($manifest['build_id'] ?? ''), [
    'erp-meli-2.29.12-v3-certified-cutover-20260803',
    'erp-meli-2.30.0-cron-v3-operativo-total-20260803',
    'erp-meli-2.31.0-cron-v3-sin-tierra-de-nadie-20260803',
    'erp-meli-2.31.3-v3-unica-verdad-operativa-20260803',
    'erp-meli-2.31.6-v3-coherente-salud-api-20260804',
    'erp-meli-2.32.0-cron-v3-completo-20260804',
    'erp-meli-2.33.0-cron-v3-fifo-rampas-20260804',
    'erp-meli-2.34.0-cron-v3-fifo-ritmo-humano-20260804',
    'erp-meli-2.34.1-clean-release-api-health-429-20260804',
    'erp-meli-2.34.2-recover-274-api-health-429-20260804',
    'erp-meli-2.34.3-v3-handbrake-direct-api-20260804',
    'erp-meli-2.34.4-release-integrity-text-lf-20260804',
    'erp-meli-2.35.0-cron-v3-fifo-drenaje-real-20260804',
    'erp-meli-2.35.1-cron-v3-fifo-legacy-finalizer-20260804',
], true)) {
    throw new RuntimeException('build_id inesperado para 2.29.12.');
}
if (!in_array((string) ($manifest['minimum_migration'] ?? ''), [
    '256_cron_v3_certified_cutover_2_29_12.sql',
    '257_cron_v3_operational_cutover_2_30_0.sql',
    '258_cron_v3_capability_matrix_2_31_0.sql',
    '259_cron_v3_single_operational_truth_2_31_1.sql',
    '260_cron_v3_legacy_bridge_contracts_2_31_2.sql',
    '261_cron_v3_history_drainage_certification_2_31_3.sql',
    '262_cron_v3_visual_truth_api_health_2_31_4.sql',
    '263_cron_v3_queue_capability_owners_2_31_5.sql',
    '264_cron_v3_history_drainage_v3_truth_2_31_6.sql',
    '265_cron_v3_full_operational_drainage_2_32_0.sql',
    '268_cron_v3_fifo_operational_snapshot_2_33_0.sql',
    '273_cron_v3_parking_action_center_2_34_0.sql',
    '274_clean_release_api_health_429_2_34_1.sql',
    '275_recover_274_app_settings_key_2_34_2.sql',
    '276_emergency_v3_api_start_without_canary_2_34_3.sql',
    '277_release_integrity_text_hash_recovery_2_34_4.sql',
    '278_cron_v3_fifo_drainage_truth_2_35_0.sql',
    '279_cron_v3_fifo_legacy_finalizer_2_35_1.sql',
], true)) {
    throw new RuntimeException('minimum_migration debe ser 256 o una migración acumulativa superior.');
}

$required = [
    'cron_v3_cli',
    'cron_v3_canary_control',
    'cron_v3_certified_cutover',
    'cron_v3_operational_read',
    'frontend_app_js',
    'migrator',
    'migration_244_cron_v3_rate_scope_authority',
    'migration_256_cron_v3_certified_cutover',
];

foreach ($required as $key) {
    if (!isset($manifest['components'][$key])) {
        throw new RuntimeException('Falta componente firmado: ' . $key);
    }
    $component = $manifest['components'][$key];
    $path = $root . '/' . (string) ($component['path'] ?? '');
    if (!is_file($path)) {
        throw new RuntimeException('No existe archivo firmado: ' . $key);
    }
    $actual = hash_file('sha256', $path);
    if (!hash_equals((string) ($component['sha256'] ?? ''), $actual)) {
        throw new RuntimeException('Hash no coincide para componente: ' . $key);
    }
}

$migration = (string) file_get_contents($root . '/database/migrations/256_cron_v3_certified_cutover_2_29_12.sql');
$migration244 = (string) file_get_contents($root . '/database/migrations/244_cron_v3_rate_scope_authority_2_29_1.sql');
$migrator = (string) file_get_contents($root . '/app/Services/Migrator.php');
if (!is_file($root . '/database/migrations/253_applied_migration_015_drift_recovery_2_29_9.sql')) {
    throw new RuntimeException('La release acumulativa debe conservar la recuperación 253.');
}
if (
    preg_match('/(?:INSERT|UPDATE|DELETE|ALTER)\s+(?:INTO\s+|FROM\s+|TABLE\s+)?(?:meli_orders|meli_order_items|meli_payments|meli_shipments|meli_packs|meli_oauth_states|cron_v3_work|cron_v3_queue_ownership)\b/i', $migration)
) {
    throw new RuntimeException('La migración 256 no puede tocar datos comerciales, jobs ni ownership.');
}
if (str_contains($migration244, 'ADD COLUMN IF NOT EXISTS')) {
    throw new RuntimeException('La migración 244 debe ser portable MySQL/MariaDB; no puede usar ADD COLUMN IF NOT EXISTS.');
}
foreach ([
    'stmt_cron_v3_scope_level',
    'stmt_cron_v3_application_id',
    'stmt_cron_v3_endpoint_key',
    'stmt_cron_v3_operation_key',
] as $needle) {
    if (!str_contains($migration244, $needle)) {
        throw new RuntimeException('La migración 244 debe declarar columna portable: ' . $needle);
    }
}
foreach ([
    'canReconcileAppliedMigration244Drift',
    'applied_244_rate_contract_reconciled',
    'cron_v3_rate_buckets',
    'idx_cron_v3_rate_dimensions',
    'idx_cron_v3_rate_operation',
] as $needle) {
    if (!str_contains($migrator, $needle)) {
        throw new RuntimeException('El migrador debe reconciliar la 244 aplicada por contrato: ' . $needle);
    }
}

echo "release_22912_contract_ok\n";











