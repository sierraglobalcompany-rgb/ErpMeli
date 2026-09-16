<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

function r0h3_add_pack_source(PDO $pdo, int $ordinal): array
{
    $accountId = $ordinal % 2 === 0 ? 7202 : 7201;
    $packExternal = (string) (840000 + $ordinal);
    $orderExternal = (string) (940000 + $ordinal);
    $past = gmdate('Y-m-d H:i:s', time() - 3600);
    $packId = r0h3_insert($pdo, 'meli_packs', [
        'meli_account_id' => $accountId,
        'external_pack_id' => $packExternal,
        'status' => 'unknown',
        'integrity_status' => 'provisional',
        'synced_at' => $past,
    ]);
    $orderId = r0h3_insert($pdo, 'meli_orders', [
        'meli_account_id' => $accountId,
        'external_order_id' => $orderExternal,
        'external_pack_id' => $packExternal,
        'status' => 'paid',
        'synced_at' => $past,
    ]);
    r0h3_insert($pdo, 'meli_pack_orders', ['meli_pack_id' => $packId, 'meli_order_id' => $orderId]);
    $sourceId = (new App\Services\OrderEnrichmentService())->enqueue($accountId, $orderId, 'pack', $packExternal, 10);

    return [
        'company_id' => 7200,
        'meli_account_id' => $accountId,
        'source_id' => $sourceId,
        'pack_id' => $packExternal,
    ];
}

