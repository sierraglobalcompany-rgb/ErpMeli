<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_transport_fixture.inc.php';

use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanReviewService;
use App\Services\Cap2DomainsWire;
use App\Services\CronAdmissionService;
use App\Services\OrderEnrichmentService;

function block1_scope(): array
{
    return [
        ['company_id' => 7100, 'meli_account_id' => 7101],
        ['company_id' => 7200, 'meli_account_id' => 7201],
        ['company_id' => 7200, 'meli_account_id' => 7202],
    ];
}

function block1_dt(?string $value, int $fallbackOffset = -3600): string
{
    $value = trim((string) $value);
    if ($value !== '') {
        $ts = strtotime($value . ' UTC');
        if ($ts !== false) {
            return gmdate('Y-m-d H:i:s', $ts);
        }
    }
    return gmdate('Y-m-d H:i:s', time() + $fallbackOffset);
}

function block1_add_pack(PDO $pdo, int $companyId, int $accountId, string $packExternal, string $orderExternal): array
{
    $past = gmdate('Y-m-d H:i:s', time() - 3600);
    $packId = r0h3_insert($pdo, 'meli_packs', [
        'meli_account_id' => $accountId,
        'external_pack_id' => $packExternal,
        'status' => 'unknown',
        'integrity_status' => 'provisional',
        'expected_orders_count' => 0,
        'linked_orders_count' => 1,
        'expected_orders_json' => null,
        'synced_at' => $past,
    ]);
    $orderId = r0h3_insert($pdo, 'meli_orders', [
        'meli_account_id' => $accountId,
        'external_order_id' => $orderExternal,
        'external_pack_id' => $packExternal,
        'status' => 'paid',
        'total_amount' => 100,
        'paid_amount' => 100,
        'currency_id' => 'COP',
        'synced_at' => $past,
    ]);
    r0h3_insert($pdo, 'meli_pack_orders', ['meli_pack_id' => $packId, 'meli_order_id' => $orderId]);

    return [
        'company_id' => $companyId,
        'meli_account_id' => $accountId,
        'pack_id' => $packId,
        'order_id' => $orderId,
        'external_pack_id' => $packExternal,
        'first_order_external_id' => $orderExternal,
    ];
}

function block1_wire_pack_response(string $packExternal, array $orders): array
{
    return [
        'id' => (int) $packExternal,
        'status' => 'confirmed',
        'shipment' => ['id' => null],
        'buyer' => ['id' => 123],
        'orders' => array_map(static fn (string $order): array => ['id' => (int) $order], $orders),
    ];
}

