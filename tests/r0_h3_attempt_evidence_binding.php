<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$fixture = r0h3_seed($pdo);
$target = $fixture['healthy'][0];
$historical = $fixture['historical'][0];

$sourceId = (new App\Services\OrderEnrichmentService())->enqueue(
    (int) $target['meli_account_id'],
    (int) $target['order_id'],
    'pack',
    (string) $target['external_pack_id'],
    10
);
$admission = new App\Services\CronAdmissionService($pdo);

$pdo->beginTransaction();
$first = $admission->submit('order_enrichment_pack', (int) $target['company_id'], (int) $target['meli_account_id'], $sourceId, 'source:' . $sourceId, ['pack_id' => (string) $target['external_pack_id']]);
$pdo->commit();
r0h3_assert(!empty($first['accepted']), 'h3_valid_attempt_baseline_must_admit', ['receipt' => $first]);

$pdo->prepare(
    "INSERT INTO queue_v4_clean_attempts
     (job_id,run_id,company_id,meli_account_id,lease_owner,lease_generation,outcome,dispatch_state)
     VALUES(?,999,?,?,?,1,'running','NOT_DISPATCHED')"
)->execute([
    (int) $historical['queue_id'],
    (int) $historical['company_id'],
    (int) $historical['meli_account_id'],
    'r0-h3-mutated-attempt',
]);

$secondTarget = $fixture['healthy'][1];
$secondSource = (new App\Services\OrderEnrichmentService())->enqueue(
    (int) $secondTarget['meli_account_id'],
    (int) $secondTarget['order_id'],
    'pack',
    (string) $secondTarget['external_pack_id'],
    10
);
$pdo->beginTransaction();
$second = $admission->submit('order_enrichment_pack', (int) $secondTarget['company_id'], (int) $secondTarget['meli_account_id'], $secondSource, 'source:' . $secondSource, ['pack_id' => (string) $secondTarget['external_pack_id']]);
$pdo->commit();

echo json_encode(['baseline' => $first, 'after_attempt_mutation' => $second], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(
    empty($second['accepted']) && ($second['reason'] ?? '') === 'R0_H3_AUTHORITY_CHANGED',
    'h3_authority_must_bind_queue_attempts',
    ['receipt' => $second]
);

