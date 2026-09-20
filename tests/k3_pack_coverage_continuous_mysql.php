<?php
declare(strict_types=1);

use App\QueueV4Clean\QueueV4CleanProducer;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\Cap2DomainsWire;
use App\Services\CronAdmissionService;
use App\Services\OrderEnrichmentService;

$mode = (string) ($argv[1] ?? 'orchestrate');
$contextPath = (string) ($argv[2] ?? '');

if ($mode === 'orchestrate') {
    $evidenceBase = (string) (getenv('K3_CONTINUOUS_EVIDENCE_DIR') ?: sys_get_temp_dir());
    $runDir = $evidenceBase . DIRECTORY_SEPARATOR . 'k3-continuous-' . bin2hex(random_bytes(4));
    if (!is_dir($runDir) && !mkdir($runDir, 0777, true) && !is_dir($runDir)) {
        fwrite(STDERR, "k3_continuous_evidence_dir_failed\n");
        exit(2);
    }
    $contextPath = $runDir . DIRECTORY_SEPARATOR . 'context.json';
    $runs = [];
    foreach (['prepare', 'resume'] as $childMode) {
        $stdout = $runDir . DIRECTORY_SEPARATOR . $childMode . '-stdout.txt';
        $stderr = $runDir . DIRECTORY_SEPARATOR . $childMode . '-stderr.txt';
        $command = '"' . PHP_BINARY . '" "' . __FILE__ . '" ' . $childMode . ' "' . $contextPath . '"';
        $process = proc_open($command, [1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes, dirname(__DIR__));
        if (!is_resource($process)) {
            fwrite(STDERR, "k3_continuous_child_start_failed:$childMode\n");
            exit(3);
        }
        $exit = proc_close($process);
        $runs[$childMode] = ['exit_code' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
        if ($exit !== 0) {
            fwrite(STDERR, (string) file_get_contents($stderr));
            fwrite(STDERR, (string) file_get_contents($stdout));
            exit($exit > 0 ? $exit : 4);
        }
    }
    $finalPath = $runDir . DIRECTORY_SEPARATOR . 'final.json';
    if (!is_file($finalPath)) {
        fwrite(STDERR, "k3_continuous_final_missing\n");
        exit(5);
    }
    $final = json_decode((string) file_get_contents($finalPath), true, 128, JSON_THROW_ON_ERROR);
    $final['process_runs'] = $runs;
    $final['evidence_root'] = $runDir;
    echo json_encode($final, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

if (!in_array($mode, ['prepare', 'resume'], true) || $contextPath === '') {
    fwrite(STDERR, "k3_continuous_mode_invalid\n");
    exit(6);
}

require __DIR__ . '/r0_h3_transport_fixture.inc.php';

/** @return list<array{company_id:int,meli_account_id:int}> */
function k3ct_scope(): array
{
    return [
        ['company_id' => 7100, 'meli_account_id' => 7101],
        ['company_id' => 7200, 'meli_account_id' => 7201],
        ['company_id' => 7200, 'meli_account_id' => 7202],
    ];
}

function k3ct_cursor(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COALESCE(last_job_id,0) FROM queue_v4_clean_checkpoints
          WHERE producer_key='pack_discovery_fairness' AND company_id=0 AND meli_account_id=0"
    )->fetchColumn();
}

function k3ct_schedule(PDO $pdo): int
{
    $producer = new QueueV4CleanProducer($pdo, new QueueV4CleanRepository($pdo));
    $method = new ReflectionMethod($producer, 'schedulePackExactDiscovery');
    return (int) $method->invoke($producer, k3ct_scope());
}

/** @return array{existing:int,coverage:int} */
function k3ct_eligibility(PDO $pdo): array
{
    $existing = (int) $pdo->query(
        "SELECT COUNT(*)
           FROM order_resource_enrichment_jobs j
           JOIN meli_accounts a ON a.id=j.meli_account_id
           JOIN meli_packs p ON p.meli_account_id=j.meli_account_id AND p.external_pack_id=j.external_resource_id
          WHERE a.company_id=7200 AND j.meli_account_id IN (7201,7202)
            AND j.resource_type='pack' AND j.status IN ('pending','retry')
            AND j.next_run_at<=UTC_TIMESTAMP()
            AND (j.locked_at IS NULL OR j.locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))
            AND COALESCE(j.failure_class,'') NOT IN ('remote_result_uncertain','remote_result_uncertain_safe_get')
            AND (p.expected_orders_count IS NULL OR p.expected_orders_count=0 OR p.expected_orders_json IS NULL OR p.expected_orders_json='' OR p.expected_orders_json='[]')
            AND NOT EXISTS (
                SELECT 1 FROM queue_v4_clean_jobs q
                 WHERE q.company_id=a.company_id AND q.meli_account_id=j.meli_account_id
                   AND q.job_type='domain_exact' AND q.resource_id=CAST(j.id AS CHAR)
                   AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'
                   AND q.state IN ('ready','running','waiting','review','completed')
            )"
    )->fetchColumn();
    $coverage = (int) $pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT p.id
              FROM meli_packs p
              JOIN meli_accounts a ON a.id=p.meli_account_id
              JOIN meli_pack_orders po ON po.meli_pack_id=p.id
              JOIN meli_orders o ON o.id=po.meli_order_id AND o.meli_account_id=p.meli_account_id AND o.external_pack_id=p.external_pack_id
             WHERE a.company_id=7200 AND p.meli_account_id IN (7201,7202)
               AND p.external_pack_id IS NOT NULL AND p.external_pack_id<>''
               AND (p.expected_orders_count IS NULL OR p.expected_orders_count=0 OR p.expected_orders_json IS NULL OR p.expected_orders_json='' OR p.expected_orders_json='[]')
               AND NOT EXISTS (
                    SELECT 1 FROM order_resource_enrichment_jobs existing
                     WHERE existing.meli_account_id=p.meli_account_id
                       AND existing.resource_type='pack'
                       AND existing.external_resource_id=p.external_pack_id
                       AND existing.status IN ('pending','retry','running','complete')
               )
             GROUP BY p.id
        ) candidates"
    )->fetchColumn();
    return ['existing' => $existing, 'coverage' => $coverage];
}

