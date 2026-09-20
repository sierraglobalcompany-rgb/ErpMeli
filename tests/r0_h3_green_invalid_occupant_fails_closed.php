<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$fixture = r0h3_seed($pdo);
$healthy = $fixture['healthy'];

$pdo->prepare(
    "INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
     VALUES(7200,7201,'domain_exact','not-a-source','domain:order_enrichment_pack:bad',?,'ready','2000-01-01')"
)->execute([json_encode(['capability' => 'order_enrichment_pack', 'source_id' => 999999, 'payload' => ['pack_id' => 'bad']], JSON_UNESCAPED_SLASHES)]);

$target = $healthy[0];
$sourceId = (new App\Services\OrderEnrichmentService())->enqueue((int) $target['meli_account_id'], (int) $target['order_id'], 'pack', (string) $target['external_pack_id'], 10);
$pdo->beginTransaction();
$receipt = (new App\Services\CronAdmissionService($pdo))->submit('order_enrichment_pack', (int) $target['company_id'], (int) $target['meli_account_id'], $sourceId, 'source:' . $sourceId, ['pack_id' => (string) $target['external_pack_id']]);
$pdo->commit();

echo json_encode(['receipt' => $receipt], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(empty($receipt['accepted']) && ($receipt['reason'] ?? '') === 'R0_OCCUPANCY_UNAVAILABLE', 'invalid_existing_occupant_must_fail_closed_as_unavailable', ['receipt' => $receipt]);
