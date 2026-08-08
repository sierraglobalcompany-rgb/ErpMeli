<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string) file_get_contents($root . '/VERSION'));
if (version_compare($version, '2.30.0', '<')) {
    throw new RuntimeException('VERSION debe ser 2.30.0 o superior, actual=' . $version);
}

$manifest = json_decode(
    (string) file_get_contents($root . '/resources/runtime-manifest.json'),
    true,
    64,
    JSON_THROW_ON_ERROR
);

if (version_compare((string) ($manifest['version'] ?? '0'), '2.30.0', '<')) {
    throw new RuntimeException('runtime-manifest.version debe ser 2.30.0 o superior.');
}
if ($version === '2.30.0' && ($manifest['build_id'] ?? '') !== 'erp-meli-2.30.0-cron-v3-operativo-total-20260803') {
    throw new RuntimeException('build_id inesperado para 2.30.0.');
}
if ($version === '2.30.0' && ($manifest['minimum_migration'] ?? '') !== '257_cron_v3_operational_cutover_2_30_0.sql') {
    throw new RuntimeException('minimum_migration debe ser 257_cron_v3_operational_cutover_2_30_0.sql.');
}

$required = [
    'process_sync_queue',
    'cron_v3_cli',
    'cron_v3_setup_assistant',
    'cron_v3_operational_mode',
    'cron_v3_operational_cutover',
    'cron_v3_runtime_status',
    'cron_v3_operational_read',
    'frontend_app_js',
    'frontend_app_css',
    'release_integrity',
    'recovery_kernel',
    'migrator',
    'migration_015_sync_products_claims',
    'migration_253_applied_migration_015_drift_recovery',
    'migration_257_cron_v3_operational_cutover',
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

$migration = (string) file_get_contents($root . '/database/migrations/257_cron_v3_operational_cutover_2_30_0.sql');
if (!str_contains($migration, '2.30.0')) {
    throw new RuntimeException('La migración 257 debe registrar 2.30.0.');
}
if (!is_file($root . '/database/migrations/253_applied_migration_015_drift_recovery_2_29_9.sql')) {
    throw new RuntimeException('La release acumulativa debe conservar recuperación 253.');
}
if (preg_match('/\b(?:INSERT|UPDATE|DELETE|ALTER)\s+(?:INTO\s+|FROM\s+|TABLE\s+)?(?:meli_orders|meli_order_items|meli_payments|meli_shipments|meli_packs|meli_oauth_states)\b/i', $migration)) {
    throw new RuntimeException('La migración 257 no puede tocar datos comerciales.');
}

echo "release_2300_contract_ok\n";

