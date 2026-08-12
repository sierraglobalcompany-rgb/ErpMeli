<?php

declare(strict_types=1);

use App\Services\InventoryInsufficientStock;
use App\Services\InventoryLedgerService;
use App\Services\InventoryApplicationService;
use App\Services\InventoryWarehouseService;
use App\Services\InventoryQueryService;
use App\Services\ProductLinkService;
use App\Services\OrderInventoryService;
use App\Services\MeliReadClientInterface;
use App\Core\Database;
use App\Core\Session;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\QueueV4Clean\QueueV4CleanProducer;

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
        echo str_contains($e->getMessage(), 'Lock wait timeout') ? "CHILD_LOCKED\n" : "CHILD_ERROR:" . get_class($e) . ':' . $e->getMessage() . "\n";
        exit(str_contains($e->getMessage(), 'Lock wait timeout') ? 0 : 4);
    }
}

if (($argv[1] ?? '') === '--review-dismiss-child') {
    $reviewId = (int) ($argv[2] ?? 0);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=2');
    Database::setConnection($pdo);
    Session::put('user', [
        'id'=>1,'name'=>'Admin local','email'=>'admin@local.invalid','role'=>'admin','is_temporary'=>0,
        'expires_at'=>null,'session_generation'=>(new App\Services\SessionGenerationService())->current(),
    ]);
    try {
        (new InventoryApplicationService())->dismissReview($reviewId, 'Cierre concurrente de laboratorio');
        echo "CHILD_DISMISSED\n";
        exit(0);
    } catch (App\Core\HttpException $e) {
        echo "CHILD_ALREADY_TERMINAL\n";
        exit(0);
    } catch (Throwable $e) {
        echo str_contains($e->getMessage(), 'Lock wait timeout') ? "CHILD_LOCKED\n" : "CHILD_ERROR:" . get_class($e) . ':' . $e->getMessage() . "\n";
        exit(str_contains($e->getMessage(), 'Lock wait timeout') ? 0 : 6);
    }
}

if (($argv[1] ?? '') === '--review-retry-child') {
    $reviewId = (int) ($argv[2] ?? 0);
    Database::setConnection($pdo);
    Session::put('user', [
        'id'=>1,'name'=>'Admin local','email'=>'admin@local.invalid','role'=>'admin','is_temporary'=>0,
        'expires_at'=>null,'session_generation'=>(new App\Services\SessionGenerationService())->current(),
    ]);
    try {
        $result = (new InventoryApplicationService())->retryReview($reviewId);
        echo json_encode(['ok'=>true,'result'=>$result], JSON_UNESCAPED_SLASHES), "\n";
        exit(0);
    } catch (Throwable $e) {
        echo "CHILD_ERROR:" . get_class($e) . ':' . $e->getMessage() . "\n";
        exit(7);
    }
}

if (($argv[1] ?? '') === '--product-link-child') {
    $itemId = (int) ($argv[2] ?? 0);
    Database::setConnection($pdo);
    Session::put('user', [
        'id'=>1,'name'=>'Admin local','email'=>'admin@local.invalid','role'=>'admin','is_temporary'=>0,
        'expires_at'=>null,'session_generation'=>(new App\Services\SessionGenerationService())->current(),
    ]);
    try {
        (new ProductLinkService())->link(1, $itemId, 0, 1.0, 'manual');
        echo "CHILD_LINKED\n";
        exit(0);
    } catch (RuntimeException $error) {
        echo str_contains($error->getMessage(), 'ya tiene un vínculo activo') ? "CHILD_LINK_REJECTED\n" : "CHILD_ERROR:{$error->getMessage()}\n";
        exit(str_contains($error->getMessage(), 'ya tiene un vínculo activo') ? 0 : 8);
    } catch (Throwable $error) {
        echo 'CHILD_ERROR:' . get_class($error) . ':' . $error->getMessage() . "\n";
        exit(9);
    }
}

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('ASSERT_FAILED:' . $label);
    }
};

$tables = ['user_meli_account_access','queue_v4_clean_attempts','queue_v4_clean_readiness_accounts','queue_v4_clean_jobs','queue_v4_clean_runs','queue_v4_clean_checkpoints','queue_v4_clean_leases','queue_v4_clean_readiness_runs','queue_v4_clean_control','app_settings','meli_tokens',
    'audit_logs','user_company_access','users','inventory_reviews','inventory_movements','inventory_balances','inventory_warehouses',
    'product_meli_links','meli_order_items','meli_orders','meli_items','internal_products','meli_accounts','companies'];
