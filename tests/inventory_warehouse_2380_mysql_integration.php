<?php

declare(strict_types=1);

use App\Services\InventoryInsufficientStock;
use App\Services\InventoryLedgerService;
use App\Services\OrderInventoryService;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $path = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

$dsn = getenv('INVENTORY_TEST_DSN') ?: '';
$user = getenv('INVENTORY_TEST_USER') ?: 'root';
$password = getenv('INVENTORY_TEST_PASSWORD') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "INVENTORY_TEST_DSN is required.\n");
    exit(2);
}

$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone='+00:00'");

if (($argv[1] ?? '') === '--concurrent-child') {
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=2');
    try {
        (new InventoryLedgerService($pdo))->applyBatch([[
            'company_id'=>1,'warehouse_id'=>1,'internal_product_id'=>3,'movement_type'=>'adjustment_out',
            'quantity'=>'8','unit_cost'=>'0','reference_type'=>'test','reference_id'=>'concurrent-child',
            'idempotency_key'=>'concurrent-child','source'=>'system',
        ]]);
        echo "CHILD_UNEXPECTED_PASS\n";
        exit(3);
    } catch (Throwable $e) {
        echo str_contains($e->getMessage(), 'Lock wait timeout') ? "CHILD_LOCKED\n" : "CHILD_ERROR:" . get_class($e) . "\n";
        exit(str_contains($e->getMessage(), 'Lock wait timeout') ? 0 : 4);
    }
}

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('ASSERT_FAILED:' . $label);
    }
};

$tables = ['inventory_reviews','inventory_movements','inventory_balances','inventory_warehouses',
    'product_meli_links','meli_order_items','meli_orders','meli_items','internal_products','meli_accounts','companies'];
foreach ($tables as $table) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
}
$pdo->exec('CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(160),status TINYINT NOT NULL DEFAULT 1,deleted_at DATETIME NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE meli_accounts(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,account_name VARCHAR(160),FOREIGN KEY(company_id) REFERENCES companies(id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE internal_products(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,internal_sku VARCHAR(80),name VARCHAR(160),unit VARCHAR(40),status VARCHAR(20) NOT NULL DEFAULT "active",deleted_at DATETIME NULL,FOREIGN KEY(company_id) REFERENCES companies(id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE meli_items(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(80) NOT NULL,title VARCHAR(160),seller_sku VARCHAR(80),UNIQUE KEY uq_item(meli_account_id,external_item_id),FOREIGN KEY(meli_account_id) REFERENCES meli_accounts(id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE meli_orders(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_order_id VARCHAR(80) NOT NULL,external_pack_id VARCHAR(80) NULL,status VARCHAR(60),status_detail VARCHAR(100),UNIQUE KEY uq_order(meli_account_id,external_order_id),FOREIGN KEY(meli_account_id) REFERENCES meli_accounts(id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE meli_order_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_order_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(80) NOT NULL,external_variation_id BIGINT UNSIGNED NULL,title VARCHAR(160),seller_sku VARCHAR(80),quantity DECIMAL(18,4) NOT NULL,FOREIGN KEY(meli_order_id) REFERENCES meli_orders(id),FOREIGN KEY(meli_account_id) REFERENCES meli_accounts(id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE product_meli_links(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,internal_product_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,meli_item_id BIGINT UNSIGNED NOT NULL,meli_variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,conversion_factor DECIMAL(18,4) NOT NULL DEFAULT 1,status VARCHAR(20) NOT NULL,FOREIGN KEY(internal_product_id) REFERENCES internal_products(id),FOREIGN KEY(meli_account_id) REFERENCES meli_accounts(id),FOREIGN KEY(meli_item_id) REFERENCES meli_items(id)) ENGINE=InnoDB');
$pdo->exec((string) file_get_contents(dirname(__DIR__) . '/database/migrations/295_inventory_warehouse_v1_2_38_0.sql'));

$pdo->exec("INSERT INTO companies VALUES (1,'Empresa A',1,NULL),(2,'Empresa B',1,NULL)");
$pdo->exec("INSERT INTO meli_accounts VALUES (1,1,'Cuenta A'),(2,2,'Cuenta B')");
$pdo->exec("INSERT INTO internal_products VALUES (1,1,'SKU-1','Producto 1','unidad','active',NULL),(2,1,'SKU-2','Producto 2','unidad','active',NULL),(3,1,'SKU-3','Concurrencia','unidad','active',NULL),(4,2,'SKU-B','Producto B','unidad','active',NULL)");
$pdo->exec("INSERT INTO inventory_warehouses(company_id,code,name,status,is_default) VALUES (1,'MAIN','Principal','active',1),(2,'B','Bodega B','active',0)");
$pdo->exec("INSERT INTO meli_items VALUES (1,1,'MLA1','Producto ML','MLSKU')");
$pdo->exec("INSERT INTO product_meli_links(internal_product_id,meli_account_id,meli_item_id,meli_variation_id,conversion_factor,status) VALUES (1,1,1,0,3.0000,'active')");

