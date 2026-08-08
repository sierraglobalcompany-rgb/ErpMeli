<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\SaleFinancialStateService;

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$migration = $read('database/migrations/242_financial_state_v3_2_29_0.sql');
$orderSync = $read('app/Services/OrderSyncService.php');
$notifications = $read('app/Services/NotificationWorkItemService.php');
$saleFinancial = $read('app/Services/SaleFinancialService.php');
$recalc = $read('app/Services/OrderFinancialRecalcJobService.php');
$saleRead = $read('app/Services/SaleReadService.php');
$controller = $read('app/Controllers/SaleController.php');
$view = $read('app/Views/sales/show.php');
$gap = $read('app/Services/FinancialGapScanService.php');
$billingHandler = $read('app/Services/CronV3Handlers/SaleBillingCaptureHandler.php');

foreach ([
    'sale_financial_state',
    'sale_financial_evidence',
    'financial_gap_scan_state',
    'input_version',
    'uq_sale_financial_job_v3',
    "('sales_financial.daily_capture_enabled','0'",
    "('financial_recalc.auto_billing_for_missing','0'",
] as $needle) {
    $assert(str_contains($migration, $needle), 'Migración V3 incompleta: ' . $needle);
}

$assert(str_contains($orderSync, 'SaleFinancialStateService())->projectOrder'), 'La orden debe proyectarse al persistirse.');
$assert(str_contains($orderSync, "'order_persisted'"), 'La orden debe programar la captura oficial exacta.');
$assert(!str_contains($notifications, 'OrderFinancialRecalcJobService'), 'Notificaciones no pueden crear la segunda ruta financiera.');
$assert(str_contains($notifications, 'refreshFinancialForShipment'), 'Un cambio logístico debe invalidar/proyectar la versión financiera.');
$assert(substr_count($saleFinancial, '/billing/integration/group/ML/order/details') === 1, 'Billing debe tener una sola autoridad remota.');
$assert(!str_contains($saleFinancial, 'enqueueDailyDue'), 'No puede existir recaptura diaria.');
$assert(str_contains($saleFinancial, 'input_version'), 'La captura oficial debe estar cercada por input_version.');
$assert(str_contains($saleFinancial, "'http_429'") && str_contains($billingHandler, "'http_429'"), 'Billing 429 must reach the Cron V3 circuit policy.');
$assert(!str_contains($recalc, 'OrderBillingImportService'), 'financial_recalc debe ser exclusivamente local.');
$assert(!str_contains($recalc, 'MeliApiClient'), 'financial_recalc no puede crear transporte ML.');
$assert(str_contains($recalc, "'remote_transport' => false"), 'El snapshot del recálculo debe declarar transporte remoto deshabilitado.');
$assert(str_contains($gap, 'min(50, $readLimit)'), 'El scanner debe leer máximo 50 ventas.');
$assert(str_contains($gap, 'min(20, $enqueueLimit)'), 'El scanner debe encolar máximo 20 trabajos.');
$assert(str_contains($saleRead, 'financial_state'), 'La lectura de venta debe incluir el estado canónico.');
$assert(str_contains($saleRead, 'FinancialCompletenessService'), 'La lectura debe incluir diagnóstico local.');
$assert(!str_contains(substr($controller, strpos($controller, 'public function show'), 350), 'projectSale'), 'GET venta debe ser read-only.');
$assert(str_contains($controller, 'un único paso financiero exacto'), 'El botón debe preparar un único paso.');
foreach (['Comercial', 'Logística', 'Provisional', 'Oficial', 'Neto provisional local', 'Neto oficial Mercado Libre'] as $label) {
    $assert(str_contains($view, $label), 'Vista financiera incompleta: ' . $label);
}

$snapshotA = [
    'orders' => [['id' => '1', 'paid' => '100.00']],
    'payments' => [['id' => '9', 'amount' => '100.00']],
    'meta' => ['account' => 2, 'company' => 1],
];
$snapshotB = [
    'meta' => ['company' => 1, 'account' => 2],
    'payments' => [['amount' => '100.00', 'id' => '9']],
    'orders' => [['paid' => '100.00', 'id' => '1']],
];
$snapshotChanged = $snapshotB;
$snapshotChanged['payments'][0]['amount'] = '99.00';
$versionA = SaleFinancialStateService::inputVersionFromSnapshot($snapshotA);
$versionB = SaleFinancialStateService::inputVersionFromSnapshot($snapshotB);
$versionChanged = SaleFinancialStateService::inputVersionFromSnapshot($snapshotChanged);
$assert(preg_match('/^[a-f0-9]{64}$/', $versionA) === 1, 'input_version debe ser SHA-256.');
$assert(hash_equals($versionA, $versionB), 'El hash debe ser estable ante el orden de claves.');
$assert(!hash_equals($versionA, $versionChanged), 'El hash debe cambiar cuando cambia la evidencia.');

echo "financial_state_v3_2290_ok\n";
