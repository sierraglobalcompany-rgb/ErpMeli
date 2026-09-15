<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$fixture = r0h3_seed($pdo);
$h3 = $fixture['historical'][0];
$pdo->prepare(
    "INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
     VALUES(?,?,?,?,? ,?,'waiting','2000-01-01')"
)->execute([
    (int) $h3['company_id'],
    (int) $h3['meli_account_id'],
    'domain_exact',
    (string) $h3['source_id'],
    'domain:order_enrichment_pack:h3-extra',
    json_encode(['capability' => 'order_enrichment_pack', 'source_id' => (int) $h3['source_id']], JSON_UNESCAPED_SLASHES),
]);

$target = $fixture['healthy'][0];
$sourceId = (new App\Services\OrderEnrichmentService())->enqueue((int) $target['meli_account_id'], (int) $target['order_id'], 'pack', (string) $target['external_pack_id'], 10);
$pdo->beginTransaction();
$receipt = (new App\Services\CronAdmissionService($pdo))->submit('order_enrichment_pack', (int) $target['company_id'], (int) $target['meli_account_id'], $sourceId, 'source:' . $sourceId, ['pack_id' => (string) $target['external_pack_id']]);
$pdo->commit();

echo json_encode(['receipt' => $receipt], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(empty($receipt['accepted']) && ($receipt['reason'] ?? '') === 'R0_H3_AUTHORITY_CHANGED', 'additional_pointer_for_h3_source_must_invalidate_exception', ['receipt' => $receipt]);

