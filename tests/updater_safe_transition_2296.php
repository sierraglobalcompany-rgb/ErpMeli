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

$integrity = (string) file_get_contents($root . '/app/Services/ReleaseIntegrityService.php');
$recovery = (string) file_get_contents($root . '/app/Recovery/RecoveryKernel.php');
$migration = (string) file_get_contents($root . '/database/migrations/250_updater_safe_transition_2_29_6.sql');

$check(
    str_contains($integrity, "'database_version_mismatch'")
        && strpos($integrity, "'database_version_mismatch'") > strpos($integrity, "'migration_pending'"),
    'ReleaseIntegrityService debe clasificar la transición de versión como esquema pendiente seguro.'
);
$check(
    str_contains($recovery, 'releaseIntegrityIssuesHtml')
        && str_contains($recovery, 'Detalle seguro detectado')
        && str_contains($recovery, 'Paquete incompleto o mezclado'),
    'El actualizador debe mostrar detalle seguro cuando la carga sí está incompleta.'
);
$check(
    str_contains($migration, "('app.version', '2.29.6'")
        && str_contains($migration, "'update.safe_transition_previous_marker'")
        && !str_contains($migration, 'description')
        && !preg_match('/(?:INSERT|UPDATE|DELETE|ALTER)\s+(?:INTO\s+|FROM\s+|TABLE\s+)?(?:meli_orders|meli_order_items|meli_payments|meli_shipments|meli_packs|meli_oauth_states)\b/i', $migration),
    'La migración 250 debe ser metadata segura y no tocar datos comerciales.'
);

$temporary = sys_get_temp_dir() . '/erp-updater-transition-2296-' . bin2hex(random_bytes(5));
mkdir($temporary . '/resources', 0770, true);
mkdir($temporary . '/jobs', 0770, true);
mkdir($temporary . '/database/migrations', 0770, true);
file_put_contents($temporary . '/VERSION', '2.29.6');
file_put_contents($temporary . '/jobs/cron_probe.php', '<?php echo "probe";');
file_put_contents($temporary . '/jobs/process_sync_queue.php', '<?php echo "queue";');
file_put_contents($temporary . '/database/migrations/250_updater_safe_transition_2_29_6.sql', '-- metadata');
file_put_contents(
    $temporary . '/resources/runtime-manifest.json',
    json_encode([
        'version' => '2.29.6',
        'build_id' => 'erp-meli-2.29.6-updater-marker-transition-20260803',
        'minimum_migration' => '250_updater_safe_transition_2_29_6.sql',
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
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
);

$service = new App\Services\ReleaseIntegrityService();
$complete = $service->inspectDirectory($temporary, false, false);
$check((bool) ($complete['ok'] ?? false), 'Un paquete 2.29.6 completo no debe bloquearse antes de migrar.');
file_put_contents($temporary . '/jobs/cron_probe.php', '<?php echo "old";');
$mixed = $service->inspectDirectory($temporary, false, false);
$codes = array_column((array) ($mixed['errors'] ?? []), 'code');
$check(in_array('component_mismatch', $codes, true), 'Un componente mezclado debe seguir bloqueado.');

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

echo "updater_safe_transition_2296_ok\n";