function block1_add_review_pending_unit(PDO $pdo, int $companyId, int $accountId, string $packExternal, string $orderExternal, int $queueGeneration = 1, int $sourceGeneration = 1, int $attemptCount = 1, ?array $attemptRows = null): array
{
    $pack = block1_add_pack($pdo, $companyId, $accountId, $packExternal, $orderExternal);
    $past = gmdate('Y-m-d H:i:s', time() - 3600);
    $sourceId = r0h3_insert($pdo, 'order_resource_enrichment_jobs', [
        'meli_account_id' => $accountId,
        'meli_order_id' => (int) $pack['order_id'],
        'resource_type' => 'pack',
        'external_resource_id' => $packExternal,
        'status' => 'pending',
        'priority' => 10,
        'attempts' => max(1, $attemptCount),
        'next_run_at' => $past,
        'lease_generation' => max(1, $sourceGeneration),
        'last_started_at' => $past,
        'last_processed_at' => $past,
    ]);
    r0h3_insert($pdo, 'order_resource_enrichment_job_orders', [
        'order_resource_enrichment_job_id' => $sourceId,
        'meli_order_id' => (int) $pack['order_id'],
    ]);
    $queueId = r0h3_insert($pdo, 'queue_v4_clean_jobs', [
        'company_id' => $companyId,
        'meli_account_id' => $accountId,
        'job_type' => 'domain_exact',
        'resource_id' => (string) $sourceId,
        'idempotency_key' => 'domain:order_enrichment_pack:block1-' . $packExternal,
        'payload_json' => json_encode(['capability' => 'order_enrichment_pack', 'source_id' => $sourceId, 'payload' => ['pack_id' => $packExternal]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'state' => 'review',
        'available_at' => $past,
        'attempt_count' => max(1, $attemptCount),
        'max_attempts' => max(3, $attemptCount + 2),
        'lease_generation' => max(1, $queueGeneration),
        'last_error_class' => 'domain_source_error',
    ]);

    $attemptRows = $attemptRows ?: [[
        'outcome' => 'review',
        'error_class' => 'domain_source_error',
        'dispatch_state' => 'NOT_DISPATCHED',
        'physical_http_calls' => 0,
        'lease_generation' => max(1, $queueGeneration),
        'started_at' => $past,
        'finished_at' => $past,
        'source_closed_at' => $past,
    ]];
    $attemptIds = [];
    foreach (array_values($attemptRows) as $idx => $attempt) {
        $attemptIds[] = r0h3_insert($pdo, 'queue_v4_clean_attempts', [
            'job_id' => $queueId,
            'run_id' => 9100 + $idx,
            'company_id' => $companyId,
            'meli_account_id' => $accountId,
            'lease_owner' => 'block1-' . $packExternal . '-' . $idx,
            'lease_generation' => (int) ($attempt['lease_generation'] ?? $queueGeneration),
            'outcome' => (string) ($attempt['outcome'] ?? 'review'),
            'error_class' => $attempt['error_class'] ?? null,
            'dispatch_state' => (string) ($attempt['dispatch_state'] ?? 'NOT_DISPATCHED'),
            'transport_method' => $attempt['transport_method'] ?? null,
            'endpoint_key' => $attempt['endpoint_key'] ?? null,
            'physical_http_calls' => (int) ($attempt['physical_http_calls'] ?? 0),
            'physical_started_at' => empty($attempt['physical_started_at']) ? null : block1_dt((string) $attempt['physical_started_at']),
            'http_status' => $attempt['http_status'] ?? null,
            'response_known_at' => empty($attempt['response_known_at']) ? null : block1_dt((string) $attempt['response_known_at']),
            'started_at' => block1_dt((string) ($attempt['started_at'] ?? '')),
            'finished_at' => empty($attempt['finished_at']) ? null : block1_dt((string) $attempt['finished_at']),
            'source_closed_at' => empty($attempt['source_closed_at']) ? null : block1_dt((string) $attempt['source_closed_at']),
        ]);
    }

    return $pack + [
        'source_id' => $sourceId,
        'queue_id' => $queueId,
        'generation' => max(1, $queueGeneration),
        'source_generation' => max(1, $sourceGeneration),
        'attempt_ids' => $attemptIds,
    ];
}

function block1_unit_from_capture(PDO $pdo, array $captureUnit, int $index, bool $twoOrderPack = false): array
{
    $summary = $captureUnit['raw_state_summary'][0] ?? [];
    $sourceRow = $captureUnit['source_rows']['rows'][0] ?? [];
    $queueRow = $captureUnit['queue_rows']['rows'][0] ?? [];
    $queueKey = (string) (($captureUnit['queue_keys'][0] ?? '') ?: ('q' . $index));
    $attemptRows = $captureUnit['attempt_rows_by_queue'][$queueKey]['rows'] ?? null;
    $packExternal = (string) (881000 + $index);
    $orderExternal = (string) (981000 + $index);
    $unit = block1_add_review_pending_unit(
        $pdo,
        7200,
        $index % 2 === 0 ? 7201 : 7202,
        $packExternal,
        $orderExternal,
        max(1, (int) ($summary['queue_lease_generation'] ?? $queueRow['lease_generation'] ?? 1)),
        max(1, (int) ($summary['source_lease_generation'] ?? $sourceRow['lease_generation'] ?? 1)),
        max(1, (int) ($queueRow['attempt_count'] ?? count((array) $attemptRows) ?: 1)),
        is_array($attemptRows) ? $attemptRows : null,
    );
    if ($twoOrderPack) {
        $unit['child_order_external_id'] = (string) (991000 + $index);
    }
    return $unit + [
        'capture_unit_key' => (string) ($captureUnit['unit_key'] ?? ''),
        'capture_queue_generation' => (int) ($summary['queue_lease_generation'] ?? 0),
        'capture_source_generation' => (int) ($summary['source_lease_generation'] ?? 0),
        'capture_attempt_rows' => is_array($attemptRows) ? count($attemptRows) : 0,
    ];
}

function block1_add_uncertain_closed_occupant(PDO $pdo): array
{
    $unit = block1_add_review_pending_unit($pdo, 7200, 7201, '875999', '975999', 2, 2);
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare('UPDATE order_resource_enrichment_jobs SET status="complete",completed_at=?,failure_class=NULL WHERE id=?')->execute([$now, (int) $unit['source_id']]);
    $pdo->prepare('UPDATE queue_v4_clean_jobs SET state="completed",completed_at=?,last_error_class=NULL WHERE id=?')->execute([$now, (int) $unit['queue_id']]);
    $attemptId = r0h3_insert($pdo, 'queue_v4_clean_attempts', [
        'job_id' => (int) $unit['queue_id'],
        'run_id' => 9901,
        'company_id' => 7200,
        'meli_account_id' => 7201,
        'lease_owner' => 'block1-uncertain',
        'lease_generation' => 1,
        'outcome' => 'review',
        'error_class' => 'remote_result_uncertain',
        'dispatch_state' => 'PHYSICAL_STARTED',
        'transport_method' => 'GET',
        'endpoint_key' => 'pack_exact',
        'physical_http_calls' => 1,
        'physical_started_at' => $now,
        'started_at' => $now,
    ]);
    r0h3_insert($pdo, 'queue_v4_clean_transport_events', [
        'company_id' => 7200,
        'meli_account_id' => 7201,
        'source_kind' => 'queue',
        'work_id' => (int) $unit['queue_id'],
        'attempt_id' => $attemptId,
        'lease_generation' => 1,
        'request_id' => 'block1-uncertain-' . (int) $unit['queue_id'],
        'method' => 'GET',
        'endpoint_key' => 'pack_exact',
        'dispatch_state' => 'PHYSICAL_STARTED',
        'physical_started_at' => $now,
    ]);

    return $unit;
}

function block1_fingerprint_unit(PDO $pdo, array $unit): string
{
    $source = $pdo->query('SELECT * FROM order_resource_enrichment_jobs WHERE id=' . (int) $unit['source_id'])->fetch(PDO::FETCH_ASSOC) ?: [];
    $queue = $pdo->query('SELECT * FROM queue_v4_clean_jobs WHERE id=' . (int) $unit['queue_id'])->fetch(PDO::FETCH_ASSOC) ?: [];
    $attempts = $pdo->query('SELECT * FROM queue_v4_clean_attempts WHERE job_id=' . (int) $unit['queue_id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $events = $pdo->query('SELECT * FROM queue_v4_clean_transport_events WHERE source_kind="queue" AND work_id=' . (int) $unit['queue_id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return hash('sha256', json_encode([$source, $queue, $attempts, $events], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function block1_h3_fingerprint(PDO $pdo, array $historical): string
{
    $parts = [];
    foreach ($historical as $entry) {
        $parts[] = block1_fingerprint_unit($pdo, [
            'source_id' => (int) $entry['source_id'],
            'queue_id' => (int) $entry['queue_id'],
        ]);
    }
    return hash('sha256', json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function block1_measure_pack_occupancy(PDO $pdo, string $phase): array
{
    r0h3_assert(!$pdo->inTransaction(), 'block1_occupancy_measure_requires_own_transaction', ['phase' => $phase]);
    $pdo->beginTransaction();
    try {
        $count = (new App\QueueV4Clean\PackDiscoveryOccupancyPolicy($pdo))->outstandingForAccounts(block1_scope());
        $rows = $pdo->query(
            "SELECT q.id queue_id,q.company_id,q.meli_account_id,CAST(q.resource_id AS UNSIGNED) source_id,
                    q.state queue_state,q.completed_at queue_completed_at,q.last_error_class,
                    j.status source_status,j.completed_at source_completed_at,j.failure_class,j.external_resource_id,
                    (SELECT COUNT(*) FROM queue_v4_clean_attempts a
                      WHERE a.company_id=q.company_id AND a.meli_account_id=q.meli_account_id AND a.job_id=q.id
                        AND (a.outcome='running' OR a.dispatch_state='PHYSICAL_STARTED'
                             OR a.error_class IN ('remote_result_uncertain','remoteresultuncertainexception','remote_result_uncertain_safe_get'))) unresolved_attempts,
                    (SELECT COUNT(*) FROM queue_v4_clean_transport_events e
                      WHERE e.company_id=q.company_id AND e.meli_account_id=q.meli_account_id
                        AND e.source_kind='queue' AND e.work_id=q.id
                        AND e.dispatch_state='PHYSICAL_STARTED' AND e.response_known_at IS NULL) unresolved_transport
               FROM queue_v4_clean_jobs q
               JOIN order_resource_enrichment_jobs j
                 ON j.id=CAST(q.resource_id AS UNSIGNED)
                AND j.meli_account_id=q.meli_account_id
              WHERE q.job_type='domain_exact'
                AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'
                AND (
                     (q.company_id=7100 AND q.meli_account_id=7101)
                  OR (q.company_id=7200 AND q.meli_account_id=7201)
                  OR (q.company_id=7200 AND q.meli_account_id=7202)
                )
              ORDER BY q.company_id,q.meli_account_id,source_id,queue_id
              FOR UPDATE"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $pdo->rollBack();
        return [
            'phase' => $phase,
            'open_unit_count' => $count,
            'observed_rows' => $rows,
            'captured_at_utc' => gmdate('c'),
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        r0h3_assert(false, 'block1_occupancy_measure_failed', [
            'phase' => $phase,
            'error' => $error::class . ':' . $error->getMessage(),
        ]);
    }
}

function block1_insert_contradictory_event(PDO $pdo, array $unit): void
{
    $attemptId = (int) end($unit['attempt_ids']);
    r0h3_insert($pdo, 'queue_v4_clean_transport_events', [
        'company_id' => (int) $unit['company_id'],
        'meli_account_id' => (int) $unit['meli_account_id'],
        'source_kind' => 'queue',
        'work_id' => (int) $unit['queue_id'],
        'attempt_id' => $attemptId,
        'lease_generation' => (int) $unit['generation'],
        'request_id' => 'block1-contradict-' . (int) $unit['queue_id'],
        'method' => 'GET',
        'endpoint_key' => 'pack_exact',
        'dispatch_state' => 'RESPONSE_KNOWN',
        'physical_started_at' => gmdate('Y-m-d H:i:s'),
        'response_known_at' => gmdate('Y-m-d H:i:s'),
        'http_status' => 200,
    ]);
}

function block1_sleep_until_pack_source_due(PDO $pdo, int $queueId, int $sourceId): void
{
    $stmt = $pdo->prepare(
        "SELECT LEAST(
                    COALESCE(TIMESTAMPDIFF(MICROSECOND, UTC_TIMESTAMP(6), q.available_at),0),
                    COALESCE(TIMESTAMPDIFF(MICROSECOND, UTC_TIMESTAMP(6), j.next_run_at),0)
                ) min_wait_us,
                q.state,q.last_error_class,j.status,j.failure_class,j.next_run_at
           FROM queue_v4_clean_jobs q
           JOIN order_resource_enrichment_jobs j ON j.id=? AND j.meli_account_id=q.meli_account_id
          WHERE q.id=?"
    );
    $stmt->execute([$sourceId, $queueId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        r0h3_assert(false, 'block1_due_wait_source_missing', ['queue_id' => $queueId, 'source_id' => $sourceId]);
    }
    $waitUs = max(0, (int) ($row['min_wait_us'] ?? 0));
    if ($waitUs > 0) {
        usleep(min($waitUs + 50000, 30000000));
    }
}

function block1_run_pack_worker_until_call(PDO $pdo, QueueV4CleanReviewService $review, array $unit, int $beforeCalls): array
{
    $deadline = microtime(true) + 90.0;
    $lastRun = null;
    do {
        r0h3_wait_for_global_rhythm($pdo);
        $lastRun = r0h3_worker_run($pdo, 1, [(int) $unit['meli_account_id']]);
        if (count(Cap2DomainsWire::$calls) === $beforeCalls + 1) {
            return $lastRun;
        }
        $state = $pdo->query(
            'SELECT q.state,q.last_error_class,j.status,j.failure_class,j.next_run_at
               FROM queue_v4_clean_jobs q
               JOIN order_resource_enrichment_jobs j ON j.id=' . (int) $unit['source_id'] . ' AND j.meli_account_id=q.meli_account_id
              WHERE q.id=' . (int) $unit['queue_id']
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $reason = (string) (($state['last_error_class'] ?? '') ?: ($state['failure_class'] ?? ''));
        if (!str_contains($reason, 'waiting_rhythm')) {
            r0h3_assert(false, 'worker_must_emit_one_pack_get_for_recovered_unit', [
                'unit' => $unit,
                'run' => $lastRun,
                'state' => $state,
                'calls' => array_slice(Cap2DomainsWire::$calls, $beforeCalls),
            ]);
        }
        block1_sleep_until_pack_source_due($pdo, (int) $unit['queue_id'], (int) $unit['source_id']);
        try {
            $review->recoverPackSourcePendingAfterDomainSourceError((int) $unit['company_id'], (int) $unit['meli_account_id'], (int) $unit['queue_id']);
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'queue_v4_pack_review_recovery_not_due') {
                throw $error;
            }
        }
    } while (microtime(true) < $deadline);

    r0h3_assert(false, 'worker_waiting_rhythm_did_not_clear_for_recovered_unit', [
        'unit' => $unit,
        'last_run' => $lastRun,
    ]);
}

function block1_close_recovered_pack(PDO $pdo, QueueV4CleanReviewService $review, array $unit, bool $withChild, ?array $finance): array
{
    $beforeAttempts = (int) $pdo->query('SELECT attempt_count FROM queue_v4_clean_jobs WHERE id=' . (int) $unit['queue_id'])->fetchColumn();
    $receipt = $review->recoverPackSourcePendingAfterDomainSourceError((int) $unit['company_id'], (int) $unit['meli_account_id'], (int) $unit['queue_id']);
    r0h3_assert(!empty($receipt['ok']), 'block1_recovery_must_succeed', ['unit' => $unit, 'receipt' => $receipt]);
    $jobReady = $pdo->query('SELECT state,attempt_count,last_error_class FROM queue_v4_clean_jobs WHERE id=' . (int) $unit['queue_id'])->fetch(PDO::FETCH_ASSOC);
    r0h3_assert(is_array($jobReady) && (string) $jobReady['state'] === 'ready' && (int) $jobReady['attempt_count'] === $beforeAttempts, 'block1_recovery_only_moves_to_ready', $jobReady ?: []);

    $packExternal = (string) $unit['external_pack_id'];
    $firstOrder = (string) $unit['first_order_external_id'];
    $orders = $withChild ? [$firstOrder, (string) $unit['child_order_external_id']] : [$firstOrder];
    $responses = [
        '/packs/' . $packExternal => [200, block1_wire_pack_response($packExternal, $orders)],
    ];
    if ($withChild) {
        $responses['/orders/' . (string) $unit['child_order_external_id']] = [200, r0h3_wire_order_response((string) $unit['child_order_external_id'], $packExternal)];
    }
    Cap2DomainsWire::$responses = $responses;

    $financeBefore = null;
    if ($finance !== null) {
        $repo = new QueueV4CleanRepository($pdo);
        $financeBefore = $repo->releaseDueWaiting([(int) $unit['meli_account_id']], (int) $unit['meli_account_id']);
        r0h3_assert($financeBefore === 0, 'finance_must_not_release_before_pack_integrity', ['unit' => $unit, 'release' => $financeBefore]);
    }

    r0h3_wait_for_global_rhythm($pdo);
    $beforeCalls = count(Cap2DomainsWire::$calls);
    $runPack = block1_run_pack_worker_until_call($pdo, $review, $unit, $beforeCalls);
    $afterPackCalls = count(Cap2DomainsWire::$calls);
    r0h3_assert($afterPackCalls === $beforeCalls + 1, 'worker_must_emit_one_pack_get_for_recovered_unit', ['unit' => $unit, 'run' => $runPack, 'calls' => array_slice(Cap2DomainsWire::$calls, $beforeCalls)]);
    $packAfterDiscovery = r0h3_fetch_pack_state($pdo, (int) $unit['meli_account_id'], $packExternal);

    $runChild = null;
    if ($withChild) {
        r0h3_assert((string) ($packAfterDiscovery['integrity_status'] ?? '') !== 'complete', 'two_order_pack_must_wait_for_child', $packAfterDiscovery);
        r0h3_assert(r0h3_queue_count($pdo, 'order_exact', (int) $unit['meli_account_id'], (string) $unit['child_order_external_id']) === 1, 'missing_child_must_be_admitted', $unit);
        r0h3_wait_for_global_rhythm($pdo);
        $runChild = r0h3_worker_run($pdo, 1, [(int) $unit['meli_account_id']]);
        r0h3_assert(count(Cap2DomainsWire::$calls) === $afterPackCalls + 1, 'child_worker_must_emit_one_order_get', ['unit' => $unit, 'run' => $runChild]);
    }

    $packAfter = r0h3_fetch_pack_state($pdo, (int) $unit['meli_account_id'], $packExternal);
    $queueAfter = $pdo->query('SELECT state,completed_at,last_error_class FROM queue_v4_clean_jobs WHERE id=' . (int) $unit['queue_id'])->fetch(PDO::FETCH_ASSOC);
    $sourceAfter = $pdo->query('SELECT status,completed_at,failure_class FROM order_resource_enrichment_jobs WHERE id=' . (int) $unit['source_id'])->fetch(PDO::FETCH_ASSOC);
    r0h3_assert((string) ($queueAfter['state'] ?? '') === 'completed' && !empty($queueAfter['completed_at']) && (string) ($queueAfter['last_error_class'] ?? '') === '', 'queue_must_close_by_worker', $queueAfter ?: []);
    r0h3_assert((string) ($sourceAfter['status'] ?? '') === 'complete' && !empty($sourceAfter['completed_at']) && (string) ($sourceAfter['failure_class'] ?? '') === '', 'source_must_close_by_worker', $sourceAfter ?: []);
    r0h3_assert((string) ($packAfter['integrity_status'] ?? '') === 'complete', 'pack_must_complete_legitimately', $packAfter);

    $financeAfter = null;
    if ($finance !== null) {
        $repo = new QueueV4CleanRepository($pdo);
        $financeAfter = $repo->releaseDueWaiting([(int) $unit['meli_account_id']], (int) $unit['meli_account_id']);
        r0h3_assert($financeAfter === 1, 'finance_must_release_after_integrity', ['unit' => $unit, 'release' => $financeAfter]);
    }

    return [
        'queue_id' => (int) $unit['queue_id'],
        'source_id' => (int) $unit['source_id'],
        'pack_id' => $packExternal,
        'with_child' => $withChild ? 'YES' : 'NO',
        'recovery_state' => 'ready',
        'worker_queue_state' => (string) ($queueAfter['state'] ?? ''),
        'source_status' => (string) ($sourceAfter['status'] ?? ''),
        'pack_integrity' => (string) ($packAfter['integrity_status'] ?? ''),
        'finance_release_before' => $financeBefore,
        'finance_release_after' => $financeAfter,
        'pack_run_completed' => (int) ($runPack['completed'] ?? 0),
        'child_run_completed' => is_array($runChild) ? (int) ($runChild['completed'] ?? 0) : 0,
    ];
}

$capturePath = getenv('R0_H3_CAPTURE_PATH') ?: dirname(__DIR__) . '/storage/pr16-block1-capture/capture.real.source.json';
$capture = json_decode((string) file_get_contents($capturePath), true, 512, JSON_THROW_ON_ERROR);
$units = is_array($capture['target_units'] ?? null) ? $capture['target_units'] : [];
$recoverableCaptureUnits = array_values(array_filter($units, static function (array $unit): bool {
    $summary = $unit['raw_state_summary'][0] ?? [];
    return (string) ($unit['classification'] ?? '') === 'BLOCKED_BY_STATE_OR_MISSING_ROUTE'
        && (string) ($summary['queue_state'] ?? '') === 'review'
        && (string) ($summary['source_status'] ?? '') === 'pending'
        && (string) ($summary['queue_last_error_class'] ?? '') === 'domain_source_error';
}));
$uncertainCaptureUnits = array_values(array_filter($units, static fn (array $unit): bool => (string) ($unit['classification'] ?? '') === 'CONTRADICTORY_OR_UNCERTAIN'));
r0h3_assert(count($recoverableCaptureUnits) === 77, 'capture_must_have_77_recoverable_pending_domain_source_error_units');
r0h3_assert(count($uncertainCaptureUnits) === 1, 'capture_must_have_one_uncertain_unit');

// RED/GREEN focalizado: un evento RESPONSE_KNOWN contradictorio para el mismo
// intento/generación debe bloquear la recuperación local.
r0h3_seed($pdo);
r0h3_prepare_transport_fixture($pdo, 10);
r0h3_clear_positive_scope($pdo);
r0h3_defer_fresh_order_discovery($pdo);
$review = new QueueV4CleanReviewService($pdo);
$contradictory = block1_add_review_pending_unit($pdo, 7200, 7201, '870101', '970101', 2, 1);
block1_insert_contradictory_event($pdo, $contradictory);
$beforeContradictory = block1_fingerprint_unit($pdo, $contradictory);
try {
    $review->recoverPackSourcePendingAfterDomainSourceError(7200, 7201, (int) $contradictory['queue_id']);
    r0h3_assert(false, 'contradictory_transport_must_reject_recovery');
} catch (RuntimeException $error) {
    r0h3_assert($error->getMessage() === 'queue_v4_pack_review_recovery_contradictory_transport', 'contradictory_rejection_reason', ['message' => $error->getMessage()]);
}
r0h3_assert(hash_equals($beforeContradictory, block1_fingerprint_unit($pdo, $contradictory)), 'contradictory_rejection_must_not_mutate_unit');

// Secuencia principal: un solo seed y sin reseed hasta el final.
$seed = r0h3_seed($pdo);
r0h3_prepare_transport_fixture($pdo, 200);
r0h3_clear_positive_scope($pdo);
r0h3_defer_fresh_order_discovery($pdo);
$review = new QueueV4CleanReviewService($pdo);
$h3Before = block1_h3_fingerprint($pdo, $seed['historical']);

$uncertain = block1_add_uncertain_closed_occupant($pdo);
$uncertainBefore = block1_fingerprint_unit($pdo, $uncertain);

$created = [];
foreach ($recoverableCaptureUnits as $i => $captureUnit) {
    $created[] = block1_unit_from_capture($pdo, $captureUnit, $i + 1, $i === 0);
}
$initialOccupancy = block1_measure_pack_occupancy($pdo, 'initial_77_plus_uncertain');
r0h3_assert($initialOccupancy['open_unit_count'] === 78, 'initial_occupancy_must_measure_77_plus_uncertain', $initialOccupancy);

$finance = r0h3_add_financial_waiting($pdo, (int) $created[0]['company_id'], (int) $created[0]['meli_account_id'], (string) $created[0]['external_pack_id']);
$coverage = [];
foreach ($created as $idx => $unit) {
    $withChild = $idx === 0;
    $coverage[] = [
        'capture_unit_key' => (string) $unit['capture_unit_key'],
        'capture_queue_generation' => (int) $unit['capture_queue_generation'],
        'capture_source_generation' => (int) $unit['capture_source_generation'],
        'capture_attempt_rows' => (int) $unit['capture_attempt_rows'],
    ] + block1_close_recovered_pack($pdo, $review, $unit, $withChild, $withChild ? $finance : null);
    if ($idx % 10 === 0 || $idx === 76) {
        $measurement = block1_measure_pack_occupancy($pdo, 'after_close_' . ($idx + 1));
        r0h3_assert($measurement['open_unit_count'] === 78 - ($idx + 1), 'occupancy_must_decrease_by_real_closure', ['idx' => $idx, 'measurement' => $measurement]);
    }
}

$after77 = block1_measure_pack_occupancy($pdo, 'after_77_closed');
r0h3_assert($after77['open_unit_count'] === 1, 'after_77_only_uncertain_must_remain', $after77);
r0h3_assert(hash_equals($uncertainBefore, block1_fingerprint_unit($pdo, $uncertain)), 'uncertain_unit_must_remain_unchanged');
r0h3_assert(hash_equals($h3Before, block1_h3_fingerprint($pdo, $seed['historical'])), 'h3_units_must_remain_unchanged');

$admission = new CronAdmissionService($pdo);
$healthyA = block1_add_pack($pdo, 7200, 7201, '879001', '979001');
$sourceA = (new OrderEnrichmentService())->enqueue(7201, (int) $healthyA['order_id'], 'pack', '879001', 10);
$pdo->beginTransaction();
$acceptA = $admission->submit('order_enrichment_pack', 7200, 7201, $sourceA, 'source:' . $sourceA, ['pack_id' => '879001']);
$pdo->commit();
r0h3_assert(!empty($acceptA['accepted']), 'one_healthy_unit_must_enter_with_uncertain_retained', $acceptA);
$afterHealthyA = block1_measure_pack_occupancy($pdo, 'after_healthy_a_admitted');
r0h3_assert($afterHealthyA['open_unit_count'] === 2, 'occupancy_must_be_two_after_healthy_plus_uncertain', $afterHealthyA);

$healthyB = block1_add_pack($pdo, 7200, 7202, '879002', '979002');
$sourceB = (new OrderEnrichmentService())->enqueue(7202, (int) $healthyB['order_id'], 'pack', '879002', 10);
$pdo->beginTransaction();
$denyB = $admission->submit('order_enrichment_pack', 7200, 7202, $sourceB, 'source:' . $sourceB, ['pack_id' => '879002']);
$pdo->commit();
r0h3_assert(empty($denyB['accepted']) && ($denyB['reason'] ?? '') === 'R0_OCCUPANCY_EXHAUSTED', 'second_healthy_must_be_denied_at_limit_two', $denyB);

$healthyUnit = [
    'company_id' => 7200,
    'meli_account_id' => 7201,
    'source_id' => $sourceA,
    'queue_id' => (int) ($acceptA['job_id'] ?? 0),
    'external_pack_id' => '879001',
    'first_order_external_id' => '979001',
];
Cap2DomainsWire::$responses = ['/packs/879001' => [200, block1_wire_pack_response('879001', ['979001'])]];
r0h3_wait_for_global_rhythm($pdo);
$runHealthy = r0h3_worker_run($pdo, 1, [7201]);
$healthyAfter = block1_measure_pack_occupancy($pdo, 'after_healthy_a_closed');
r0h3_assert($healthyAfter['open_unit_count'] === 1, 'occupancy_must_return_to_uncertain_only_after_healthy_closes', ['measurement' => $healthyAfter, 'run' => $runHealthy]);

$sourceC = (new OrderEnrichmentService())->enqueue(7202, (int) $healthyB['order_id'], 'pack', '879002', 10);
$pdo->beginTransaction();
$acceptC = $admission->submit('order_enrichment_pack', 7200, 7202, $sourceC, 'source:' . $sourceC, ['pack_id' => '879002']);
$pdo->commit();
r0h3_assert(!empty($acceptC['accepted']), 'slot_must_be_reusable_after_healthy_closure', $acceptC);

echo json_encode([
    'CAPTURE_ZIP_SHA256' => '1e91d7757f873d1bb7df3840bbc2484b2f0bdbc30f76b1787c4c2264e4938244',
    'CONTRADICTORY_TRANSPORT_REJECTED' => 'YES',
    'CAPTURE_77_REPRODUCED' => count($coverage),
    'SEED_REUSED_DURING_MAIN_SEQUENCE' => 'YES',
    'INITIAL_OPEN_UNITS' => $initialOccupancy['open_unit_count'],
    'AFTER_77_OPEN_UNITS' => $after77['open_unit_count'],
    'UNCERTAIN_UNIT_PRESERVED' => 'YES',
    'H3_UNITS_PRESERVED' => 'YES',
    'ONE_HEALTHY_ALLOWED_WITH_UNCERTAIN' => 'YES',
    'SECOND_HEALTHY_DENIED_AT_LIMIT_TWO' => 'YES',
    'SLOT_REUSED_AFTER_HEALTHY_CLOSURE' => 'YES',
    'REAL_MELI_HTTP' => 0,
    'SIMULATED_TRANSPORT_CALLS' => count(Cap2DomainsWire::$calls),
    'coverage' => $coverage,
    'occupancy' => [
        'initial' => $initialOccupancy,
        'after_77' => $after77,
        'after_healthy_a' => $afterHealthyA,
        'after_healthy_close' => $healthyAfter,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
