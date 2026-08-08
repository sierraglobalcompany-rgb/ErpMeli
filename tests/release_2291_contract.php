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
$assert(version_compare($version, '2.29.1', '>='), 'VERSION debe ser 2.29.1 o posterior.');
$assert(is_array($manifest) && ($manifest['version'] ?? null) === $version, 'Manifiesto y VERSION no coinciden.');
if ($version === '2.29.1') {
    $assert(($manifest['build_id'] ?? null) === 'erp-meli-2.29.1-cron-v3-rc2-20260803', 'Build ID inesperado.');
    $assert(($manifest['minimum_migration'] ?? null) === '245_cron_v3_rc2_release_2_29_1.sql', 'Migracion minima inesperada.');
}

foreach ([
    '240_cron_v3_security_containment_2_29_0.sql',
    '241_cron_v3_engine_2_29_0.sql',
    '241_financial_job_fk_support_2_29_1.sql',
    '242_financial_state_v3_2_29_0.sql',
    '243_cron_v3_scope_containment_2_29_0.sql',
    '244_cron_v3_rate_scope_authority_2_29_1.sql',
    '245_cron_v3_rc2_release_2_29_1.sql',
] as $migration) {
    $assert(is_file($root . '/database/migrations/' . $migration), 'Falta migracion ' . $migration);
}

$requiredComponents = [
    'cron_v3_local', 'cron_v3_remote', 'cron_v3_kernel', 'cron_v3_cli',
    'cron_v3_rate_gate', 'cron_v3_doctor', 'cron_v3_runner', 'cron_v3_repository',
    'cron_deadline_context',
];
foreach ($requiredComponents as $name) {
    $assert(isset($manifest['components'][$name]), 'Falta componente critico ' . $name);
}
foreach (($manifest['components'] ?? []) as $name => $component) {
    $path = $root . '/' . (string) ($component['path'] ?? '');
    $assert(is_file($path), 'Falta componente ' . $name);
    $assert(hash_file('sha256', $path) === ($component['sha256'] ?? ''), 'Hash invalido ' . $name);
}

$engineMigration = (string) file_get_contents($root . '/database/migrations/241_cron_v3_engine_2_29_0.sql');
$assert(str_contains($engineMigration, "('cron_v3.enabled','0'")
    && str_contains($engineMigration, "('cron_v3.shadow_enabled','0'"), 'Cron V3 debe instalarse apagado.');
$releaseMigration = (string) file_get_contents($root . '/database/migrations/245_cron_v3_rc2_release_2_29_1.sql');
$assert(str_contains($releaseMigration, "('app.version','2.29.1'")
    && str_contains($releaseMigration, "('cron_v3.activation_authority','environment'")
    && str_contains($releaseMigration, "('cron_v3.minimum_mariadb','10.6'"),
    'La migracion final debe declarar version, autoridad y minimo MariaDB real.');
$bridgeMigration = (string) file_get_contents($root . '/database/migrations/241_financial_job_fk_support_2_29_1.sql');
$assert(str_contains($bridgeMigration, 'idx_sale_financial_job_account_fk')
    && str_contains($bridgeMigration, '(meli_account_id)'),
    'Falta el indice puente que permite a 242 reemplazar la unicidad sin romper la FK.');
$assert(is_file($root . '/docs/cron_v3_audit_2_29_1.md'), 'Falta auditoria RC2.');

echo "release_2291_contract_ok\n";
