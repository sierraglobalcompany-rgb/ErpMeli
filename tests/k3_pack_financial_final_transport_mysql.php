<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_transport_fixture.inc.php';

use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\Cap2DomainsWire;
use App\Services\SaleFinancialStateService;

r0h3_seed($pdo);
r0h3_prepare_transport_fixture($pdo, 1);
r0h3_clear_positive_scope($pdo);
r0h3_defer_fresh_order_discovery($pdo);

$packExternal = '870001';
$orders = ['970001', '970002'];
$packId = r0h3_insert($pdo, 'meli_packs', [
    'meli_account_id' => 7201,
    'external_pack_id' => $packExternal,
    'status' => 'paid',
    'integrity_status' => 'complete',
    'expected_orders_count' => 2,
    'linked_orders_count' => 2,
    'expected_orders_json' => json_encode($orders, JSON_THROW_ON_ERROR),
    'synced_at' => gmdate('Y-m-d H:i:s'),
    'verified_at' => gmdate('Y-m-d H:i:s'),
]);
foreach ($orders as $externalOrder) {
    $orderId = r0h3_insert($pdo, 'meli_orders', [
        'meli_account_id' => 7201,
        'external_order_id' => $externalOrder,
        'external_pack_id' => $packExternal,
        'status' => 'paid',
        'total_amount' => 100,
        'paid_amount' => 100,
        'currency_id' => 'COP',
        'synced_at' => gmdate('Y-m-d H:i:s'),
    ]);
    r0h3_insert($pdo, 'meli_order_items', [
        'meli_order_id' => $orderId,
        'meli_account_id' => 7201,
        'external_item_id' => 'ITEM-' . $externalOrder,
        'title' => 'K3 order ' . $externalOrder,
        'seller_sku' => 'K3-' . $externalOrder,
        'quantity' => 1,
        'unit_price' => 100,
        'sale_fee' => 10,
    ]);
    r0h3_insert($pdo, 'meli_pack_orders', ['meli_pack_id' => $packId, 'meli_order_id' => $orderId]);
}

$state = (new SaleFinancialStateService())->projectSale(7200, 7201, 'P:' . $packExternal);
$sourceId = r0h3_insert($pdo, 'sale_financial_reconciliation_jobs', [
    'company_id' => 7200,
    'meli_account_id' => 7201,
    'sale_key' => 'P:' . $packExternal,
    'external_sale_id' => $packExternal,
    'input_version' => (string) $state['input_version'],
    'status' => 'pending',
    'next_run_at' => '2000-01-01 00:00:00',
]);
$queueId = r0h3_insert($pdo, 'queue_v4_clean_jobs', [
    'company_id' => 7200,
    'meli_account_id' => 7201,
    'job_type' => 'domain_exact',
    'resource_id' => (string) $sourceId,
    'idempotency_key' => 'k3-financial-final-' . $sourceId,
    'payload_json' => json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR),
    'state' => 'ready',
    'available_at' => '2000-01-01 00:00:00',
]);

Cap2DomainsWire::$responses = [
    '/billing/integration/group/ML/order/details' => [200, []],
];
Cap2DomainsWire::$onWire = static function (): void {
    $last = Cap2DomainsWire::$calls[array_key_last(Cap2DomainsWire::$calls)] ?? [];
    if (($last['path'] ?? '') !== '/billing/integration/group/ML/order/details') {
        return;
    }
    $orderId = (string) ($last['query']['order_ids'] ?? '');
    Cap2DomainsWire::$status = 200;
    Cap2DomainsWire::$raw = json_encode([[
        'order_id' => $orderId,
        'detail_id' => 'k3-fee-' . $orderId,
        'detail_type' => 'SALE_FEE',
        'description' => 'K3 simulated sale fee ' . $orderId,
        'amount' => 10,
        'date_created' => '2026-09-19T00:00:00Z',
    ]], JSON_THROW_ON_ERROR);
};

