<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$cron = (string) file_get_contents($root . '/app/Views/settings/cron_shell.php');
$financial = (string) file_get_contents($root . '/app/Views/financial_recalc/show.php');
$emergency = (string) file_get_contents($root . '/app/Recovery/EmergencyControlKernel.php');
$emergencyJs = (string) file_get_contents($root . '/public/assets/emergency-control.js');
$products = (string) file_get_contents($root . '/app/Views/products/meli/_table.php');
$styles = (string) file_get_contents($root . '/public/assets/ux.css');
$migration = (string) file_get_contents($root . '/database/migrations/208_human_frontend_accessibility_2_28_28.sql');

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$check(str_contains($layout, '$documentTitle') && !str_contains($layout, '<title>ERP Meli</title>'),
    'El layout no genera títulos de documento orientados por ruta.');
$check(!str_contains($cron, '<main class="cron-control"') && str_contains($cron, 'Salidas HTTP/h'),
    'Cron conserva un landmark principal anidado o terminología ambigua.');
$check(!str_contains($cron, 'consultas reales') && str_contains($cron, 'transportes HTTP iniciados'),
    'Cron todavía presenta un transporte iniciado como consulta completada.');
$check(str_contains($financial, 'safeOperationMessage') && !str_contains($financial, "View::e(\$job['last_db_error_message'])"),
    'El detalle financiero puede exponer el error técnico de base de datos.');
$check(str_contains($emergency, 'emergency-control.js') && !str_contains($emergency, '<script>(function()'),
    'El freno de mano conserva JavaScript inline incompatible con CSP estricta.');
$check(str_contains($emergencyJs, "event.key !== 'Tab'") && str_contains($emergencyJs, 'returnFocus'),
    'El modal de emergencia no atrapa/restaura el foco.');
$check(str_contains($products, 'loading="lazy"') && str_contains($products, 'referrerpolicy="no-referrer"'),
    'Las miniaturas remotas no limitan carga ni referencia saliente.');
$check(str_contains($styles, '.human-table[data-responsive="cards"]'),
    'Las tablas humanas no se adaptan como tarjetas en móvil.');
$check(str_contains($migration, 'INSERT IGNORE INTO app_settings'),
    'La migración de defaults podría sobrescribir preferencias del operador.');

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "PASS human_frontend_accessibility_22828\n");
