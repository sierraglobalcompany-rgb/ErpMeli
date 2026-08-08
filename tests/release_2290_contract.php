<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$version = trim((string) file_get_contents($root . '/VERSION'));
$manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
$assert(version_compare($version, '2.29.0', '>='), 'VERSION debe ser 2.29.0 o posterior.');
$assert(is_array($manifest) && ($manifest['version'] ?? null) === $version, 'Manifiesto y VERSION no coinciden.');
if ($version === '2.29.0') {
    $assert(($manifest['build_id'] ?? null) === 'erp-meli-2.29.0-cron-v3-shadow-20260802', 'Build ID inesperado.');
    $assert(($manifest['minimum_migration'] ?? null) === '243_cron_v3_scope_containment_2_29_0.sql', 'Migración mínima inesperada.');
}

foreach ([
    '240_cron_v3_security_containment_2_29_0.sql',
    '241_cron_v3_engine_2_29_0.sql',
    '242_financial_state_v3_2_29_0.sql',
    '243_cron_v3_scope_containment_2_29_0.sql',
] as $migration) {
    $assert(is_file($root . '/database/migrations/' . $migration), 'Falta migración ' . $migration);
}

foreach (($manifest['components'] ?? []) as $name => $component) {
    $path = $root . '/' . (string) ($component['path'] ?? '');
    $assert(is_file($path), 'Falta componente ' . $name);
    $assert(hash_file('sha256', $path) === ($component['sha256'] ?? ''), 'Hash inválido ' . $name);
}

$migration = (string) file_get_contents($root . '/database/migrations/241_cron_v3_engine_2_29_0.sql');
$assert(str_contains($migration, "('cron_v3.enabled','0'") && str_contains($migration, "('cron_v3.shadow_enabled','0'"), 'Cron V3 debe instalarse apagado.');
$assert(str_contains($migration, 'physical_http_calls'), 'La evidencia física HTTP debe estar en el esquema.');
$scopeMigration = (string) file_get_contents($root . '/database/migrations/243_cron_v3_scope_containment_2_29_0.sql');
$assert(str_contains($scopeMigration, "('app.version','2.29.0'"), 'La migración final debe promover app.version.');
$assert(is_file($root . '/docs/cron_v3_audit_2_29_0.md'), 'Falta auditoría de entrega.');

echo "release_2290_contract_ok\n";