function r0h3_wait_for_files(string $runDir, string $prefix, int $count, float $deadline): void
{
    do {
        $files = glob($runDir . DIRECTORY_SEPARATOR . $prefix . '-*.json') ?: [];
        if (count($files) >= $count) {
            return;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    r0h3_assert(false, 'concurrency_barrier_timeout', ['prefix' => $prefix, 'expected' => $count, 'actual' => count($files)]);
}

function r0h3_open_pack_queue_units(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(DISTINCT q.company_id, q.meli_account_id, q.resource_id)
         FROM queue_v4_clean_jobs q
         JOIN order_resource_enrichment_jobs j
           ON j.id=CAST(q.resource_id AS UNSIGNED) AND j.meli_account_id=q.meli_account_id
         WHERE q.company_id=7200
           AND q.job_type='domain_exact'
           AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'
           AND NOT (q.state='completed' AND j.status='complete' AND q.completed_at IS NOT NULL AND j.completed_at IS NOT NULL)"
    )->fetchColumn();
}

function r0h3_sql_overlap_observation(PDO $pdo, string $runDir, string $label, int $expectedParticipants): array
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

$matrix = [];
$php = PHP_BINARY;
$participant = __DIR__ . DIRECTORY_SEPARATOR . 'r0_h3_concurrency_participant.php';
$durableRoot = (string) (getenv('R0_H3_CONCURRENCY_EVIDENCE_DIR') ?: '');
$rootRunDir = ($durableRoot !== '' ? $durableRoot : sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'r0-h3-concurrency-' . bin2hex(random_bytes(4));
mkdir($rootRunDir, 0777, true);

foreach ([2, 5, 20] as $participants) {
    foreach ([0, 1, 2] as $initial) {
        r0h3_seed($pdo);
        $sources = [];
        for ($i = 1; $i <= $participants + $initial + 2; $i++) {
            $sources[] = r0h3_add_pack_source($pdo, ($participants * 100) + ($initial * 30) + $i);
        }

        $admission = new App\Services\CronAdmissionService($pdo);
        for ($i = 0; $i < $initial; $i++) {
            $source = $sources[$i];
            $pdo->beginTransaction();
            $receipt = $admission->submit('order_enrichment_pack', $source['company_id'], $source['meli_account_id'], $source['source_id'], 'source:' . $source['source_id'], ['pack_id' => $source['pack_id']]);
            $pdo->commit();
            r0h3_assert(!empty($receipt['accepted']), 'initial_concurrency_occupant_expected', ['receipt' => $receipt, 'initial' => $initial]);
        }

        $runDir = $rootRunDir . DIRECTORY_SEPARATOR . $participants . '-' . $initial;
        mkdir($runDir, 0777, true);
        $pdo->beginTransaction();
        $pdo->query("SELECT control_key FROM queue_v4_clean_control WHERE control_key='primary' FOR UPDATE")->fetch(PDO::FETCH_ASSOC);

        $processes = [];
        for ($i = 0; $i < $participants; $i++) {
            $source = $sources[$initial + $i];
            $out = $runDir . DIRECTORY_SEPARATOR . 'stdout-' . $i . '.txt';
            $err = $runDir . DIRECTORY_SEPARATOR . 'stderr-' . $i . '.txt';
            $cmd = '"' . $php . '" "' . $participant . '" "' . $runDir . '" ' . $i . ' ' . $source['company_id'] . ' ' . $source['meli_account_id'] . ' ' . $source['source_id'] . ' "' . $source['pack_id'] . '"';
            $process = proc_open($cmd, [1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, dirname(__DIR__));
            r0h3_assert(is_resource($process), 'participant_process_started', ['cmd' => $cmd]);
            $processes[] = $process;
        }

        r0h3_wait_for_files($runDir, 'ready', $participants, microtime(true) + 10.0);
        usleep(1500000);
        $sqlObservation = r0h3_sql_overlap_observation($pdo, $runDir, $participants . '-' . $initial, $participants);
        $activeBeforeRelease = (int) $sqlObservation['active_participant_connections'];
        $pdo->commit();

        foreach ($processes as $process) {
            $deadline = microtime(true) + 20.0;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(50000);
            } while (microtime(true) < $deadline);
            r0h3_assert(!$status['running'], 'participant_process_timeout', ['participants' => $participants, 'initial' => $initial]);
            proc_close($process);
        }

        r0h3_wait_for_files($runDir, 'result', $participants, microtime(true) + 5.0);
        $results = [];
        foreach (glob($runDir . DIRECTORY_SEPARATOR . 'result-*.json') ?: [] as $path) {
            $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            $results[] = $decoded;
        }
        $accepted = 0;
        $errors = [];
        $transientDenials = [];
        $waited = 0;
        foreach ($results as $result) {
            if (($result['error'] ?? null) !== null) {
                $errors[] = $result;
                continue;
            }
            if (in_array((string) ($result['receipt']['reason'] ?? ''), [
                'R0_OCCUPANCY_UNAVAILABLE',
                'R0_H3_AUTHORITY_UNAVAILABLE',
            ], true)) {
                $transientDenials[] = $result;
            }
            if (((float) $result['finished_at'] - (float) $result['started_at']) >= 1.0) {
                $waited++;
            }
            if (!empty($result['receipt']['accepted']) && empty($result['receipt']['deduplicated'])) {
                $accepted++;
            }
        }
        $expectedAccepted = min($participants, 2 - $initial);
        $finalOpen = r0h3_open_pack_queue_units($pdo);
        $matrix[] = [
            'participants' => $participants,
            'initial' => $initial,
            'active_wait_observed_count' => $activeBeforeRelease,
            'participants_waited' => $waited,
            'accepted' => $accepted,
            'expected_accepted' => $expectedAccepted,
            'final_open' => $finalOpen,
            'errors' => count($errors),
            'sql_observation' => $sqlObservation,
        ];
        r0h3_assert($sqlObservation['sql_overlap_linked_to_participants_and_real_authority'] === 'VERIFIED', 'sql_overlap_linked_to_participants_and_real_authority', $sqlObservation);
        r0h3_assert($activeBeforeRelease >= min(2, $participants) && $waited >= 1, 'sql_contention_must_be_observed', ['participants' => $participants, 'initial' => $initial, 'processlist_active' => $activeBeforeRelease, 'waited' => $waited]);
        r0h3_assert($errors === [], 'participants_must_not_error', ['participants' => $participants, 'initial' => $initial, 'errors' => $errors, 'results' => $results]);
        r0h3_assert($transientDenials === [], 'retryable_denials_must_not_remain_final', ['participants' => $participants, 'initial' => $initial, 'transient_denials' => $transientDenials, 'results' => $results]);
        r0h3_assert($accepted === $expectedAccepted, 'concurrency_must_admit_exact_available_slots', ['participants' => $participants, 'initial' => $initial, 'accepted' => $accepted, 'expected' => $expectedAccepted, 'results' => $results]);
        r0h3_assert($finalOpen <= 2, 'concurrency_final_occupancy_must_remain_bounded', ['participants' => $participants, 'initial' => $initial, 'final_open' => $finalOpen]);
    }
}

echo json_encode(['matrix' => $matrix, 'run_dir' => $rootRunDir], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
