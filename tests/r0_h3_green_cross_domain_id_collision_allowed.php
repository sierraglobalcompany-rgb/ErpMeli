<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$fixture = r0h3_seed($pdo);
$healthy = $fixture['healthy'];

$target = $healthy[0];
$packSourceId = (new App\Services\OrderEnrichmentService())->enqueue((int) $target['meli_account_id'], (int) $target['order_id'], 'pack', (string) $target['external_pack_id'], 10);

$pdo->prepare(
    "INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
     VALUES(7200,7201,'domain_exact',?,'domain:financial_reconciliation:collision',?,'waiting','2000-01-01')"
)->execute([
    (string) $packSourceId,
    json_encode(['capability' => 'financial_reconciliation', 'source_id' => $packSourceId, 'payload' => ['pack_id' => (string) $target['external_pack_id']]], JSON_UNESCAPED_SLASHES),
]);

$second = $healthy[1];
$secondSource = (new App\Services\OrderEnrichmentService())->enqueue((int) $second['meli_account_id'], (int) $second['order_id'], 'pack', (string) $second['external_pack_id'], 10);
$pdo->beginTransaction();
$receipt = (new App\Services\CronAdmissionService($pdo))->submit('order_enrichment_pack', (int) $second['company_id'], (int) $second['meli_account_id'], $secondSource, 'source:' . $secondSource, ['pack_id' => (string) $second['external_pack_id']]);
$pdo->commit();

echo json_encode(['receipt' => $receipt], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(
    !empty($receipt['accepted']) && empty($receipt['deduplicated']),
    'cross_domain_numeric_id_collision_must_not_block_pack_admission',
    ['receipt' => $receipt]
);

