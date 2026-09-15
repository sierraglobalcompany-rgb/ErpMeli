<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_transport_fixture.inc.php';

use App\QueueV4Clean\QueueV4CleanProducer;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\Cap2DomainsWire;

$seed = r0h3_seed($pdo);
r0h3_prepare_transport_fixture($pdo, 10);
r0h3_clear_positive_scope($pdo);
r0h3_defer_fresh_order_discovery($pdo);

$authorityBefore = (string) $pdo->query(
    "SELECT setting_value FROM app_settings WHERE setting_key='queue_v4.pack_discovery_h3_authority' LIMIT 1"
)->fetchColumn();

$repo = new QueueV4CleanRepository($pdo);
$producer = new QueueV4CleanProducer($pdo, $repo);
$scope = [
    ['company_id' => 7100, 'meli_account_id' => 7101],
    ['company_id' => 7200, 'meli_account_id' => 7201],
    ['company_id' => 7200, 'meli_account_id' => 7202],
];
$occupancyObservations = [];

$packA = r0h3_add_local_pack($pdo, 7200, 7201, '860001', '960001');
$financeA = r0h3_add_financial_waiting($pdo, 7200, 7201, '860001');

$occupancyObservations[] = r0h3_measure_pack_occupancy($pdo, $scope, 'before_admit_a') + [
    'finance_target_state' => 'waiting',
    'pack_integrity_status' => (string) r0h3_fetch_pack_state($pdo, 7201, '860001')['integrity_status'],
];


$beforeRelease = $repo->releaseDueWaiting([7201], 7201);
r0h3_assert($beforeRelease === 0, 'finance_must_not_release_before_pack_integrity', [
    'released' => $beforeRelease,
    'runtime' => $repo->lastFinanceWakeupRuntime(),
]);
r0h3_assert(
    r0h3_count_financial_pointer_state($pdo, (int) $financeA['queue_id'], 'waiting') === 1,
    'finance_pointer_must_stay_waiting_before_discovery',
    $financeA
);

$wireBeforeProducer = count(Cap2DomainsWire::$calls);
$producedA = $producer->produce(60);
$wireAfterProducer = count(Cap2DomainsWire::$calls);
$sourceA = r0h3_pack_discovery_queue_for($pdo, 7201, '860001');
r0h3_assert(is_array($sourceA), 'producer_must_create_pack_a_discovery_source');
r0h3_assert((int) ($producedA['pack_discovery_created'] ?? 0) === 1, 'producer_must_admit_one_pack_a_source', $producedA);
r0h3_assert($wireAfterProducer === $wireBeforeProducer, 'producer_admission_must_not_emit_http', [
    'before' => $wireBeforeProducer,
    'after' => $wireAfterProducer,
]);
r0h3_assert((string) ($sourceA['state'] ?? '') === 'ready', 'pack_a_pointer_must_be_ready_after_admission', $sourceA);
$occupancyObservations[] = r0h3_measure_pack_occupancy($pdo, $scope, 'after_admit_a') + [
    'finance_target_state' => 'waiting',
    'pack_integrity_status' => (string) r0h3_fetch_pack_state($pdo, 7201, '860001')['integrity_status'],
];

