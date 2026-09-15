<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

function r0h3_admit_pair(PDO $pdo, array $targets): array
{
    $admission = new App\Services\CronAdmissionService($pdo);
    $admitted = [];
    foreach ($targets as $target) {
        $sourceId = (new App\Services\OrderEnrichmentService())->enqueue((int) $target['meli_account_id'], (int) $target['order_id'], 'pack', (string) $target['external_pack_id'], 10);
        $pdo->beginTransaction();
        $receipt = $admission->submit('order_enrichment_pack', (int) $target['company_id'], (int) $target['meli_account_id'], $sourceId, 'source:' . $sourceId, ['pack_id' => (string) $target['external_pack_id']]);
        $pdo->commit();
        r0h3_assert(!empty($receipt['accepted']), 'transport_closure_setup_admission_expected', ['receipt' => $receipt]);
        $admitted[] = ['source_id' => $sourceId, 'queue_id' => (int) $receipt['job_id'], 'target' => $target];
    }

    return $admitted;
}

function r0h3_mark_remote_closed(PDO $pdo, array $admitted, bool $duplicateEvent): void
{
    $target = $admitted['target'];
    $queueId = (int) $admitted['queue_id'];
    $sourceId = (int) $admitted['source_id'];
    $pdo->prepare("UPDATE order_resource_enrichment_jobs SET status='complete', completed_at=UTC_TIMESTAMP(3), failure_class=NULL, lock_token=NULL, locked_at=NULL WHERE id=?")->execute([$sourceId]);
    $pdo->prepare("UPDATE queue_v4_clean_jobs SET state='completed', completed_at=UTC_TIMESTAMP(3), lease_owner=NULL, lease_expires_at=NULL,last_error_class=NULL WHERE id=?")->execute([$queueId]);
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_attempts
         (job_id,run_id,company_id,meli_account_id,lease_owner,lease_generation,outcome,error_class,finished_at,dispatch_state,transport_method,endpoint_key,physical_http_calls,physical_started_at,http_status,response_known_at,source_closed_at)
         VALUES(?,998,?,?,?,0,'completed',NULL,UTC_TIMESTAMP(3),'RESPONSE_KNOWN','GET','pack_exact',1,UTC_TIMESTAMP(3),200,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))"
    )->execute([$queueId, (int) $target['company_id'], (int) $target['meli_account_id'], 'r0-h3-remote-close']);
    $attemptId = (int) $pdo->lastInsertId();
    foreach ($duplicateEvent ? ['r0-remote-1', 'r0-remote-2'] : ['r0-remote-1'] as $requestId) {
        $pdo->prepare(
            "INSERT INTO queue_v4_clean_transport_events
             (company_id,meli_account_id,source_kind,work_id,attempt_id,lease_generation,request_id,method,endpoint_key,dispatch_state,physical_started_at,response_known_at,http_status)
             VALUES(?,?,'queue',?,?,0,?,'GET','pack_exact','RESPONSE_KNOWN',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),200)"
        )->execute([(int) $target['company_id'], (int) $target['meli_account_id'], $queueId, $attemptId, $requestId]);
    }
}

$fixture = r0h3_seed($pdo);
$admitted = r0h3_admit_pair($pdo, [$fixture['healthy'][0], $fixture['healthy'][1]]);
r0h3_mark_remote_closed($pdo, $admitted[0], false);
$third = $fixture['healthy'][2];
$thirdSource = (new App\Services\OrderEnrichmentService())->enqueue((int) $third['meli_account_id'], (int) $third['order_id'], 'pack', (string) $third['external_pack_id'], 10);
$pdo->beginTransaction();
$acceptedAfterCertifiedRemoteClose = (new App\Services\CronAdmissionService($pdo))->submit('order_enrichment_pack', (int) $third['company_id'], (int) $third['meli_account_id'], $thirdSource, 'source:' . $thirdSource, ['pack_id' => (string) $third['external_pack_id']]);
$pdo->commit();

$fixture = r0h3_seed($pdo);
$admitted = r0h3_admit_pair($pdo, [$fixture['healthy'][0], $fixture['healthy'][1]]);
r0h3_mark_remote_closed($pdo, $admitted[0], true);
$third = $fixture['healthy'][2];
$thirdSource = (new App\Services\OrderEnrichmentService())->enqueue((int) $third['meli_account_id'], (int) $third['order_id'], 'pack', (string) $third['external_pack_id'], 10);
$pdo->beginTransaction();
$deniedAfterAmbiguousRemoteClose = (new App\Services\CronAdmissionService($pdo))->submit('order_enrichment_pack', (int) $third['company_id'], (int) $third['meli_account_id'], $thirdSource, 'source:' . $thirdSource, ['pack_id' => (string) $third['external_pack_id']]);
$pdo->commit();

echo json_encode([
    'after_certified_remote_close' => $acceptedAfterCertifiedRemoteClose,
    'after_ambiguous_remote_close' => $deniedAfterAmbiguousRemoteClose,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(!empty($acceptedAfterCertifiedRemoteClose['accepted']), 'certified_remote_closure_must_free_slot', ['receipt' => $acceptedAfterCertifiedRemoteClose]);
r0h3_assert(
    empty($deniedAfterAmbiguousRemoteClose['accepted']) && ($deniedAfterAmbiguousRemoteClose['reason'] ?? '') === 'R0_OCCUPANCY_EXHAUSTED',
    'ambiguous_remote_closure_must_not_free_slot',
    ['receipt' => $deniedAfterAmbiguousRemoteClose]
);
