<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string) file_get_contents($root . '/VERSION'));
if ($version !== '2.31.0') {
    echo "release_2310_contract_skipped_for_cumulative_" . $version . "\n";
    return;
}

$manifest = json_decode(
    (string) file_get_contents($root . '/resources/runtime-manifest.json'),
    true,
    64,
    JSON_THROW_ON_ERROR
);

if (($manifest['version'] ?? '') !== '2.31.0') {
    throw new RuntimeException('runtime-manifest.version debe ser 2.31.0.');
}
if (($manifest['build_id'] ?? '') !== 'erp-meli-2.31.0-cron-v3-sin-tierra-de-nadie-20260803') {
    throw new RuntimeException('build_id inesperado para 2.31.0.');
}
if (($manifest['minimum_migration'] ?? '') !== '258_cron_v3_capability_matrix_2_31_0.sql') {
    throw new RuntimeException('minimum_migration debe ser 258_cron_v3_capability_matrix_2_31_0.sql.');
}

$required = [
    'cron_v3_capability_matrix',
    'cron_v3_operational_snapshot',
    'cron_v3_operational_cutover',
    'cron_v3_runtime_status',
    'cron_v3_doctor',
    'runtime_process_inventory',
    'settings_controller',
    'public_router',
    'frontend_app_js',
    'migration_258_cron_v3_capability_matrix',
    'cron_v3_doctor_cli',
    'cron_v3_backlog_snapshot_cli',
    'cron_v3_diagnose_legacy_cli',
    'cron_v3_certify_cli',
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

echo "release_2310_contract_ok\n";

