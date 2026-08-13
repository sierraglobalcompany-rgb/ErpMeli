<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$expectedVersion = trim((string) (getenv('ERP_INVENTORY_CONTRACT_VERSION') ?: '2.38.4'));
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
    $assert(trim($read('VERSION')) === $expectedVersion, 'version');
    $migration = $read('database/migrations/295_inventory_warehouse_v1_2_38_0.sql');
    foreach (['inventory_warehouses', 'inventory_balances', 'inventory_movements', 'inventory_reviews'] as $table) {
        $assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table), 'migration_table:' . $table);
    }
    $assert(str_contains($migration, 'DECIMAL(20,6)'), 'decimal_authority');
    $assert(str_contains($migration, 'uq_inventory_movement_idempotency'), 'idempotency_unique');
    $assert(str_contains($migration, 'uq_inventory_movement_reversal'), 'reversal_unique');
    $assert(str_contains($migration, 'uq_inventory_warehouse_default'), 'default_unique');
    $assert(str_contains($migration, 'ENGINE=InnoDB'), 'transactional_engine');
    $assert(str_contains($migration, "SELECT 'inventory_pending_floor' AS producer_key")
        && str_contains($migration, "SELECT 'inventory_pending_cursor'"), 'migration_cutover_checkpoint_pair');
    $assert(str_contains($migration, "rr.state='CERTIFIED'")
        && str_contains($migration, "ra.outcome='PASS'")
        && str_contains($migration, 'INSERT IGNORE INTO queue_v4_clean_checkpoints'), 'migration_cutover_certified_fail_closed');

    $sync = $read('app/Services/OrderSyncService.php');
    $assert(substr_count($sync, 'new OrderInventoryService') === 1, 'single_inventory_hook');
    $hook = strpos($sync, 'new OrderInventoryService');
    $persist = strpos($sync, 'persistOrder(', strpos($sync, 'syncOrderByIdForQueueV4Clean'));
    $assert($persist !== false && $hook !== false && $hook > $persist, 'hook_after_order_persistence');
    $assert(!str_contains($read('app/Services/OrderInventoryService.php'), 'MeliApi'), 'no_inventory_http');
    $orderInventory = $read('app/Services/OrderInventoryService.php');
    $assert(!str_contains($orderInventory, 'external_pack_id='), 'no_pack_projection');
    $assert(str_contains($orderInventory, 'existingSaleIssues') && str_contains($orderInventory, 'FOR UPDATE'), 'immutable_order_snapshot_and_mutex');
    $assert(str_contains($orderInventory, 'beginTransaction()') && str_contains($orderInventory, 'projectLocked'), 'order_projection_atomic_transaction');

    $ledger = $read('app/Services/InventoryLedgerService.php');
    $assert(str_contains($ledger, 'FOR UPDATE'), 'balance_row_lock');
    $assert(str_contains($ledger, 'w.status="active" AND p.status="active"'), 'active_entity_gate');
    $assert(str_contains($ledger, "['sale_reversal', 'release']"), 'inactive_compensation_allowlist');
    $assert(str_contains($ledger, 'DECIMAL(20,6)'), 'db_decimal_math');
    $assert(!preg_match('/\(float\)|floatval\s*\(/i', $ledger), 'no_float_business_math');
    $assert(str_contains($ledger, 'Every physical exit is valued at the locked moving average'), 'outflow_cost_authority');

    $controller = $read('app/Controllers/InventoryController.php');
    $assert(str_contains($controller, 'SameOriginGuard::assertRequest(true)'), 'same_origin');
    $assert(str_contains($controller, 'Csrf::validate'), 'csrf');
    $application = $read('app/Services/InventoryApplicationService.php');
    $warehouse = $read('app/Services/InventoryWarehouseService.php');
    $assert(str_contains($application, "requireRole('admin', 'operador')"), 'manual_roles');
    $assert(str_contains($application, 'Auth::isTemporary()'), 'manual_no_temporary');
    $assert(str_contains($warehouse, 'Auth::isTemporary()'), 'warehouse_no_temporary');
    $assert(str_contains($application, 'AuditService::record'), 'movement_audit');
    $assert(str_contains($application, 'dismissReview'), 'review_dismiss_authority');
    $assert(str_contains($application, 'lockReviewOrder') && str_contains($application, 'ORDER BY id FOR UPDATE'), 'review_retry_row_lock');
    $assert(str_contains($application, 'FROM meli_orders o') && str_contains($application, 'o.meli_account_id=? FOR UPDATE'), 'review_order_mutex');
    $assert(str_contains($application, 'El motivo del movimiento es obligatorio.'), 'manual_reason_backend_gate');
    $assert(str_contains($warehouse, 'AuditService::record'), 'warehouse_audit');
    $queryService = $read('app/Services/InventoryQueryService.php');
    $assert(str_contains($queryService, 'movement_scope_account_'), 'kardex_account_scope_always_on');
    $assert(str_contains($queryService, 'm.meli_account_id IS NULL OR m.meli_account_id IN'), 'kardex_manual_or_authorized_account');
    $linkService = $read('app/Services/ProductLinkService.php');
    $assert(substr_count($linkService, '$factor < 0.0001') === 2, 'conversion_factor_quantized_gate');
    $assert(str_contains($linkService, 'p.company_id=a.company_id') && str_contains($linkService, 'i.meli_account_id=l.meli_account_id'), 'product_link_tenant_joins');
    $assert(str_contains($linkService, 'GROUP BY meli_account_id,meli_item_id'), 'linked_state_account_scope');
    $assert(str_contains($linkService, 'FOR UPDATE') && str_contains($linkService, 'beginTransaction()'), 'product_link_concurrency_mutex');

    $routes = $read('public/index.php');
    foreach (['/inventory', '/inventory/kardex', '/inventory/movements', '/inventory/reviews/retry', '/inventory/reviews/dismiss'] as $route) {
        $assert(str_contains($routes, "'" . $route . "'"), 'route:' . $route);
    }
    $view = $read('app/Views/inventory/index.php');
    $assert(str_contains($view, 'name="request_id"'), 'manual_idempotency_field');
    $assert(str_contains($view, 'name="_token"'), 'inventory_form_csrf');
    $assert(str_contains($view, 'Cerrar sin movimiento'), 'review_dismiss_ui');

    $all = $migration . $sync . $ledger . $application . $warehouse . $controller . $queryService . $linkService;
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
    fwrite(STDERR, 'Inventory contract ' . $expectedVersion . ': FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
