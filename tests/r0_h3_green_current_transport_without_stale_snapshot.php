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
    r0h3_assert(!empty($receipt['accepted']), 'setup_admission_expected', ['receipt' => $receipt]);
    $admitted[] = ['source_id' => $sourceId, 'queue_id' => (int) $receipt['job_id'], 'target' => $target];
}

$pdo->prepare("UPDATE order_resource_enrichment_jobs SET status='complete', completed_at=UTC_TIMESTAMP(3), failure_class=NULL, lock_token=NULL, locked_at=NULL WHERE id=?")->execute([$admitted[0]['source_id']]);
$pdo->prepare("UPDATE queue_v4_clean_jobs SET state='completed', completed_at=UTC_TIMESTAMP(3), lease_owner=NULL, lease_expires_at=NULL,last_error_class=NULL WHERE id=?")->execute([$admitted[0]['queue_id']]);
$pdo->prepare(
    "INSERT INTO queue_v4_clean_transport_events(company_id,meli_account_id,source_kind,work_id,lease_generation,request_id,method,endpoint_key,dispatch_state,physical_started_at,response_known_at)
     VALUES(?,?,?,?,1,?,'GET','pack_exact','PHYSICAL_STARTED',UTC_TIMESTAMP(3),NULL)"
)->execute([
    (int) $admitted[0]['target']['company_id'],
    (int) $admitted[0]['target']['meli_account_id'],
    'queue',
    $admitted[0]['queue_id'],
    'r0-h3-current-transport-' . bin2hex(random_bytes(4)),
]);

$third = $healthy[2];
$thirdSource = (new App\Services\OrderEnrichmentService())->enqueue((int) $third['meli_account_id'], (int) $third['order_id'], 'pack', (string) $third['external_pack_id'], 10);
$pdo->beginTransaction();
$thirdReceipt = $admission->submit('order_enrichment_pack', (int) $third['company_id'], (int) $third['meli_account_id'], $thirdSource, 'source:' . $thirdSource, ['pack_id' => (string) $third['external_pack_id']]);
$pdo->commit();

echo json_encode(['third_receipt' => $thirdReceipt], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(
    empty($thirdReceipt['accepted']) && ($thirdReceipt['reason'] ?? '') === 'R0_OCCUPANCY_UNAVAILABLE',
    'orphaned_current_transport_must_fail_closed_as_unavailable',
    ['receipt' => $thirdReceipt]
);
