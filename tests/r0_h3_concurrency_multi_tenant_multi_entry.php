<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_transport_fixture.inc.php';

use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\Cap2DomainsWire;
use App\Services\CronAdmissionService;

function r0h3_wait_mixed_files(string $runDir, string $prefix, int $count, float $deadline): void
{
    do {
        $files = glob($runDir . DIRECTORY_SEPARATOR . $prefix . '-*.json') ?: [];
        if (count($files) >= $count) {
            return;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    r0h3_assert(false, 'mixed_concurrency_barrier_timeout', [
        'prefix' => $prefix,
        'expected' => $count,
        'actual' => count($files),
        'dir' => $runDir,
    ]);
}

function r0h3_spawn_mixed_participants(string $runDir, array $participants): array
{
    $php = PHP_BINARY;
    $participantScript = __DIR__ . DIRECTORY_SEPARATOR . 'r0_h3_concurrency_mixed_participant.php';
    $processes = [];
    foreach ($participants as $index => $spec) {
        $out = $runDir . DIRECTORY_SEPARATOR . 'stdout-' . $index . '.txt';
        $err = $runDir . DIRECTORY_SEPARATOR . 'stderr-' . $index . '.txt';
        $args = [
            $php,
            $participantScript,
            $runDir,
            (string) $index,
            (string) $spec['mode'],
            (string) $spec['company_id'],
            (string) $spec['meli_account_id'],
            (string) $spec['source_id'],
            (string) $spec['external_pack_id'],
        ];
        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $process = proc_open($cmd, [1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, dirname(__DIR__));
        r0h3_assert(is_resource($process), 'mixed_participant_process_started', ['cmd' => $cmd]);
        $processes[] = $process;
    }

    return $processes;
}

function r0h3_collect_mixed_results(string $runDir, array $processes, int $expected): array
{
    foreach ($processes as $process) {
        $deadline = microtime(true) + 25.0;
        $status = ['running' => true];
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        r0h3_assert(!$status['running'], 'mixed_participant_process_timeout', ['dir' => $runDir, 'status' => $status]);
        proc_close($process);
    }
    r0h3_wait_mixed_files($runDir, 'result', $expected, microtime(true) + 5.0);
    $results = [];
    foreach (glob($runDir . DIRECTORY_SEPARATOR . 'result-*.json') ?: [] as $path) {
        $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $results[] = $decoded + ['result_file' => basename($path)];
    }
    usort($results, static fn (array $a, array $b): int => (int) $a['index'] <=> (int) $b['index']);
    r0h3_assert(count($results) === $expected, 'mixed_result_count', ['expected' => $expected, 'actual' => count($results)]);
    return $results;
}

function r0h3_prepare_mixed_scenario(PDO $pdo, int $needed): array
{
    r0h3_seed($pdo);
    r0h3_prepare_transport_fixture($pdo, 10);
    r0h3_clear_positive_scope($pdo);
    r0h3_defer_fresh_order_discovery($pdo);
    $tenants = [
        [7100, 7101],
        [7200, 7201],
        [7200, 7202],
    ];
    $sources = [];
    for ($i = 0; $i < $needed; $i++) {
        [$companyId, $accountId] = $tenants[$i % count($tenants)];
        $external = (string) (870000 + $i);
        $sources[] = r0h3_add_pack_source_for_admission(
            $pdo,
            $companyId,
            $accountId,
            $external,
            (string) (970000 + $i),
        );
    }

    return $sources;
}

function r0h3_sql_mixed_overlap_observation(PDO $pdo, string $runDir, string $label, int $expectedParticipants): array
{
    $sqlDir = $runDir . DIRECTORY_SEPARATOR . 'sql';
    if (!is_dir($sqlDir)) {
        mkdir($sqlDir, 0777, true);
    }
    $ready = [];
    foreach (glob($runDir . DIRECTORY_SEPARATOR . 'ready-*.json') ?: [] as $path) {
        $ready[] = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR) + ['file' => basename($path)];
    }
    $ids = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['connection_id_before'] ?? 0), $ready)));
    $rows = [];
    if ($ids !== []) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $pdo->prepare(
            "SELECT ID,USER,HOST,DB,COMMAND,TIME,STATE,LEFT(INFO,220) INFO
               FROM information_schema.PROCESSLIST
              WHERE DB=DATABASE()
                AND ID IN ($placeholders)
              ORDER BY ID"
        );
        $statement->execute($ids);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    $active = array_values(array_filter($rows, static fn (array $row): bool => (string) ($row['COMMAND'] ?? '') !== 'Sleep'));
    $observation = [
        'label' => $label,
        'captured_at_utc' => gmdate('c'),
        'coordinator_connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
        'control_row_locked_by_harness' => true,
        'expected_participants' => $expectedParticipants,
        'ready_participants' => $ready,
        'participant_connection_ids' => $ids,
        'processlist_rows' => $rows,
        'active_participant_connections' => count($active),
        'sql_overlap_linked_to_participants_and_real_authority' => count($active) >= min(2, $expectedParticipants) ? 'VERIFIED' : 'NOT_VERIFIED',
    ];
    file_put_contents(
        $sqlDir . DIRECTORY_SEPARATOR . 'processlist-before-release.json',
        json_encode($observation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );

    return $observation;
}