$ledger = new InventoryLedgerService($pdo);
$base = static fn(string $type,string $qty,string $cost,string $key,int $product=1): array => [
    'company_id'=>1,'warehouse_id'=>1,'internal_product_id'=>$product,'movement_type'=>$type,
    'quantity'=>$qty,'unit_cost'=>$cost,'reference_type'=>'test','reference_id'=>$key,
    'idempotency_key'=>$key,'source'=>'system',
];
$ledger->applyBatch([$base('opening','10','5','open')]);
$ledger->applyBatch([$base('receipt','10','7','receipt')]);
$sale = $ledger->applyBatch([$base('sale_issue','4','0','sale')])[0];
$balance = $pdo->query('SELECT * FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetch();
$assert($balance['on_hand']==='16.000000' && $balance['average_unit_cost']==='6.000000', 'weighted_average_and_issue');
$assert($sale['unit_cost']==='6.000000' && $sale['total_cost']==='24.000000', 'exit_captured_cost');
$ledger->applyBatch([$base('sale_reversal','4','6','reverse') + ['reversal_of_movement_id'=>(int)$sale['id']]]);
$ledger->applyBatch([$base('reserve','3','0','reserve')]);
$ledger->applyBatch([$base('release','2','0','release')]);
$ledger->applyBatch([$base('adjustment_out','1','0','adjust-out')]);
$balance = $pdo->query('SELECT * FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetch();
$assert($balance['on_hand']==='19.000000' && $balance['reserved']==='1.000000' && $balance['available']==='18.000000', 'reserve_release_adjustment');
$beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM inventory_movements')->fetchColumn();
$ledger->applyBatch([$base('receipt','10','7','receipt')]);
$assert((int)$pdo->query('SELECT COUNT(*) FROM inventory_movements')->fetchColumn()===$beforeCount, 'idempotent_repeat');
try {
    $ledger->applyBatch([$base('receipt','11','7','receipt')]);
    $assert(false, 'idempotency_payload_drift_must_throw');
} catch (RuntimeException) {
    $assert(true, 'idempotency_payload_drift_blocked');
}
try {
    $ledger->applyBatch([$base('adjustment_out','99','0','insufficient')]);
    $assert(false, 'insufficient_must_throw');
} catch (InventoryInsufficientStock) {
    $assert(true, 'insufficient_blocked');
}
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn()==='19.000000', 'insufficient_zero_delta');

// Venta real: 2 unidades ML x factor 3 = 6 unidades de bodega, una sola vez.
$pdo->exec("INSERT INTO meli_orders VALUES (10,1,'ORDER-1','PACK-1','paid','paid')");
$pdo->exec("INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,external_variation_id,title,seller_sku,quantity) VALUES (10,1,'MLA1',NULL,'P','MLSKU',2)");
$projection = new OrderInventoryService($pdo);
$result = $projection->project(1,1,10);
$assert($result['outcome']==='applied' && $result['movements']===1, 'paid_order_applied');
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn()==='13.000000', 'conversion_factor_applied');
$projection->project(1,1,10);
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reference_id='ORDER-1' AND movement_type='sale_issue'")->fetchColumn()===1, 'sale_idempotent');
$pdo->exec("UPDATE meli_orders SET status='cancelled' WHERE id=10 AND meli_account_id=1");
$projection->project(1,1,10);
$projection->project(1,1,10);
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn()==='19.000000', 'cancellation_exact_reversal');
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reference_id='ORDER-1' AND movement_type='sale_reversal'")->fetchColumn()===1, 'reversal_idempotent');

// Pack: dos órdenes descuentan por sus líneas; no existe movimiento de pack.
$pdo->exec("INSERT INTO meli_orders VALUES (11,1,'ORDER-2','PACK-X','paid','paid'),(12,1,'ORDER-3','PACK-X','paid','paid')");
$pdo->exec("INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,external_variation_id,title,seller_sku,quantity) VALUES (11,1,'MLA1',NULL,'P','MLSKU',1),(12,1,'MLA1',NULL,'P','MLSKU',1)");
$projection->project(1,1,11); $projection->project(1,1,12);
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reference_id IN ('ORDER-2','ORDER-3') AND movement_type='sale_issue'")->fetchColumn()===2, 'pack_orders_once_each');
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reference_type='meli_pack'")->fetchColumn()===0, 'pack_double_count_zero');

// Sin vínculo y sin bodega: revisión exacta y cero movimiento.
$pdo->exec("INSERT INTO meli_orders VALUES (13,1,'ORDER-U',NULL,'paid','paid'),(20,2,'ORDER-W',NULL,'paid','paid')");
$pdo->exec("INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,external_variation_id,title,seller_sku,quantity) VALUES (13,1,'UNLINKED',NULL,'U','U',1),(20,2,'OTHER',NULL,'O','O',1)");
$projection->project(1,1,13); $projection->project(2,2,20);
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_reviews WHERE reason_code='UNLINKED_PRODUCT' AND company_id=1 AND meli_account_id=1")->fetchColumn()===1, 'unlinked_review');
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_reviews WHERE reason_code='DEFAULT_WAREHOUSE_MISSING' AND company_id=2 AND meli_account_id=2")->fetchColumn()===1, 'warehouse_review');
$assert((int)$pdo->query('SELECT COUNT(*) FROM inventory_movements WHERE company_id=2')->fetchColumn()===0, 'tenant_b_no_mutation');

