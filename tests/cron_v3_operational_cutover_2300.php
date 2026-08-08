<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$process = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
$entryState = (string) file_get_contents($root . '/jobs/_cron_entry_state.php');
$cli = (string) file_get_contents($root . '/app/Services/CronV3Cli.php');
$ownership = (string) file_get_contents($root . '/app/Services/CronV3OwnershipService.php');
$setup = (string) file_get_contents($root . '/app/Services/CronV3SetupAssistantService.php');
$recovery = (string) file_get_contents($root . '/app/Recovery/RecoveryKernel.php');
$operational = (string) file_get_contents($root . '/app/Services/CronV3OperationalCutoverService.php');
$runtime = (string) file_get_contents($root . '/app/Services/CronV3RuntimeStatusService.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$view = (string) file_get_contents($root . '/app/Views/settings/cron_shell.php');
$js = (string) file_get_contents($root . '/public/assets/app.js');
$migration = (string) file_get_contents($root . '/database/migrations/257_cron_v3_operational_cutover_2_30_0.sql');

$assert(str_contains($process, 'ERP_CRON_SKIP component=process_sync_queue'), 'V2 debe imprimir skip explícito.');
$assert(str_contains($process, 'reason=v3_operational'), 'V2 debe salir por reason=v3_operational.');
$assert(str_contains($process, 'CronV3OperationalModeService())->enabled'), 'V2 debe consultar modo operativo antes de procesar.');
$assert(str_contains($entryState, 'v3_operational_skip'), 'El estado temprano debe aceptar v3_operational_skip.');

$assert(str_contains($ownership, 'CronV3OperationalModeService())->enabled'), 'Ownership V2 debe devolver lista vacía en modo operativo.');
$assert(str_contains($cli, 'CronV3OperationalCutoverService'), 'El CLI V3 debe aplicar corte operativo.');
$assert(str_contains($cli, 'operational_cutover_blocked'), 'El CLI V3 debe bloquear si el corte operativo no es seguro.');
$assert(str_contains($cli, 'CronV3CertifiedCutoverService'), 'El canario certificado se conserva fuera del modo operativo.');

foreach (['notification_spool', 'orders_search_page', 'pack_exact', 'shipment_exact', 'sale_billing_capture', 'cron_v3_health_snapshot'] as $type) {
    $assert(str_contains($operational, "'" . $type . "'"), 'El corte operativo debe clasificar: ' . $type);
}
$assert(str_contains($operational, 'ML_WRITE_ENABLED debe permanecer en false'), 'El corte operativo debe bloquear ML_WRITE_ENABLED=true.');
$assert(str_contains($operational, 'CRON_V3_SHADOW_ENABLED debe estar en false'), 'El corte operativo debe apagar Shadow.');
$assert(str_contains($operational, "'disabled'"), 'Familias no soportadas deben quedar bloqueadas explícitamente.');

$assert(str_contains($setup, 'prepareOperationalConfig'), 'El asistente debe preparar config operativa.');
$assert(str_contains($setup, "'CRON_V3_ENABLED' => 'true'"), 'La config operativa debe encender V3.');
$assert(str_contains($setup, "'CRON_V3_SHADOW_ENABLED' => 'false'"), 'La config operativa debe apagar Shadow.');
$assert(str_contains($recovery, 'prepareOperationalConfig'), 'El actualizador debe preparar V3 operativo en 2.30.0.');
$assert(str_contains($recovery, "version_compare(\$fileVersion, '2.30.0', '>=')"), 'El actualizador debe diferenciar 2.30.0.');

$assert(str_contains($runtime, 'CronV3OperationalReadService'), 'Runtime status debe leer señal V3 real.');
$assert(str_contains($runtime, "job_name='process_sync_queue'"), 'Runtime status debe detectar señal V2 en cron_health_checks.');
$assert(str_contains($routes, '/settings/cron/v3-runtime-status.json'), 'Debe existir endpoint runtime V3.');
$assert(str_contains($view, 'Corte operativo V3'), 'La pantalla debe mostrar corte operativo V3.');
$assert(str_contains($view, 'data-v3-runtime-url'), 'La pantalla debe exponer URL runtime V3.');
$assert(str_contains($js, 'renderRuntime'), 'El frontend debe renderizar runtime V3.');
$assert(str_contains($js, 'fetchRuntime'), 'El frontend debe refrescar runtime V3.');

$assert(str_contains($migration, "('cron_v3.operational_mode', '1'"), 'La migración debe activar modo operativo.');
$assert(str_contains($migration, "('cron_v3.v2_runtime_disabled', '1'"), 'La migración debe deshabilitar runtime V2.');
$assert(str_contains($migration, "('cron_v3.rollback_enabled', '0'"), 'La migración debe dejar rollback apagado por defecto.');
$assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE|ALTER)\s+(?:INTO\s+|FROM\s+|TABLE\s+)?(?:meli_orders|meli_order_items|meli_payments|meli_shipments|meli_packs|meli_oauth_states)\b/i', $migration), 'La migración no debe tocar datos comerciales.');

echo "PASS cron_v3_operational_cutover_2300\n";
