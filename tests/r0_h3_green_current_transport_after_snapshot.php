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

$third = $healthy[2];
$thirdSource = (new App\Services\OrderEnrichmentService())->enqueue((int) $third['meli_account_id'], (int) $third['order_id'], 'pack', (string) $third['external_pack_id'], 10);

$pdoB = new PDO('mysql:host=127.0.0.1;port=' . getenv('R0_H3_DB_PORT') . ';dbname=' . getenv('R0_H3_DB_NAME') . ';charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdoB->beginTransaction();
$staleSeen = (int) $pdoB->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='domain_exact' AND company_id=7200")->fetchColumn();

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

$receiptB = (new App\Services\CronAdmissionService($pdoB))->submit('order_enrichment_pack', (int) $third['company_id'], (int) $third['meli_account_id'], $thirdSource, 'source:' . $thirdSource, ['pack_id' => (string) $third['external_pack_id']]);
$pdoB->commit();

echo json_encode(['stale_seen' => $staleSeen, 'third_from_stale_tx' => $receiptB], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(
    empty($receiptB['accepted']) && in_array(($receiptB['reason'] ?? ''), ['R0_OCCUPANCY_EXHAUSTED', 'R0_H3_AUTHORITY_UNAVAILABLE', 'R0_OCCUPANCY_UNAVAILABLE'], true),
    'current_transport_evidence_after_snapshot_must_fail_closed',
    ['receipt' => $receiptB]
);
