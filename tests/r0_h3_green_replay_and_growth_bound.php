<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$fixture = r0h3_seed($pdo);
$healthy = $fixture['healthy'];
$admission = new App\Services\CronAdmissionService($pdo);

$first = $healthy[0];
$firstSource = (new App\Services\OrderEnrichmentService())->enqueue(
    (int) $first['meli_account_id'],
    (int) $first['order_id'],
    'pack',
    (string) $first['external_pack_id'],
    10
);

$pdo->beginTransaction();
$firstReceipt = $admission->submit(
    'order_enrichment_pack',
    (int) $first['company_id'],
    (int) $first['meli_account_id'],
    $firstSource,
    'source:' . $firstSource,
    ['pack_id' => (string) $first['external_pack_id']]
);
$pdo->commit();
r0h3_assert(!empty($firstReceipt['accepted']) && empty($firstReceipt['deduplicated']), 'initial_admission_expected', ['receipt' => $firstReceipt]);

$pdo->beginTransaction();
$replayReceipt = $admission->submit(
    'order_enrichment_pack',
    (int) $first['company_id'],
    (int) $first['meli_account_id'],
    $firstSource,
    'source:' . $firstSource,
    ['pack_id' => (string) $first['external_pack_id']]
);
$pdo->commit();
r0h3_assert(!empty($replayReceipt['accepted']) && !empty($replayReceipt['deduplicated']), 'same_source_same_key_replay_must_remain_idempotent', ['receipt' => $replayReceipt]);

for ($i = 1; $i < count($healthy); $i++) {
    $target = $healthy[$i];
    $sourceId = (new App\Services\OrderEnrichmentService())->enqueue(
        (int) $target['meli_account_id'],
        (int) $target['order_id'],
        'pack',
        (string) $target['external_pack_id'],
        10
    );
    $pdo->beginTransaction();
    $receipt = $admission->submit(
        'order_enrichment_pack',
        (int) $target['company_id'],
        (int) $target['meli_account_id'],
        $sourceId,
        'source:' . $sourceId,
        ['pack_id' => (string) $target['external_pack_id']]
    );
    $pdo->commit();
    if ($i === 1) {
        r0h3_assert(!empty($receipt['accepted']) && empty($receipt['deduplicated']), 'second_unique_admission_expected', ['receipt' => $receipt]);
        continue;
    }
    r0h3_assert(empty($receipt['accepted']) && ($receipt['reason'] ?? '') === 'R0_OCCUPANCY_EXHAUSTED', 'additional_unique_admissions_must_not_grow_past_two', ['i' => $i, 'receipt' => $receipt]);
}

$count = (int) $pdo->query(
    "SELECT COUNT(DISTINCT q.company_id, q.meli_account_id, q.resource_id)
     FROM queue_v4_clean_jobs q
     WHERE q.job_type='domain_exact'
       AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'
       AND q.company_id=7200"
)->fetchColumn();

echo json_encode(['first' => $firstReceipt, 'replay' => $replayReceipt, 'bounded_units' => $count], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert($count === 2, 'new_pack_discovery_units_must_remain_bounded_at_two', ['count' => $count]);

