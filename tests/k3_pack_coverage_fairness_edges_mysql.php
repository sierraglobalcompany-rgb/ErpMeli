<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

use App\QueueV4Clean\QueueV4CleanProducer;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\OrderEnrichmentService;

/** @param array<string,mixed> $target */
function k3e_source(PDO $pdo, array $target): int
{
    $id = (new OrderEnrichmentService())->enqueue(
        (int) $target['meli_account_id'],
        (int) $target['order_id'],
        'pack',
        (string) $target['external_pack_id'],
        10,
    );
    $pdo->prepare('UPDATE order_resource_enrichment_jobs SET next_run_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?')->execute([$id]);
    return $id;
}

function k3e_cursor(PDO $pdo, int $route): void
{
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_checkpoints
            (producer_key,company_id,meli_account_id,watermark_at,next_due_at,last_job_id)
         VALUES ('pack_discovery_fairness',0,0,NULL,UTC_TIMESTAMP(3),?)
         ON DUPLICATE KEY UPDATE last_job_id=VALUES(last_job_id)"
    )->execute([$route]);
}

function k3e_cursor_value(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT last_job_id FROM queue_v4_clean_checkpoints
          WHERE producer_key='pack_discovery_fairness' AND company_id=0 AND meli_account_id=0"
    )->fetchColumn();
}

function k3e_schedule(PDO $pdo): int
{
    $method = new ReflectionMethod(new QueueV4CleanProducer($pdo, new QueueV4CleanRepository($pdo)), 'schedulePackExactDiscovery');
    return (int) $method->invoke(new QueueV4CleanProducer($pdo, new QueueV4CleanRepository($pdo)), [
        ['company_id' => 7100, 'meli_account_id' => 7101],
        ['company_id' => 7200, 'meli_account_id' => 7201],
        ['company_id' => 7200, 'meli_account_id' => 7202],
    ]);
}

$results = [];

// Only coverage exists: a missing preferred existing route must not block progress.
$fixture = r0h3_seed($pdo);
k3e_cursor($pdo, 2);
$before = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202) AND resource_type='pack'")->fetchColumn();
$created = k3e_schedule($pdo);
$after = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202) AND resource_type='pack'")->fetchColumn();
$results['only_coverage'] = ['created' => $created, 'source_delta' => $after - $before, 'cursor' => k3e_cursor_value($pdo)];
r0h3_assert($created === 2 && $after - $before === 2, 'k3_only_coverage_route_must_fill_available_slots', $results['only_coverage']);

// Only existing sources exist: coverage is absent and the legacy route continues in FIFO order.
$fixture = r0h3_seed($pdo);
k3e_cursor($pdo, 1);
$existingA = k3e_source($pdo, $fixture['healthy'][0]);
$existingB = k3e_source($pdo, $fixture['healthy'][1]);
$pdo->prepare(
    "UPDATE meli_packs
        SET expected_orders_count=1,expected_orders_json=JSON_ARRAY(external_pack_id)
      WHERE meli_account_id IN (7201,7202)
        AND external_pack_id NOT IN (?,?)"
)->execute([(string) $fixture['healthy'][0]['external_pack_id'], (string) $fixture['healthy'][1]['external_pack_id']]);
$before = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202) AND resource_type='pack'")->fetchColumn();
$created = k3e_schedule($pdo);
$after = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202) AND resource_type='pack'")->fetchColumn();
$queued = array_map('intval', $pdo->query(
    "SELECT CAST(resource_id AS UNSIGNED) FROM queue_v4_clean_jobs
      WHERE company_id=7200 AND job_type='domain_exact'
        AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='order_enrichment_pack'
      ORDER BY id"
)->fetchAll(PDO::FETCH_COLUMN));
$results['only_existing'] = ['created' => $created, 'source_delta' => $after - $before, 'queued' => $queued];
r0h3_assert($created === 2 && $after === $before && $queued === [$existingA, $existingB], 'k3_only_existing_route_must_preserve_fifo_without_new_coverage', $results['only_existing']);

