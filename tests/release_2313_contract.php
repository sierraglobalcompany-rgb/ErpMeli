<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string) file_get_contents($root . '/VERSION'));
if (version_compare($version, '2.31.3', '<')) {
    throw new RuntimeException('VERSION debe ser >= 2.31.3, actual=' . $version);
}

$manifest = json_decode(
    (string) file_get_contents($root . '/resources/runtime-manifest.json'),
    true,
    64,
    JSON_THROW_ON_ERROR
);

if (version_compare((string) ($manifest['version'] ?? '0.0.0'), '2.31.3', '<')) {
    throw new RuntimeException('runtime-manifest.version debe ser >= 2.31.3.');
}
if (!preg_match('/^erp-meli-2\.(?:31\.(?:3|[4-9]|[1-9][0-9]+)|32\.[0-9]+|33\.[0-9]+|34\.[0-9]+|35\.[0-9]+)-/', (string) ($manifest['build_id'] ?? ''))) {
    throw new RuntimeException('build_id inesperado para la línea 2.31.x/2.32.x/2.33.x/2.34.x/2.35.x.');
}
if (preg_match('/^(26[1-9]|2[7-9][0-9])_/', (string) ($manifest['minimum_migration'] ?? '')) !== 1) {
    throw new RuntimeException('minimum_migration debe ser >= 261.');
}

$required = [
    'cron_v3_capability_matrix',
    'cron_v3_operational_snapshot',
    'cron_v3_legacy_queue_adapter',
    'settings_controller',
    'frontend_app_js',
    'api_health_js',
    'api_health_shell',
    'cron_shell',
    'migration_259_cron_v3_single_operational_truth',
    'migration_260_cron_v3_legacy_bridge_contracts',
    'migration_261_cron_v3_history_drainage_certification',
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

echo "release_2313_contract_ok\n";
