<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "ASSERTION FAILED: {$message}\n");
        exit(1);
    }
};

$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$historyView = (string) file_get_contents($root . '/app/Views/settings/automation_history.php');
$queueView = (string) file_get_contents($root . '/app/Views/settings/automation_queue.php');
$adapter = (string) file_get_contents($root . '/app/Services/CronV3LegacyQueueAdapter.php');
$coordinator = (string) file_get_contents($root . '/app/Services/CronV3LegacyImportCoordinator.php');
$runner = (string) file_get_contents($root . '/app/Services/CronV3Runner.php');
$bootstrap = (string) file_get_contents($root . '/app/Services/CronV3DefaultHandlerBootstrap.php');
$cutover = (string) file_get_contents($root . '/app/Services/CronV3OperationalCutoverService.php');
$capability = (string) file_get_contents($root . '/app/Services/CronV3CapabilityMatrixService.php');
$cli = (string) file_get_contents($root . '/app/Services/CronV3Cli.php');
$migration = (string) file_get_contents($root . '/database/migrations/265_cron_v3_full_operational_drainage_2_32_0.sql');

$assert(str_contains($historyView, 'group=attention&amp;resolution=legacy_needs_diagnosis'), 'Historial debe enlazar el grupo legacy con filtro resolution, no con group inválido.');
$assert(str_contains($controller, '$rawGroup === \'legacy_needs_diagnosis\''), 'El controlador debe conservar alias legacy sin caer en intervención genérica.');
$assert(str_contains($queueView, 'Registros anteriores a la trazabilidad'), 'La cola debe explicar legacy_needs_diagnosis como diagnóstico local.');
$assert(str_contains($queueView, 'Diagnosticar localmente'), 'La acción primaria humana debe ser diagnóstico local.');

$assert(str_contains($adapter, "'notification_fallback' => ["), 'V3 debe registrar adaptador legacy para notification_fallback.');
$assert(str_contains($adapter, "'ownership_keys' => ['order_exact', 'pack_exact', 'shipment_exact', 'question_exact', 'claim_exact', 'item_exact']"), 'notification_fallback debe depender de ownership de trabajos exactos.');
foreach (['order_exact', 'pack_exact', 'shipment_exact', 'question_exact', 'claim_exact', 'item_exact'] as $type) {
    $assert(str_contains($adapter, $type), 'El importador debe poder materializar ' . $type . '.');
}
$assert(str_contains($coordinator, '$definition[\'ownership_keys\']'), 'El coordinador debe habilitar adaptadores por ownership de los tipos producidos.');
$assert(str_contains($runner, 'CronV3LegacySourceFinalizer'), 'Runner debe cerrar la fuente legacy después del fencing.');

$assert(str_contains($bootstrap, 'OperationalMaintenanceHandler'), 'Bootstrap debe registrar handler local de mantenimiento operativo.');
$assert(str_contains($bootstrap, 'MonthlyReportMaintenanceHandler'), 'Bootstrap debe registrar handler local de mantenimiento mensual.');
$assert(str_contains($cli, 'CronV3MaintenanceProducer'), 'CLI local debe producir mantenimiento V3 acotado.');
$assert(str_contains($capability, "'operational_maintenance' => [") && str_contains($capability, "'state' => 'v3_local_only'"), 'Mantenimiento local debe dejar de ser brecha roja.');
$assert(str_contains($cutover, "'operational_maintenance'") && !preg_match('/BLOCKED_TYPES[\\s\\S]*operational_maintenance/', $cutover), 'Mantenimiento local no debe quedar bloqueado en el corte operativo.');

$assert(str_contains($controller, 'cronV3RhythmIncreaseGate'), 'Guardar ritmo debe tener gate de subida V3.');
$assert(str_contains($controller, 'waiting_capability_queues'), 'El gate debe bloquear subida si hay colas críticas sin capacidad.');

$assert(str_contains($migration, "('app.version', '2.32.0'"), 'Migración 265 debe declarar app.version 2.32.0.');
$assert(str_contains($migration, "'cron_v3.notification_fallback_importer'"), 'Migración 265 debe registrar importador notification_fallback.');
$assert(str_contains($migration, "'cron_v3.rate_increase_requires_full_capability'"), 'Migración 265 debe registrar bloqueo de subida de ritmo.');
$assert(!preg_match('/\\b(?:meli_orders|meli_order_items|meli_payments|meli_shipments|meli_packs|meli_oauth_states)\\b/i', $migration), 'Migración 265 no debe tocar datos comerciales.');

echo "cron_v3_full_operational_drainage_2320: OK\n";
