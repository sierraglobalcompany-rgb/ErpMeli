<?php

declare(strict_types=1);

require __DIR__ . '/k3_unit02_disposition_fixture.inc.php';

use App\QueueV4Clean\PackDiscoveryOccupancyPolicy;

/**
 * @param list<array{company_id:int,meli_account_id:int}>|null $scope
 * @return array<string,mixed>
 */
function k3u2_metrics(PDO $pdo, ?array $scope = null): array
{
    $pdo->beginTransaction();
    try {
        $result = (new PackDiscoveryOccupancyPolicy($pdo))->measureForAccounts($scope ?? k3u2_scope());
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

$fixture = k3u2_seed($pdo);
$initial = k3u2_metrics($pdo);
r0h3_assert($initial === [
    'outstanding' => 2,
    'administrative_dispositions' => 0,
    'physical_unknown_dispatches' => 2,
    'measurement_status' => 'CERTIFIED',
    'measurement_scope' => 'H3_CERTIFIED_NON_H3_PACK_DISCOVERY',
], 'k3_physical_unknown_one_attempt_and_journal_count_once', $initial);

$unit = $fixture['unit01'];
$attemptId = r0h3_insert($pdo, 'queue_v4_clean_attempts', [
    'job_id' => (int) $unit['queue_id'],
    'run_id' => 991099,
    'company_id' => (int) $unit['company_id'],
    'meli_account_id' => (int) $unit['meli_account_id'],
    'lease_owner' => 'k3-metric-second-physical-send',
    'lease_generation' => 2,
    'outcome' => 'review',
    'error_class' => 'remote_result_uncertain_safe_get',
    'dispatch_state' => 'PHYSICAL_STARTED',
    'transport_method' => 'GET',
    'endpoint_key' => 'pack_exact',
    'physical_http_calls' => 1,
    'physical_started_at' => gmdate('Y-m-d H:i:s', time() - 1200),
    'started_at' => gmdate('Y-m-d H:i:s', time() - 1200),
    'finished_at' => gmdate('Y-m-d H:i:s', time() - 1190),
]);
r0h3_insert($pdo, 'queue_v4_clean_transport_events', [
    'company_id' => (int) $unit['company_id'],
    'meli_account_id' => (int) $unit['meli_account_id'],
    'source_kind' => 'queue',
    'work_id' => (int) $unit['queue_id'],
    'attempt_id' => $attemptId,
    'lease_generation' => 2,
    'request_id' => 'k3-metric-second-' . $attemptId,
    'method' => 'GET',
    'endpoint_key' => 'pack_exact',
    'dispatch_state' => 'PHYSICAL_STARTED',
    'physical_started_at' => gmdate('Y-m-d H:i:s', time() - 1200),
]);
$twoSends = k3u2_metrics($pdo);
r0h3_assert($twoSends['outstanding'] === 2 && $twoSends['physical_unknown_dispatches'] === 3,
    'k3_two_physical_unknown_sends_same_unit_do_not_inflate_occupancy', $twoSends);

$fixture = k3u2_seed($pdo);
$unit = $fixture['unit02'];
$pdo->prepare('DELETE FROM queue_v4_clean_transport_events WHERE id=?')->execute([(int) $unit['historical_transport_event_id']]);
$pdo->prepare('DELETE FROM queue_v4_clean_attempts WHERE id=?')->execute([(int) $unit['historical_attempt_id']]);
r0h3_insert($pdo, 'queue_v4_clean_attempts', [
    'job_id' => (int) $unit['queue_id'],
    'run_id' => 992099,
    'company_id' => (int) $unit['company_id'],
    'meli_account_id' => (int) $unit['meli_account_id'],
    'lease_owner' => 'k3-metric-local-only',
    'lease_generation' => 3,
    'outcome' => 'running',
    'dispatch_state' => 'NOT_DISPATCHED',
    'physical_http_calls' => 0,
    'started_at' => gmdate('Y-m-d H:i:s'),
]);
$localOnly = k3u2_metrics($pdo);
r0h3_assert($localOnly['outstanding'] === 2 && $localOnly['physical_unknown_dispatches'] === 1,
    'k3_running_not_dispatched_is_not_physical_unknown', $localOnly);

$fixture = k3u2_seed($pdo);
$unit = $fixture['unit01'];
r0h3_insert($pdo, 'queue_v4_clean_transport_events', [
    'company_id' => (int) $unit['company_id'],
    'meli_account_id' => (int) $unit['meli_account_id'],
    'source_kind' => 'queue',
    'work_id' => (int) $unit['queue_id'],
    'attempt_id' => null,
    'lease_generation' => 99,
    'request_id' => 'k3-metric-orphan-' . (int) $unit['queue_id'],
    'method' => 'GET',
    'endpoint_key' => 'pack_exact',
    'dispatch_state' => 'PHYSICAL_STARTED',
    'physical_started_at' => gmdate('Y-m-d H:i:s'),
]);
$unavailable = k3u2_metrics($pdo);
r0h3_assert($unavailable === [
    'outstanding' => 2,
    'administrative_dispositions' => null,
    'physical_unknown_dispatches' => null,
    'measurement_status' => 'UNAVAILABLE',
    'measurement_scope' => 'H3_CERTIFIED_NON_H3_PACK_DISCOVERY',
], 'k3_invalid_transport_observation_is_not_reported_as_zero', $unavailable);

$fixture = k3u2_seed($pdo);
$pdo->prepare('DELETE FROM app_settings WHERE setting_key=?')->execute([PackDiscoveryOccupancyPolicy::AUTHORITY_KEY]);
$emptyScopeWithoutAuthority = k3u2_metrics($pdo, []);
r0h3_assert($emptyScopeWithoutAuthority === [
    'outstanding' => 2,
    'administrative_dispositions' => null,
    'physical_unknown_dispatches' => null,
    'measurement_status' => 'UNAVAILABLE',
    'measurement_scope' => 'UNVERIFIED',
], 'k3_empty_scope_does_not_invent_h3_certification', $emptyScopeWithoutAuthority);

echo json_encode(['status' => 'PASS', 'metric_cases' => 5, 'real_meli_http' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