foreach ($tables as $table) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
}
$pdo->exec('CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(160),status TINYINT NOT NULL DEFAULT 1,deleted_at DATETIME NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(160),email VARCHAR(190),role VARCHAR(40),status TINYINT,is_temporary TINYINT DEFAULT 0) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE user_company_access(user_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(user_id,company_id),FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(company_id) REFERENCES companies(id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE user_meli_account_access(user_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(user_id,meli_account_id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE audit_logs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL,action VARCHAR(80),module VARCHAR(80),entity_type VARCHAR(80),entity_id BIGINT UNSIGNED NULL,meli_account_id BIGINT UNSIGNED NULL,ip_hash CHAR(64),before_json LONGTEXT NULL,after_json LONGTEXT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE meli_accounts(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,account_name VARCHAR(160),meli_user_id VARCHAR(80) NULL DEFAULT NULL,status VARCHAR(20) NOT NULL DEFAULT "connected",FOREIGN KEY(company_id) REFERENCES companies(id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE meli_tokens(meli_account_id BIGINT UNSIGNED PRIMARY KEY,FOREIGN KEY(meli_account_id) REFERENCES meli_accounts(id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE app_settings(setting_key VARCHAR(120) PRIMARY KEY,setting_value TEXT NULL,is_encrypted TINYINT NOT NULL DEFAULT 0,setting_group VARCHAR(80) NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE internal_products(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,internal_sku VARCHAR(80),name VARCHAR(160),unit VARCHAR(40),status VARCHAR(20) NOT NULL DEFAULT "active",deleted_at DATETIME NULL,FOREIGN KEY(company_id) REFERENCES companies(id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE meli_items(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(80) NOT NULL,title VARCHAR(160),seller_sku VARCHAR(80),UNIQUE KEY uq_item(meli_account_id,external_item_id),FOREIGN KEY(meli_account_id) REFERENCES meli_accounts(id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE meli_orders(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_order_id VARCHAR(80) NOT NULL,external_pack_id VARCHAR(80) NULL,status VARCHAR(60),status_detail VARCHAR(100),UNIQUE KEY uq_order(meli_account_id,external_order_id),FOREIGN KEY(meli_account_id) REFERENCES meli_accounts(id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE meli_order_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_order_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(80) NOT NULL,external_variation_id BIGINT UNSIGNED NULL,title VARCHAR(160),seller_sku VARCHAR(80),quantity DECIMAL(18,4) NOT NULL,FOREIGN KEY(meli_order_id) REFERENCES meli_orders(id),FOREIGN KEY(meli_account_id) REFERENCES meli_accounts(id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE product_meli_links(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,internal_product_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,meli_item_id BIGINT UNSIGNED NOT NULL,meli_variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,meli_variation_external_id BIGINT UNSIGNED NULL,meli_title_snapshot VARCHAR(160) NULL,meli_sku_snapshot VARCHAR(80) NULL,item_snapshot_json LONGTEXT NULL,conversion_factor DECIMAL(18,4) NOT NULL DEFAULT 1,status VARCHAR(20) NOT NULL,link_source VARCHAR(20) NOT NULL DEFAULT "manual",deactivated_at DATETIME NULL,FOREIGN KEY(internal_product_id) REFERENCES internal_products(id),FOREIGN KEY(meli_account_id) REFERENCES meli_accounts(id),FOREIGN KEY(meli_item_id) REFERENCES meli_items(id)) ENGINE=InnoDB');
$pdo->exec((string) file_get_contents(dirname(__DIR__) . '/database/migrations/294_queue_v4_clean_greenfield_2_37_0.sql'));
$pdo->exec((string) file_get_contents(dirname(__DIR__) . '/database/migrations/295_inventory_warehouse_v1_2_38_0.sql'));

$pdo->exec("INSERT INTO companies VALUES (1,'Empresa A',1,NULL),(2,'Empresa B',1,NULL),(3,'Empresa C',1,NULL)");
$pdo->exec("INSERT INTO meli_accounts VALUES (1,1,'Cuenta A','1001','connected'),(2,2,'Cuenta B','1002','connected'),(3,3,'Cuenta C','1003','connected')");
$pdo->exec('INSERT INTO meli_tokens VALUES (1),(2),(3)');
$pdo->exec("INSERT INTO users VALUES (1,'Admin local','admin@local.invalid','admin',1,0)");
$pdo->exec("INSERT INTO user_company_access VALUES (1,1),(1,2)");
$pdo->exec('INSERT INTO user_meli_account_access VALUES (1,1),(1,2),(1,3)');
$pdo->exec("INSERT INTO internal_products VALUES (1,1,'SKU-1','Producto 1','unidad','active',NULL),(2,1,'SKU-2','Producto 2','unidad','active',NULL),(3,1,'SKU-3','Concurrencia','unidad','active',NULL),(4,2,'SKU-B','Producto B','unidad','active',NULL),(5,1,'SKU-N','Caso numérico','unidad','active',NULL),(6,1,'SKU-M','Operación manual','unidad','active',NULL)");
$pdo->exec("INSERT INTO inventory_warehouses(company_id,code,name,status,is_default) VALUES (1,'MAIN','Principal','active',1),(2,'B','Bodega B','active',0)");
$pdo->exec("INSERT INTO meli_items VALUES (1,1,'MLA1','Producto ML','MLSKU')");
$pdo->exec("INSERT INTO product_meli_links(internal_product_id,meli_account_id,meli_item_id,meli_variation_id,conversion_factor,status) VALUES (1,1,1,0,3.0000,'active')");

Database::setConnection($pdo);
Session::put('user', [
    'id'=>1,'name'=>'Admin local','email'=>'admin@local.invalid','role'=>'admin','is_temporary'=>0,
    'expires_at'=>null,'session_generation'=>(new App\Services\SessionGenerationService())->current(),
]);

// CRUD de bodega mediante el servicio real, con scope y auditoría.
$warehouseService = new InventoryWarehouseService();
$auxWarehouse = $warehouseService->create(['company_id'=>1,'code'=>'AUX','name'=>'Auxiliar']);
$warehouseService->setDefault($auxWarehouse);
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_warehouses WHERE company_id=1 AND is_default=1 AND id={$auxWarehouse}")->fetchColumn()===1, 'warehouse_default_service');
$warehouseService->setDefault(1);
$warehouseService->setStatus($auxWarehouse, 'inactive');
$assert($pdo->query("SELECT status FROM inventory_warehouses WHERE id={$auxWarehouse} AND company_id=1")->fetchColumn()==='inactive', 'warehouse_status_service');

