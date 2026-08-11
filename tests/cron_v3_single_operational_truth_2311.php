<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$js = $read('public/assets/app.js');
$view = $read('app/Views/settings/cron_shell.php');
$apiShell = $read('app/Views/settings/api_health_shell.php');
$apiJs = $read('public/assets/api-health.js');

$assert(str_contains($js, 'renderOperationalSnapshot'), 'Cron debe pintar desde operational-snapshot.json.');
$assert(str_contains($js, 'root.dataset.operationalUrl'), 'El frontend debe consultar la ruta operativa directamente.');
$assert(str_contains($js, "root.querySelectorAll('[data-cron-v3-setup]')"), 'El panel de configuración segura debe tratarse por separado.');
$assert(str_contains($js, "root.querySelectorAll('[data-cron-v3-canary]')"), 'El canario legado debe ocultarse cuando V3 operativo sea verdad.');
$assert(str_contains($js, "root.querySelectorAll('[data-cron-v3-shadow-action]')"), 'La acción Shadow debe ocultarse durante el retiro operativo.');
$assert(str_contains($js, 'panel.hidden = false'), 'El panel de configuración segura debe permanecer visible.');
$assert(str_contains($view, 'data-cron-v3-retirement-flag="CRON_V4_ENABLED"'), 'La vista debe exponer el estado efectivo de Cron V4.');
$assert(str_contains($view, 'cron_v3_local.php --runtime=45 --max-items=50'), 'La vista debe mostrar comando V3 local activo.');
$assert(str_contains($view, 'ERP_CRON_SKIP reason=v3_operational'), 'La vista debe explicar que V2 salta durante el corte.');
$assert(str_contains($apiShell, '<noscript>'), 'Salud API necesita fallback sin JavaScript.');
$assert(str_contains($apiJs, 'No se pudo cargar Salud API de forma progresiva'), 'Salud API no debe quedar en skeleton infinito si fetch no existe.');

$migration = $read('database/migrations/259_cron_v3_single_operational_truth_2_31_1.sql');
$assert(str_contains($migration, "('app.version', '2.31.1'"), 'La migración 259 debe registrar app.version 2.31.1.');
$assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE|ALTER)\s+(?:INTO\s+|FROM\s+|TABLE\s+)?(?:meli_orders|meli_order_items|meli_payments|meli_shipments|meli_packs|meli_oauth_states)\b/i', $migration), 'La migración 259 no debe tocar datos comerciales.');

echo "PASS cron_v3_single_operational_truth_2311\n";