$first = r0h3_worker_run($pdo, 1, [7201]);
$firstIds = array_map(static fn (array $call): string => (string) ($call['query']['order_ids'] ?? ''), Cap2DomainsWire::$calls);
r0h3_assert($firstIds === [$orders[0]], 'k3_financial_first_window_one_order_get', ['calls' => Cap2DomainsWire::$calls, 'run' => $first]);
$checkpointsAfterFirst = (int) $pdo->query(
    "SELECT COUNT(*) FROM sale_financial_evidence
      WHERE company_id=7200 AND meli_account_id=7201 AND sale_key='P:870001'
        AND evidence_type='billing_capture' AND evidence_json LIKE '%\"format\":\"billing_order_v2\"%'"
)->fetchColumn();
$publicationsAfterFirst = (int) $pdo->query(
    "SELECT COUNT(*) FROM meli_sale_financial_history h
      JOIN meli_sale_financials f ON f.id=h.meli_sale_financial_id
      WHERE f.company_id=7200 AND f.meli_account_id=7201 AND f.sale_key='P:870001' AND h.source='billing_official'"
)->fetchColumn();
r0h3_assert($checkpointsAfterFirst === 1 && $publicationsAfterFirst === 0, 'k3_financial_no_early_publication', [
    'checkpoints' => $checkpointsAfterFirst,
    'publications' => $publicationsAfterFirst,
]);

$progress = $pdo->query(
    'SELECT s.next_run_at,q.available_at FROM sale_financial_reconciliation_jobs s
     JOIN queue_v4_clean_jobs q ON q.id=' . $queueId . ' AND q.resource_id=CAST(s.id AS CHAR)
     WHERE s.id=' . $sourceId
)->fetch(PDO::FETCH_ASSOC);
$dueAt = max(
    strtotime((string) ($progress['next_run_at'] ?? '') . ' UTC') ?: time(),
    strtotime((string) ($progress['available_at'] ?? '') . ' UTC') ?: time(),
);
while (time() <= $dueAt + 1) {
    usleep(100000);
}
r0h3_wait_for_global_rhythm($pdo);
$second = r0h3_worker_run($pdo, 1, [7201]);
$allIds = array_map(static fn (array $call): string => (string) ($call['query']['order_ids'] ?? ''), Cap2DomainsWire::$calls);
$checkpointsFinal = (int) $pdo->query(
    "SELECT COUNT(*) FROM sale_financial_evidence
      WHERE company_id=7200 AND meli_account_id=7201 AND sale_key='P:870001'
        AND evidence_type='billing_capture' AND evidence_json LIKE '%\"format\":\"billing_order_v2\"%'"
)->fetchColumn();
$publicationsFinal = (int) $pdo->query(
    "SELECT COUNT(*) FROM meli_sale_financial_history h
      JOIN meli_sale_financials f ON f.id=h.meli_sale_financial_id
      WHERE f.company_id=7200 AND f.meli_account_id=7201 AND f.sale_key='P:870001' AND h.source='billing_official'"
)->fetchColumn();
$sourceFinal = (string) $pdo->query('SELECT status FROM sale_financial_reconciliation_jobs WHERE id=' . $sourceId)->fetchColumn();
$queueFinal = (string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=' . $queueId)->fetchColumn();

$result = [
    'billing_order_ids' => $allIds,
    'checkpoints' => $checkpointsFinal,
    'publications' => $publicationsFinal,
    'source_status' => $sourceFinal,
    'queue_state' => $queueFinal,
    'second_run' => $second,
    'real_meli_http' => 0,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert($allIds === $orders, 'k3_financial_two_individual_fifo_gets', $result);
r0h3_assert($checkpointsFinal === 2 && $publicationsFinal === 1, 'k3_financial_two_checkpoints_one_publication', $result);
r0h3_assert($sourceFinal === 'complete' && $queueFinal === 'completed', 'k3_financial_source_and_pointer_closed', $result);
Cap2DomainsWire::$onWire = null;
