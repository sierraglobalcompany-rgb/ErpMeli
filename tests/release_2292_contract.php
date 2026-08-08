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
$assert(version_compare($version, '2.29.2', '>='), 'VERSION debe ser 2.29.2 o posterior.');
$assert(is_array($manifest) && ($manifest['version'] ?? null) === $version, 'Manifiesto y VERSION no coinciden.');
if ($version === '2.29.2') {
    $assert(($manifest['build_id'] ?? null) === 'erp-meli-2.29.2-cron-v3-rc3-20260803', 'Build ID inesperado.');
    $assert(($manifest['minimum_migration'] ?? null) === '246_cron_v3_config_authority_2_29_2.sql', 'Migracion minima inesperada.');
}

foreach ([
    '241_financial_job_fk_support_2_29_1.sql',
    '244_cron_v3_rate_scope_authority_2_29_1.sql',
    '245_cron_v3_rc2_release_2_29_1.sql',
    '246_cron_v3_config_authority_2_29_2.sql',
] as $migration) {
    $assert(is_file($root . '/database/migrations/' . $migration), 'Falta migracion ' . $migration);
}
foreach (($manifest['components'] ?? []) as $name => $component) {
    $path = $root . '/' . (string) ($component['path'] ?? '');
    $assert(is_file($path), 'Falta componente ' . $name);
    $assert(hash_file('sha256', $path) === ($component['sha256'] ?? ''), 'Hash invalido ' . $name);
}

$migration = (string) file_get_contents($root . '/database/migrations/246_cron_v3_config_authority_2_29_2.sql');
$assert(str_contains($migration, "('cron_v3.activation_authority','env_resolver'")
    && str_contains($migration, "('app.version','2.29.2'"),
    'La migracion 246 no declara autoridad canonica o version.');
$assert(is_file($root . '/tests/cron_v3_config_authority_2292.php'), 'Falta prueba config.env de RC3.');
$assert(is_file($root . '/docs/cron_v3_audit_2_29_2.md'), 'Falta auditoria RC3.');

echo "release_2292_contract_ok\n";