// Neither route exists: no spin and no invented turn.
$fixture = r0h3_seed($pdo);
k3e_cursor($pdo, 2);
$pdo->exec("UPDATE meli_packs SET expected_orders_count=1,expected_orders_json=JSON_ARRAY(external_pack_id) WHERE meli_account_id IN (7201,7202)");
$created = k3e_schedule($pdo);
$results['none'] = ['created' => $created, 'cursor' => k3e_cursor_value($pdo)];
r0h3_assert($created === 0 && k3e_cursor_value($pdo) === 2, 'k3_empty_routes_must_not_consume_turn_or_loop', $results['none']);

// An uncertain existing source remains untouched while healthy coverage can progress.
$fixture = r0h3_seed($pdo);
k3e_cursor($pdo, 2);
$uncertain = k3e_source($pdo, $fixture['healthy'][0]);
$pdo->prepare(
    "UPDATE order_resource_enrichment_jobs
        SET status='retry',failure_class='remote_result_uncertain_safe_get',reached_remote=1
      WHERE id=?"
)->execute([$uncertain]);
$created = k3e_schedule($pdo);
$uncertainQueued = (int) $pdo->query(
    "SELECT COUNT(*) FROM queue_v4_clean_jobs
      WHERE company_id=7200 AND job_type='domain_exact' AND resource_id='" . $uncertain . "'"
)->fetchColumn();
$uncertainState = $pdo->query('SELECT status,failure_class,reached_remote FROM order_resource_enrichment_jobs WHERE id=' . $uncertain)->fetch(PDO::FETCH_ASSOC);
$results['uncertain'] = ['created' => $created, 'uncertain_queued' => $uncertainQueued, 'source' => $uncertainState];
r0h3_assert($created === 2 && $uncertainQueued === 0, 'k3_uncertain_source_must_not_block_healthy_coverage_or_be_retried', $results['uncertain']);
r0h3_assert(($uncertainState['failure_class'] ?? '') === 'remote_result_uncertain_safe_get' && (int) ($uncertainState['reached_remote'] ?? 0) === 1, 'k3_uncertain_evidence_must_remain_unchanged', $results['uncertain']);

// A failed post-coverage admission rolls back source, pointer and cursor together.
$fixture = r0h3_seed($pdo);
k3e_cursor($pdo, 1);
$beforeSources = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202)")->fetchColumn();
$pdo->exec('DROP TRIGGER IF EXISTS k3_fail_pack_queue_insert');
$pdo->exec(
    "CREATE TRIGGER k3_fail_pack_queue_insert
     BEFORE INSERT ON queue_v4_clean_jobs
     FOR EACH ROW
     BEGIN
       IF NEW.job_type='domain_exact' AND JSON_UNQUOTE(JSON_EXTRACT(NEW.payload_json,'$.capability'))='order_enrichment_pack' THEN
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='k3_expected_admission_failure';
       END IF;
     END"
);
$failure = null;
try {
    k3e_schedule($pdo);
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $pdo->exec('DROP TRIGGER IF EXISTS k3_fail_pack_queue_insert');
}
$afterSources = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202)")->fetchColumn();
$results['rollback'] = [
    'error' => $failure?->getMessage(),
    'source_delta' => $afterSources - $beforeSources,
    'cursor' => k3e_cursor_value($pdo),
];
r0h3_assert($failure !== null && str_contains($failure->getMessage(), 'k3_expected_admission_failure'), 'k3_failure_must_reach_admission_boundary', $results['rollback']);
r0h3_assert($afterSources === $beforeSources && k3e_cursor_value($pdo) === 1, 'k3_rollback_must_not_leave_source_or_consume_turn', $results['rollback']);

// Corrupt persistent state fails closed before scheduling.
$fixture = r0h3_seed($pdo);
k3e_cursor($pdo, 99);
$invalid = null;
try {
    k3e_schedule($pdo);
} catch (Throwable $error) {
    $invalid = $error;
}
$results['invalid_cursor'] = ['error' => $invalid?->getMessage(), 'queue_count' => (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE company_id=7200")->fetchColumn()];
r0h3_assert($invalid !== null && $invalid->getMessage() === 'queue_v4_clean_pack_discovery_fairness_cursor_invalid', 'k3_invalid_cursor_must_fail_closed', $results['invalid_cursor']);
r0h3_assert($results['invalid_cursor']['queue_count'] === 0, 'k3_invalid_cursor_must_not_admit_work', $results['invalid_cursor']);
k3e_cursor($pdo, 0);

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
