<?php
declare(strict_types=1);

require __DIR__ . '/cap2_manual_fixture.php';

use App\Services\Cap2DomainsWire;
use App\Services\OrderBillingImportService;

$assert = static function (bool $condition, string $label, array $context = []): void {
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $label . ':' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
    echo 'PASS:' . $label . PHP_EOL;
};

Cap2DomainsWire::$calls = [];
$empty = (new OrderBillingImportService())->importForOrderIds(9001, 9011, []);
$assert($empty === ['requested' => 0, 'imported_orders' => 0, 'billing_rows' => 0, 'skipped' => 0, 'errors' => 0], 'empty_import_remains_noop');
$assert(Cap2DomainsWire::$calls === [], 'empty_import_sends_zero_http');

$error = null;
try {
    (new OrderBillingImportService())->importForOrderIds(9001, 9011, [1, 2, 3], true);
} catch (Throwable $caught) {
    $error = $caught;
}

$assert($error instanceof RuntimeException, 'grouped_billing_import_rejects_explicit_execution');
$assert(str_contains($error->getMessage(), 'un order_id por llamada física'), 'grouped_billing_import_reports_single_call_contract');
$assert(Cap2DomainsWire::$calls === [], 'retired_grouped_import_sends_zero_http');

echo "STATUS=PASS CALLS_V2_PHASE5_BILLING_IMPORTER_RETIRED REAL_MELI_HTTP=0\n";
