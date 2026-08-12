<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('ASSERT_FAILED:' . $label);
    }
};
$read = static function (string $path) use ($root): string {
    $bytes = file_get_contents($root . '/' . $path);
    if (!is_string($bytes)) {
        throw new RuntimeException('READ_FAILED:' . $path);
    }
    return $bytes;
};

try {
    $assert(trim($read('VERSION')) === '2.38.0', 'version');
    $migration = $read('database/migrations/295_inventory_warehouse_v1_2_38_0.sql');
    foreach (['inventory_warehouses', 'inventory_balances', 'inventory_movements', 'inventory_reviews'] as $table) {
        $assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table), 'migration_table:' . $table);
    }
    $assert(str_contains($migration, 'DECIMAL(20,6)'), 'decimal_authority');
    $assert(str_contains($migration, 'uq_inventory_movement_idempotency'), 'idempotency_unique');
    $assert(str_contains($migration, 'uq_inventory_movement_reversal'), 'reversal_unique');
    $assert(str_contains($migration, 'uq_inventory_warehouse_default'), 'default_unique');
    $assert(str_contains($migration, 'ENGINE=InnoDB'), 'transactional_engine');

    $sync = $read('app/Services/OrderSyncService.php');
    $assert(substr_count($sync, 'new OrderInventoryService') === 1, 'single_inventory_hook');
    $hook = strpos($sync, 'new OrderInventoryService');
    $persist = strpos($sync, 'persistOrder(', strpos($sync, 'syncOrderByIdForQueueV4Clean'));
    $assert($persist !== false && $hook !== false && $hook > $persist, 'hook_after_order_persistence');
    $assert(!str_contains($read('app/Services/OrderInventoryService.php'), 'MeliApi'), 'no_inventory_http');
    $assert(!str_contains($read('app/Services/OrderInventoryService.php'), 'external_pack_id='), 'no_pack_projection');

    $ledger = $read('app/Services/InventoryLedgerService.php');
    $assert(str_contains($ledger, 'FOR UPDATE'), 'balance_row_lock');
    $assert(str_contains($ledger, 'DECIMAL(20,6)'), 'db_decimal_math');
    $assert(!preg_match('/\(float\)|floatval\s*\(/i', $ledger), 'no_float_business_math');

    $controller = $read('app/Controllers/InventoryController.php');
    $assert(str_contains($controller, 'SameOriginGuard::assertRequest(true)'), 'same_origin');
    $assert(str_contains($controller, 'Csrf::validate'), 'csrf');
    $application = $read('app/Services/InventoryApplicationService.php');
    $warehouse = $read('app/Services/InventoryWarehouseService.php');
    $assert(str_contains($application, "requireRole('admin', 'operador')"), 'manual_roles');
    $assert(str_contains($application, 'Auth::isTemporary()'), 'manual_no_temporary');
    $assert(str_contains($warehouse, 'Auth::isTemporary()'), 'warehouse_no_temporary');
    $assert(str_contains($application, 'AuditService::record'), 'movement_audit');
    $assert(str_contains($warehouse, 'AuditService::record'), 'warehouse_audit');

    $routes = $read('public/index.php');
    foreach (['/inventory', '/inventory/kardex', '/inventory/movements', '/inventory/reviews/retry'] as $route) {
        $assert(str_contains($routes, "'" . $route . "'"), 'route:' . $route);
    }
    $view = $read('app/Views/inventory/index.php');
    $assert(str_contains($view, 'name="request_id"'), 'manual_idempotency_field');
    $assert(str_contains($view, 'name="_token"'), 'inventory_form_csrf');

    $all = $migration . $sync . $ledger . $application . $warehouse . $controller;
    $assert(!str_contains(strtolower($all), 'storage/raw'), 'raw_storage_not_referenced');
    $assert(!preg_match('/(?:curl_|\/users\/me|api\.mercadolibre)/i', $all), 'no_meli_http');

    echo json_encode([
        'ok' => true,
        'checks' => $checks,
        'queue_v4_hook' => 1,
        'meli_http_calls' => 0,
        'raw_storage_touched' => false,
    ], JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Inventory contract 2.38.0: FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
