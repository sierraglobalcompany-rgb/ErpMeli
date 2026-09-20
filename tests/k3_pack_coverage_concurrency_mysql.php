<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

use App\QueueV4Clean\PackDiscoveryOccupancyPolicy;
use App\Services\OrderEnrichmentService;

function k3c_wait(string $dir, string $prefix, int $count, float $deadline): array
{
    do {
        $files = glob($dir . DIRECTORY_SEPARATOR . $prefix . '-*.json') ?: [];
        if (count($files) >= $count) {
            return $files;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    r0h3_assert(false, 'k3_concurrency_barrier_timeout', ['prefix' => $prefix, 'expected' => $count, 'actual' => count($files)]);
}

$matrix = [];
$evidenceRoot = (string) (getenv('K3_CONCURRENCY_EVIDENCE_DIR') ?: sys_get_temp_dir());
$root = $evidenceRoot . DIRECTORY_SEPARATOR . 'k3-producer-concurrency-' . bin2hex(random_bytes(4));
mkdir($root, 0777, true);

foreach ([2, 5, 20] as $participants) {
    $fixture = r0h3_seed($pdo);
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_checkpoints
            (producer_key,company_id,meli_account_id,watermark_at,next_due_at,last_job_id)
         VALUES ('pack_discovery_fairness',0,0,NULL,UTC_TIMESTAMP(3),0)
         ON DUPLICATE KEY UPDATE last_job_id=0"
    )->execute();
    $existingSources = [];
    foreach (array_slice($fixture['healthy'], 0, 6) as $target) {
        $source = (new OrderEnrichmentService())->enqueue(
            (int) $target['meli_account_id'],
            (int) $target['order_id'],
            'pack',
            (string) $target['external_pack_id'],
            10,
        );
        $pdo->prepare('UPDATE order_resource_enrichment_jobs SET next_run_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?')->execute([$source]);
        $existingSources[] = $source;
    }
    $authorityBefore = hash('sha256', (string) $pdo->query(
        "SELECT setting_value FROM app_settings WHERE setting_key='queue_v4.pack_discovery_h3_authority'"
    )->fetchColumn());
    $sourceCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202) AND resource_type='pack'")->fetchColumn();

    $runDir = $root . DIRECTORY_SEPARATOR . (string) $participants;
    mkdir($runDir, 0777, true);
    $pdo->beginTransaction();
    $pdo->query("SELECT control_key FROM queue_v4_clean_control WHERE control_key='primary' FOR UPDATE")->fetchColumn();

    $processes = [];
    for ($index = 0; $index < $participants; $index++) {
        $stdout = $runDir . DIRECTORY_SEPARATOR . 'stdout-' . $index . '.txt';
        $stderr = $runDir . DIRECTORY_SEPARATOR . 'stderr-' . $index . '.txt';
        $command = '"' . PHP_BINARY . '" "' . __DIR__ . DIRECTORY_SEPARATOR . 'k3_pack_coverage_producer_participant.php" "' . $runDir . '" ' . $index;
        $process = proc_open($command, [1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes, dirname(__DIR__));
        r0h3_assert(is_resource($process), 'k3_participant_start_failed', ['index' => $index]);
        $processes[] = $process;
    }

    $readyFiles = k3c_wait($runDir, 'ready', $participants, microtime(true) + 15.0);
    $connectionIds = [];
    $readyByConnection = [];
    foreach ($readyFiles as $path) {
        $row = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $connectionIds[] = (int) $row['connection_id'];
        $readyByConnection[(int) $row['connection_id']] = $row;
    }
    $placeholders = implode(',', array_fill(0, count($connectionIds), '?'));
    $processlist = $pdo->prepare("SELECT ID,COMMAND,STATE,INFO FROM information_schema.PROCESSLIST WHERE ID IN ($placeholders) ORDER BY ID");
    $parentConnectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    $lockWaits = $pdo->prepare(
        "SELECT w.requesting_trx_id,w.requested_lock_id,w.blocking_trx_id,w.blocking_lock_id,
                r.trx_mysql_thread_id requesting_thread_id,r.trx_state requesting_state,
                r.trx_wait_started,r.trx_query requesting_query,
                b.trx_mysql_thread_id blocking_thread_id,b.trx_state blocking_state,b.trx_query blocking_query
           FROM information_schema.INNODB_LOCK_WAITS w
           JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id
           JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id
          WHERE r.trx_mysql_thread_id IN ($placeholders)
          ORDER BY r.trx_mysql_thread_id"
    );
    $observationDeadline = microtime(true) + 10.0;
    $processlistRows = [];
    $lockWaitRows = [];
    $correlatedWaiters = [];
    do {
        $processlist->execute($connectionIds);
        $processlistRows = $processlist->fetchAll(PDO::FETCH_ASSOC);
        $lockWaits->execute($connectionIds);
        $lockWaitRows = $lockWaits->fetchAll(PDO::FETCH_ASSOC);
        $waitByConnection = [];
        foreach ($lockWaitRows as $waitRow) {
            $waitByConnection[(int) $waitRow['requesting_thread_id']] = $waitRow;
        }
        $correlatedWaiters = array_values(array_filter($processlistRows, static function (array $row) use ($readyByConnection, $waitByConnection): bool {
            $id = (int) ($row['ID'] ?? 0);
            $info = strtolower((string) ($row['INFO'] ?? ''));
            return isset($readyByConnection[$id])
                && isset($waitByConnection[$id])
                && (string) ($waitByConnection[$id]['requesting_state'] ?? '') === 'LOCK WAIT'
                && str_contains($info, 'queue_v4_clean_control')
                && str_contains(strtolower((string) ($waitByConnection[$id]['requesting_query'] ?? '')), 'queue_v4_clean_control');
        }));
        if (count($correlatedWaiters) === $participants) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $observationDeadline);
    $active = array_values(array_filter($processlistRows, static fn (array $row): bool => (string) $row['COMMAND'] !== 'Sleep'));
    $processlistPath = $runDir . DIRECTORY_SEPARATOR . 'processlist.json';
    file_put_contents($processlistPath, json_encode([
        'captured_before_parent_lock_release' => true,
        'captured_at_utc' => gmdate('c'),
        'parent_connection_id' => $parentConnectionId,
        'participant_ready_receipts' => array_values($readyByConnection),
        'processlist' => $processlistRows,
        'innodb_lock_waits' => $lockWaitRows,
        'correlated_waiting_connection_ids' => array_map(static fn (array $row): int => (int) $row['ID'], $correlatedWaiters),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    r0h3_assert(is_file($processlistPath), 'k3_raw_processlist_capture_must_exist_before_lock_release', [
        'participants' => $participants,
        'path' => $processlistPath,
        'rows' => $processlistRows,
    ]);
    r0h3_assert(count($correlatedWaiters) === $participants, 'k3_every_participant_must_wait_on_real_authority_lock', [
        'participants' => $participants,
        'ready' => $readyByConnection,
        'processlist' => $processlistRows,
        'correlated' => $correlatedWaiters,
    ]);
    $pdo->commit();

    foreach ($processes as $process) {
        $deadline = microtime(true) + 30.0;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        r0h3_assert(!$status['running'], 'k3_participant_timeout', ['participants' => $participants]);
        proc_close($process);
    }
    $resultFiles = k3c_wait($runDir, 'result', $participants, microtime(true) + 5.0);
    $results = [];
    foreach ($resultFiles as $path) {
        $results[] = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }
    $errors = array_values(array_filter($results, static fn (array $row): bool => ($row['error'] ?? null) !== null));
    $created = array_sum(array_map(static fn (array $row): int => (int) ($row['created'] ?? 0), $results));
    $scope = [
        ['company_id' => 7100, 'meli_account_id' => 7101],
        ['company_id' => 7200, 'meli_account_id' => 7201],
        ['company_id' => 7200, 'meli_account_id' => 7202],
    ];
    $pdo->beginTransaction();
    $open = (new PackDiscoveryOccupancyPolicy($pdo))->outstandingForAccounts($scope);
    $pdo->commit();
    $sourceCountAfter = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202) AND resource_type='pack'")->fetchColumn();
    $existingPlaceholders = implode(',', array_fill(0, count($existingSources), '?'));
    $existingStatement = $pdo->prepare(
        "SELECT COUNT(*) FROM queue_v4_clean_jobs q
          JOIN order_resource_enrichment_jobs j ON j.id=CAST(q.resource_id AS UNSIGNED) AND j.meli_account_id=q.meli_account_id
         WHERE q.company_id=7200 AND j.id IN ($existingPlaceholders)
           AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'"
    );
    $existingStatement->execute($existingSources);
    $existingAdmitted = (int) $existingStatement->fetchColumn();
    $authorityAfter = hash('sha256', (string) $pdo->query(
        "SELECT setting_value FROM app_settings WHERE setting_key='queue_v4.pack_discovery_h3_authority'"
    )->fetchColumn());
    $outOfScope = (int) $pdo->query(
        "SELECT COUNT(*) FROM queue_v4_clean_jobs
          WHERE company_id<>7100 AND company_id<>7200
             OR (company_id=7200 AND meli_account_id NOT IN (7201,7202))"
    )->fetchColumn();
    $row = [
        'participants' => $participants,
        'active_overlap' => count($active),
        'authority_lock_waits' => count($correlatedWaiters),
        'processlist_evidence' => $processlistPath,
        'created' => $created,
        'open' => $open,
        'source_delta' => $sourceCountAfter - $sourceCountBefore,
        'existing_admitted' => $existingAdmitted,
        'errors' => $errors,
        'authority_unchanged' => hash_equals($authorityBefore, $authorityAfter),
        'out_of_scope' => $outOfScope,
    ];
    $matrix[] = $row;
    r0h3_assert(count($active) >= min(2, $participants), 'k3_real_sql_overlap_must_be_observed', $row);
    r0h3_assert($errors === [] && $created === 2 && $open === 2, 'k3_concurrent_producers_must_respect_global_two', $row);
    r0h3_assert($sourceCountAfter - $sourceCountBefore === 1 && $existingAdmitted === 1, 'k3_concurrent_successes_must_split_existing_and_coverage', $row);
    r0h3_assert($row['authority_unchanged'] && $outOfScope === 0, 'k3_concurrency_must_preserve_h3_and_tenant_scope', $row);
}

echo json_encode(['matrix' => $matrix, 'evidence_root' => $root], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
