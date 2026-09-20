<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

use App\QueueV4Clean\QueueV4CleanProducer;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\CronAdmissionService;
use App\Services\OrderEnrichmentService;

const K3_ROUTE_EXISTING = 1;
const K3_ROUTE_COVERAGE = 2;

/** @param array<string,mixed> $target */
function k3_source(PDO $pdo, array $target): int
{
    $sourceId = (new OrderEnrichmentService())->enqueue(
        (int) $target['meli_account_id'],
        (int) $target['order_id'],
        'pack',
        (string) $target['external_pack_id'],
        10,
    );
    $pdo->prepare('UPDATE order_resource_enrichment_jobs SET next_run_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?')
        ->execute([$sourceId]);
    return $sourceId;
}

/** @param array<string,mixed> $target */
function k3_admit(PDO $pdo, array $target, int $sourceId): void
{
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
        r0h3_assert(!empty($receipt['accepted']) && empty($receipt['deduplicated']), 'k3_fixture_occupant_admitted', $receipt);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function k3_schedule(PDO $pdo): int
{
    $producer = new QueueV4CleanProducer($pdo, new QueueV4CleanRepository($pdo));
    $method = new ReflectionMethod($producer, 'schedulePackExactDiscovery');
    return (int) $method->invoke($producer, [
        ['company_id' => 7100, 'meli_account_id' => 7101],
        ['company_id' => 7200, 'meli_account_id' => 7201],
        ['company_id' => 7200, 'meli_account_id' => 7202],
    ]);
}

$fixture = r0h3_seed($pdo);
$healthy = $fixture['healthy'];

$occupantSource = k3_source($pdo, $healthy[0]);
k3_admit($pdo, $healthy[0], $occupantSource);
$existingCandidateSource = k3_source($pdo, $healthy[1]);
$coveragePack = $healthy[2];

$pdo->prepare(
    "INSERT INTO queue_v4_clean_checkpoints
        (producer_key,company_id,meli_account_id,watermark_at,next_due_at,last_job_id)
     VALUES ('pack_discovery_fairness',0,0,NULL,UTC_TIMESTAMP(3),?)
     ON DUPLICATE KEY UPDATE last_job_id=VALUES(last_job_id)"
)->execute([K3_ROUTE_EXISTING]);

$beforeCoverage = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE resource_type='pack'")->fetchColumn();
$created = k3_schedule($pdo);
$afterCoverage = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE resource_type='pack'")->fetchColumn();

$existingQueued = (int) $pdo->query(
    "SELECT COUNT(*) FROM queue_v4_clean_jobs
     WHERE job_type='domain_exact' AND resource_id='" . $existingCandidateSource . "'
       AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='order_enrichment_pack'"
)->fetchColumn();
$coverageSource = $pdo->prepare(
    "SELECT j.id
       FROM order_resource_enrichment_jobs j
       JOIN queue_v4_clean_jobs q
         ON q.meli_account_id=j.meli_account_id AND q.resource_id=CAST(j.id AS CHAR)
      WHERE j.meli_account_id=? AND j.resource_type='pack' AND j.external_resource_id=?
        AND q.job_type='domain_exact'
        AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'
      LIMIT 1"
);
$coverageSource->execute([(int) $coveragePack['meli_account_id'], (string) $coveragePack['external_pack_id']]);
$coverageSourceId = (int) $coverageSource->fetchColumn();

$result = [
    'created' => $created,
    'existing_candidate_source' => $existingCandidateSource,
    'existing_candidate_queued' => $existingQueued,
    'coverage_pack' => (string) $coveragePack['external_pack_id'],
    'coverage_source_id' => $coverageSourceId,
    'source_count_delta' => $afterCoverage - $beforeCoverage,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

r0h3_assert($created === 1, 'k3_one_vacancy_must_admit_exactly_one_unit', $result);
r0h3_assert($existingQueued === 0, 'k3_previous_existing_turn_must_not_repeat_when_coverage_is_eligible', $result);
r0h3_assert($coverageSourceId > 0 && $afterCoverage === $beforeCoverage + 1, 'k3_coverage_route_must_receive_next_successful_opportunity', $result);

$cursorAfterCoverage = (int) $pdo->query(
    "SELECT last_job_id FROM queue_v4_clean_checkpoints
      WHERE producer_key='pack_discovery_fairness' AND company_id=0 AND meli_account_id=0"
)->fetchColumn();
r0h3_assert($cursorAfterCoverage === K3_ROUTE_COVERAGE, 'k3_successful_coverage_must_persist_cursor', [
    'cursor' => $cursorAfterCoverage,
]);

$pdo = null;
$pdoRestart = new PDO(
    'mysql:host=127.0.0.1;port=' . getenv('R0_H3_DB_PORT') . ';dbname=' . getenv('R0_H3_DB_NAME') . ';charset=utf8mb4',
    'root',
    '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);
$fixtureRestart = r0h3_seed($pdoRestart);
$healthyRestart = $fixtureRestart['healthy'];
$restartOccupant = k3_source($pdoRestart, $healthyRestart[0]);
k3_admit($pdoRestart, $healthyRestart[0], $restartOccupant);
$restartExisting = k3_source($pdoRestart, $healthyRestart[1]);
$beforeRestartSources = (int) $pdoRestart->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE resource_type='pack'")->fetchColumn();
$restartCreated = k3_schedule($pdoRestart);
$afterRestartSources = (int) $pdoRestart->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE resource_type='pack'")->fetchColumn();
$restartExistingQueued = (int) $pdoRestart->query(
    "SELECT COUNT(*) FROM queue_v4_clean_jobs
     WHERE job_type='domain_exact' AND resource_id='" . $restartExisting . "'
       AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='order_enrichment_pack'"
)->fetchColumn();
$cursorAfterRestart = (int) $pdoRestart->query(
    "SELECT last_job_id FROM queue_v4_clean_checkpoints
      WHERE producer_key='pack_discovery_fairness' AND company_id=0 AND meli_account_id=0"
)->fetchColumn();
$restartResult = [
    'created' => $restartCreated,
    'existing_source' => $restartExisting,
    'existing_queued' => $restartExistingQueued,
    'source_count_delta' => $afterRestartSources - $beforeRestartSources,
    'cursor' => $cursorAfterRestart,
];
echo json_encode(['restart' => $restartResult], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert($restartCreated === 1 && $restartExistingQueued === 1, 'k3_cursor_must_survive_new_connection_and_select_existing_next', $restartResult);
r0h3_assert($afterRestartSources === $beforeRestartSources, 'k3_existing_turn_must_not_create_coverage', $restartResult);
r0h3_assert($cursorAfterRestart === K3_ROUTE_EXISTING, 'k3_successful_existing_must_persist_cursor', $restartResult);
