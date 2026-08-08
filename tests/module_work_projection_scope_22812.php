<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$runner = (string) file_get_contents($root . '/app/Core/Modules/ModuleJobRunner.php');
$check(str_contains($runner, 'enqueueScoped('), 'El alta modular debe ofrecer scope explícito.');
$check(str_contains($runner, 'transitionScoped('), 'Las transiciones modulares deben usar un método scoped.');
$check(str_contains($runner, 'a.id=j.meli_account_id AND a.company_id=?'), 'La transición debe comprobar empresa y cuenta del job.');
$check(str_contains($runner, 'j.meli_account_id=?'), 'Una cuenta distinta de la misma empresa no debe modificar el job.');

foreach ([
    '/app/Modules/Shared/Controllers/ModuleDashboardController.php',
    '/app/Modules/MeliInsights/Controllers/DashboardController.php',
    '/app/Modules/MeliGrowth/Controllers/DashboardController.php',
] as $file) {
    $source = (string) file_get_contents($root . $file);
    $check(str_contains($source, 'BusinessScopeContext'), basename($file) . ' debe validar el acceso empresarial.');
    $check(str_contains($source, 'enqueueScoped('), basename($file) . ' debe encolar con empresa y cuenta exactas.');
}

foreach ([
    '/app/Modules/MeliInsights/Controllers/DashboardController.php',
    '/app/Modules/MeliGrowth/Controllers/DashboardController.php',
] as $file) {
    $source = (string) file_get_contents($root . $file);
    foreach (['pauseScoped(', 'resumeScoped(', 'cancelScoped(', 'retryScoped('] as $method) {
        $check(str_contains($source, $method), basename($file) . ' debe usar ' . $method . ' para impedir IDs cruzados.');
    }
}

$projection = (string) file_get_contents($root . '/app/Services/WorkQueueProjectionService.php');
$check(str_contains($projection, 'accountIds($userId)'), 'La proyección debe obtener las cuentas autorizadas además de empresas.');
$check(str_contains($projection, '.meli_account_id IN ('), 'find/page/detail deben filtrar meli_account_id cuando exista.');
$check(str_contains($projection, '->account((int) $filters[\'account_id\'])'), 'Un filtro de cuenta ajena debe fallar cerrado.');

$logs = (string) file_get_contents($root . '/app/Controllers/LogController.php');
$check(str_contains($logs, "'user' => Auth::id()") && str_contains($logs, "'scope' => hash("), 'La caché de logs debe variar por usuario y scope de cuentas.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL {$failure}\n");
    }
    exit(1);
}

echo "PASS module_work_projection_scope_22812\n";