// Operación manual real: apertura, entrada, ajustes, reserva y liberación.
$manual = new InventoryApplicationService();
$manualInput = static fn(string $type,string $quantity,string $cost,string $request): array => [
    'company_id'=>1,'warehouse_id'=>1,'internal_product_id'=>6,'movement_type'=>$type,
    'quantity'=>$quantity,'unit_cost'=>$cost,'request_id'=>$request,'reason'=>'Prueba local autorizada',
];
$manual->manualMovement($manualInput('opening','10','2','00000000000000000000000000000001'));
$manual->manualMovement($manualInput('receipt','5','4','00000000000000000000000000000002'));
$manual->manualMovement($manualInput('adjustment_in','2','3','00000000000000000000000000000003'));
$manual->manualMovement($manualInput('adjustment_out','1','0','00000000000000000000000000000004'));
$manual->manualMovement($manualInput('reserve','2','0','00000000000000000000000000000005'));
$manual->manualMovement($manualInput('release','1','0','00000000000000000000000000000006'));
$manualBalance = $pdo->query('SELECT on_hand,reserved,available FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=6')->fetch();
$assert($manualBalance['on_hand']==='16.000000' && $manualBalance['reserved']==='1.000000' && $manualBalance['available']==='15.000000', 'manual_movement_service');
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE internal_product_id=6 AND source='manual'")->fetchColumn()===6, 'manual_movement_types');
$assert((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE module='inventory'")->fetchColumn()>=8, 'inventory_audit_written');
try {
    $manual->manualMovement(array_replace(
        $manualInput('receipt','1','1','00000000000000000000000000000007'),
        ['reason'=>'']
    ));
    $assert(false, 'manual_reason_required_must_throw');
} catch (RuntimeException) {
    $assert(true, 'manual_reason_required');
}

$ledger = new InventoryLedgerService($pdo);
$base = static fn(string $type,string $qty,string $cost,string $key,int $product=1): array => [
    'company_id'=>1,'warehouse_id'=>1,'internal_product_id'=>$product,'movement_type'=>$type,
    'quantity'=>$qty,'unit_cost'=>$cost,'reference_type'=>'test','reference_id'=>$key,
    'idempotency_key'=>$key,'source'=>'system',
];
try {
    $ledger->applyBatch([$base('receipt','1','1','cross-account-ledger') + ['meli_account_id'=>2]]);
    $assert(false, 'cross_account_ledger_must_throw');
} catch (RuntimeException) {
    $assert(true, 'cross_account_ledger_blocked');
}
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE idempotency_key='cross-account-ledger'")->fetchColumn()===0, 'cross_account_ledger_zero_delta');
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
$adjustmentCost = $ledger->applyBatch([$base('adjustment_out','1','999','adjust-out-cost-authority')])[0];
$assert($adjustmentCost['unit_cost']==='6.000000' && $adjustmentCost['total_cost']==='6.000000', 'adjustment_out_uses_locked_average');
$balance = $pdo->query('SELECT * FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetch();
$assert($balance['on_hand']==='18.000000' && $balance['reserved']==='1.000000' && $balance['available']==='17.000000', 'reserve_release_adjustment');
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
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn()==='18.000000', 'insufficient_zero_delta');

// Casos numéricos obligatorios: 100@10 + 50@16 = 150@12; venta 10 = 120.
$ledger->applyBatch([$base('opening','100','10','numeric-opening',5)]);
$numericOpening = $pdo->query('SELECT on_hand,average_unit_cost FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=5')->fetch();
$assert($numericOpening['on_hand']==='100.000000' && $numericOpening['average_unit_cost']==='10.000000', 'numeric_case_1');
$ledger->applyBatch([$base('receipt','50','16','numeric-receipt',5)]);
$numericWeighted = $pdo->query('SELECT on_hand,average_unit_cost FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=5')->fetch();
$assert($numericWeighted['on_hand']==='150.000000' && $numericWeighted['average_unit_cost']==='12.000000', 'numeric_case_2');
$numericSale = $ledger->applyBatch([$base('sale_issue','10','0','numeric-sale',5)])[0];
for ($repeat=0; $repeat<10; $repeat++) {
    $ledger->applyBatch([$base('sale_issue','10','0','numeric-sale',5)]);
}
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=5')->fetchColumn()==='140.000000', 'numeric_case_3_idempotent_stock');
$assert($numericSale['unit_cost']==='12.000000' && $numericSale['total_cost']==='120.000000', 'numeric_case_3_sale_cost');
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE internal_product_id=5 AND movement_type='sale_issue'")->fetchColumn()===1, 'numeric_case_4_sale_once');
foreach ([
    $base('sale_reversal','9','12','bad-reversal-qty',5) + ['reversal_of_movement_id'=>(int)$numericSale['id']],
    $base('sale_reversal','10','11','bad-reversal-cost',5) + ['reversal_of_movement_id'=>(int)$numericSale['id']],
    $base('sale_reversal','10','12','bad-reversal-product',1) + ['reversal_of_movement_id'=>(int)$numericSale['id']],
    $base('receipt','1','1','bad-reversal-type',5) + ['reversal_of_movement_id'=>(int)$numericSale['id']],
] as $invalidReversal) {
    try {
        $ledger->applyBatch([$invalidReversal]);
        $assert(false, 'invalid_reversal_must_throw');
    } catch (RuntimeException) {
        $assert(true, 'invalid_reversal_blocked');
    }
}
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE idempotency_key LIKE 'bad-reversal-%'")->fetchColumn()===0, 'invalid_reversal_zero_delta');
$ledger->applyBatch([$base('sale_reversal','10','12','numeric-reversal',5) + ['reversal_of_movement_id'=>(int)$numericSale['id']]]);
$ledger->applyBatch([$base('sale_reversal','10','12','numeric-reversal',5) + ['reversal_of_movement_id'=>(int)$numericSale['id']]]);
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=5')->fetchColumn()==='150.000000', 'numeric_case_5_reversal_stock');
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE internal_product_id=5 AND movement_type='sale_reversal'")->fetchColumn()===1, 'numeric_case_5_reversal_once');
$reserveCost = $ledger->applyBatch([$base('reserve','1','999','reserve-cost-zero',5)])[0];
$releaseCost = $ledger->applyBatch([$base('release','1','999','release-cost-zero',5)])[0];
$assert($reserveCost['unit_cost']==='0.000000' && $releaseCost['unit_cost']==='0.000000', 'reserve_release_cost_zero');
try {
    (new ProductLinkService())->updateFactor(1, 0.00001);
    $assert(false, 'quantized_zero_factor_must_throw');
} catch (RuntimeException) {
    $assert(true, 'quantized_zero_factor_blocked');
}

// La publicación es el mutex físico: dos POST concurrentes no pueden crear
// dos vínculos activos para la misma cuenta/item/variación.
$pdo->exec("INSERT INTO meli_items VALUES (6,1,'LINK-RACE','Vínculo concurrente','RACE')");
$linkCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --product-link-child 6';
$linkEnv = array_merge($_ENV, [
    'INVENTORY_TEST_DSN'=>$dsn,'INVENTORY_TEST_USER'=>$user,'INVENTORY_TEST_PASSWORD'=>$password,
]);
$linkProcesses = []; $linkPipes = [];
for ($linkRace=0; $linkRace<2; $linkRace++) {
    $pipes = [];
    $process = proc_open($linkCommand, [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, dirname(__DIR__), $linkEnv);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start product-link race child.');
    }
    fclose($pipes[0]);
    $linkProcesses[] = $process;
    $linkPipes[] = $pipes;
}
$linkOutputs = [];
foreach ($linkProcesses as $index=>$process) {
    $stdout = stream_get_contents($linkPipes[$index][1]); fclose($linkPipes[$index][1]);
    $stderr = stream_get_contents($linkPipes[$index][2]); fclose($linkPipes[$index][2]);
    $code = proc_close($process);
    $assert($code===0, 'product_link_race_child_' . $index . ':' . trim((string)$stdout . ' ' . (string)$stderr));
    $linkOutputs[] = trim((string)$stdout);
}
sort($linkOutputs);
$assert($linkOutputs===['CHILD_LINKED','CHILD_LINK_REJECTED'], 'product_link_race_one_winner');
$assert((int)$pdo->query("SELECT COUNT(*) FROM product_meli_links WHERE meli_account_id=1 AND meli_item_id=6 AND meli_variation_id=0 AND status='active'")->fetchColumn()===1, 'product_link_race_single_active');

// Venta real: 2 unidades ML x factor 3 = 6 unidades de bodega, una sola vez.
$pdo->exec("INSERT INTO meli_orders VALUES (10,1,'ORDER-1','PACK-1','paid','paid')");
$pdo->exec("INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,external_variation_id,title,seller_sku,quantity) VALUES (10,1,'MLA1',NULL,'P','MLSKU',2)");
$projection = new OrderInventoryService($pdo);
$result = $projection->project(1,1,10);
$assert($result['outcome']==='applied' && $result['movements']===1, 'paid_order_applied');
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn()==='12.000000', 'conversion_factor_applied');
$projection->project(1,1,10);
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reference_id='ORDER-1' AND movement_type='sale_issue'")->fetchColumn()===1, 'sale_idempotent');
$pdo->exec("UPDATE meli_orders SET status='cancelled' WHERE id=10 AND meli_account_id=1");
$projection->project(1,1,10);
$projection->project(1,1,10);
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn()==='18.000000', 'cancellation_exact_reversal');
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
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_reviews WHERE reason_code='WAREHOUSE_NOT_CONFIGURED' AND company_id=2 AND meli_account_id=2")->fetchColumn()===1, 'warehouse_review');
$assert((int)$pdo->query('SELECT COUNT(*) FROM inventory_movements WHERE company_id=2')->fetchColumn()===0, 'tenant_b_no_mutation');

