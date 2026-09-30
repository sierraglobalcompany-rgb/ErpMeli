<?php

declare(strict_types=1);

require __DIR__ . '/k3_unit02_disposition_fixture.inc.php';

use App\QueueV4Clean\PackDiscoveryOccupancyPolicy;
use App\QueueV4Clean\PackDiscoveryUnit02DispositionService;

const K3U2_REASON = 'Felipe approved the unique non-renewable UNIT-02 occupancy disposition.';

/** @return array<string,mixed> */
function k3u2_h3_document(PDO $pdo): array
{
    $statement = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');
    $statement->execute([PackDiscoveryOccupancyPolicy::AUTHORITY_KEY]);
    $decoded = json_decode((string) $statement->fetchColumn(), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('k3_unit02_h3_fixture_invalid');
    }
    return $decoded;
}

/** @param array<string,mixed> $document */
function k3u2_replace_h3(PDO $pdo, array $document): void
{
    $pdo->prepare('UPDATE app_settings SET setting_value=? WHERE setting_key=?')->execute([
        json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        PackDiscoveryOccupancyPolicy::AUTHORITY_KEY,
    ]);
}

/** @param callable(PDO,array<string,mixed>,array<string,mixed>):void $mutate */
function k3u2_expect_h3_rejection(PDO $pdo, string $expected, callable $mutate): void
{
    $fixture = k3u2_seed($pdo);
    $evidence = k3u2_authorized_evidence($pdo, $fixture['unit02']);
    $mutate($pdo, $fixture, $evidence);
    $actual = null;
    try {
        (new PackDiscoveryUnit02DispositionService($pdo))->apply($evidence, 5001, K3U2_REASON, true);
    } catch (Throwable $error) {
        $actual = $error->getMessage();
    }
    r0h3_assert($actual === $expected, 'k3_unit02_h3_rejection_reason', ['expected' => $expected, 'actual' => $actual]);
    r0h3_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key='queue_v4.pack_discovery_unit02_disposition'")->fetchColumn() === 0
        && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='pack_discovery_unit02_disposition_applied'")->fetchColumn() === 0,
        'k3_unit02_h3_rejection_zero_partial_writes',
        ['reason' => $actual]
    );
}

k3u2_expect_h3_rejection($pdo, 'k3_unit02_h3_authority_missing', static function (PDO $pdo): void {
    $pdo->prepare('DELETE FROM app_settings WHERE setting_key=?')->execute([PackDiscoveryOccupancyPolicy::AUTHORITY_KEY]);
});

k3u2_expect_h3_rejection($pdo, 'k3_unit02_h3_authority_malformed', static function (PDO $pdo): void {
    $pdo->prepare('UPDATE app_settings SET setting_value=? WHERE setting_key=?')->execute(['{', PackDiscoveryOccupancyPolicy::AUTHORITY_KEY]);
});

k3u2_expect_h3_rejection($pdo, 'k3_unit02_h3_authority_version_invalid', static function (PDO $pdo): void {
    $authority = k3u2_h3_document($pdo);
    $authority['version'] = 'unsupported-version';
    k3u2_replace_h3($pdo, $authority);
});

k3u2_expect_h3_rejection($pdo, 'k3_unit02_h3_authority_incomplete', static function (PDO $pdo): void {
    $authority = k3u2_h3_document($pdo);
    array_pop($authority['historical']);
    k3u2_replace_h3($pdo, $authority);
});

k3u2_expect_h3_rejection($pdo, 'k3_unit02_h3_authority_changed', static function (PDO $pdo): void {
    $authority = k3u2_h3_document($pdo);
    $authority['historical'][0]['source_sha256'] = str_repeat('0', 64);
    k3u2_replace_h3($pdo, $authority);
});

k3u2_expect_h3_rejection($pdo, 'k3_unit02_h3_scope_denied', static function (PDO $pdo): void {
    $authority = k3u2_h3_document($pdo);
    $authority['scope'] = array_values(array_filter(
        $authority['scope'],
        static fn (array $row): bool => (int) $row['meli_account_id'] !== 7202
    ));
    k3u2_replace_h3($pdo, $authority);
});

k3u2_expect_h3_rejection($pdo, 'k3_unit02_h3_identity_conflict', static function (PDO $pdo, array $fixture): void {
    $unit = $fixture['unit02'];
    $authority = k3u2_h3_document($pdo);
    $authority['historical'][2] = [
        'company_id' => (int) $unit['company_id'],
        'meli_account_id' => (int) $unit['meli_account_id'],
        'source_id' => (int) $unit['source_id'],
        'queue_id' => (int) $unit['queue_id'],
        'source_sha256' => r0h3_source_hash($pdo, (int) $unit['source_id']),
        'queue_sha256' => r0h3_queue_hash($pdo, (int) $unit['queue_id']),
        'attempts_sha256' => r0h3_attempts_hash($pdo, (int) $unit['company_id'], (int) $unit['meli_account_id'], (int) $unit['queue_id']),
        'transport_events_sha256' => r0h3_transport_hash($pdo, (int) $unit['company_id'], (int) $unit['meli_account_id'], (int) $unit['queue_id']),
    ];
    k3u2_replace_h3($pdo, $authority);
});

$fixture = k3u2_seed($pdo);
$evidence = k3u2_authorized_evidence($pdo, $fixture['unit02']);
$service = new PackDiscoveryUnit02DispositionService($pdo);
$applied = $service->apply($evidence, 5001, K3U2_REASON, true);
$replayed = $service->apply($evidence, 5001, K3U2_REASON, true);
r0h3_assert(($applied['status'] ?? '') === 'APPLIED' && ($replayed['status'] ?? '') === 'ALREADY_APPLIED', 'k3_unit02_valid_h3_apply_and_replay');

$storedBefore = (string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='queue_v4.pack_discovery_unit02_disposition'")->fetchColumn();
$pdo->prepare('DELETE FROM app_settings WHERE setting_key=?')->execute([PackDiscoveryOccupancyPolicy::AUTHORITY_KEY]);
$replayError = null;
try {
    $service->apply($evidence, 5001, K3U2_REASON, true);
} catch (Throwable $error) {
    $replayError = $error->getMessage();
}
$storedAfter = (string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='queue_v4.pack_discovery_unit02_disposition'")->fetchColumn();
r0h3_assert($replayError === 'k3_unit02_h3_authority_missing'
    && hash_equals($storedBefore, $storedAfter)
    && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='pack_discovery_unit02_disposition_applied'")->fetchColumn() === 1,
    'k3_unit02_replay_revalidates_h3_without_mutating_existing_disposition',
    ['error' => $replayError]);

echo json_encode(['status' => 'PASS', 'h3_gate_cases' => 9, 'real_meli_http' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