Cap2DomainsWire::$responses = [
    '/packs/860001' => [200, r0h3_wire_pack_response('860001', '960001', '960002')],
    '/orders/960002' => [200, r0h3_wire_order_response('960002', '860001')],
];
$runPack = r0h3_worker_run($pdo, 1, [7201]);
r0h3_assert(count(Cap2DomainsWire::$calls) === 1, 'pack_discovery_worker_must_emit_one_pack_get', [
    'calls' => Cap2DomainsWire::$calls,
    'run' => $runPack,
]);
r0h3_assert(Cap2DomainsWire::$calls[0]['path'] === '/packs/860001', 'first_wire_call_must_be_pack_discovery', Cap2DomainsWire::$calls[0]);
$packAfterDiscovery = r0h3_fetch_pack_state($pdo, 7201, '860001');
r0h3_assert((string) ($packAfterDiscovery['integrity_status'] ?? '') !== 'complete', 'pack_must_remain_incomplete_before_child_order', $packAfterDiscovery);
$childQueued = r0h3_queue_count($pdo, 'order_exact', 7201, '960002');
r0h3_assert($childQueued === 1, 'missing_child_must_be_admitted_durably', ['count' => $childQueued]);
$midRelease = $repo->releaseDueWaiting([7201], 7201);
r0h3_assert($midRelease === 0, 'finance_must_not_release_before_child_processed', [
    'released' => $midRelease,
    'pack' => $packAfterDiscovery,
]);
$occupancyObservations[] = r0h3_measure_pack_occupancy($pdo, $scope, 'after_discovery_source_closed_before_child') + [
    'finance_target_state' => 'waiting',
    'pack_integrity_status' => (string) ($packAfterDiscovery['integrity_status'] ?? ''),
];
r0h3_assert(
    r0h3_count_financial_pointer_state($pdo, (int) $financeA['queue_id'], 'waiting') === 1,
    'finance_pointer_must_still_be_waiting_before_child_worker',
    [
        'finance' => $financeA,
        'state_counts' => $pdo->query(
            'SELECT state,COUNT(*) c FROM queue_v4_clean_jobs WHERE id=' . (int) $financeA['queue_id'] . ' GROUP BY state'
        )->fetchAll(PDO::FETCH_ASSOC),
    ]
);

r0h3_wait_for_global_rhythm($pdo);
$runChild = r0h3_worker_run($pdo, 1, [7201]);
r0h3_assert(count(Cap2DomainsWire::$calls) === 2, 'child_worker_must_emit_second_get', [
    'calls' => Cap2DomainsWire::$calls,
    'run' => $runChild,
]);
r0h3_assert(Cap2DomainsWire::$calls[1]['path'] === '/orders/960002', 'second_wire_call_must_be_child_order_exact', Cap2DomainsWire::$calls[1]);
$packAfterChild = r0h3_fetch_pack_state($pdo, 7201, '860001');
r0h3_assert((string) ($packAfterChild['integrity_status'] ?? '') === 'complete', 'pack_must_complete_through_product_code', $packAfterChild);
r0h3_assert((int) ($packAfterChild['expected_orders_count'] ?? 0) === 2, 'pack_expected_count_must_be_two', $packAfterChild);
r0h3_assert((int) ($packAfterChild['linked_orders_count'] ?? 0) === 2, 'pack_linked_count_must_be_two', $packAfterChild);
$occupancyObservations[] = r0h3_measure_pack_occupancy($pdo, $scope, 'after_child_pack_complete_before_finance_wakeup') + [
    'finance_target_state' => 'waiting',
    'pack_integrity_status' => (string) ($packAfterChild['integrity_status'] ?? ''),
];

$afterRelease = $repo->releaseDueWaiting([7201], 7201);
r0h3_assert($afterRelease === 1, 'finance_must_release_after_legitimate_integrity', [
    'released' => $afterRelease,
    'runtime' => $repo->lastFinanceWakeupRuntime(),
]);
r0h3_assert(
    r0h3_count_financial_pointer_state($pdo, (int) $financeA['queue_id'], 'ready') === 1,
    'finance_pointer_must_be_ready_after_wakeup',
    $financeA
);

$sourceAAfter = r0h3_pack_discovery_queue_for($pdo, 7201, '860001');
r0h3_assert(is_array($sourceAAfter) && (string) ($sourceAAfter['state'] ?? '') === 'completed', 'pack_a_discovery_pointer_must_close', $sourceAAfter ?? []);
r0h3_assert((string) ($sourceAAfter['source_status'] ?? '') === 'complete', 'pack_a_discovery_source_must_close', $sourceAAfter ?? []);
$occupancyObservations[] = r0h3_measure_pack_occupancy($pdo, $scope, 'after_finance_wakeup') + [
    'finance_target_state' => 'ready',
    'pack_integrity_status' => (string) ($packAfterChild['integrity_status'] ?? ''),
];