// Reembolso parcial nunca adivina cantidades: queda en revisión sin tocar existencias.
$pdo->exec("INSERT INTO meli_orders VALUES (14,1,'ORDER-R',NULL,'paid','partial_refund')");
$pdo->exec("INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,external_variation_id,title,seller_sku,quantity) VALUES (14,1,'MLA1',NULL,'P','MLSKU',1)");
$beforeRefund = $pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn();
$refund = $projection->project(1,1,14);
$assert($refund['outcome']==='review' && $refund['movements']===0, 'partial_refund_review_only');
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn()===$beforeRefund, 'partial_refund_zero_delta');
$partialReviewId = (int) $pdo->query(
    "SELECT id FROM inventory_reviews WHERE meli_order_id=14 AND reason_code='PARTIAL_RETURN_AUTHORITY_REQUIRED'"
)->fetchColumn();
$manual->dismissReview($partialReviewId, 'Devolución revisada externamente; no existe autoridad de cantidad para mover inventario.');
$assert((string)$pdo->query("SELECT state FROM inventory_reviews WHERE id={$partialReviewId}")->fetchColumn()==='dismissed', 'partial_review_admin_dismissed');
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn()===$beforeRefund, 'partial_review_dismiss_zero_delta');

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

// Retry y dismiss reales esperan el mismo mutex de orden; al liberarlo
// terminalizan sin stale apply ni deadlock.
$raceReviewId = (int) $pdo->query(
    "SELECT id FROM inventory_reviews WHERE meli_order_id=15 AND company_id=1 AND meli_account_id=1 AND state='open'"
)->fetchColumn();
$pdo->beginTransaction();
$pdo->query('SELECT id FROM meli_orders WHERE id=15 AND meli_account_id=1 FOR UPDATE')->fetchColumn();
$retryCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
    . ' --review-retry-child ' . escapeshellarg((string) $raceReviewId);
$dismissCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
    . ' --review-dismiss-child ' . escapeshellarg((string) $raceReviewId);
$raceEnv = array_merge($_ENV, [
    'INVENTORY_TEST_DSN'=>$dsn,'INVENTORY_TEST_USER'=>$user,'INVENTORY_TEST_PASSWORD'=>$password,
]);
$retryPipes = []; $dismissPipes = [];
$retryProcess = proc_open($retryCommand, [['pipe','r'],['pipe','w'],['pipe','w']], $retryPipes, dirname(__DIR__), $raceEnv);
$dismissProcess = proc_open($dismissCommand, [['pipe','r'],['pipe','w'],['pipe','w']], $dismissPipes, dirname(__DIR__), $raceEnv);
if (!is_resource($retryProcess) || !is_resource($dismissProcess)) {
    throw new RuntimeException('Could not start review race children.');
}
fclose($retryPipes[0]); fclose($dismissPipes[0]);
usleep(300000);
$pdo->commit();
$retryOut = stream_get_contents($retryPipes[1]); fclose($retryPipes[1]);
$retryErr = stream_get_contents($retryPipes[2]); fclose($retryPipes[2]);
$dismissOut = stream_get_contents($dismissPipes[1]); fclose($dismissPipes[1]);
$dismissErr = stream_get_contents($dismissPipes[2]); fclose($dismissPipes[2]);
$retryCode = proc_close($retryProcess); $dismissCode = proc_close($dismissProcess);
$assert($retryCode===0 && $dismissCode===0, 'review_retry_dismiss_no_deadlock:retry=' . $retryCode
    . ':' . trim((string)$retryOut . ' ' . (string)$retryErr) . ':dismiss=' . $dismissCode
    . ':' . trim((string)$dismissOut . ' ' . (string)$dismissErr));
