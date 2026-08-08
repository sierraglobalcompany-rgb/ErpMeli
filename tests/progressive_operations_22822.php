<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$routes = (string) file_get_contents($root . '/public/index.php');
$expectedRoutes = [
    "/reports/profitability/section.json",
    "/financial-recalc/section.json",
    "/sales-control/overview.json",
    "/sync/section.json",
    "/notifications/section.json",
];
foreach ($expectedRoutes as $route) {
    $check(str_contains($routes, "'{$route}'"), "Falta la ruta progresiva {$route}.");
}

$contracts = [
    ['app/Controllers/ProfitabilityController.php', "render('profitability-list'", 'reports/profitability/index.php', 'data-async-section="profitability-list"'],
    ['app/Controllers/FinancialRecalcController.php', "render('financial-recalc'", 'financial_recalc/index.php', 'data-async-section="financial-recalc"'],
    ['app/Controllers/SalesControlController.php', "render('sales-control-overview'", 'sales_control/index.php', 'data-async-section="sales-control-overview"'],
    ['app/Controllers/SyncController.php', "render('sync-overview'", 'sync/index.php', 'data-async-section="sync-overview"'],
    ['app/Controllers/NotificationController.php', "render('notifications-center'", 'notifications/index.php', 'data-async-section="notifications-center"'],
];
foreach ($contracts as [$controllerFile, $controllerNeedle, $viewFile, $viewNeedle]) {
    $controller = (string) file_get_contents($root . '/' . $controllerFile);
    $view = (string) file_get_contents($root . '/app/Views/' . $viewFile);
    $check(str_contains($controller, $controllerNeedle), "{$controllerFile} no usa el read model progresivo.");
    $check(str_contains($controller, 'releaseSession()'), "{$controllerFile} no libera la sesión antes de la lectura lenta.");
    $check(str_contains($view, $viewNeedle), "{$viewFile} no presenta skeleton progresivo.");
    $check(str_contains($view, 'data-async-status'), "{$viewFile} no presenta errores/reintentos independientes.");
    $check(str_contains($view, 'full=1'), "{$viewFile} no conserva fallback sin JavaScript.");
}

$performance = (string) file_get_contents($root . '/public/assets/performance.js');
foreach (['profitability-list', 'financial-recalc', 'sales-control-overview', 'sync-overview', 'notifications-center'] as $section) {
    $check(str_contains($performance, "'{$section}'"), "performance.js no tiene lenguaje humano para {$section}.");
}
$check(str_contains($performance, "controller.abort('timeout')"), 'Las secciones no conservan timeout de ocho segundos.');
$check(str_contains($performance, 'Math.min(2, initialSections.length)'), 'La carga progresiva no limita concurrencia a dos secciones.');

$appJs = (string) file_get_contents($root . '/public/assets/app.js');
$check(substr_count($appJs, 'window.setTimeout(() => controller.abort(), 8000)') >= 2, 'Los monitores de Sincronización y Finanzas necesitan timeout propio.');
$dateReport = (string) file_get_contents($root . '/app/Services/DateReportService.php');
$check(str_contains($dateReport, 'int $limit = 500'), 'La previsualización fiscal debe conservar su límite contractual de 500.');
$check(str_contains($dateReport, 'base.meli_account_id ASC') && str_contains($dateReport, 'base.external_item_id ASC'), 'La paginación de rentabilidad necesita desempate estable por cuenta y publicación.');

$migration = (string) file_get_contents($root . '/database/migrations/202_progressive_human_modules_2_28_22.sql');
$check(str_contains($migration, "'app.version','2.28.22'"), 'La migración 202 no registra la versión.');
$check(!preg_match('/\b(DELETE|TRUNCATE|DROP)\b/i', $migration), 'La migración 202 contiene una operación destructiva.');

if ($failures !== []) {
    fwrite(STDERR, "progressive_operations_22822: FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "progressive_operations_22822: OK\n";
