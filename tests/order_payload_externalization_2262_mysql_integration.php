<?php

declare(strict_types=1);

/**
 * Verifica que una sincronización normalice la orden y conserve una sola
 * copia privada comprimida del payload. La prueba usa una base desechable.
 */

$root = dirname(__DIR__);
$_ENV['DB_HOST'] = (string) (getenv('ERP_TEST_DB_HOST') ?: '127.0.0.1');
$_ENV['DB_PORT'] = (string) (getenv('ERP_TEST_DB_PORT') ?: '3306');
$_ENV['DB_NAME'] = (string) (getenv('ERP_TEST_DB_NAME') ?: 'erp_meli_order_payload_test');
$_ENV['DB_USER'] = (string) (getenv('ERP_TEST_DB_USER') ?: 'root');
$_ENV['DB_PASS'] = (string) (getenv('ERP_TEST_DB_PASS') ?: '');
$_ENV['APP_ENV'] = 'local';
$_ENV['ML_WRITE_ENABLED'] = 'false';
$_ENV['MELI_API_BASE'] = 'http://127.0.0.1:9';
$_ENV['ERP_PRIVATE_PATH'] = (string) (
    getenv('ERP_TEST_PRIVATE_PATH')
    ?: 'C:/codex/ERP Meli Gestion Pro/work/private-order-payload-test'
);

require $root . '/bootstrap.php';

use App\Core\Database;
use App\Services\FileRemotePayloadStore;
use App\Services\OrderSyncService;
use App\ValueObjects\PayloadReference;

/** @param bool $condition */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string,mixed> */
function fixture(string $suffix): array
{
    return [
        'id' => '999000000000001',
        'pack_id' => null,
        'date_created' => '2026-07-29T08:00:00.000-05:00',
        'date_closed' => '2026-07-29T08:02:00.000-05:00',
        'last_updated' => '2026-07-29T08:03:0' . ($suffix === 'first' ? '0' : '1') . '.000-05:00',
        'status' => 'paid',
        'status_detail' => null,
        'total_amount' => 110171,
        'paid_amount' => 110171,
        'currency_id' => 'COP',
        'buyer' => ['id' => 998877, 'nickname' => 'COMPRADOR-PRUEBA'],
        'shipping' => [],
        'tags' => ['paid'],
        'order_items' => [
            [
                'item' => [
                    'id' => 'MCO-TEST-1',
                    'title' => 'Producto A ' . $suffix,
                    'seller_sku' => 'TEST-A',
                    'listing_type_id' => 'gold_special',
                ],
                'quantity' => 1,
                'unit_price' => 60191,
                'full_unit_price' => 60191,
                'sale_fee' => 10533,
            ],
            [
                'item' => [
                    'id' => 'MCO-TEST-2',
                    'title' => 'Producto B ' . $suffix,
                    'seller_sku' => 'TEST-B',
                    'listing_type_id' => 'gold_special',
                ],
                'quantity' => 2,
                'unit_price' => 24990,
                'full_unit_price' => 24990,
                'sale_fee' => 8746,
            ],
        ],
        'payments' => [
            [
                'id' => '999000000000101',
                'status' => 'approved',
                'status_detail' => 'accredited',
                'payment_method_id' => 'visa',
                'payment_type' => 'credit_card',
                'transaction_amount' => 110171,
                'shipping_cost' => 0,
                'coupon_amount' => 0,
                'total_paid_amount' => 110171,
                'marketplace_fee' => 19279,
                'date_approved' => '2026-07-29T08:02:00.000-05:00',
                'fixture_revision' => $suffix,
            ],
        ],
        'fixture_revision' => $suffix,
    ];
}

/** @return array<string,mixed> */
function entityRow(PDO $pdo, string $table, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM `' . $table . '` WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    assertTrue(is_array($row), 'No se encontró la fila normalizada en ' . $table . '.');
    return $row;
}

/** @return PayloadReference */
function requireReference(FileRemotePayloadStore $store, string $table, int $id): PayloadReference
{
    $reference = $store->referenceFor($table, $id, 1);
    assertTrue($reference instanceof PayloadReference, 'Falta referencia privada para ' . $table . '.');
    assertTrue($store->verify($reference), 'El payload privado no superó verificación para ' . $table . '.');
    return $reference;
}