$assert(str_contains((string)$retryOut, '"ok":true') || str_contains((string)$retryOut, 'CHILD_ERROR:App\\Core\\HttpException'), 'review_retry_terminal');
$assert(str_contains((string)$dismissOut, 'CHILD_DISMISSED') || str_contains((string)$dismissOut, 'CHILD_ALREADY_TERMINAL'), 'review_dismiss_terminal');
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reference_id='ORDER-A'")->fetchColumn()===0, 'review_race_zero_delta');

// Una orden mixta nunca se aplica parcialmente: una línea sin autoridad bloquea el lote completo.
$pdo->exec("INSERT INTO meli_orders VALUES (16,1,'ORDER-MIX-U',NULL,'paid','paid'),(17,1,'ORDER-MIX-A',NULL,'paid','paid')");
$pdo->exec("INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,external_variation_id,title,seller_sku,quantity) VALUES
    (16,1,'MLA1',NULL,'V','MLSKU',1),(16,1,'MISSING-MIX',NULL,'U','U',1),
    (17,1,'MLA1',NULL,'V','MLSKU',1),(17,1,'AMBIGUOUS',NULL,'A','A',1)");
$beforeMixedMovements = (int)$pdo->query('SELECT COUNT(*) FROM inventory_movements')->fetchColumn();
$beforeMixedStock = $pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn();
$mixedUnlinked = $projection->project(1,1,16);
$mixedAmbiguous = $projection->project(1,1,17);
$assert($mixedUnlinked['outcome']==='review' && $mixedUnlinked['movements']===0, 'mixed_unlinked_order_atomic');
$assert($mixedAmbiguous['outcome']==='review' && $mixedAmbiguous['movements']===0, 'mixed_ambiguous_order_atomic');
$assert((int)$pdo->query('SELECT COUNT(*) FROM inventory_movements')->fetchColumn()===$beforeMixedMovements, 'mixed_orders_zero_movements');
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn()===$beforeMixedStock, 'mixed_orders_zero_stock_delta');

// Dos listings distintos ligados al mismo producto se agregan antes del ledger.
$pdo->exec("INSERT INTO meli_items VALUES (4,1,'MLA2','Segundo listing','MLSKU2')");
$pdo->exec("INSERT INTO product_meli_links(internal_product_id,meli_account_id,meli_item_id,meli_variation_id,conversion_factor,status) VALUES (1,1,4,0,3.0000,'active')");
$pdo->exec("INSERT INTO meli_orders VALUES (18,1,'ORDER-SAME-PRODUCT',NULL,'paid','paid')");
$pdo->exec("INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,external_variation_id,title,seller_sku,quantity) VALUES
    (18,1,'MLA1',NULL,'Uno','MLSKU',1),(18,1,'MLA2',NULL,'Dos','MLSKU2',1)");
$beforeSameProduct = $pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn();
$sameProduct = $projection->project(1,1,18);
$afterSameProduct = $pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn();
$assert($sameProduct['outcome']==='applied' && $sameProduct['movements']===1, 'same_product_lines_aggregated_once');
$assert((string)$pdo->query("SELECT on_hand_delta FROM inventory_movements WHERE reference_id='ORDER-SAME-PRODUCT' AND movement_type='sale_issue'")->fetchColumn()==='-6.000000', 'same_product_total_quantity');
$projection->project(1,1,18);
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn()===$afterSameProduct, 'same_product_retry_idempotent');
$pdo->exec("UPDATE meli_orders SET status='cancelled' WHERE id=18 AND meli_account_id=1");
$projection->project(1,1,18); $projection->project(1,1,18);
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn()===$beforeSameProduct, 'same_product_cancel_restores_total');
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reference_id='ORDER-SAME-PRODUCT' AND movement_type='sale_reversal'")->fetchColumn()===1, 'same_product_reversal_once');

// Una orden pagada ya capturada conserva su snapshot aunque cambien default, vínculo o factor.
$pdo->exec("INSERT INTO meli_orders VALUES (19,1,'ORDER-SNAPSHOT',NULL,'paid','paid')");
$pdo->exec("INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,external_variation_id,title,seller_sku,quantity) VALUES (19,1,'MLA1',NULL,'S','MLSKU',1)");
$snapshotBefore = $pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn();
$snapshotFirst = $projection->project(1,1,19);
$snapshotAfter = $pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn();
$warehouseService->setStatus($auxWarehouse, 'active');
$warehouseService->setDefault($auxWarehouse);
$pdo->exec("UPDATE product_meli_links SET internal_product_id=2,conversion_factor=9.0000 WHERE id=1 AND meli_account_id=1");
$snapshotReplay = $projection->project(1,1,19);
$assert($snapshotFirst['movements']===1 && $snapshotReplay['movements']===1, 'paid_snapshot_replay_authority');
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reference_id='ORDER-SNAPSHOT' AND movement_type='sale_issue'")->fetchColumn()===1, 'paid_snapshot_never_double_applies');
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn()===$snapshotAfter, 'paid_snapshot_product_one_stable');
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reference_id='ORDER-SNAPSHOT' AND internal_product_id=2")->fetchColumn()===0, 'paid_snapshot_relink_zero_delta');
$pdo->exec("UPDATE meli_orders SET status='cancelled' WHERE id=19 AND meli_account_id=1");
$projection->project(1,1,19);
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn()===$snapshotBefore, 'paid_snapshot_cancel_restores_original');
$pdo->exec("UPDATE product_meli_links SET internal_product_id=1,conversion_factor=3.0000 WHERE id=1 AND meli_account_id=1");
$warehouseService->setDefault(1);
$warehouseService->setStatus($auxWarehouse, 'inactive');

