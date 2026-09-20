<?php

declare(strict_types=1);

require __DIR__ . '/k3_unit02_disposition_fixture.inc.php';

use App\QueueV4Clean\PackDiscoveryOccupancyPolicy;
use App\QueueV4Clean\PackDiscoveryUnit02DispositionService;
use App\Services\CronAdmissionService;

/** @return array{outstanding:int,administrative_dispositions:?int,physical_unknown_dispatches:?int,measurement_status:string,measurement_scope:string} */
function k3u2_measure(PDO $pdo): array
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
function k3u2_apply(PDO $pdo, array $evidence, int $actor = 5001): array
{
    return (new PackDiscoveryUnit02DispositionService($pdo))->apply(
        $evidence,
        $actor,
        'Felipe approved the unique non-renewable UNIT-02 occupancy disposition.',
        true,
    );
}

/** @param callable(array<string,mixed>):void $mutate */
function k3u2_assert_drift_fails_closed(PDO $pdo, string $assertion, callable $mutate): void
{
    $fixture = k3u2_seed($pdo);
    k3u2_apply($pdo, k3u2_authorized_evidence($pdo, $fixture['unit02']));
    $mutate($fixture['unit02']);
    $measured = k3u2_measure($pdo);
    r0h3_assert($measured === [
        'outstanding' => 2,
        'administrative_dispositions' => 0,
        'physical_unknown_dispatches' => 2,
        'measurement_status' => 'CERTIFIED',
        'measurement_scope' => 'H3_CERTIFIED_NON_H3_PACK_DISCOVERY',
    ], $assertion, $measured);
}

$fixture = k3u2_seed($pdo);
$beforeHashes = [
    'unit01' => k3u2_unit_evidence_hash($pdo, $fixture['unit01']),
    'unit02' => k3u2_unit_evidence_hash($pdo, $fixture['unit02']),
];
$before = k3u2_measure($pdo);
r0h3_assert($before === [
    'outstanding' => 2,
    'administrative_dispositions' => 0,
    'physical_unknown_dispatches' => 2,
    'measurement_status' => 'CERTIFIED',
    'measurement_scope' => 'H3_CERTIFIED_NON_H3_PACK_DISCOVERY',
], 'k3_unit02_current_contract_two_occupants', $before);

$evidence = k3u2_authorized_evidence($pdo, $fixture['unit02']);
$applied = k3u2_apply($pdo, $evidence);
$after = k3u2_measure($pdo);
r0h3_assert(($applied['status'] ?? '') === 'APPLIED'
    && $after === [
        'outstanding' => 1,
        'administrative_dispositions' => 1,
        'physical_unknown_dispatches' => 2,
        'measurement_status' => 'CERTIFIED',
        'measurement_scope' => 'H3_CERTIFIED_NON_H3_PACK_DISCOVERY',
    ], 'k3_unit02_exact_disposition_withdraws_only_occupancy', ['applied' => $applied, 'after' => $after]);
r0h3_assert(k3u2_unit_evidence_hash($pdo, $fixture['unit01']) === $beforeHashes['unit01']
    && k3u2_unit_evidence_hash($pdo, $fixture['unit02']) === $beforeHashes['unit02'],
    'k3_unit02_original_evidence_bytes_unchanged');
r0h3_assert((int) $pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key='queue_v4.pack_discovery_unit02_disposition'")->fetchColumn() === 1
    && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='pack_discovery_unit02_disposition_applied'")->fetchColumn() === 1,
    'k3_unit02_authority_and_audit_atomic');

$replayed = k3u2_apply($pdo, $evidence);
r0h3_assert(($replayed['status'] ?? '') === 'ALREADY_APPLIED'
    && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='pack_discovery_unit02_disposition_applied'")->fetchColumn() === 1,
    'k3_unit02_exact_replay_idempotent', $replayed);

