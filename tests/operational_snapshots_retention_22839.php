<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$migration = $read('database/migrations/219_operational_snapshots_health_retention_2_28_39.sql');
$snapshot = $read('app/Services/OperationalSnapshotService.php');
$cronRead = $read('app/Services/CronOperationalReadService.php');
$maintenance = $read('app/Services/OperationalMaintenanceService.php');
$retention = $read('app/Services/TechnicalRetentionCliService.php');
$cache = $read('app/Services/ReadModelCacheService.php');
$health = $read('app/Services/ApiHealthService.php');
$job = $read('jobs/process_sync_queue.php');

foreach (['system_operational_snapshots','api_incident_groups','api_incident_materializer_state','system_retention_cli_state','idx_work_queue_runs_history'] as $needle) {
    $check(str_contains($migration, $needle), 'Falta contrato 2.28.39: ' . $needle);
}
$check(str_contains($snapshot, 'remote_backlog') && str_contains($snapshot, 'time() - 300'), 'El snapshot no limita ruido o no conserva backlog remoto.');
$check(str_contains($snapshot, "'value_state' => 'last_known'") && str_contains($snapshot, "? 'unavailable'") && str_contains($snapshot, "? 'partial'"), 'El snapshot no conserva el último valor válido o no tipa lecturas incompletas.');
$check(str_contains($snapshot, "'protocol' => \$protocol") && !str_contains($snapshot, 'VALUES ("cron_cycle","global",:generation,:run_token,"complete"'), 'El snapshot persiste complete aunque la medición sea parcial o no disponible.');
$check(str_contains($cronRead, 'overviewFromOperationalSnapshot') && str_contains($cronRead, "'snapshot_source' => 'persisted_cli'"), 'El overview web no prefiere el snapshot O(1) persistido por CLI.');
$check(str_contains($cronRead, "'pending' => \$pending") && str_contains($cronRead, "'pending_label' => \$pending === null"), 'Una cola desconocida todavía puede presentarse como cero pendiente.');
$check(str_contains($job, 'OperationalSnapshotService'), 'Cron no persiste la generación operativa al cerrar.');
$check(str_contains($maintenance, 'ApiIncidentMaterializerService') && str_contains($maintenance, 'TechnicalRetentionCliService'), 'El carril local no materializa Salud o retención.');
$check(str_contains($retention, 'runDatasetStep') && str_contains($retention, 'max(1, min(500'), 'Retención no usa el pipeline verificable o excede 500 filas.');
$check(!str_contains($cache, 'apcu_clear_cache('), 'La caché todavía borra APCu globalmente.');
$check(str_contains($cache, 'garbageCollect') && str_contains($cache, '$namespacePrefix'), 'La caché no tiene GC o namespace.');
$check(str_contains($health, 'ApiIncidentReadModelService'), 'Salud API no usa incidentes materializados cuando están completos.');
$check(!preg_match('/(?:INSERT|UPDATE|DELETE|ALTER)\s+(?:INTO\s+|FROM\s+|TABLE\s+)?(?:orders|payments|shipments|packs|meli_accounts|companies)\b/i', $migration), 'La migración 219 toca datos comerciales.');

if ($failures !== []) {
    fwrite(STDERR, "FAIL operational_snapshots_retention_22839\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS operational_snapshots_retention_22839\n";