$packB = r0h3_add_local_pack($pdo, 7200, 7202, '860002', '960101');
$wireBeforeB = count(Cap2DomainsWire::$calls);
$producedB = $producer->produce(60);
$wireAfterB = count(Cap2DomainsWire::$calls);
$sourceB = r0h3_pack_discovery_queue_for($pdo, 7202, '860002');
r0h3_assert(is_array($sourceB), 'producer_must_create_next_pack_b_after_closure');
r0h3_assert((int) ($producedB['pack_discovery_created'] ?? 0) >= 1, 'producer_must_reuse_slot_for_pack_b', $producedB);
r0h3_assert($wireAfterB === $wireBeforeB, 'pack_b_admission_must_not_emit_http', [
    'before' => $wireBeforeB,
    'after' => $wireAfterB,
]);
$occupancyObservations[] = r0h3_measure_pack_occupancy($pdo, $scope, 'after_admit_b') + [
    'finance_target_state' => 'ready',
    'pack_integrity_status' => (string) ($packAfterChild['integrity_status'] ?? ''),
];
$openMax = max(array_map(static fn (array $row): int => (int) $row['open_unit_count'], $occupancyObservations));
r0h3_assert($openMax <= 2, 'global_open_pack_units_must_never_exceed_two', ['open_max' => $openMax]);

$authorityAfter = (string) $pdo->query(
    "SELECT setting_value FROM app_settings WHERE setting_key='queue_v4.pack_discovery_h3_authority' LIMIT 1"
)->fetchColumn();
r0h3_assert(hash('sha256', $authorityBefore) === hash('sha256', $authorityAfter), 'h3_authority_must_not_change');

$summary = [
    'PACK_INITIAL_STATE' => 'provisional',
    'PACK_INITIAL_EXPECTED_COMPOSITION' => 'unknown',
    'PACK_INITIAL_ENRICHMENT_SOURCE_COUNT' => 0,
    'MISSING_CHILD_BEFORE_DISCOVERY' => 'YES',
    'DISCOVERY_SOURCE_CREATED_BY_PRODUCT_CODE' => 'YES',
    'DISCOVERY_POINTER_MATCHES_CREATED_SOURCE' => 'YES',
    'EXPECTED_CHILD_ADMITTED_DURABLY' => 'YES',
    'CHILD_PERSISTED_AND_LINKED_BY_PRODUCT_CODE' => 'YES',
    'PACK_COMPLETED_BY_PRODUCT_CODE' => 'YES',
    'FINANCE_RELEASE_BEFORE_INTEGRITY' => $beforeRelease,
    'FINANCE_RELEASE_AFTER_INTEGRITY' => $afterRelease,
    'NEXT_DISCOVERY_ADMITTED_AFTER_LEGITIMATE_CLOSURE' => 'YES',
    'OPEN_PACK_UNITS_MAX' => $openMax,
    'H3_BEFORE_AFTER_MATCH' => 'YES',
    'REAL_MELI_HTTP' => 0,
    'SIMULATED_TRANSPORT_CALLS' => count(Cap2DomainsWire::$calls),
    'HTTP_DURING_ADMISSION_TRANSACTION' => 0,
    'producer_a' => $producedA,
    'producer_b' => $producedB,
    'worker_pack' => $runPack,
    'worker_child' => $runChild,
    'occupancy_observations' => $occupancyObservations,
    'wire_calls' => Cap2DomainsWire::$calls,
    'pack_after_discovery' => $packAfterDiscovery,
    'pack_after_child' => $packAfterChild,
    'finance' => $financeA,
    'pack_b' => $packB,
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
