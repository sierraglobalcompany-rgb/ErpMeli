<?php

declare(strict_types=1);

require __DIR__ . '/k3_unit02_disposition_fixture.inc.php';

use App\QueueV4Clean\PackDiscoveryOccupancyPolicy;
use App\QueueV4Clean\PackDiscoveryUnit02DispositionService;

const K3U2_REAL_HISTORICAL_ERROR_CLASS = 'domain_source_waiting:order_enrichment_pack:remote_uncertain_safe_get';

/** @return array{unit01:array<string,mixed>,unit02:array<string,mixed>,healthy:list<array<string,mixed>>} */
function k3u2_real_seed(PDO $pdo, string $errorClass = K3U2_REAL_HISTORICAL_ERROR_CLASS): array
{
    $fixture = k3u2_seed($pdo);
    $pdo->prepare('UPDATE queue_v4_clean_attempts SET error_class=? WHERE id=? AND job_id=? AND company_id=? AND meli_account_id=?')
        ->execute([
            $errorClass,
            (int) $fixture['unit02']['historical_attempt_id'],
            (int) $fixture['unit02']['queue_id'],
            (int) $fixture['unit02']['company_id'],
            (int) $fixture['unit02']['meli_account_id'],
        ]);

    return $fixture;
}

/** @return array{outstanding:int,administrative_dispositions:?int,physical_unknown_dispatches:?int,measurement_status:string,measurement_scope:string} */
function k3u2_real_measure(PDO $pdo): array
{
    $pdo->beginTransaction();
    try {
        $result = (new PackDiscoveryOccupancyPolicy($pdo))->measureForAccounts(k3u2_scope());
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/** @return array<string,mixed> */
function k3u2_real_apply(PDO $pdo, array $evidence): array
{
    return (new PackDiscoveryUnit02DispositionService($pdo))->apply(
        $evidence,
        5001,
        'Felipe approved the unique non-renewable UNIT-02 occupancy disposition.',
        true,
    );
}

/** @param callable(array<string,mixed>):void|null $mutate */
function k3u2_real_assert_rejected(PDO $pdo, string $assertion, string $errorClass, ?callable $mutate = null): void
{
    $fixture = k3u2_real_seed($pdo, $errorClass);
    if ($mutate !== null) {
        $mutate($fixture['unit02']);
    }
    $errorMessage = null;
    try {
        k3u2_real_apply($pdo, k3u2_authorized_evidence($pdo, $fixture['unit02']));
    } catch (Throwable $error) {
        $errorMessage = $error->getMessage();
    }
    $dispositions = (int) $pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key='queue_v4.pack_discovery_unit02_disposition'")->fetchColumn();
    $audits = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='pack_discovery_unit02_disposition_applied'")->fetchColumn();
    r0h3_assert($errorMessage !== null && $dispositions === 0 && $audits === 0, $assertion, [
        'error' => $errorMessage,
        'dispositions' => $dispositions,
        'audits' => $audits,
    ]);
}

$fixture = k3u2_real_seed($pdo);
$beforeHashes = [
    'unit01' => k3u2_unit_evidence_hash($pdo, $fixture['unit01']),
    'unit02' => k3u2_unit_evidence_hash($pdo, $fixture['unit02']),
];
$beforeJobs = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn();
$beforeAttempts = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_attempts')->fetchColumn();
$beforeEvents = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_transport_events')->fetchColumn();
$before = k3u2_real_measure($pdo);
r0h3_assert($before === [
    'outstanding' => 2,
    'administrative_dispositions' => 0,
    'physical_unknown_dispatches' => 2,
    'measurement_status' => 'CERTIFIED',
    'measurement_scope' => 'H3_CERTIFIED_NON_H3_PACK_DISCOVERY',
], 'k3_unit02_real_class_starts_with_two_occupants', $before);

$evidence = k3u2_authorized_evidence($pdo, $fixture['unit02']);
$applied = k3u2_real_apply($pdo, $evidence);
$replayed = k3u2_real_apply($pdo, $evidence);
$after = k3u2_real_measure($pdo);
$historical = $pdo->prepare('SELECT error_class,dispatch_state,response_known_at,physical_http_calls FROM queue_v4_clean_attempts WHERE id=?');
$historical->execute([(int) $fixture['unit02']['historical_attempt_id']]);
$historicalRow = $historical->fetch(PDO::FETCH_ASSOC);
r0h3_assert(($applied['status'] ?? '') === 'APPLIED'
    && ($replayed['status'] ?? '') === 'ALREADY_APPLIED'
    && $after === [
        'outstanding' => 1,
        'administrative_dispositions' => 1,
        'physical_unknown_dispatches' => 2,
        'measurement_status' => 'CERTIFIED',
        'measurement_scope' => 'H3_CERTIFIED_NON_H3_PACK_DISCOVERY',
    ], 'k3_unit02_real_class_applies_once_and_replays', [
        'applied' => $applied,
        'replayed' => $replayed,
        'after' => $after,
    ]);
r0h3_assert(k3u2_unit_evidence_hash($pdo, $fixture['unit01']) === $beforeHashes['unit01']
    && k3u2_unit_evidence_hash($pdo, $fixture['unit02']) === $beforeHashes['unit02']
    && (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn() === $beforeJobs
    && (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_attempts')->fetchColumn() === $beforeAttempts
    && (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_transport_events')->fetchColumn() === $beforeEvents,
    'k3_unit02_real_class_preserves_original_evidence_and_work');
r0h3_assert(is_array($historicalRow)
    && (string) $historicalRow['error_class'] === K3U2_REAL_HISTORICAL_ERROR_CLASS
    && (string) $historicalRow['dispatch_state'] === 'PHYSICAL_STARTED'
    && empty($historicalRow['response_known_at'])
    && (int) $historicalRow['physical_http_calls'] === 1,
    'k3_unit02_real_class_keeps_historical_response_unknown', is_array($historicalRow) ? $historicalRow : []);

foreach ([
    'unknown_literal' => 'something_else',
    'suffix_near_miss' => K3U2_REAL_HISTORICAL_ERROR_CLASS . ':extra',
    'unprefixed_near_miss' => 'remote_uncertain_safe_get',
    'wrong_capability' => 'domain_source_waiting:financial_reconciliation:remote_uncertain_safe_get',
] as $case => $errorClass) {
    k3u2_real_assert_rejected($pdo, 'k3_unit02_real_class_rejects_' . $case, $errorClass);
}

k3u2_real_assert_rejected($pdo, 'k3_unit02_real_class_rejects_attempt_generation_drift', K3U2_REAL_HISTORICAL_ERROR_CLASS,
    function (array $unit) use ($pdo): void {
        $pdo->prepare('UPDATE queue_v4_clean_attempts SET lease_generation=lease_generation+1 WHERE id=?')
            ->execute([(int) $unit['historical_attempt_id']]);
    });
k3u2_real_assert_rejected($pdo, 'k3_unit02_real_class_rejects_transport_endpoint_drift', K3U2_REAL_HISTORICAL_ERROR_CLASS,
    function (array $unit) use ($pdo): void {
        $pdo->prepare("UPDATE queue_v4_clean_transport_events SET endpoint_key='different_endpoint' WHERE id=?")
            ->execute([(int) $unit['historical_transport_event_id']]);
    });
k3u2_real_assert_rejected($pdo, 'k3_unit02_real_class_rejects_known_historical_response', K3U2_REAL_HISTORICAL_ERROR_CLASS,
    function (array $unit) use ($pdo): void {
        $pdo->prepare('UPDATE queue_v4_clean_attempts SET response_known_at=UTC_TIMESTAMP(3) WHERE id=?')
            ->execute([(int) $unit['historical_attempt_id']]);
    });
k3u2_real_assert_rejected($pdo, 'k3_unit02_real_class_rejects_pack_integrity_drift', K3U2_REAL_HISTORICAL_ERROR_CLASS,
    function (array $unit) use ($pdo): void {
        $pdo->prepare("UPDATE meli_packs SET integrity_status='provisional' WHERE id=? AND meli_account_id=?")
            ->execute([(int) $unit['pack_row_id'], (int) $unit['meli_account_id']]);
    });
k3u2_real_assert_rejected($pdo, 'k3_unit02_real_class_rejects_h3_authority_drift', K3U2_REAL_HISTORICAL_ERROR_CLASS,
    function () use ($pdo): void {
        $pdo->exec("DELETE FROM app_settings WHERE setting_key='queue_v4.pack_discovery_h3_authority'");
    });
k3u2_real_assert_rejected($pdo, 'k3_unit02_real_class_rejects_identity_drift', K3U2_REAL_HISTORICAL_ERROR_CLASS,
    function (array $unit) use ($pdo): void {
        $pdo->prepare('UPDATE queue_v4_clean_attempts SET meli_account_id=7201 WHERE id=?')
            ->execute([(int) $unit['historical_attempt_id']]);
    });

echo json_encode([
    'status' => 'PASS',
    'exact_literal' => K3U2_REAL_HISTORICAL_ERROR_CLASS,
    'unit01' => 'RETAINED',
    'unit02_historical_response' => 'UNKNOWN',
    'before' => $before,
    'after' => $after,
    'negative_cases' => 10,
    'real_meli_http' => 0,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
