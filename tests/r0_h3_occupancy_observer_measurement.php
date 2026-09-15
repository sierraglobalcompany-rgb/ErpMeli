<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_transport_fixture.inc.php';

use App\QueueV4Clean\PackDiscoveryOccupancyPolicy;
use App\Services\CronAdmissionService;

$seed = r0h3_seed($pdo);
r0h3_prepare_transport_fixture($pdo, 10);
r0h3_clear_positive_scope($pdo);
r0h3_defer_fresh_order_discovery($pdo);

$scope = [
    ['company_id' => 7100, 'meli_account_id' => 7101],
    ['company_id' => 7200, 'meli_account_id' => 7201],
    ['company_id' => 7200, 'meli_account_id' => 7202],
];
$policy = new PackDiscoveryOccupancyPolicy($pdo);

$conservativeEmpty = $policy->outstandingForAccounts($scope);
$measuredEmpty = r0h3_measure_pack_occupancy($pdo, $scope, 'empty');
r0h3_assert($conservativeEmpty === 2, 'old_observer_outside_transaction_returns_target_on_empty', [
    'conservative' => $conservativeEmpty,
    'measured' => $measuredEmpty,
]);
r0h3_assert($measuredEmpty['open_unit_count'] === 0, 'new_observer_measures_empty_as_zero', $measuredEmpty);

$sources = [
    r0h3_add_pack_source_for_admission($pdo, 7200, 7201, '880001', '980001'),
    r0h3_add_pack_source_for_admission($pdo, 7200, 7202, '880002', '980002'),
];
$admission = new CronAdmissionService($pdo);

$pdo->beginTransaction();
$first = $admission->submit(
    'order_enrichment_pack',
    (int) $sources[0]['company_id'],
    (int) $sources[0]['meli_account_id'],
    (int) $sources[0]['source_id'],
    'source:' . (int) $sources[0]['source_id'],
    ['pack_id' => (string) $sources[0]['external_pack_id']],
);
$pdo->commit();
r0h3_assert(!empty($first['accepted']), 'first_unit_must_admit_for_observer_test', ['receipt' => $first]);
$measuredOne = r0h3_measure_pack_occupancy($pdo, $scope, 'one_open');
r0h3_assert($measuredOne['open_unit_count'] === 1, 'new_observer_measures_one_open_unit', $measuredOne);

$pdo->beginTransaction();
$second = $admission->submit(
    'order_enrichment_pack',
    (int) $sources[1]['company_id'],
    (int) $sources[1]['meli_account_id'],
    (int) $sources[1]['source_id'],
    'source:' . (int) $sources[1]['source_id'],
    ['pack_id' => (string) $sources[1]['external_pack_id']],
);
$pdo->commit();
r0h3_assert(!empty($second['accepted']), 'second_unit_must_admit_for_observer_test', ['receipt' => $second]);
$measuredTwo = r0h3_measure_pack_occupancy($pdo, $scope, 'two_open');
r0h3_assert($measuredTwo['open_unit_count'] === 2, 'new_observer_measures_two_open_units', $measuredTwo);

echo json_encode([
    'OCCUPANCY_OBSERVER_MEASUREMENT' => 'PASS',
    'OLD_OUTSIDE_TRANSACTION_EMPTY' => $conservativeEmpty,
    'MEASURED_EMPTY' => $measuredEmpty,
    'MEASURED_ONE' => $measuredOne,
    'MEASURED_TWO' => $measuredTwo,
    'REAL_MELI_HTTP' => 0,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