$pdo->beginTransaction();
try {
    $unit02Readmission = (new CronAdmissionService($pdo))->submit(
        'order_enrichment_pack',
        (int) $fixture['unit02']['company_id'],
        (int) $fixture['unit02']['meli_account_id'],
        (int) $fixture['unit02']['source_id'],
        'source:' . (int) $fixture['unit02']['source_id'],
        ['pack_id' => (string) $fixture['unit02']['external_pack_id']],
    );
    $unit01Readmission = (new CronAdmissionService($pdo))->submit(
        'order_enrichment_pack',
        (int) $fixture['unit01']['company_id'],
        (int) $fixture['unit01']['meli_account_id'],
        (int) $fixture['unit01']['source_id'],
        'source:' . (int) $fixture['unit01']['source_id'],
        ['pack_id' => (string) $fixture['unit01']['external_pack_id']],
    );
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}
r0h3_assert(($unit02Readmission['reason'] ?? '') === 'SOURCE_NOT_ELIGIBLE'
    && ($unit01Readmission['reason'] ?? '') === 'SOURCE_NOT_ELIGIBLE'
    && k3u2_unit_evidence_hash($pdo, $fixture['unit01']) === $beforeHashes['unit01']
    && k3u2_unit_evidence_hash($pdo, $fixture['unit02']) === $beforeHashes['unit02'],
    'k3_unit02_disposition_does_not_grant_reentry_or_retry', [
        'unit01' => $unit01Readmission,
        'unit02' => $unit02Readmission,
    ]);

$fixture = k3u2_seed($pdo);
$bad = k3u2_authorized_evidence($pdo, $fixture['unit02']);
$bad['hashes']['attempts_sha256'] = str_repeat('0', 64);
$rejected = null;
try {
    k3u2_apply($pdo, $bad);
} catch (Throwable $error) {
    $rejected = $error->getMessage();
}
r0h3_assert($rejected === 'k3_unit02_evidence_mismatch'
    && (int) $pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key='queue_v4.pack_discovery_unit02_disposition'")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='pack_discovery_unit02_disposition_applied'")->fetchColumn() === 0,
    'k3_unit02_invalid_evidence_zero_partial_writes', ['error' => $rejected]);

$fixture = k3u2_seed($pdo);
$evidence = k3u2_authorized_evidence($pdo, $fixture['unit02']);
k3u2_apply($pdo, $evidence);
r0h3_insert($pdo, 'queue_v4_clean_attempts', [
    'job_id' => (int) $fixture['unit02']['queue_id'],
    'run_id' => 992003,
    'company_id' => 7200,
    'meli_account_id' => 7202,
    'lease_owner' => 'k3-unit02-new-attempt',
    'lease_generation' => 3,
    'outcome' => 'running',
    'dispatch_state' => 'NOT_DISPATCHED',
    'physical_http_calls' => 0,
    'started_at' => gmdate('Y-m-d H:i:s'),
]);
$drifted = k3u2_measure($pdo);
r0h3_assert($drifted['outstanding'] === 2 && $drifted['administrative_dispositions'] === 0,
    'k3_unit02_new_attempt_invalidates_discount', $drifted);

$fixture = k3u2_seed($pdo);
$other = k3u2_authorized_evidence($pdo, $fixture['unit01']);
$otherRejected = null;
try {
    k3u2_apply($pdo, $other);
} catch (Throwable $error) {
    $otherRejected = $error->getMessage();
}
r0h3_assert($otherRejected !== null && k3u2_measure($pdo)['outstanding'] === 2,
    'k3_unit02_unit01_never_inherits_disposition', ['error' => $otherRejected]);

$fixture = k3u2_seed($pdo);
$approvalRejected = null;
try {
    (new PackDiscoveryUnit02DispositionService($pdo))->apply(
        k3u2_authorized_evidence($pdo, $fixture['unit02']),
        5001,
        'Felipe approved the unique non-renewable UNIT-02 occupancy disposition.',
        false,
    );
} catch (Throwable $error) {
    $approvalRejected = $error->getMessage();
}
r0h3_assert($approvalRejected === 'k3_unit02_owner_approval_required'
    && (int) $pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key='queue_v4.pack_discovery_unit02_disposition'")->fetchColumn() === 0,
    'k3_unit02_explicit_owner_approval_required', ['error' => $approvalRejected]);