$pdo = Database::connection();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ([
    'order_resource_enrichment_job_orders',
    'order_resource_enrichment_jobs',
    'remote_payload_references',
    'remote_payload_objects',
    'meli_payments',
    'meli_order_items',
    'meli_orders',
] as $table) {
    $pdo->exec('TRUNCATE TABLE `' . $table . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec(
    "INSERT IGNORE INTO companies (id,name,status)
     VALUES (1,'Local QA',1)"
);
$pdo->exec(
    "INSERT IGNORE INTO meli_accounts
       (id,company_id,account_name,meli_user_id,nickname,site_id,country_id,status)
     VALUES
       (1,1,'Local QA',999001,'LOCAL-QA','MCO','CO','desconectado')"
);

$service = new OrderSyncService(1);
$persist = new ReflectionMethod($service, 'persistOrder');
$firstFixture = fixture('first');
$orderId = (int) $persist->invoke($service, $firstFixture, false, null);
assertTrue($orderId > 0, 'La primera normalización no devolvió una orden.');

$order = entityRow($pdo, 'meli_orders', $orderId);
assertTrue($order['raw_json'] === null && $order['raw_path'] === null, 'La orden conserva una copia duplicada en MariaDB.');

$items = $pdo->query('SELECT id,raw_json FROM meli_order_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
assertTrue(count($items) === 2, 'La orden no conservó sus dos productos.');
foreach ($items as $item) {
    assertTrue($item['raw_json'] === null, 'Un producto conserva el payload duplicado.');
}

$payment = $pdo->query('SELECT id,raw_json,raw_path FROM meli_payments LIMIT 1')->fetch(PDO::FETCH_ASSOC);
assertTrue(is_array($payment), 'No se guardó el pago embebido.');
assertTrue($payment['raw_json'] === null && $payment['raw_path'] === null, 'El pago conserva una copia duplicada.');

$store = new FileRemotePayloadStore();
$orderReference = requireReference($store, 'meli_orders', $orderId);
$firstDecoded = json_decode($store->retrieve($orderReference), true, 512, JSON_THROW_ON_ERROR);
assertTrue(($firstDecoded['fixture_revision'] ?? '') === 'first', 'La primera copia privada no corresponde a la orden.');
foreach ($items as $item) {
    requireReference($store, 'meli_order_items', (int) $item['id']);
}
requireReference($store, 'meli_payments', (int) $payment['id']);

$firstItemIds = array_map('intval', array_column($items, 'id'));
$secondFixture = fixture('second');
$secondOrderId = (int) $persist->invoke($service, $secondFixture, false, null);
assertTrue($secondOrderId === $orderId, 'La resincronización creó una orden duplicada.');

$secondItems = $pdo->query('SELECT id,raw_json,title,quantity FROM meli_order_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
assertTrue(count($secondItems) === 2, 'La resincronización cambió el número de productos.');
assertTrue(array_sum(array_map('intval', array_column($secondItems, 'quantity'))) === 3, 'La resincronización cambió las tres unidades.');
assertTrue(array_intersect($firstItemIds, array_map('intval', array_column($secondItems, 'id'))) === [], 'Los productos antiguos no fueron reemplazados de forma controlada.');

$stale = $pdo->prepare(
    'SELECT COUNT(*) FROM remote_payload_references
     WHERE entity_table="meli_order_items" AND entity_id IN (' . implode(',', $firstItemIds) . ')'
);
$stale->execute();
assertTrue((int) $stale->fetchColumn() === 0, 'Quedaron referencias activas a productos reemplazados.');

$secondOrderReference = requireReference($store, 'meli_orders', $orderId);
$secondDecoded = json_decode($store->retrieve($secondOrderReference), true, 512, JSON_THROW_ON_ERROR);
assertTrue(($secondDecoded['fixture_revision'] ?? '') === 'second', 'La referencia de la orden no avanzó a la última revisión.');
foreach ($secondItems as $item) {
    assertTrue($item['raw_json'] === null, 'Un producto resincronizado conserva JSON duplicado.');
    requireReference($store, 'meli_order_items', (int) $item['id']);
}

$activeReferences = (int) $pdo->query(
    'SELECT COUNT(*) FROM remote_payload_references
     WHERE entity_table IN ("meli_orders","meli_order_items","meli_payments")'
)->fetchColumn();
assertTrue($activeReferences === 4, 'El catálogo no contiene exactamente orden, dos productos y pago.');

$orphanBefore = (int) $pdo->query(
    'SELECT COUNT(*) FROM remote_payload_objects o
     WHERE NOT EXISTS (
       SELECT 1 FROM remote_payload_references r WHERE r.payload_object_id=o.id
     )'
)->fetchColumn();
assertTrue($orphanBefore > 0, 'La prueba no produjo objetos reemplazados para verificar su limpieza.');
$purged = $store->purgeOrphans(100);
assertTrue($purged['errors'] === 0, 'La limpieza de objetos reemplazados produjo errores.');
$orphanAfter = (int) $pdo->query(
    'SELECT COUNT(*) FROM remote_payload_objects o
     WHERE NOT EXISTS (
       SELECT 1 FROM remote_payload_references r WHERE r.payload_object_id=o.id
     )'
)->fetchColumn();
assertTrue($orphanAfter === 0, 'Quedaron objetos privados sin referencias.');

echo json_encode([
    'status' => 'PASS',
    'order_id' => $orderId,
    'products' => count($secondItems),
    'units' => array_sum(array_map('intval', array_column($secondItems, 'quantity'))),
    'active_references' => $activeReferences,
    'purged_objects' => $purged['deleted'],
    'remote_transport' => false,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
