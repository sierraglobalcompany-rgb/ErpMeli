<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$target = [
    'company_id' => 7200,
    'meli_account_id' => 7201,
    'external_pack_id' => '990001901',
    'order_id' => r0h3_insert($pdo, 'meli_orders', [
        'meli_account_id' => 7201,
        'external_order_id' => '990001001',
        'external_pack_id' => '990001901',
        'status' => 'paid',
        'synced_at' => gmdate('Y-m-d H:i:s', time() - 3600),
    ]),
];
$packId = r0h3_insert($pdo, 'meli_packs', [
    'meli_account_id' => 7201,
    'external_pack_id' => (string) $target['external_pack_id'],
    'status' => 'unknown',
    'integrity_status' => 'provisional',
    'synced_at' => gmdate('Y-m-d H:i:s', time() - 3600),
]);
r0h3_insert($pdo, 'meli_pack_orders', ['meli_pack_id' => $packId, 'meli_order_id' => (int) $target['order_id']]);
$sourceId = (new App\Services\OrderEnrichmentService())->enqueue(7201, (int) $target['order_id'], 'pack', (string) $target['external_pack_id'], 10);
$pdo->beginTransaction();
$receipt = (new App\Services\CronAdmissionService($pdo))->submit('order_enrichment_pack', 7200, 7201, $sourceId, 'source:' . $sourceId, ['pack_id' => (string) $target['external_pack_id']]);
$pdo->commit();
$open = r0h3_open_new_pack_units($pdo);
echo json_encode(['receipt' => $receipt, 'open' => $open], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(empty($receipt['accepted']) && ($receipt['reason'] ?? '') === 'R0_OCCUPANCY_EXHAUSTED' && $open === 2, 'restart_probe_must_see_same_retained_two_units', ['receipt' => $receipt, 'open' => $open]);