$fixture = k3u2_seed($pdo);
$pdo->exec("INSERT INTO users(id,name,email,password_hash,role,status,is_temporary)
            VALUES(5002,'K3 temporary reviewer','k3-temp@example.invalid','unused','admin',1,1)
            ON DUPLICATE KEY UPDATE role='admin',status=1,is_temporary=1");
$temporaryActorRejected = null;
try {
    k3u2_apply($pdo, k3u2_authorized_evidence($pdo, $fixture['unit02']), 5002);
} catch (Throwable $error) {
    $temporaryActorRejected = $error->getMessage();
}
r0h3_assert($temporaryActorRejected === 'k3_unit02_actor_not_permanent_admin'
    && (int) $pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key='queue_v4.pack_discovery_unit02_disposition'")->fetchColumn() === 0,
    'k3_unit02_actor_must_be_active_permanent_admin', ['error' => $temporaryActorRejected]);

$fixture = k3u2_seed($pdo);
$pdo->prepare(
    "INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES(?,?,0,'queue_v4_clean')"
)->execute([PackDiscoveryOccupancyPolicy::UNIT02_DISPOSITION_KEY, '{"version":"invalid"}']);
r0h3_assert(k3u2_measure($pdo) === [
    'outstanding' => 2,
    'administrative_dispositions' => 0,
    'physical_unknown_dispatches' => 2,
    'measurement_status' => 'CERTIFIED',
    'measurement_scope' => 'H3_CERTIFIED_NON_H3_PACK_DISCOVERY',
], 'k3_unit02_malformed_authority_fails_closed');

$fixture = k3u2_seed($pdo);
$pdo->exec('DROP TRIGGER IF EXISTS k3u2_reject_audit');
$pdo->exec(
    "CREATE TRIGGER k3u2_reject_audit BEFORE INSERT ON audit_logs FOR EACH ROW
     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='k3u2 synthetic audit rejection'"
);
$atomicError = null;
try {
    k3u2_apply($pdo, k3u2_authorized_evidence($pdo, $fixture['unit02']));
} catch (Throwable $error) {
    $atomicError = $error->getMessage();
} finally {
    $pdo->exec('DROP TRIGGER IF EXISTS k3u2_reject_audit');
}
r0h3_assert($atomicError !== null
    && (int) $pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key='queue_v4.pack_discovery_unit02_disposition'")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='pack_discovery_unit02_disposition_applied'")->fetchColumn() === 0,
    'k3_unit02_audit_failure_rolls_back_authority', ['error' => $atomicError]);

k3u2_assert_drift_fails_closed($pdo, 'k3_unit02_queue_lease_invalidates_discount', function (array $unit) use ($pdo): void {
    $pdo->prepare('UPDATE queue_v4_clean_jobs SET lease_owner=?,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 5 MINUTE) WHERE id=?')
        ->execute(['k3-unit02-foreign-owner', (int) $unit['queue_id']]);
});
k3u2_assert_drift_fails_closed($pdo, 'k3_unit02_source_lease_invalidates_discount', function (array $unit) use ($pdo): void {
    $pdo->prepare('UPDATE order_resource_enrichment_jobs SET lock_token=?,locked_at=UTC_TIMESTAMP(3) WHERE id=?')
        ->execute([str_repeat('b', 32), (int) $unit['source_id']]);
});
k3u2_assert_drift_fails_closed($pdo, 'k3_unit02_manual_reservation_invalidates_discount', function (array $unit) use ($pdo): void {
    k3u2_add_active_reservation($pdo, $unit);
});
k3u2_assert_drift_fails_closed($pdo, 'k3_unit02_pack_integrity_drift_invalidates_discount', function (array $unit) use ($pdo): void {
    $pdo->prepare("UPDATE meli_packs SET expected_orders_json='[]' WHERE id=? AND meli_account_id=?")
        ->execute([(int) $unit['pack_row_id'], (int) $unit['meli_account_id']]);
});
k3u2_assert_drift_fails_closed($pdo, 'k3_unit02_additional_pointer_invalidates_discount', function (array $unit) use ($pdo): void {
    r0h3_insert($pdo, 'queue_v4_clean_jobs', [
        'company_id' => (int) $unit['company_id'],
        'meli_account_id' => (int) $unit['meli_account_id'],
        'job_type' => 'domain_exact',
        'resource_id' => (string) $unit['source_id'],
        'idempotency_key' => 'domain:order_enrichment_pack:k3-unit02-extra-pointer',
        'payload_json' => json_encode([
            'capability' => 'order_enrichment_pack',
            'source_id' => (int) $unit['source_id'],
            'payload' => ['pack_id' => (string) $unit['external_pack_id']],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'state' => 'waiting',
        'available_at' => gmdate('Y-m-d H:i:s'),
    ]);
});

echo json_encode([
    'status' => 'PASS',
    'current' => $before,
    'disposed' => $after,
    'unit01' => 'RETAINED',
    'unit02_historical_response' => 'UNKNOWN',
    'real_meli_http' => 0,
    'fail_closed_drift_cases' => 5,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