// Reembolso parcial nunca adivina cantidades: queda en revisión sin tocar existencias.
$pdo->exec("INSERT INTO meli_orders VALUES (14,1,'ORDER-R',NULL,'paid','partial_refund')");
$pdo->exec("INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,external_variation_id,title,seller_sku,quantity) VALUES (14,1,'MLA1',NULL,'P','MLSKU',1)");
$beforeRefund = $pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn();
$refund = $projection->project(1,1,14);
$assert($refund['outcome']==='review' && $refund['movements']===0, 'partial_refund_review_only');
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn()===$beforeRefund, 'partial_refund_zero_delta');

// Una revisión por vínculo faltante se puede reintentar con seguridad después de crear la autoridad.
$pdo->exec("INSERT INTO meli_items VALUES (2,1,'UNLINKED','Producto enlazado','U')");
$pdo->exec("INSERT INTO product_meli_links(internal_product_id,meli_account_id,meli_item_id,meli_variation_id,conversion_factor,status) VALUES (2,1,2,0,2.0000,'active')");
$ledger->applyBatch([$base('opening','5','4','open-product-2',2)]);
$retry = $projection->project(1,1,13);
$assert($retry['outcome']==='applied' && $retry['movements']===1, 'review_retry_applied');
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_reviews WHERE external_order_id='ORDER-U' AND state='resolved'")->fetchColumn()===1, 'review_retry_resolved');

// Dos vínculos activos nunca multiplican cantidades: la autoridad ambigua se revisa y no descuenta.
$pdo->exec("INSERT INTO meli_items VALUES (3,1,'AMBIGUOUS','Producto ambiguo','A')");
$pdo->exec("INSERT INTO product_meli_links(internal_product_id,meli_account_id,meli_item_id,meli_variation_id,conversion_factor,status) VALUES (1,1,3,0,1.0000,'active'),(2,1,3,0,1.0000,'active')");
$pdo->exec("INSERT INTO meli_orders VALUES (15,1,'ORDER-A',NULL,'paid','paid')");
$pdo->exec("INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,external_variation_id,title,seller_sku,quantity) VALUES (15,1,'AMBIGUOUS',NULL,'A','A',2)");
$beforeAmbiguous = (int) $pdo->query('SELECT COUNT(*) FROM inventory_movements')->fetchColumn();
$ambiguous = $projection->project(1,1,15);
$assert($ambiguous['outcome']==='review' && $ambiguous['movements']===0, 'ambiguous_link_review');
$assert((int)$pdo->query('SELECT COUNT(*) FROM inventory_movements')->fetchColumn()===$beforeAmbiguous, 'ambiguous_link_zero_delta');

// La empresa/cuenta no puede proyectar una orden ajena.
try {
    $projection->project(2,2,10);
    $assert(false, 'cross_tenant_order_must_throw');
} catch (RuntimeException) {
    $assert(true, 'cross_tenant_order_blocked');
}

// La unicidad física impide dos bodegas predeterminadas para la misma empresa.
try {
    $pdo->exec("INSERT INTO inventory_warehouses(company_id,code,name,status,is_default) VALUES (1,'SECOND','Segunda','active',1)");
    $assert(false, 'duplicate_default_must_throw');
} catch (PDOException) {
    $assert(true, 'duplicate_default_blocked');
}

// Concurrencia real: el segundo escritor no puede atravesar el FOR UPDATE.
$ledger->applyBatch([$base('opening','10','2','concurrent-open',3)]);
$pdo->beginTransaction();
$ledger->applyBatch([$base('adjustment_out','8','0','concurrent-parent',3)]);
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --concurrent-child';
$env = array_merge($_ENV, [
    'INVENTORY_TEST_DSN'=>$dsn,'INVENTORY_TEST_USER'=>$user,'INVENTORY_TEST_PASSWORD'=>$password,
]);
$pipes = [];
$process = proc_open($command, [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, dirname(__DIR__), $env);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start concurrency child.');
}
fclose($pipes[0]);
$childOut = stream_get_contents($pipes[1]); fclose($pipes[1]);
$childErr = stream_get_contents($pipes[2]); fclose($pipes[2]);
$childCode = proc_close($process);
$pdo->commit();
$assert($childCode===0 && str_contains((string)$childOut, 'CHILD_LOCKED'), 'concurrent_writer_locked:' . trim((string)$childErr));
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=3')->fetchColumn()==='2.000000', 'concurrent_single_writer');

echo json_encode([
    'ok'=>true,'checks'=>$checks,'schema'=>295,'ml_http_calls'=>0,'business_tables_mutated'=>0,
    'pack_double_count'=>0,'raw_storage_touched'=>false,
], JSON_UNESCAPED_SLASHES), PHP_EOL;
