<?php

declare(strict_types=1);

require __DIR__ . '/k3_unit02_disposition_fixture.inc.php';

use App\QueueV4Clean\PackDiscoveryOccupancyPolicy;
use App\QueueV4Clean\PackDiscoveryUnit02DispositionService;
use App\Services\Cap2DomainsWire;
use App\Services\CronAdmissionService;
use App\Services\OrderEnrichmentService;

/** @return array{accepted:bool,job_id:?int,deduplicated:bool,reason:string} */
function k3u2_admit(PDO $pdo, array $target, int $sourceId): array
{
    $pdo->beginTransaction();
    try {
        $receipt = (new CronAdmissionService($pdo))->submit(
            'order_enrichment_pack',
            (int) $target['company_id'],
            (int) $target['meli_account_id'],
            $sourceId,
            'source:' . $sourceId,
            ['pack_id' => (string) $target['external_pack_id']],
        );
        $pdo->commit();
        return $receipt;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/** @return array{outstanding:int,administrative_dispositions:?int,physical_unknown_dispatches:?int,measurement_status:string,measurement_scope:string} */
function k3u2_continuity_measure(PDO $pdo): array
{
    $pdo->beginTransaction();
    try {
        $metrics = (new PackDiscoveryOccupancyPolicy($pdo))->measureForAccounts(k3u2_scope());
        $pdo->commit();
        return $metrics;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

$base = r0h3_seed($pdo);
$pdo->exec('DELETE FROM audit_logs');
$pdo->exec("INSERT INTO users(id,name,email,password_hash,role,status,is_temporary)
            VALUES(5001,'K3 synthetic owner','k3-owner@example.invalid','unused','admin',1,0)
            ON DUPLICATE KEY UPDATE role='admin',status=1,is_temporary=0");
$pdo->exec("DELETE FROM app_settings WHERE setting_key='queue_v4.pack_discovery_unit02_disposition'");
$unit01 = k3u2_create_unit($pdo, 'UNIT-01', 7201, false);
$unit02 = k3u2_create_unit($pdo, 'UNIT-02', 7202, true);
$fixture = ['unit01' => $unit01, 'unit02' => $unit02, 'healthy' => $base['healthy']];
r0h3_prepare_transport_fixture($pdo, 1);
$beforeHashes = [
    'unit01' => k3u2_unit_evidence_hash($pdo, $unit01),
    'unit02' => k3u2_unit_evidence_hash($pdo, $unit02),
    'h3' => k3u2_h3_hash($pdo, $base['historical']),
];

$applied = (new PackDiscoveryUnit02DispositionService($pdo))->apply(
    k3u2_authorized_evidence($pdo, $unit02),
    5001,
    'Felipe approved the unique non-renewable UNIT-02 occupancy disposition.',
    true,
);
r0h3_assert(($applied['status'] ?? '') === 'APPLIED', 'k3_unit02_continuity_authority_applied', $applied);

$restartOut = dirname(__DIR__) . '/storage/k3u2-local-20260920/evidence/restart-reader.json';
$restartErr = dirname(__DIR__) . '/storage/k3u2-local-20260920/evidence/restart-reader.err.txt';
$command = '"' . PHP_BINARY . '" "' . __DIR__ . '/k3_unit02_disposition_restart_reader.php"';
$process = proc_open($command, [1 => ['file', $restartOut, 'w'], 2 => ['file', $restartErr, 'w']], $pipes, dirname(__DIR__));
r0h3_assert(is_resource($process), 'k3_unit02_restart_process_started');
$restartExit = proc_close($process);
$restartMetrics = json_decode(trim((string) file_get_contents($restartOut)), true, 64, JSON_THROW_ON_ERROR);
r0h3_assert($restartExit === 0 && $restartMetrics === [
    'outstanding' => 1,
    'administrative_dispositions' => 1,
    'physical_unknown_dispatches' => 2,
    'measurement_status' => 'CERTIFIED',
    'measurement_scope' => 'H3_CERTIFIED_NON_H3_PACK_DISCOVERY',
], 'k3_unit02_restart_reads_same_authority', ['exit' => $restartExit, 'metrics' => $restartMetrics]);

$first = $fixture['healthy'][0];
$second = $fixture['healthy'][1];
$third = $fixture['healthy'][2];
$firstSource = (new OrderEnrichmentService())->enqueue(
    (int) $first['meli_account_id'], (int) $first['order_id'], 'pack', (string) $first['external_pack_id'], 10
);
$firstReceipt = k3u2_admit($pdo, $first, $firstSource);
r0h3_assert(!empty($firstReceipt['accepted']) && !empty($firstReceipt['job_id']),
    'k3_unit02_one_healthy_unit_admitted_through_canonical_service', $firstReceipt);

$secondSource = (new OrderEnrichmentService())->enqueue(
    (int) $second['meli_account_id'], (int) $second['order_id'], 'pack', (string) $second['external_pack_id'], 10
);
$blockedReceipt = k3u2_admit($pdo, $second, $secondSource);
r0h3_assert(empty($blockedReceipt['accepted']) && ($blockedReceipt['reason'] ?? '') === 'R0_OCCUPANCY_EXHAUSTED',
    'k3_unit02_next_healthy_unit_blocked_at_two_operational_occupants', $blockedReceipt);
r0h3_assert(k3u2_continuity_measure($pdo)['outstanding'] === 2,
    'k3_unit02_occupancy_is_two_after_one_new_admission');

$firstOrder = $pdo->prepare(
    'SELECT external_order_id FROM meli_orders WHERE id=? AND meli_account_id=? LIMIT 1'
);
$firstOrder->execute([(int) $first['order_id'], (int) $first['meli_account_id']]);
$firstExternalOrder = (string) $firstOrder->fetchColumn();
Cap2DomainsWire::$calls = [];
Cap2DomainsWire::$responses = [
    '/packs/' . (string) $first['external_pack_id'] => [200, [
        'id' => (int) $first['external_pack_id'],
        'status' => 'confirmed',
        'shipment' => ['id' => null],
        'buyer' => ['id' => 123],
        'orders' => [['id' => (int) $firstExternalOrder]],
    ]],
];
r0h3_wait_for_global_rhythm($pdo);
$worker = r0h3_worker_run($pdo, 1, [(int) $first['meli_account_id']]);
$closed = $pdo->prepare(
    'SELECT q.state queue_state,j.status source_status,p.integrity_status
       FROM queue_v4_clean_jobs q
       JOIN order_resource_enrichment_jobs j ON j.id=? AND j.meli_account_id=q.meli_account_id
       JOIN meli_packs p ON p.meli_account_id=j.meli_account_id AND p.external_pack_id=j.external_resource_id
      WHERE q.id=? LIMIT 1'
);
$closed->execute([$firstSource, (int) $firstReceipt['job_id']]);
$closedRow = $closed->fetch(PDO::FETCH_ASSOC) ?: [];
r0h3_assert(count(Cap2DomainsWire::$calls) === 1
    && ($closedRow['queue_state'] ?? '') === 'completed'
    && ($closedRow['source_status'] ?? '') === 'complete'
    && ($closedRow['integrity_status'] ?? '') === 'complete',
    'k3_unit02_real_worker_with_simulated_wire_closes_healthy_unit', ['worker' => $worker, 'closed' => $closedRow]);
r0h3_assert(k3u2_continuity_measure($pdo)['outstanding'] === 1,
    'k3_unit02_closed_healthy_unit_returns_one_slot');

$secondReceipt = k3u2_admit($pdo, $second, $secondSource);
r0h3_assert(!empty($secondReceipt['accepted']) && !empty($secondReceipt['job_id']),
    'k3_unit02_returned_slot_is_reused_by_canonical_admission', $secondReceipt);

$past = gmdate('Y-m-d H:i:s', time() - 60);
$pdo->prepare("UPDATE order_resource_enrichment_jobs SET status='retry',attempts=1,next_run_at=?,failure_class='remote_result_uncertain_safe_get',reached_remote=1,lease_generation=1,lock_token=NULL,locked_at=NULL WHERE id=? AND meli_account_id=?")
    ->execute([$past, $secondSource, (int) $second['meli_account_id']]);
$pdo->prepare("UPDATE queue_v4_clean_jobs SET state='review',available_at=?,attempt_count=1,lease_owner=NULL,lease_expires_at=NULL,lease_generation=1,last_error_class='remote_result_uncertain_safe_get',completed_at=NULL WHERE id=? AND company_id=? AND meli_account_id=?")
    ->execute([$past, (int) $secondReceipt['job_id'], (int) $second['company_id'], (int) $second['meli_account_id']]);
$newAttempt = r0h3_insert($pdo, 'queue_v4_clean_attempts', [
    'job_id' => (int) $secondReceipt['job_id'],
    'run_id' => 993001,
    'company_id' => (int) $second['company_id'],
    'meli_account_id' => (int) $second['meli_account_id'],
    'lease_owner' => 'k3-unit02-new-uncertain',
    'lease_generation' => 1,
    'outcome' => 'review',
    'error_class' => 'remote_result_uncertain_safe_get',
    'dispatch_state' => 'PHYSICAL_STARTED',
    'transport_method' => 'GET',
    'endpoint_key' => 'pack_exact',
    'physical_http_calls' => 1,
    'physical_started_at' => $past,
    'started_at' => $past,
    'finished_at' => $past,
]);
r0h3_insert($pdo, 'queue_v4_clean_transport_events', [
    'company_id' => (int) $second['company_id'],
    'meli_account_id' => (int) $second['meli_account_id'],
    'source_kind' => 'queue',
    'work_id' => (int) $secondReceipt['job_id'],
    'attempt_id' => $newAttempt,
    'lease_generation' => 1,
    'request_id' => 'k3-unit02-new-uncertain-' . (int) $secondReceipt['job_id'],
    'method' => 'GET',
    'endpoint_key' => 'pack_exact',
    'dispatch_state' => 'PHYSICAL_STARTED',
    'physical_started_at' => $past,
]);
$uncertainMetrics = k3u2_continuity_measure($pdo);
r0h3_assert($uncertainMetrics === [
    'outstanding' => 2,
    'administrative_dispositions' => 1,
    'physical_unknown_dispatches' => 3,
    'measurement_status' => 'CERTIFIED',
    'measurement_scope' => 'H3_CERTIFIED_NON_H3_PACK_DISCOVERY',
], 'k3_unit02_new_uncertainty_occupies_normally', $uncertainMetrics);

$thirdSource = (new OrderEnrichmentService())->enqueue(
    (int) $third['meli_account_id'], (int) $third['order_id'], 'pack', (string) $third['external_pack_id'], 10
);
$thirdReceipt = k3u2_admit($pdo, $third, $thirdSource);
r0h3_assert(empty($thirdReceipt['accepted']) && ($thirdReceipt['reason'] ?? '') === 'R0_OCCUPANCY_EXHAUSTED',
    'k3_unit02_new_uncertainty_stops_further_admission', $thirdReceipt);
r0h3_assert(k3u2_unit_evidence_hash($pdo, $unit01) === $beforeHashes['unit01']
    && k3u2_unit_evidence_hash($pdo, $unit02) === $beforeHashes['unit02']
    && k3u2_h3_hash($pdo, $base['historical']) === $beforeHashes['h3'],
    'k3_unit02_unit01_unit02_and_h3_preserved_during_continuity');

echo json_encode([
    'status' => 'PASS',
    'restart_metrics' => $restartMetrics,
    'first_admission' => $firstReceipt['reason'],
    'second_initial_admission' => $blockedReceipt['reason'],
    'healthy_worker_simulated_calls' => count(Cap2DomainsWire::$calls),
    'second_after_slot_return' => $secondReceipt['reason'],
    'new_uncertainty_metrics' => $uncertainMetrics,
    'third_admission' => $thirdReceipt['reason'],
    'unit01' => 'RETAINED',
    'unit02_historical_response' => 'UNKNOWN',
    'real_meli_http' => 0,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