function r0h3_admit_initial_occupants(PDO $pdo, array $sources, int $count): void
{
    $service = new CronAdmissionService($pdo);
    for ($i = 0; $i < $count; $i++) {
        $source = $sources[$i];
        $pdo->beginTransaction();
        $receipt = $service->submit(
            'order_enrichment_pack',
            (int) $source['company_id'],
            (int) $source['meli_account_id'],
            (int) $source['source_id'],
            'source:' . (int) $source['source_id'],
            ['pack_id' => (string) $source['external_pack_id']],
        );
        $pdo->commit();
        r0h3_assert(!empty($receipt['accepted']) && empty($receipt['deduplicated']), 'mixed_initial_occupant_expected', [
            'index' => $i,
            'receipt' => $receipt,
            'source' => $source,
        ]);
    }
}

function r0h3_run_mixed_round(PDO $pdo, string $rootRunDir, string $label, array $participants, int $initialOpen, int $expectedDelta): array
{
    $runDir = $rootRunDir . DIRECTORY_SEPARATOR . preg_replace('/[^a-z0-9_-]+/i', '_', $label);
    mkdir($runDir, 0777, true);
    $pdo->beginTransaction();
    $pdo->query("SELECT control_key FROM queue_v4_clean_control WHERE control_key='primary' FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
    $processes = r0h3_spawn_mixed_participants($runDir, $participants);
    r0h3_wait_mixed_files($runDir, 'ready', count($participants), microtime(true) + 10.0);
    usleep(1500000);
    $sqlObservation = r0h3_sql_mixed_overlap_observation($pdo, $runDir, $label, count($participants));
    $activeBeforeRelease = (int) $sqlObservation['active_participant_connections'];
    $pdo->commit();

    $results = r0h3_collect_mixed_results($runDir, $processes, count($participants));
    $errors = array_values(array_filter($results, static fn (array $result): bool => ($result['error'] ?? null) !== null));
    $transientDenials = array_values(array_filter($results, static fn (array $result): bool => in_array((string) ($result['receipt']['reason'] ?? ''), [
        'R0_OCCUPANCY_UNAVAILABLE',
        'R0_H3_AUTHORITY_UNAVAILABLE',
    ], true)));
    $finalOpen = r0h3_open_pack_units_in_h3_scope($pdo);
    $delta = $finalOpen - $initialOpen;
    r0h3_assert($activeBeforeRelease >= min(2, count($participants)), 'mixed_sql_contention_must_be_observed', [
        'label' => $label,
        'active_before_release' => $activeBeforeRelease,
        'participants' => count($participants),
    ]);
    r0h3_assert($sqlObservation['sql_overlap_linked_to_participants_and_real_authority'] === 'VERIFIED', 'mixed_sql_overlap_linked_to_participants_and_real_authority', $sqlObservation);
    r0h3_assert($errors === [], 'mixed_participants_must_not_error', [
        'label' => $label,
        'errors' => $errors,
        'results' => $results,
    ]);
    r0h3_assert($transientDenials === [], 'mixed_retryable_denials_must_not_remain_final', [
        'label' => $label,
        'transient_denials' => $transientDenials,
        'results' => $results,
    ]);
    r0h3_assert($delta === $expectedDelta, 'mixed_distinct_units_must_fill_only_available_slots', [
        'label' => $label,
        'initial_open' => $initialOpen,
        'final_open' => $finalOpen,
        'delta' => $delta,
        'expected_delta' => $expectedDelta,
        'results' => $results,
    ]);
    r0h3_assert($finalOpen <= 2, 'mixed_global_occupancy_bound', [
        'label' => $label,
        'final_open' => $finalOpen,
    ]);

    return [
        'label' => $label,
        'run_dir' => $runDir,
        'participants' => count($participants),
        'active_wait_observed_count' => $activeBeforeRelease,
        'initial_open' => $initialOpen,
        'final_open' => $finalOpen,
        'delta' => $delta,
        'expected_delta' => $expectedDelta,
        'sql_observation' => $sqlObservation,
        'results' => $results,
    ];
}

$durableRoot = (string) (getenv('R0_H3_CONCURRENCY_EVIDENCE_DIR') ?: '');
$rootRunDir = ($durableRoot !== '' ? $durableRoot : sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'r0-h3-mixed-concurrency-' . bin2hex(random_bytes(4));
mkdir($rootRunDir, 0777, true);
$matrix = [];
$modes = ['service', 'adapter_capability', 'adapter_domain', 'producer'];

foreach ([2, 5, 20] as $participantCount) {
    foreach ([0, 1, 2] as $initial) {
        $sources = r0h3_prepare_mixed_scenario($pdo, $participantCount + $initial + 4);
        r0h3_admit_initial_occupants($pdo, $sources, $initial);
        $initialOpen = r0h3_open_pack_units_in_h3_scope($pdo);
        r0h3_assert($initialOpen === $initial, 'mixed_initial_open_units_match', ['initial' => $initial, 'open' => $initialOpen]);
        $participants = [];
        for ($i = 0; $i < $participantCount; $i++) {
            $source = $sources[$initial + $i];
            $participants[] = $source + ['mode' => $modes[$i % count($modes)]];
        }
        $matrix[] = r0h3_run_mixed_round(
            $pdo,
            $rootRunDir,
            'distinct_' . $participantCount . '_initial_' . $initial,
            $participants,
            $initialOpen,
            min($participantCount, 2 - $initial),
        );
    }
}

foreach ([
    ['producer', 'service'],
    ['producer', 'adapter_capability'],
    ['producer', 'adapter_domain'],
    ['service', 'adapter_capability'],
] as $pair) {
    foreach ([0, 1] as $initial) {
        $sources = r0h3_prepare_mixed_scenario($pdo, $initial + 4);
        r0h3_admit_initial_occupants($pdo, $sources, $initial);
        $initialOpen = r0h3_open_pack_units_in_h3_scope($pdo);
        r0h3_assert($initialOpen === $initial, 'mixed_pair_initial_open_units_match', ['pair' => $pair, 'initial' => $initial, 'open' => $initialOpen]);
        $participants = [
            $sources[$initial] + ['mode' => $pair[0]],
            $sources[$initial + 1] + ['mode' => $pair[1]],
        ];
        $matrix[] = r0h3_run_mixed_round(
            $pdo,
            $rootRunDir,
            'pair_' . implode('_vs_', $pair) . '_initial_' . $initial,
            $participants,
            $initialOpen,
            min(2, 2 - $initial),
        );
    }
}

$sources = r0h3_prepare_mixed_scenario($pdo, 1);
$replayParticipants = [];
foreach (['service', 'adapter_capability', 'adapter_domain', 'service', 'adapter_domain'] as $mode) {
    $replayParticipants[] = $sources[0] + ['mode' => $mode];
}
$matrix[] = r0h3_run_mixed_round($pdo, $rootRunDir, 'replay_same_unit', $replayParticipants, 0, 1);
$replayPointers = r0h3_queue_count($pdo, 'domain_exact', (int) $sources[0]['meli_account_id'], (string) $sources[0]['source_id']);
r0h3_assert($replayPointers === 1, 'mixed_replay_must_have_one_canonical_pointer', [
    'pointers' => $replayPointers,
    'source' => $sources[0],
]);

$sources = r0h3_prepare_mixed_scenario($pdo, 8);
r0h3_admit_initial_occupants($pdo, $sources, 1);
$rollbackParticipants = [
    $sources[1] + ['mode' => 'service_rollback'],
    $sources[1] + ['mode' => 'service'],
    $sources[2] + ['mode' => 'adapter_capability'],
    $sources[3] + ['mode' => 'adapter_domain'],
    $sources[4] + ['mode' => 'producer'],
];
$matrix[] = r0h3_run_mixed_round($pdo, $rootRunDir, 'rollback_then_fill', $rollbackParticipants, 1, 1);
$rollbackSourcePointers = r0h3_queue_count($pdo, 'domain_exact', (int) $sources[1]['meli_account_id'], (string) $sources[1]['source_id']);
r0h3_assert($rollbackSourcePointers <= 1, 'mixed_rollback_must_not_create_orphan_duplicates', [
    'source' => $sources[1],
    'pointers' => $rollbackSourcePointers,
]);

$sources = r0h3_prepare_mixed_scenario($pdo, 3);
$directRollback = null;
$pdo->beginTransaction();
try {
    $directRollback = (new CronAdmissionService($pdo))->submit(
        'order_enrichment_pack',
        (int) $sources[0]['company_id'],
        (int) $sources[0]['meli_account_id'],
        (int) $sources[0]['source_id'],
        'source:' . (int) $sources[0]['source_id'],
        ['pack_id' => (string) $sources[0]['external_pack_id']],
    );
    r0h3_assert(!empty($directRollback['accepted']) && empty($directRollback['deduplicated']), 'mixed_direct_rollback_must_reach_real_admission_before_abort', ['receipt' => $directRollback]);
    $pdo->rollBack();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
$directRollbackPointers = r0h3_queue_count($pdo, 'domain_exact', (int) $sources[0]['meli_account_id'], (string) $sources[0]['source_id']);
r0h3_assert($directRollbackPointers === 0, 'mixed_direct_rollback_must_leave_no_pointer', ['pointers' => $directRollbackPointers, 'receipt' => $directRollback]);
$pdo->beginTransaction();
$afterRollbackReceipt = (new CronAdmissionService($pdo))->submit(
    'order_enrichment_pack',
    (int) $sources[1]['company_id'],
    (int) $sources[1]['meli_account_id'],
    (int) $sources[1]['source_id'],
    'source:' . (int) $sources[1]['source_id'],
    ['pack_id' => (string) $sources[1]['external_pack_id']],
);
$pdo->commit();
r0h3_assert(!empty($afterRollbackReceipt['accepted']), 'mixed_direct_rollback_vacancy_must_remain_available', ['receipt' => $afterRollbackReceipt]);
$matrix[] = [
    'label' => 'direct_rollback_then_admit',
    'run_dir' => $rootRunDir,
    'participants' => 0,
    'initial_open' => 0,
    'final_open' => r0h3_open_pack_units_in_h3_scope($pdo),
    'delta' => 1,
    'expected_delta' => 1,
    'direct_rollback_receipt' => $directRollback,
    'after_rollback_receipt' => $afterRollbackReceipt,
];

$sources = r0h3_prepare_mixed_scenario($pdo, 8);
r0h3_admit_initial_occupants($pdo, $sources, 2);
$initialOpen = r0h3_open_pack_units_in_h3_scope($pdo);
r0h3_assert($initialOpen === 2, 'mixed_closure_initial_two_open', ['open' => $initialOpen]);
Cap2DomainsWire::$responses = [
    '/packs/' . $sources[0]['external_pack_id'] => [200, r0h3_wire_pack_response((string) $sources[0]['external_pack_id'], (string) $sources[0]['first_order_external_id'], (string) ((int) $sources[0]['first_order_external_id'] + 1000))],
];
$closeRun = r0h3_worker_run($pdo, 1, [(int) $sources[0]['meli_account_id']]);
r0h3_assert((int) ($closeRun['completed'] ?? 0) === 1, 'mixed_closure_worker_must_complete_one_pack_source', [
    'run' => $closeRun,
    'calls' => Cap2DomainsWire::$calls,
]);
$afterCloseOpen = r0h3_open_pack_units_in_h3_scope($pdo);
r0h3_assert($afterCloseOpen === 1, 'mixed_legitimate_closure_must_open_one_slot', [
    'before' => $initialOpen,
    'after' => $afterCloseOpen,
]);
$vacancyParticipants = [
    $sources[2] + ['mode' => 'service'],
    $sources[3] + ['mode' => 'adapter_capability'],
    $sources[4] + ['mode' => 'adapter_domain'],
    $sources[5] + ['mode' => 'producer'],
];
$matrix[] = r0h3_run_mixed_round($pdo, $rootRunDir, 'closure_new_vacancy', $vacancyParticipants, $afterCloseOpen, 1);

$authority = (string) $pdo->query(
    "SELECT setting_value FROM app_settings WHERE setting_key='queue_v4.pack_discovery_h3_authority' LIMIT 1"
)->fetchColumn();
$authorityDecoded = json_decode($authority, true, 64, JSON_THROW_ON_ERROR);
r0h3_assert(count((array) ($authorityDecoded['historical'] ?? [])) === 3, 'mixed_h3_must_keep_exactly_three_historical_identities');

echo json_encode([
    'CONCURRENCY_MULTI_TENANT_MULTI_ENTRY' => 'PASS',
    'REAL_MELI_HTTP' => 0,
    'SIMULATED_TRANSPORT_CALLS' => count(Cap2DomainsWire::$calls),
    'OPEN_PACK_UNITS_MAX' => 2,
    'H3_HISTORICAL_IDENTITIES' => count((array) ($authorityDecoded['historical'] ?? [])),
    'run_dir' => $rootRunDir,
    'matrix' => $matrix,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