/** @param list<array<string,mixed>> $historical */
function k3ct_h3_hash(PDO $pdo, array $historical): string
{
    $parts = [];
    foreach ($historical as $entry) {
        $parts[] = [
            r0h3_source_hash($pdo, (int) $entry['source_id']),
            r0h3_queue_hash($pdo, (int) $entry['queue_id']),
            r0h3_attempts_hash($pdo, (int) $entry['company_id'], (int) $entry['meli_account_id'], (int) $entry['queue_id']),
            r0h3_transport_hash($pdo, (int) $entry['company_id'], (int) $entry['meli_account_id'], (int) $entry['queue_id']),
        ];
    }
    return hash('sha256', json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** @param array<string,int> $unit */
function k3ct_unit_hash(PDO $pdo, array $unit): string
{
    return hash('sha256', json_encode([
        r0h3_source_hash($pdo, $unit['source_id']),
        r0h3_queue_hash($pdo, $unit['queue_id']),
        r0h3_attempts_hash($pdo, $unit['company_id'], $unit['meli_account_id'], $unit['queue_id']),
        r0h3_transport_hash($pdo, $unit['company_id'], $unit['meli_account_id'], $unit['queue_id']),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** @return array{source_id:int,queue_id:int,company_id:int,meli_account_id:int} */
function k3ct_create_uncertain_occupant(PDO $pdo, array $target): array
{
    $sourceId = (new OrderEnrichmentService())->enqueue(
        (int) $target['meli_account_id'],
        (int) $target['order_id'],
        'pack',
        (string) $target['external_pack_id'],
        10,
    );
    $pdo->beginTransaction();
    try {
        $receipt = (new CronAdmissionService($pdo))->submit(
            'order_enrichment_pack',
            (int) $target['company_id'],
            (int) $target['meli_account_id'],
            $sourceId,
            'source:' . $sourceId,
            ['pack_id' => (string) $target['external_pack_id']],
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    r0h3_assert(!empty($receipt['accepted']) && !empty($receipt['job_id']), 'k3_continuous_uncertain_admission_required', $receipt);
    $queueId = (int) $receipt['job_id'];
    $past = gmdate('Y-m-d H:i:s', time() - 60);
    $pdo->prepare(
        "UPDATE order_resource_enrichment_jobs
            SET status='retry',attempts=1,next_run_at=?,failure_class='remote_result_uncertain_safe_get',
                reached_remote=1,lease_generation=1,lock_token=NULL,locked_at=NULL
          WHERE id=? AND meli_account_id=?"
    )->execute([$past, $sourceId, (int) $target['meli_account_id']]);
    $pdo->prepare(
        "UPDATE queue_v4_clean_jobs
            SET state='review',available_at=?,attempt_count=1,lease_owner=NULL,lease_expires_at=NULL,
                lease_generation=1,last_error_class='remote_result_uncertain_safe_get',completed_at=NULL
          WHERE id=? AND company_id=? AND meli_account_id=?"
    )->execute([$past, $queueId, (int) $target['company_id'], (int) $target['meli_account_id']]);
    $attemptId = r0h3_insert($pdo, 'queue_v4_clean_attempts', [
        'job_id' => $queueId,
        'run_id' => 990001,
        'company_id' => (int) $target['company_id'],
        'meli_account_id' => (int) $target['meli_account_id'],
        'lease_owner' => 'k3-continuous-uncertain',
        'lease_generation' => 1,
        'outcome' => 'review',
        'error_class' => 'remote_result_uncertain_safe_get',
        'dispatch_state' => 'PHYSICAL_STARTED',
        'transport_method' => 'GET',
        'endpoint_key' => 'pack_exact',
        'physical_http_calls' => 1,
        'physical_started_at' => $past,
        'started_at' => $past,
        'finished_at' => $past,
    ]);
    r0h3_insert($pdo, 'queue_v4_clean_transport_events', [
        'company_id' => (int) $target['company_id'],
        'meli_account_id' => (int) $target['meli_account_id'],
        'source_kind' => 'queue',
        'work_id' => $queueId,
        'attempt_id' => $attemptId,
        'lease_generation' => 1,
        'request_id' => 'k3-continuous-uncertain-' . $queueId,
        'method' => 'GET',
        'endpoint_key' => 'pack_exact',
        'dispatch_state' => 'PHYSICAL_STARTED',
        'physical_started_at' => $past,
    ]);
    return [
        'source_id' => $sourceId,
        'queue_id' => $queueId,
        'company_id' => (int) $target['company_id'],
        'meli_account_id' => (int) $target['meli_account_id'],
    ];
}

/** @param list<int> $preexistingSourceIds @return array<string,mixed> */
function k3ct_cycle(PDO $pdo, int $ordinal, array $preexistingSourceIds): array
{
    $eligibility = k3ct_eligibility($pdo);
    r0h3_assert($eligibility['existing'] > 0 && $eligibility['coverage'] > 0, 'k3_continuous_both_routes_must_be_eligible', [
        'ordinal' => $ordinal,
        'eligibility' => $eligibility,
    ]);
    $occupancyBefore = r0h3_measure_pack_occupancy($pdo, k3ct_scope(), 'continuous_' . $ordinal . '_before');
    $cursorBefore = k3ct_cursor($pdo);
    $maxQueueBefore = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM queue_v4_clean_jobs')->fetchColumn();
    $sourceCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202) AND resource_type='pack'")->fetchColumn();
    $created = k3ct_schedule($pdo);
    r0h3_assert($created === 1, 'k3_continuous_one_slot_must_admit_exactly_one', ['ordinal' => $ordinal, 'created' => $created]);
    $admitted = $pdo->query(
        "SELECT q.id queue_id,q.company_id,q.meli_account_id,CAST(q.resource_id AS UNSIGNED) source_id,j.external_resource_id
           FROM queue_v4_clean_jobs q
           JOIN order_resource_enrichment_jobs j ON j.id=CAST(q.resource_id AS UNSIGNED) AND j.meli_account_id=q.meli_account_id
          WHERE q.id>$maxQueueBefore AND q.job_type='domain_exact'
            AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'
          ORDER BY q.id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    r0h3_assert(is_array($admitted), 'k3_continuous_admitted_identity_missing', ['ordinal' => $ordinal]);
    $sourceId = (int) $admitted['source_id'];
    $route = in_array($sourceId, $preexistingSourceIds, true) ? 1 : 2;
    $cursorAfter = k3ct_cursor($pdo);
    $occupancyAfterAdmit = r0h3_measure_pack_occupancy($pdo, k3ct_scope(), 'continuous_' . $ordinal . '_after_admit');
    $sourceCountAfterAdmit = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202) AND resource_type='pack'")->fetchColumn();

    $secondCreated = k3ct_schedule($pdo);
    $sourceCountAfterSecond = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202) AND resource_type='pack'")->fetchColumn();
    $cursorAfterSecond = k3ct_cursor($pdo);
    r0h3_assert($secondCreated === 0 && $sourceCountAfterSecond === $sourceCountAfterAdmit && $cursorAfterSecond === $cursorAfter,
        'k3_continuous_full_occupancy_must_not_consume_turn_or_create_coverage', [
            'ordinal' => $ordinal,
            'second_created' => $secondCreated,
            'source_count_after_admit' => $sourceCountAfterAdmit,
            'source_count_after_second' => $sourceCountAfterSecond,
            'cursor_after' => $cursorAfter,
            'cursor_after_second' => $cursorAfterSecond,
        ]);

    $order = $pdo->prepare(
        "SELECT o.external_order_id
           FROM meli_packs p
           JOIN meli_pack_orders po ON po.meli_pack_id=p.id
           JOIN meli_orders o ON o.id=po.meli_order_id AND o.meli_account_id=p.meli_account_id
          WHERE p.meli_account_id=? AND p.external_pack_id=?
          ORDER BY o.id LIMIT 1"
    );
    $order->execute([(int) $admitted['meli_account_id'], (string) $admitted['external_resource_id']]);
    $orderExternal = (string) $order->fetchColumn();
    r0h3_assert($orderExternal !== '', 'k3_continuous_pack_order_missing', $admitted);
    Cap2DomainsWire::$calls = [];
    Cap2DomainsWire::$responses = [
        '/packs/' . (string) $admitted['external_resource_id'] => [200, [
            'id' => (int) $admitted['external_resource_id'],
            'status' => 'confirmed',
            'shipment' => ['id' => null],
            'buyer' => ['id' => 123],
            'orders' => [['id' => (int) $orderExternal]],
        ]],
    ];
    Cap2DomainsWire::$onWire = null;
    r0h3_wait_for_global_rhythm($pdo);
    $worker = r0h3_worker_run($pdo, 1, [(int) $admitted['meli_account_id']]);
    $closed = $pdo->prepare(
        "SELECT q.state queue_state,q.completed_at queue_completed,j.status source_status,j.completed_at source_completed,
                p.integrity_status
           FROM queue_v4_clean_jobs q
           JOIN order_resource_enrichment_jobs j ON j.id=? AND j.meli_account_id=q.meli_account_id
           JOIN meli_packs p ON p.meli_account_id=j.meli_account_id AND p.external_pack_id=j.external_resource_id
          WHERE q.id=?"
    );
    $closed->execute([$sourceId, (int) $admitted['queue_id']]);
    $closedRow = $closed->fetch(PDO::FETCH_ASSOC) ?: [];
    r0h3_assert(count(Cap2DomainsWire::$calls) === 1
        && (string) ($closedRow['queue_state'] ?? '') === 'completed'
        && (string) ($closedRow['source_status'] ?? '') === 'complete'
        && (string) ($closedRow['integrity_status'] ?? '') === 'complete',
        'k3_continuous_worker_must_close_healthy_unit_through_product_code', [
            'ordinal' => $ordinal,
            'wire_calls' => Cap2DomainsWire::$calls,
            'closed' => $closedRow,
            'worker' => $worker,
        ]);
    $occupancyAfterClose = r0h3_measure_pack_occupancy($pdo, k3ct_scope(), 'continuous_' . $ordinal . '_after_close');
    r0h3_assert(
        (int) $occupancyBefore['open_unit_count'] === 1
        && (int) $occupancyAfterAdmit['open_unit_count'] === 2
        && (int) $occupancyAfterClose['open_unit_count'] === 1,
        'k3_continuous_occupancy_must_be_1_2_1', [
            'ordinal' => $ordinal,
            'before' => $occupancyBefore,
            'after_admit' => $occupancyAfterAdmit,
            'after_close' => $occupancyAfterClose,
        ]);
    return [
        'ordinal' => $ordinal,
        'pid' => getmypid(),
        'route' => $route === 1 ? 'EXISTING' : 'COVERAGE',
        'route_code' => $route,
        'both_routes_eligible' => true,
        'eligibility' => $eligibility,
        'cursor_before' => $cursorBefore,
        'cursor_after' => $cursorAfter,
        'synthetic_identity' => [
            'company_id' => (int) $admitted['company_id'],
            'meli_account_id' => (int) $admitted['meli_account_id'],
            'source_id' => $sourceId,
            'queue_id' => (int) $admitted['queue_id'],
            'pack_id' => (string) $admitted['external_resource_id'],
        ],
        'occupancy' => [
            'before' => (int) $occupancyBefore['open_unit_count'],
            'after_admit' => (int) $occupancyAfterAdmit['open_unit_count'],
            'after_close' => (int) $occupancyAfterClose['open_unit_count'],
        ],
        'source_count_before' => $sourceCountBefore,
        'source_count_after_admit' => $sourceCountAfterAdmit,
        'second_producer_created' => $secondCreated,
        'worker_result' => $worker,
        'simulated_transport_calls' => Cap2DomainsWire::$calls,
    ];
}

if ($mode === 'prepare') {
    $fixture = r0h3_seed($pdo);
    r0h3_prepare_transport_fixture($pdo, 1);
    r0h3_defer_fresh_order_discovery($pdo);
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_checkpoints
            (producer_key,company_id,meli_account_id,watermark_at,next_due_at,last_job_id)
         VALUES ('pack_discovery_fairness',0,0,NULL,UTC_TIMESTAMP(3),0)
         ON DUPLICATE KEY UPDATE last_job_id=0"
    )->execute();
    $preexisting = [];
    // Keep one additional existing source unused so both routes remain
    // genuinely eligible before the eighth successful admission.
    foreach (array_slice($fixture['healthy'], 0, 5) as $target) {
        $sourceId = (new OrderEnrichmentService())->enqueue(
            (int) $target['meli_account_id'],
            (int) $target['order_id'],
            'pack',
            (string) $target['external_pack_id'],
            10,
        );
        $pdo->prepare('UPDATE order_resource_enrichment_jobs SET next_run_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?')->execute([$sourceId]);
        $preexisting[] = $sourceId;
    }
    $uncertain = k3ct_create_uncertain_occupant($pdo, $fixture['healthy'][11]);
    $initial = [
        'h3_hash' => k3ct_h3_hash($pdo, $fixture['historical']),
        'uncertain_hash' => k3ct_unit_hash($pdo, $uncertain),
        'cursor' => k3ct_cursor($pdo),
        'occupancy' => r0h3_measure_pack_occupancy($pdo, k3ct_scope(), 'continuous_initial')['open_unit_count'],
    ];
    r0h3_assert($initial['cursor'] === 0 && $initial['occupancy'] === 1, 'k3_continuous_initial_state_required', $initial);
    $trace = [];
    for ($ordinal = 1; $ordinal <= 3; $ordinal++) {
        $trace[] = k3ct_cycle($pdo, $ordinal, $preexisting);
    }
    $context = [
        'prepare_pid' => getmypid(),
        'historical' => $fixture['historical'],
        'uncertain' => $uncertain,
        'preexisting_source_ids' => $preexisting,
        'initial' => $initial,
        'trace' => $trace,
    ];
    file_put_contents($contextPath, json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    echo json_encode(['phase' => 'prepare', 'pid' => getmypid(), 'successes' => count($trace), 'context' => $contextPath], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

$context = json_decode((string) file_get_contents($contextPath), true, 128, JSON_THROW_ON_ERROR);
r0h3_assert((int) ($context['prepare_pid'] ?? 0) > 0 && (int) $context['prepare_pid'] !== getmypid(), 'k3_continuous_real_php_restart_required', [
    'prepare_pid' => $context['prepare_pid'] ?? null,
    'resume_pid' => getmypid(),
]);
$historical = (array) $context['historical'];
$uncertain = (array) $context['uncertain'];
$initial = (array) $context['initial'];
$trace = (array) $context['trace'];
r0h3_assert(k3ct_h3_hash($pdo, $historical) === (string) $initial['h3_hash'], 'k3_continuous_h3_must_survive_restart');
r0h3_assert(k3ct_unit_hash($pdo, $uncertain) === (string) $initial['uncertain_hash'], 'k3_continuous_uncertain_unit_must_survive_restart');
r0h3_assert(k3ct_cursor($pdo) === (int) $trace[array_key_last($trace)]['cursor_after'], 'k3_continuous_cursor_must_survive_restart');
for ($ordinal = 4; $ordinal <= 8; $ordinal++) {
    $trace[] = k3ct_cycle($pdo, $ordinal, array_map('intval', (array) $context['preexisting_source_ids']));
}
$routes = array_column($trace, 'route');
$existingCount = count(array_filter($routes, static fn (string $route): bool => $route === 'EXISTING'));
$coverageCount = count(array_filter($routes, static fn (string $route): bool => $route === 'COVERAGE'));
$orphans = (int) $pdo->query(
    "SELECT COUNT(*)
       FROM queue_v4_clean_jobs q
       LEFT JOIN order_resource_enrichment_jobs j ON j.id=CAST(q.resource_id AS UNSIGNED) AND j.meli_account_id=q.meli_account_id
       LEFT JOIN meli_accounts a ON a.id=q.meli_account_id AND a.company_id=q.company_id
      WHERE q.job_type='domain_exact'
        AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'
        AND (j.id IS NULL OR a.id IS NULL OR CAST(JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.source_id')) AS CHAR)<>q.resource_id)"
)->fetchColumn();
$final = [
    'CONTINUOUS_ONE_SLOT_TRACE' => 'PASS',
    'REAL_PHP_RESTART_SAME_DB' => 'PASS',
    'prepare_pid' => (int) $context['prepare_pid'],
    'resume_pid' => getmypid(),
    'database' => (string) getenv('R0_H3_DB_NAME'),
    'successes' => count($trace),
    'route_counts' => ['EXISTING' => $existingCount, 'COVERAGE' => $coverageCount],
    'routes' => $routes,
    'h3_unchanged' => k3ct_h3_hash($pdo, $historical) === (string) $initial['h3_hash'],
    'uncertain_unchanged' => k3ct_unit_hash($pdo, $uncertain) === (string) $initial['uncertain_hash'],
    'orphan_count' => $orphans,
    'REAL_MELI_HTTP' => 0,
    'trace' => $trace,
];
r0h3_assert(count($trace) === 8 && $existingCount === 4 && $coverageCount === 4, 'k3_continuous_routes_must_alternate_four_each', $final);
r0h3_assert($final['h3_unchanged'] && $final['uncertain_unchanged'] && $orphans === 0, 'k3_continuous_protected_state_and_integrity_required', $final);
$finalPath = dirname($contextPath) . DIRECTORY_SEPARATOR . 'final.json';
file_put_contents($finalPath, json_encode($final, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo json_encode(['phase' => 'resume', 'pid' => getmypid(), 'successes' => count($trace), 'final' => $finalPath], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
