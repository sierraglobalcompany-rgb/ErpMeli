<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

function r0h3_contradiction_admit_two(PDO $pdo, array $targets): array
{
    $admission = new App\Services\CronAdmissionService($pdo);
    $rows = [];
    foreach ($targets as $target) {
        $sourceId = (new App\Services\OrderEnrichmentService())->enqueue((int) $target['meli_account_id'], (int) $target['order_id'], 'pack', (string) $target['external_pack_id'], 10);
        $pdo->beginTransaction();
        $receipt = $admission->submit('order_enrichment_pack', (int) $target['company_id'], (int) $target['meli_account_id'], $sourceId, 'source:' . $sourceId, ['pack_id' => (string) $target['external_pack_id']]);
        $pdo->commit();
        r0h3_assert(!empty($receipt['accepted']), 'contradiction_setup_admission_expected', ['receipt' => $receipt]);
        $rows[] = ['source_id' => $sourceId, 'queue_id' => (int) $receipt['job_id'], 'target' => $target];
    }

    return $rows;
}

function r0h3_contradiction_close_with_extra_event(PDO $pdo, array $admitted, array $extra): void
{
    $target = $admitted['target'];
    $queueId = (int) $admitted['queue_id'];
    $sourceId = (int) $admitted['source_id'];
    $pdo->prepare("UPDATE order_resource_enrichment_jobs SET status='complete', completed_at=UTC_TIMESTAMP(3), failure_class=NULL, lock_token=NULL, locked_at=NULL WHERE id=?")->execute([$sourceId]);
    $pdo->prepare("UPDATE queue_v4_clean_jobs SET state='completed', completed_at=UTC_TIMESTAMP(3), lease_owner=NULL, lease_expires_at=NULL,last_error_class=NULL WHERE id=?")->execute([$queueId]);
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_attempts
         (job_id,run_id,company_id,meli_account_id,lease_owner,lease_generation,outcome,error_class,finished_at,dispatch_state,transport_method,endpoint_key,physical_http_calls,physical_started_at,http_status,response_known_at,source_closed_at)
         VALUES(?,997,?,?,?,0,'completed',NULL,UTC_TIMESTAMP(3),'RESPONSE_KNOWN','GET','pack_exact',1,UTC_TIMESTAMP(3),200,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))"
    )->execute([$queueId, (int) $target['company_id'], (int) $target['meli_account_id'], 'r0-h3-contradictory-close']);
    $attemptId = (int) $pdo->lastInsertId();
    foreach ([
        ['request_id' => 'r0-ok', 'method' => 'GET', 'endpoint_key' => 'pack_exact', 'http_status' => 200],
        $extra,
    ] as $event) {
        $pdo->prepare(
            "INSERT INTO queue_v4_clean_transport_events
             (company_id,meli_account_id,source_kind,work_id,attempt_id,lease_generation,request_id,method,endpoint_key,dispatch_state,physical_started_at,response_known_at,http_status)
             VALUES(?,?,'queue',?,?,0,?,?,?,'RESPONSE_KNOWN',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),?)"
        )->execute([
            (int) $target['company_id'],
            (int) $target['meli_account_id'],
            $queueId,
            $attemptId,
            (string) $event['request_id'],
            (string) $event['method'],
            (string) $event['endpoint_key'],
            (int) $event['http_status'],
        ]);
    }
}

function r0h3_contradiction_third_receipt(PDO $pdo, array $third): array
{
    $sourceId = (new App\Services\OrderEnrichmentService())->enqueue((int) $third['meli_account_id'], (int) $third['order_id'], 'pack', (string) $third['external_pack_id'], 10);
    $pdo->beginTransaction();
    $receipt = (new App\Services\CronAdmissionService($pdo))->submit('order_enrichment_pack', (int) $third['company_id'], (int) $third['meli_account_id'], $sourceId, 'source:' . $sourceId, ['pack_id' => (string) $third['external_pack_id']]);
    $pdo->commit();

    return $receipt;
}

$cases = [
    'http_status_differs' => ['request_id' => 'r0-http-500', 'method' => 'GET', 'endpoint_key' => 'pack_exact', 'http_status' => 500],
    'endpoint_differs' => ['request_id' => 'r0-other-endpoint', 'method' => 'GET', 'endpoint_key' => 'order_exact', 'http_status' => 200],
];
$results = [];
foreach ($cases as $name => $extraEvent) {
    $fixture = r0h3_seed($pdo);
    $admitted = r0h3_contradiction_admit_two($pdo, [$fixture['healthy'][0], $fixture['healthy'][1]]);
    r0h3_contradiction_close_with_extra_event($pdo, $admitted[0], $extraEvent);
    $receipt = r0h3_contradiction_third_receipt($pdo, $fixture['healthy'][2]);
    $results[$name] = $receipt;
    r0h3_assert(
        empty($receipt['accepted']) && ($receipt['reason'] ?? '') === 'R0_OCCUPANCY_EXHAUSTED',
        'contradictory_remote_event_set_must_not_free_slot_' . $name,
        ['receipt' => $receipt]
    );
}

echo json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

