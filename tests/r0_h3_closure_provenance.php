<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$fixture = r0h3_seed($pdo);
$healthy = $fixture['healthy'];
$admission = new App\Services\CronAdmissionService($pdo);
$admitted = [];

for ($i = 0; $i < 2; $i++) {
    $target = $healthy[$i];
    $sourceId = (new App\Services\OrderEnrichmentService())->enqueue((int) $target['meli_account_id'], (int) $target['order_id'], 'pack', (string) $target['external_pack_id'], 10);
    $pdo->beginTransaction();
    $receipt = $admission->submit('order_enrichment_pack', (int) $target['company_id'], (int) $target['meli_account_id'], $sourceId, 'source:' . $sourceId, ['pack_id' => (string) $target['external_pack_id']]);
    $pdo->commit();
    r0h3_assert(!empty($receipt['accepted']), 'closure_setup_admission_expected', ['receipt' => $receipt]);
    $admitted[] = ['source_id' => $sourceId, 'queue_id' => (int) $receipt['job_id'], 'target' => $target];
}

$pdo->prepare("UPDATE order_resource_enrichment_jobs SET status='complete', completed_at=UTC_TIMESTAMP(3), failure_class=NULL, lock_token=NULL, locked_at=NULL WHERE id=?")->execute([$admitted[0]['source_id']]);
$pdo->prepare("UPDATE queue_v4_clean_jobs SET state='completed', completed_at=UTC_TIMESTAMP(3), lease_owner=NULL, lease_expires_at=NULL,last_error_class=NULL WHERE id=?")->execute([$admitted[0]['queue_id']]);
$pdo->prepare(
    "INSERT INTO queue_v4_clean_attempts
     (job_id,run_id,company_id,meli_account_id,lease_owner,lease_generation,outcome,dispatch_state,physical_http_calls)
     VALUES(?,998,?,?,?,0,'completed','NOT_DISPATCHED',0)"
)->execute([
    $admitted[0]['queue_id'],
    (int) $admitted[0]['target']['company_id'],
    (int) $admitted[0]['target']['meli_account_id'],
    'r0-h3-incomplete-close',
]);

$third = $healthy[2];
$thirdSource = (new App\Services\OrderEnrichmentService())->enqueue((int) $third['meli_account_id'], (int) $third['order_id'], 'pack', (string) $third['external_pack_id'], 10);
$pdo->beginTransaction();
$thirdReceipt = $admission->submit('order_enrichment_pack', (int) $third['company_id'], (int) $third['meli_account_id'], $thirdSource, 'source:' . $thirdSource, ['pack_id' => (string) $third['external_pack_id']]);
$pdo->commit();

echo json_encode(['third_after_incomplete_close' => $thirdReceipt], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(
    empty($thirdReceipt['accepted']) && ($thirdReceipt['reason'] ?? '') === 'R0_OCCUPANCY_EXHAUSTED',
    'incomplete_closure_attempt_must_not_free_slot',
    ['receipt' => $thirdReceipt]
);