// Producto inactivo: revisión visible, cero salida y retry aplicable al reactivarlo.
$pdo->exec("INSERT INTO meli_orders VALUES (40,1,'ORDER-INACTIVE-PRODUCT',NULL,'paid','paid')");
$pdo->exec("INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,external_variation_id,title,seller_sku,quantity) VALUES (40,1,'MLA1',NULL,'I','MLSKU',1)");
$pdo->exec("UPDATE internal_products SET status='inactive' WHERE id=1 AND company_id=1");
$inactiveProjection = $projection->project(1,1,40);
$inactiveReviewId = (int)$pdo->query("SELECT id FROM inventory_reviews WHERE meli_order_id=40 AND company_id=1 AND meli_account_id=1 AND state='open'")->fetchColumn();
$assert($inactiveProjection['outcome']==='review' && $inactiveReviewId>0, 'inactive_product_review_visible');
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reference_id='ORDER-INACTIVE-PRODUCT'")->fetchColumn()===0, 'inactive_product_projection_zero_delta');
$pdo->exec("UPDATE internal_products SET status='active' WHERE id=1 AND company_id=1");
$inactiveRetry = $manual->retryReview($inactiveReviewId);
$assert($inactiveRetry['outcome']==='applied' && $inactiveRetry['movements']===1, 'inactive_product_retry_after_reactivation');

// Dos links activos siguen siendo ambiguos aunque sólo uno de sus productos esté activo.
$pdo->exec("INSERT INTO meli_items VALUES (5,1,'MIXED-LINK-STATUS','Links mixtos','MX')");
$pdo->exec("UPDATE internal_products SET status='inactive' WHERE id=2 AND company_id=1");
$pdo->exec("INSERT INTO product_meli_links(internal_product_id,meli_account_id,meli_item_id,meli_variation_id,conversion_factor,status) VALUES (1,1,5,0,1.0000,'active'),(2,1,5,0,1.0000,'active')");
$pdo->exec("INSERT INTO meli_orders VALUES (41,1,'ORDER-MIXED-LINK-STATUS',NULL,'paid','paid')");
$pdo->exec("INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,external_variation_id,title,seller_sku,quantity) VALUES (41,1,'MIXED-LINK-STATUS',NULL,'MX','MX',1)");
$mixedStatusProjection = $projection->project(1,1,41);
$assert($mixedStatusProjection['outcome']==='review' && $mixedStatusProjection['movements']===0, 'active_inactive_link_pair_remains_ambiguous');
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reference_id='ORDER-MIXED-LINK-STATUS'")->fetchColumn()===0, 'mixed_status_links_zero_delta');
$pdo->exec("UPDATE internal_products SET status='active' WHERE id=2 AND company_id=1");

// Compensaciones autorizadas siguen disponibles si la bodega original se inactiva.
$ledger->applyBatch([$base('reserve','1','0','inactive-warehouse-reserve')]);
$warehouseService->setStatus($auxWarehouse, 'active');
$warehouseService->setDefault($auxWarehouse);
$blockedInactivation = false;
try {
    $warehouseService->setStatus(1, 'inactive');
} catch (RuntimeException) {
    $blockedInactivation = true;
}
$assert($blockedInactivation && $pdo->query('SELECT status FROM inventory_warehouses WHERE id=1')->fetchColumn()==='active', 'warehouse_with_balance_cannot_hide');
// Simula una preimagen legacy ya inactiva para certificar compensaciones fail-safe.
$pdo->exec("UPDATE inventory_warehouses SET status='inactive' WHERE id=1 AND company_id=1");
$pdo->exec("UPDATE internal_products SET status='inactive' WHERE id=1 AND company_id=1");
$ledger->applyBatch([$base('release','1','0','inactive-warehouse-release')]);
$pdo->exec("UPDATE meli_orders SET status='cancelled' WHERE id=11 AND meli_account_id=1");
$inactiveReversal = $projection->project(1,1,11);
$assert($inactiveReversal['outcome']==='reversed' && $inactiveReversal['movements']===1, 'inactive_warehouse_reversal_allowed');
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE idempotency_key='inactive-warehouse-release'")->fetchColumn()===1, 'inactive_warehouse_release_allowed');
$pdo->exec("UPDATE internal_products SET status='active' WHERE id=1 AND company_id=1");
$pdo->exec("UPDATE inventory_warehouses SET status='active' WHERE id=1 AND company_id=1");
$warehouseService->setDefault(1);
$warehouseService->setStatus($auxWarehouse, 'inactive');

// Un producto inactivo no acepta ventas/entradas nuevas y deja saldo/movimientos intactos.
$pdo->exec("UPDATE internal_products SET status='inactive' WHERE id=1 AND company_id=1");
$inactiveProductStock = $pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn();
try {
    $ledger->applyBatch([$base('sale_issue','1','0','inactive-product-sale')]);
    $assert(false, 'inactive_product_sale_must_throw');
} catch (RuntimeException) {
    $assert(true, 'inactive_product_sale_blocked');
}
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE idempotency_key='inactive-product-sale'")->fetchColumn()===0, 'inactive_product_zero_movement');
$assert($pdo->query('SELECT on_hand FROM inventory_balances WHERE company_id=1 AND warehouse_id=1 AND internal_product_id=1')->fetchColumn()===$inactiveProductStock, 'inactive_product_zero_stock_delta');
$pdo->exec("UPDATE internal_products SET status='active' WHERE id=1 AND company_id=1");

// Cancelar una orden sin movimiento cierra sus revisiones y no inventa stock.
$pdo->exec("UPDATE meli_orders SET status='cancelled' WHERE id=16 AND meli_account_id=1");
$projection->project(1,1,16);
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_reviews WHERE meli_order_id=16 AND state='open'")->fetchColumn()===0, 'cancelled_order_reviews_resolved');

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

