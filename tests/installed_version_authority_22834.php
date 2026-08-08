<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$versionService = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$recovery = (string) file_get_contents($root . '/app/Recovery/RecoveryKernel.php');
$integrity = (string) file_get_contents($root . '/app/Services/ReleaseIntegrityService.php');
$migration = (string) file_get_contents(
    $root . '/database/migrations/214_installed_version_authority_2_28_34.sql'
);
$manifest = json_decode(
    (string) file_get_contents($root . '/resources/runtime-manifest.json'),
    true,
    32,
    JSON_THROW_ON_ERROR
);

$activeVersion = trim((string) file_get_contents($root . '/VERSION'));
$check($activeVersion === ($manifest['version'] ?? ''), 'VERSION y manifiesto no coinciden.');
$check(
    is_file($root . '/database/migrations/' . basename((string) ($manifest['minimum_migration'] ?? ''))),
    'El manifiesto no exige una migración mínima existente.'
);
$check(
    str_contains($versionService, "SELECT setting_value FROM app_settings WHERE setting_key='app.version'")
        && strpos($versionService, 'SELECT setting_value FROM app_settings')
            < strpos($versionService, 'SELECT version FROM app_versions'),
    'AppVersionService no usa app.version como autoridad primaria.'
);
$check(
    str_contains($versionService, 'installed_at=CURRENT_TIMESTAMP'),
    'La reinstalación no refresca el historial app_versions.'
);
$check(
    str_contains($recovery, 'assertReleaseFilesReady()')
        && str_contains($recovery, 'release_upload_incomplete')
        && str_contains($recovery, 'release_schema_inconsistent')
        && str_contains($recovery, '<dt>Esquema instalado</dt>')
        && str_contains($recovery, '<dt>Marcador firmado</dt>'),
    'El actualizador no aplica el contrato de coherencia completo.'
);
$check(
    str_contains($integrity, 'minimum_migration_file_missing')
        && str_contains($integrity, 'database_version_mismatch'),
    'ReleaseIntegrityService no detecta cargas parciales o esquema desalineado.'
);
$check(
    str_contains($migration, "VALUES ('app.version','2.28.34'")
        && str_contains($migration, "'update.installed_version_authority','app_settings'")
        && !preg_match('/(?:INSERT|UPDATE|DELETE|ALTER)\s+(?:INTO\s+|FROM\s+|TABLE\s+)?(?:orders|payments|shipments|packs|meli_accounts|meli_oauth_states)\b/i', $migration),
    'La migración 214 no fija la autoridad o intenta mutar tablas comerciales.'
);

$temporary = sys_get_temp_dir() . '/erp-release-integrity-' . bin2hex(random_bytes(5));
mkdir($temporary . '/resources', 0770, true);
mkdir($temporary . '/jobs', 0770, true);
mkdir($temporary . '/database/migrations', 0770, true);
file_put_contents($temporary . '/VERSION', '2.28.34');
file_put_contents($temporary . '/jobs/cron_probe.php', '<?php echo "probe";');
file_put_contents($temporary . '/jobs/process_sync_queue.php', '<?php echo "queue";');
$testManifest = [
    'version' => '2.28.34',
    'build_id' => 'test-build-22834',
    'minimum_migration' => '214_installed_version_authority_2_28_34.sql',
    'components' => [
        'cron_probe' => [
            'path' => 'jobs/cron_probe.php',
            'sha256' => hash_file('sha256', $temporary . '/jobs/cron_probe.php'),
        ],
        'process_sync_queue' => [
            'path' => 'jobs/process_sync_queue.php',
            'sha256' => hash_file('sha256', $temporary . '/jobs/process_sync_queue.php'),
        ],
    ],
];
file_put_contents(
    $temporary . '/resources/runtime-manifest.json',
    json_encode($testManifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
);

$service = new App\Services\ReleaseIntegrityService();
$missingMigration = $service->inspectDirectory($temporary, false, false);
$missingCodes = array_column((array) ($missingMigration['errors'] ?? []), 'code');
$check(
    in_array('minimum_migration_file_missing', $missingCodes, true),
    'Una carga parcial sin migración mínima no fue bloqueada.'
);
file_put_contents(
    $temporary . '/database/migrations/214_installed_version_authority_2_28_34.sql',
    '-- test'
);
$completeFiles = $service->inspectDirectory($temporary, false, false);
$check((bool) ($completeFiles['ok'] ?? false), 'Un paquete mínimo coherente fue rechazado.');
file_put_contents($temporary . '/VERSION', '2.28.33');
$mixedFiles = $service->inspectDirectory($temporary, false, false);
$mixedCodes = array_column((array) ($mixedFiles['errors'] ?? []), 'code');
$check(in_array('version_mismatch', $mixedCodes, true), 'VERSION y manifiesto mezclados no fueron rechazados.');

foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
) as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($temporary);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "PASS installed_version_authority_22834\n";
