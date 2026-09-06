<?php
declare(strict_types=1);
require __DIR__ . '/cap2_manual_fixture.php';

use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\Cap2DomainsWire;
use App\Services\RemoteResultUncertainException;
use App\Services\SalesAuditRunService;
use App\Services\SalesAuditExactRepairService;
use App\Services\OrderEnrichmentService;

$h = cap2_manual_database();
$failures = [];
$check = static function (bool $ok, string $name) use (&$failures): void {
    echo ($ok ? 'PASS=' : 'FAIL=') . $name . "\n";
    if (!$ok) $failures[] = $name;
};
$uncertain = static function (callable $call): bool {
    try { $call(); return false; } catch (RemoteResultUncertainException) { return true; }
};
try {
    $pdo = $h->pdo();
    Cap2DomainsWire::$onWire = static function (): void { throw new RuntimeException('fixture_wire_response_lost'); };
    Cap2DomainsWire::$responses['/orders/search'] = [0, []];
    QueueV4CleanCycleBudget::start(100);
    $audit = new SalesAuditRunService();
    $auditId = $audit->createExactMonth(9011, 2026, 8, 9007, 9001);
    $escaped = $uncertain(fn() => $audit->processDue(2));
    $row = $pdo->query('SELECT status,last_error_retryable,remote_dispatch_state,next_offset FROM sync_sales_audit_jobs WHERE id=' . $auditId)->fetch();
    $check($escaped && $row['status'] === 'error' && (int)$row['last_error_retryable'] === 0 && $row['remote_dispatch_state'] === 'PHYSICAL_STARTED', 'audit_uncertain_terminal_and_propagated');
    $before = count(Cap2DomainsWire::$calls);
    $pdo->exec('UPDATE sync_sales_audit_jobs SET next_run_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=' . $auditId);
    $audit->processDue(2);
    $check(count(Cap2DomainsWire::$calls) === $before, 'audit_no_automatic_readmission');
    $pdo->exec('UPDATE sync_sales_audit_jobs SET status="waiting_budget" WHERE id=' . $auditId);
    $auditClaim = new ReflectionMethod(SalesAuditRunService::class, 'claim');
    $check($auditClaim->invoke($audit, 'historical-audit', $auditId) === null, 'historical_audit_uncertain_not_claimed');
    $pdo->exec('UPDATE sync_sales_audit_jobs SET status="error" WHERE id=' . $auditId);
    QueueV4CleanCycleBudget::clear();

    // Isolated next scenario: expire only fixture permits, retain all physical journals.
    $pdo->exec("UPDATE api_remote_permits SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE company_id=9001 AND meli_account_id=9011 AND status='dispatched'");
    $runId = (int)$pdo->query('SELECT sync_sales_audit_run_id FROM sync_sales_audit_jobs WHERE id=' . $auditId)->fetchColumn();
    $pdo->prepare('INSERT INTO sync_sales_repair_jobs(sync_sales_audit_run_id,source_kind,meli_account_id,company_id,period_year,period_month,status,next_run_at,total_items) VALUES(?,"exact",9011,9001,2026,8,"pending",UTC_TIMESTAMP(),2)')->execute([$runId]);
    $repairId = (int)$pdo->lastInsertId();
    foreach (['8301', '8302'] as $id) {
        $pdo->prepare('INSERT INTO sync_sales_repair_job_items(sync_sales_repair_job_id,external_order_id,action,status,next_run_at) VALUES(?,?,"fetch_missing","pending",UTC_TIMESTAMP())')->execute([$repairId,$id]);
        Cap2DomainsWire::$responses['/orders/' . $id] = [0, []];
    }
    QueueV4CleanCycleBudget::start(100);
    $before = count(Cap2DomainsWire::$calls);
    $repair = new SalesAuditExactRepairService();
    $escaped = $uncertain(fn() => $repair->processDue(2));
    $check($escaped && count(Cap2DomainsWire::$calls) === $before + 1, 'repair_uncertain_stops_remaining_selection');
    $check($pdo->query('SELECT status FROM sync_sales_repair_jobs WHERE id=' . $repairId)->fetchColumn() === 'error', 'repair_uncertain_requires_explicit_review');
    $pdo->exec('UPDATE sync_sales_repair_jobs SET next_run_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=' . $repairId);
    $pdo->exec('UPDATE sync_sales_repair_job_items SET next_run_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE sync_sales_repair_job_id=' . $repairId);
    $before = count(Cap2DomainsWire::$calls);
    $repair->processDue(2);
    $check(count(Cap2DomainsWire::$calls) === $before, 'repair_no_automatic_retry');
    $pdo->exec('UPDATE sync_sales_repair_jobs SET status="retry" WHERE id=' . $repairId);
    $repairClaim = new ReflectionMethod(SalesAuditExactRepairService::class, 'claim');
    $check($repairClaim->invoke($repair, 'historical-repair', $repairId) === null, 'historical_repair_uncertain_not_claimed');
    $pdo->exec('UPDATE sync_sales_repair_jobs SET status="error" WHERE id=' . $repairId);

    $pdo->exec("UPDATE api_remote_permits SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE company_id=9001 AND meli_account_id=9011 AND status='dispatched'");
    $raceAuditId = $audit->createExactMonth(9011, 2026, 7, 9007, 9001);
    $raceRunId = (int)$pdo->query('SELECT sync_sales_audit_run_id FROM sync_sales_audit_jobs WHERE id=' . $raceAuditId)->fetchColumn();
    $pdo->exec('UPDATE sync_sales_audit_jobs SET status="error" WHERE id=' . $raceAuditId);
    $pdo->prepare('INSERT INTO sync_sales_repair_jobs(sync_sales_audit_run_id,source_kind,meli_account_id,company_id,period_year,period_month,status,next_run_at,total_items) VALUES(?,"exact",9011,9001,2026,7,"pending",UTC_TIMESTAMP(),1)')->execute([$raceRunId]);
    $raceId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO sync_sales_repair_job_items(sync_sales_repair_job_id,external_order_id,action,status,next_run_at) VALUES(?,"8303","fetch_missing","pending",UTC_TIMESTAMP())')->execute([$raceId]);
    Cap2DomainsWire::$responses['/orders/8303'] = [0, []];
    Cap2DomainsWire::$onWire = static function () use ($pdo, $raceId): void {
        $pdo->exec('UPDATE sync_sales_repair_jobs SET lock_owner="replacement",lease_generation=lease_generation+1 WHERE id=' . $raceId);
        throw new RuntimeException('fixture_wire_owner_changed');
    };
    usleep(2200000);
    $uncertain(fn() => $repair->processDue(1));
    $check($pdo->query('SELECT status FROM sync_sales_repair_job_items WHERE sync_sales_repair_job_id=' . $raceId)->fetchColumn() === 'running', 'lost_owner_cannot_finish_repair_item');
    Cap2DomainsWire::$onWire = static function (): void { throw new RuntimeException('fixture_wire_response_lost'); };
    QueueV4CleanCycleBudget::clear();

    $pdo->exec("UPDATE api_remote_permits SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE company_id=9001 AND meli_account_id=9011 AND status='dispatched'");
    $pdo->exec("INSERT INTO meli_orders(meli_account_id,external_order_id,status,synced_at) VALUES(9011,8390,'paid',UTC_TIMESTAMP())");
    $orderId = (int)$pdo->lastInsertId();
    $enrichment = new OrderEnrichmentService();
    $packId = $enrichment->enqueue(9011,$orderId,'pack','8391');
    $pdo->prepare('INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json) VALUES(9001,9011,"domain_exact",?,?,?)')->execute([(string)$packId,'uncertain-pack',json_encode(['capability'=>'order_enrichment_pack','source_id'=>$packId])]);
    $pointerId = (int)$pdo->lastInsertId();
    Cap2DomainsWire::$responses['/packs/8391'] = [0, []];
    QueueV4CleanCycleBudget::start(100);
    usleep(2200000);
    $result = (new QueueV4CleanWorker($pdo,new QueueV4CleanRepository($pdo)))->run('test',100,45,[9011],9011);
    $check($result['stop_reason'] === 'remote_result_uncertain' && $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id='.$pointerId)->fetchColumn() === 'review', 'pack_uncertain_propagates_to_worker_stop');
    $check($pdo->query('SELECT status FROM order_resource_enrichment_jobs WHERE id='.$packId)->fetchColumn() === 'error', 'pack_uncertain_source_not_retryable');
    $enrichment->enqueue(9011,$orderId,'pack','8391');
    $check($pdo->query('SELECT status FROM order_resource_enrichment_jobs WHERE id='.$packId)->fetchColumn() === 'error', 'duplicate_pack_enqueue_preserves_uncertain');
    $pdo->exec('UPDATE order_resource_enrichment_jobs SET status="retry",failure_class="remote_result_uncertain_safe_get",next_run_at=UTC_TIMESTAMP() WHERE id=' . $packId);
    $enrichment->enqueue(9011,$orderId,'pack','8391');
    $check($pdo->query('SELECT failure_class FROM order_resource_enrichment_jobs WHERE id=' . $packId)->fetchColumn() === 'remote_result_uncertain_safe_get', 'legacy_pack_enqueue_preserves_uncertainty');
    $packClaim = new ReflectionMethod(OrderEnrichmentService::class, 'claimNext');
    $check($packClaim->invoke($enrichment, $packId) === null, 'legacy_pack_uncertain_not_claimed');
    $check((int)$pdo->query("SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE dispatch_state='PHYSICAL_STARTED' AND response_known_at IS NULL")->fetchColumn() >= 3, 'uncertain_physical_evidence_retained');
    if ($failures !== []) throw new RuntimeException('UNCERTAIN_RECOVERY_FAILURES:' . implode(',', $failures));
    echo "STATUS=PASS CAP2_UNCERTAIN_RECOVERY\nREAL_MELI_HTTP=0\n";
} finally {
    Cap2DomainsWire::$onWire = null;
    QueueV4CleanCycleBudget::clear();
    $h->cleanup();
}