// Regresión Queue V4: discovery mock crea order_exact; ambos completan en FIFO y sin HTTP real.
$pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE' WHERE control_key='primary'");
$pdo->exec("INSERT INTO queue_v4_clean_checkpoints(producer_key,company_id,meli_account_id) VALUES ('fresh_orders',1,1)");
$queue = new QueueV4CleanRepository($pdo);
$freshId = $queue->enqueue(1,1,'fresh_orders_discovery',null,'inventory-regression:fresh',[
    'from'=>'2026-08-12T00:00:00Z','to'=>'2026-08-12T00:01:00Z','offset'=>0,'limit'=>20,
]);
$mockCalls = 0;
$clientFactory = static function (int $accountId) use (&$mockCalls): MeliReadClientInterface {
    return new class($accountId,$mockCalls) implements MeliReadClientInterface {
        public function __construct(private int $accountId, private int &$calls) {}
        public function get(string $path, array $query = [], array $meta = []): array
        {
            $this->calls++;
            if ($this->accountId !== 1 || $path !== '/orders/search') {
                throw new RuntimeException('queue_mock_authority_invalid');
            }
            return ['results'=>[['id'=>'90001']],'paging'=>['total'=>1,'offset'=>0]];
        }
    };
};
$freshResult = (new QueueV4CleanWorker($pdo,$queue,$clientFactory))->run('test',1,10);
$orderJob = $pdo->query("SELECT id,state FROM queue_v4_clean_jobs WHERE job_type='order_exact' AND resource_id='90001'")->fetch();
$seen = [];
$orderResult = (new QueueV4CleanWorker($pdo,$queue,null,null,static function(array $job) use (&$seen): void {
    $seen[] = ['id'=>(int)$job['id'],'type'=>(string)$job['job_type']];
}))->run('test',1,10);
$assert($freshResult['completed']===1 && (int)$freshId<(int)$orderJob['id'], 'queue_fresh_completed_fifo');
$assert($mockCalls===1 && $orderJob['state']==='ready', 'queue_fresh_mock_created_order_exact');
$assert($orderResult['completed']===1 && $seen===[['id'=>(int)$orderJob['id'],'type'=>'order_exact']], 'queue_order_exact_completed');
$assert((int)$pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state<>'completed'")->fetchColumn()===0, 'queue_regression_clean_idle');

// Una venta anterior a la ventana fresh se refresca en forma acotada y su cancelación tardía revierte.
$pdo->exec("INSERT INTO meli_orders VALUES (30,1,'90030',NULL,'paid','paid')");
$pdo->exec("INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,external_variation_id,title,seller_sku,quantity) VALUES (30,1,'MLA1',NULL,'Q','MLSKU',1)");
$refreshId = $queue->enqueue(1,1,'order_exact','90030','order:90030',['order_id'=>'90030']);
$queueProjection = new OrderInventoryService($pdo);
$queueWorker = new QueueV4CleanWorker($pdo,$queue,null,null,static function(array $job) use ($queueProjection): void {
    $queueProjection->project((int)$job['company_id'],(int)$job['meli_account_id'],30);
});
for ($refreshRun=0; $refreshRun<2; $refreshRun++) {
    $queueWorker->run('test',3,10);
}
$assert((string)$pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$refreshId}")->fetchColumn()==='completed', 'queue_paid_order_completed');
$pdo->exec("INSERT INTO queue_v4_clean_readiness_runs(id,state,expected_accounts,passed_accounts,started_by,finished_at) VALUES (1,'CERTIFIED',3,3,1,UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO queue_v4_clean_readiness_accounts(readiness_run_id,company_id,meli_account_id,outcome) VALUES (1,1,1,'PASS'),(1,2,2,'PASS'),(1,3,3,'PASS')");
$pdo->exec("INSERT INTO meli_orders VALUES (50,1,'90050',NULL,'paid','paid')");
$pdo->exec((string) file_get_contents(dirname(__DIR__) . '/database/migrations/295_inventory_warehouse_v1_2_38_0.sql'));
$cutoverAuthority = $pdo->query(
    "SELECT producer_key,last_job_id FROM queue_v4_clean_checkpoints
     WHERE company_id=1 AND meli_account_id=1
       AND producer_key IN ('inventory_pending_floor','inventory_pending_cursor')
     ORDER BY producer_key"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$cutoverAuthority = array_map('intval', $cutoverAuthority);
$assert($cutoverAuthority === ['inventory_pending_cursor'=>50,'inventory_pending_floor'=>50], 'migration_cutover_floor_cursor_exact');
$pdo->exec("INSERT INTO meli_orders VALUES (53,1,'90053',NULL,'created','created')");
$pdo->exec((string) file_get_contents(dirname(__DIR__) . '/database/migrations/295_inventory_warehouse_v1_2_38_0.sql'));
$cutoverAfterReapply = array_map('intval', $pdo->query(
    "SELECT producer_key,last_job_id FROM queue_v4_clean_checkpoints
     WHERE company_id=1 AND meli_account_id=1
       AND producer_key IN ('inventory_pending_floor','inventory_pending_cursor')
     ORDER BY producer_key"
)->fetchAll(PDO::FETCH_KEY_PAIR));
$assert($cutoverAfterReapply === $cutoverAuthority, 'migration_reapply_never_moves_cutover_floor');
$pdo->exec("DELETE FROM meli_orders WHERE id=53 AND meli_account_id=1");
$assert((int)$pdo->query(
    "SELECT COUNT(*) FROM queue_v4_clean_checkpoints
     WHERE company_id=1 AND meli_account_id=1
       AND producer_key IN ('inventory_pending_floor','inventory_pending_cursor')"
)->fetchColumn()===2, 'migration_cutover_authority_idempotent');
$pdo->exec("DELETE FROM queue_v4_clean_checkpoints WHERE producer_key='inventory_pending_cursor' AND company_id=2 AND meli_account_id=2");
$pdo->exec((string) file_get_contents(dirname(__DIR__) . '/database/migrations/295_inventory_warehouse_v1_2_38_0.sql'));
$assert((int)$pdo->query(
    "SELECT COUNT(*) FROM queue_v4_clean_checkpoints
     WHERE company_id=2 AND meli_account_id=2
       AND producer_key IN ('inventory_pending_floor','inventory_pending_cursor')"
)->fetchColumn()===1, 'migration_partial_pair_not_silently_repaired');
try {
    (new QueueV4CleanProducer($pdo,$queue))->produce();
    $assert(false, 'runtime_partial_pending_authority_must_throw');
} catch (RuntimeException $error) {
    $assert($error->getMessage()==='queue_v4_clean_inventory_pending_authority_incomplete', 'runtime_partial_pending_authority_fail_closed');
}
$pdo->exec("INSERT INTO queue_v4_clean_checkpoints(producer_key,company_id,meli_account_id,last_job_id,next_due_at)
    SELECT 'inventory_pending_cursor',company_id,meli_account_id,last_job_id,UTC_TIMESTAMP(3)
    FROM queue_v4_clean_checkpoints
    WHERE producer_key='inventory_pending_floor' AND company_id=2 AND meli_account_id=2");
$pdo->exec("INSERT INTO queue_v4_clean_checkpoints(producer_key,company_id,meli_account_id,last_job_id,next_due_at) VALUES ('inventory_order_refresh',1,1,29,UTC_TIMESTAMP(3))");
$refreshProduction = (new QueueV4CleanProducer($pdo,$queue))->produce();
$assert($refreshProduction['inventory_refresh_created']===1, 'queue_late_lifecycle_source_bounded');
$assert((string)$pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$refreshId}")->fetchColumn()==='ready', 'queue_completed_job_revived_by_source');
$pdo->exec("UPDATE meli_orders SET status='cancelled' WHERE id=30 AND meli_account_id=1");
for ($refreshRun=0; $refreshRun<2; $refreshRun++) {
    $queueWorker->run('test',3,10);
}
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reference_id='90030' AND movement_type='sale_issue'")->fetchColumn()===1, 'queue_paid_issue_once');
$assert((int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reference_id='90030' AND movement_type='sale_reversal'")->fetchColumn()===1, 'queue_cancel_reversal_once');
$assert((string)$pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$refreshId}")->fetchColumn()==='completed', 'queue_cancel_refresh_completed');

// El pending projector fija un piso histórico, no importa órdenes antiguas y
// recupera con wrap una orden post-piso que se vuelve paid después.
$pdo->exec("UPDATE queue_v4_clean_checkpoints SET next_due_at=UTC_TIMESTAMP(3),last_job_id=9999 WHERE producer_key='inventory_order_refresh' AND company_id=1 AND meli_account_id=1");
$pdo->exec("DELETE FROM queue_v4_clean_checkpoints WHERE producer_key='inventory_refresh_turn'");
$pdo->exec("INSERT INTO queue_v4_clean_checkpoints(producer_key,company_id,meli_account_id,last_job_id,next_due_at) VALUES ('inventory_refresh_turn',1,1,1,UTC_TIMESTAMP(3))");
$pendingFloorRun = (new QueueV4CleanProducer($pdo,$queue))->produce();
$assert($pendingFloorRun['inventory_refresh_created']<=3, 'inventory_refresh_budget_one_per_tenant');
$assert((int)$pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE resource_id='90050'")->fetchColumn()===0, 'historical_paid_not_backfilled');
$pdo->exec("INSERT INTO meli_orders VALUES (51,1,'90051',NULL,'created','created'),(52,1,'90052',NULL,'paid','paid')");
$pdo->exec("UPDATE queue_v4_clean_checkpoints SET next_due_at=UTC_TIMESTAMP(3),last_job_id=1 WHERE producer_key='inventory_order_refresh' AND company_id=1 AND meli_account_id=1");
$pdo->exec("UPDATE queue_v4_clean_checkpoints SET last_job_id=1 WHERE producer_key='inventory_refresh_turn' AND company_id=1 AND meli_account_id=1");
$pdo->exec("UPDATE queue_v4_clean_checkpoints SET next_due_at=UTC_TIMESTAMP(3) WHERE producer_key='fresh_orders' AND company_id IN (1,2,3)");
$pendingAfterFloor = (new QueueV4CleanProducer($pdo,$queue))->produce();
$assert((int)$pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE resource_id='90052'")->fetchColumn()===1, 'post_cutover_paid_before_scheduler_recovered');
$pdo->exec("UPDATE meli_orders SET status='paid',status_detail='paid' WHERE id=51 AND meli_account_id=1");
$pdo->exec("UPDATE queue_v4_clean_checkpoints SET next_due_at=UTC_TIMESTAMP(3),last_job_id=1 WHERE producer_key='inventory_order_refresh' AND company_id=1 AND meli_account_id=1");
$pdo->exec("UPDATE queue_v4_clean_checkpoints SET last_job_id=1 WHERE producer_key='inventory_refresh_turn' AND company_id=1 AND meli_account_id=1");
$pdo->exec("UPDATE queue_v4_clean_checkpoints SET next_due_at=UTC_TIMESTAMP(3) WHERE producer_key='fresh_orders' AND company_id IN (1,2,3)");
(new QueueV4CleanProducer($pdo,$queue))->produce();
$assert((int)$pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE resource_id='90051'")->fetchColumn()===1, 'pending_cursor_wrap_recovers_delayed_paid');
$assert((int)$pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE resource_id='90050'")->fetchColumn()===0, 'historical_floor_remains_excluded');

// Kardex aplica siempre ACL de cuenta, incluso cuando el filtro visible dice "todas".
$pdo->exec("INSERT INTO meli_accounts VALUES (4,1,'Cuenta no autorizada','1004','connected')");
$ledger->applyBatch([$base('receipt','1','6','acl-account-four') + ['meli_account_id'=>4]]);
$kardex = (new InventoryQueryService())->movements(['company_id'=>1], 1, 100);
$kardexAccountIds = array_values(array_unique(array_filter(array_map(
    static fn(array $row): int => (int)($row['meli_account_id'] ?? 0),
    $kardex['items']
))));
$assert(!in_array(4, $kardexAccountIds, true) && in_array(1, $kardexAccountIds, true), 'kardex_account_acl_enforced');
try {
    (new InventoryQueryService())->movements(['company_id'=>1,'account_id'=>4], 1, 100);
    $assert(false, 'kardex_unauthorized_account_must_throw');
} catch (App\Core\HttpException) {
    $assert(true, 'kardex_unauthorized_account_blocked');
}

echo json_encode([
    'ok'=>true,'checks'=>$checks,'schema'=>295,'ml_http_calls'=>0,'business_tables_mutated'=>0,
    'pack_double_count'=>0,'raw_storage_touched'=>false,
], JSON_UNESCAPED_SLASHES), PHP_EOL;
