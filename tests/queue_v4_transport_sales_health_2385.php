<?php

declare(strict_types=1);

$dsn = (string) (getenv('QUEUE_V4_CLEAN_TEST_DSN') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_V4_CLEAN_TEST_DSN is required\n");
    exit(2);
}

$root = dirname(__DIR__);
$private = sys_get_temp_dir() . '/erp-qv4-transport-2385-' . bin2hex(random_bytes(6));
mkdir($private, 0700, true);
define('ERP_TEST_RUNTIME', true);
define('ERP_INSTALLATION_ROOT', $private);
define('ERP_SHARED_ROOT', $private);
define('ERP_RELEASE_ROOT', $root);
putenv('ERP_PRIVATE_PATH=' . $private);
putenv('APP_KEY=queue-v4-transport-2385-local-only');
putenv('ML_WRITE_ENABLED=false');
putenv('CRON_V3_ENABLED=false');
putenv('CRON_V3_SHADOW_ENABLED=false');
$_ENV['APP_KEY'] = 'queue-v4-transport-2385-local-only';
$_ENV['ML_WRITE_ENABLED'] = 'false';
require $root . '/bootstrap.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
});

use App\Core\Crypto;
use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanDispatchFence;
use App\QueueV4Clean\QueueV4CleanHealthSnapshotService;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanUncertainReadRecoveryService;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\AppSettingsService;
use App\Services\MeliApiClient;
use App\Services\MeliHttpTransportInterface;
use App\Services\SalesAuditRunService;

$pdo = new PDO(
    $dsn,
    (string) (getenv('QUEUE_V4_CLEAN_TEST_USER') ?: 'root'),
    (string) (getenv('QUEUE_V4_CLEAN_TEST_PASS') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
);
$pdo->exec("SET time_zone='+00:00'");
Database::setConnection($pdo);

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$accounts = $pdo->query(
    'SELECT a.company_id,a.id,a.meli_user_id FROM meli_accounts a ORDER BY a.id LIMIT 3'
)->fetchAll(PDO::FETCH_ASSOC);
$assert(count($accounts) === 3, 'three_account_fixture_missing');
$target = $accounts[0];
$companyId = (int) $target['company_id'];
$accountId = (int) $target['id'];

$settings = new AppSettingsService();
foreach ([
    'api.rhythm.profile' => 'maximum',
    'api.rhythm.target_http_per_minute' => '90',
    'api.rhythm.current_adaptive_limit' => '90',
    'api.rhythm.minimum_interval_ms' => '1',
    'api.rhythm.rolling_window_seconds' => '60',
    'api.rhythm.adaptive_enabled' => '0',
    'api.rhythm.orders_search_requests_per_15m' => '90',
    'api.rhythm.shared_429_backoff_seconds' => '60',
    'api.rhythm.shared_429_jitter_seconds' => '0',
] as $key => $value) {
    $settings->set($key, $value, 'qv4_2385_test');
}
AppSettingsService::clearCache();

$token = $pdo->prepare(
    'UPDATE meli_tokens SET access_token_encrypted=?,refresh_token_encrypted=?,refresh_version=900,
            expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 6 HOUR) WHERE meli_account_id=?'
);
$token->execute([Crypto::encrypt('test-access'), Crypto::encrypt('test-refresh'), $accountId]);
$pdo->prepare("UPDATE meli_accounts SET status='conectado',last_error=NULL WHERE company_id=? AND id=?")
    ->execute([$companyId, $accountId]);

final class QueueV4SalesTransport2385 implements MeliHttpTransportInterface
{
    public int $physicalCalls = 0;

    public function __construct(private readonly string $mode) {}

    public function request(string $method, string $url, array $data, array $headers, bool $form, array $timeouts): array
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        if ($this->mode === 'before') {
            throw new TypeError('synthetic_before_curl');
        }
        QueueV4CleanDispatchFence::immediatelyBeforeCurl($method, $path);
        $this->physicalCalls++;
        if ($this->mode === 'uncertain') {
            throw new TypeError('synthetic_after_physical_start');
        }
        $status = $this->mode === 'rate' ? 429 : 200;
        QueueV4CleanDispatchFence::responseKnown($status);
        return [
            'status' => $status,
            'body' => $status === 200 ? ['results' => [], 'paging' => ['total' => 0]] : ['error' => 'too_many_requests'],
            'headers' => $status === 429 ? ['retry-after' => '120'] : [],
            'curl_error' => '',
            'duration_ms' => 1,
            'wire_bytes' => 10,
            'decoded_bytes' => 10,
        ];
    }
}

$resetTransportState = static function () use ($pdo): void {
    $pdo->exec('DELETE FROM queue_v4_clean_transport_events');
    foreach (['sync_sales_audit_run_days','sync_sales_audit_run_orders','sync_sales_audit_jobs','sync_sales_audit_runs'] as $table) {
        $pdo->exec('DELETE FROM `' . $table . '`');
    }
    foreach (['api_remote_permits','api_budget_windows','api_rhythm_penalties','api_rhythm_states','api_request_logs','api_error_logs'] as $table) {
        $pdo->exec('DELETE FROM `' . $table . '`');
    }
};

$seed = static function () use ($pdo, $companyId, $accountId): array {
    $pdo->prepare(
        'INSERT INTO sync_sales_audit_runs
         (meli_account_id,company_id,period_year,period_month,timezone_used,normalizer_version,
          local_from,local_to,utc_from,utc_to,status,capture_started_at)
         VALUES (?,?,2026,1,"America/Bogota","test","2026-01-01","2026-02-01",
                 "2026-01-01","2026-02-01","pending",UTC_TIMESTAMP())'
    )->execute([$accountId, $companyId]);
    $runId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO sync_sales_audit_jobs
         (sync_sales_audit_run_id,meli_account_id,company_id,status,page_limit,next_run_at)
         VALUES (?,?,?,"pending",50,UTC_TIMESTAMP())'
    )->execute([$runId, $accountId, $companyId]);
    return [$runId, (int) $pdo->lastInsertId()];
};

$executeMode = static function (string $mode) use ($pdo, $resetTransportState, $seed): array {
    $resetTransportState();
    [, $jobId] = $seed();
    $transport = new QueueV4SalesTransport2385($mode);
    QueueV4CleanCycleBudget::start(10);
    try {
        $result = (new SalesAuditRunService(
            static fn(int $accountId): MeliApiClient => new MeliApiClient($accountId, $transport)
        ))->processDue(1, microtime(true) + 30);
        $job = $pdo->query('SELECT * FROM sync_sales_audit_jobs WHERE id=' . $jobId)->fetch(PDO::FETCH_ASSOC);
        return [$result, $job, $transport->physicalCalls, QueueV4CleanCycleBudget::snapshot()];
    } finally {
        QueueV4CleanCycleBudget::clear();
    }
};

[$result, $job, $calls, $budget] = $executeMode('before');
$assert($result['status'] === 'deferred' && $result['stop_reason'] === 'pre_transport'
    && $calls === 0 && (int) $job['attempts'] === 0
    && $job['remote_dispatch_state'] === 'NOT_DISPATCHED' && $budget['used'] === 0
    && (strtotime((string) $job['next_run_at'] . ' UTC') ?: 0) >= time() + 55,
    'before_curl_not_nonfailure:' . json_encode([$result,$job,$calls,$budget]));

[$result, $job, $calls, $budget] = $executeMode('uncertain');
$assert($result['status'] === 'deferred' && $result['stop_reason'] === 'safe_get_uncertain'
    && $calls === 1 && (int) $job['attempts'] === 0
    && $job['remote_dispatch_state'] === 'PHYSICAL_STARTED' && $budget['used'] === 1,
    'physical_uncertain_not_deferred:' . json_encode([$result,$job,$calls,$budget]));

[$result, $job, $calls, $budget] = $executeMode('rate');
$assert($result['status'] === 'deferred' && $calls === 1 && (int) $job['attempts'] === 0
    && $job['remote_dispatch_state'] === 'RESPONSE_KNOWN' && (int) $job['last_http_status'] === 429
    && (strtotime((string) $job['next_run_at'] . ' UTC') ?: 0) >= time() + 115,
    'retry_after_not_preserved:' . json_encode([$result,$job,$calls,$budget]));

[$result, $job, $calls, $budget] = $executeMode('success');
$assert($result['status'] === 'complete' && $calls === 1 && (int) $job['attempts'] === 1
    && $job['status'] === 'complete' && $job['remote_dispatch_state'] === 'RESPONSE_KNOWN'
    && (int) $job['last_http_status'] === 200,
    'sales_page_not_completed:' . json_encode([$result,$job,$calls,$budget]));

// A real ordinary Queue V4 worker GET must cross the physical journal with a
// positive, monotonic lease generation. Sales-audit and OAuth fixtures do not
// exercise this production path.
$resetTransportState();
$pdo->exec('DELETE FROM queue_v4_clean_recovery_events');
$pdo->exec('DELETE FROM queue_v4_clean_attempts');
$pdo->exec('DELETE FROM queue_v4_clean_jobs');
$pdo->exec('DELETE FROM queue_v4_clean_runs');
$pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE' WHERE control_key='primary'");
$repository = new QueueV4CleanRepository($pdo);
$queueJobId = $repository->enqueue(
    $companyId,
    $accountId,
    'fresh_orders_discovery',
    null,
    'transport-worker-real-get',
    ['from' => '2026-08-13T00:00:00Z', 'to' => '2026-08-13T00:01:00Z', 'offset' => 0, 'limit' => 20],
    3,
);
$queueTransport = new QueueV4SalesTransport2385('success');
$workerFactory = static fn(int $id): MeliApiClient => new MeliApiClient($id, $queueTransport);
for ($generation = 1; $generation <= 2; $generation++) {
    QueueV4CleanCycleBudget::start(10);
    try {
        $workerResult = (new QueueV4CleanWorker($pdo, $repository, $workerFactory))->run('test', 1, 5);
    } finally {
        QueueV4CleanCycleBudget::clear();
    }
    $assert($workerResult === ['claimed' => 1, 'completed' => 1, 'deferred' => 0],
        'ordinary_worker_get_not_completed_generation_' . $generation . ':' . json_encode($workerResult));
    if ($generation === 1) {
        $repository->enqueue(
            $companyId,
            $accountId,
            'fresh_orders_discovery',
            null,
            'transport-worker-real-get',
            ['from' => '2026-08-13T00:00:00Z', 'to' => '2026-08-13T00:01:00Z', 'offset' => 0, 'limit' => 20],
            3,
        );
    }
}
$queueGenerations = $pdo->query(
    "SELECT lease_generation FROM queue_v4_clean_transport_events
     WHERE source_kind='queue' AND work_id=" . $queueJobId . ' ORDER BY id'
)->fetchAll(PDO::FETCH_COLUMN);
$attemptGenerations = $pdo->query(
    'SELECT lease_generation FROM queue_v4_clean_attempts WHERE job_id=' . $queueJobId . ' ORDER BY id'
)->fetchAll(PDO::FETCH_COLUMN);
$jobGeneration = (int) $pdo->query(
    'SELECT lease_generation FROM queue_v4_clean_jobs WHERE id=' . $queueJobId
)->fetchColumn();
$assert(array_map('intval', $queueGenerations) === [1, 2]
    && array_map('intval', $attemptGenerations) === [1, 2]
    && $jobGeneration === 2 && $queueTransport->physicalCalls === 2,
    'ordinary_worker_generation_not_monotonic:' . json_encode([$queueGenerations,$attemptGenerations,$jobGeneration]));
$pdo->exec("UPDATE queue_v4_clean_control SET engine_state='STOPPED' WHERE control_key='primary'");

QueueV4CleanCycleBudget::start(15);
for ($i = 0; $i < 10; $i++) {
    QueueV4CleanCycleBudget::claim();
}
$budgetBlocked = false;
try {
    QueueV4CleanCycleBudget::claim();
} catch (\App\Services\ApiBudgetExhaustedException) {
    $budgetBlocked = true;
}
$assert($budgetBlocked && QueueV4CleanCycleBudget::snapshot() === ['limit' => 10,'used' => 10,'remaining' => 0],
    'global_physical_budget_not_ten');
QueueV4CleanCycleBudget::clear();

// N historical uncertain GETs converge one per scheduler cycle, never as a batch.
$pdo->exec('DELETE FROM queue_v4_clean_recovery_events');
$pdo->exec('DELETE FROM queue_v4_clean_attempts');
$pdo->exec('DELETE FROM queue_v4_clean_jobs');
// A malformed first candidate is quarantined once and cannot starve the two
// valid historical GET recoveries that follow it.
$pdo->prepare(
    'INSERT INTO queue_v4_clean_jobs
     (company_id,meli_account_id,job_type,resource_id,idempotency_key,state,attempt_count,max_attempts,payload_json,last_error_class,lease_generation)
     VALUES (?,? ,"order_exact","80999","recover-invalid","review",3,3,?,"remote_result_uncertain",2)'
)->execute([$companyId, $accountId, json_encode(['order_id' => '80999'], JSON_THROW_ON_ERROR)]);
$invalidJobId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO queue_v4_clean_attempts
     (job_id,run_id,company_id,meli_account_id,lease_owner,lease_generation,outcome,error_class)
     VALUES (?,1,?,?,?,1,"review","remote_result_uncertain")'
)->execute([$invalidJobId, $companyId, $accountId, 'old-invalid']);
foreach ([81001, 81002] as $orderId) {
    $key = 'recover-' . $orderId;
    $pdo->prepare(
        'INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,state,attempt_count,max_attempts,payload_json,last_error_class)
         VALUES (?,?,"order_exact",?,?,"review",3,3,?,"remote_result_uncertain")'
    )->execute([$companyId, $accountId, (string) $orderId, $key, json_encode(['order_id' => (string) $orderId], JSON_THROW_ON_ERROR)]);
    $jobId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO queue_v4_clean_attempts
         (job_id,run_id,company_id,meli_account_id,lease_owner,outcome,error_class)
         VALUES (?,1,?,?,?,"review","remote_result_uncertain")'
    )->execute([$jobId, $companyId, $accountId, 'old-' . $orderId]);
}
$blockedFirst = (new QueueV4CleanUncertainReadRecoveryService($pdo))->recoverOne();
$invalidState = (string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=' . $invalidJobId)->fetchColumn();
$blockedEvents = (int) $pdo->query(
    "SELECT COUNT(*) FROM queue_v4_clean_recovery_events
     WHERE job_id=" . $invalidJobId . " AND recovery_class='blocked_invalid_evidence'"
)->fetchColumn();
$first = (new QueueV4CleanUncertainReadRecoveryService($pdo))->recoverOne();
$waitingAfterFirst = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state='waiting'")->fetchColumn();
$second = (new QueueV4CleanUncertainReadRecoveryService($pdo))->recoverOne();
$waitingAfterSecond = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state='waiting'")->fetchColumn();
$assert($blockedFirst === ['eligible' => 1, 'recovered' => 0, 'blocked' => 1]
    && $invalidState === 'review' && $blockedEvents === 1
    && $first['recovered'] === 1 && $waitingAfterFirst === 1
    && $second['recovered'] === 1 && $waitingAfterSecond === 2,
    'uncertain_recovery_quarantine_or_progress_failed');

// An invalid cross-tenant recovery chain is rejected by the physical FK.
$foreign = $accounts[1];
$recoveredAttempt = $pdo->query(
    'SELECT e.job_id,e.attempt_id,e.company_id,e.meli_account_id
     FROM queue_v4_clean_recovery_events e ORDER BY e.id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
$crossTenantRejected = false;
try {
    $pdo->prepare(
        'INSERT INTO queue_v4_clean_recovery_events
         (job_id,attempt_id,company_id,meli_account_id,recovery_class,observed_dispatch_state)
         VALUES (?,?,?,?,"invalid_cross_tenant","NOT_DISPATCHED")'
    )->execute([
        (int) $recoveredAttempt['job_id'], (int) $recoveredAttempt['attempt_id'],
        (int) $foreign['company_id'], (int) $foreign['id'],
    ]);
} catch (PDOException) {
    $crossTenantRejected = true;
}
$assert($crossTenantRejected, 'cross_tenant_recovery_fk_not_rejected');

// Expired reads are non-failures whether curl never started or the GET became
// uncertain. They return to waiting and do not consume an attempt.
$pdo->exec('DELETE FROM queue_v4_clean_recovery_events');
$pdo->exec('DELETE FROM queue_v4_clean_attempts');
$pdo->exec('DELETE FROM queue_v4_clean_jobs');
foreach (['NOT_DISPATCHED', 'PHYSICAL_STARTED'] as $position => $dispatchState) {
    $pdo->prepare(
        'INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,state,attempt_count,max_attempts,
          payload_json,lease_owner,lease_expires_at)
         VALUES (?,? ,"order_exact",?,?,"running",2,3,?,?,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 10 SECOND))'
    )->execute([
        $companyId, $accountId, (string) (82001 + $position), 'expired-' . $position,
        json_encode(['order_id' => (string) (82001 + $position)], JSON_THROW_ON_ERROR),
        'expired-owner-' . $position,
    ]);
    $expiredJobId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO queue_v4_clean_attempts
         (job_id,run_id,company_id,meli_account_id,lease_owner,outcome,dispatch_state)
         VALUES (?,1,?,?,?,"running",?)'
    )->execute([$expiredJobId, $companyId, $accountId, 'expired-owner-' . $position, $dispatchState]);
}
$expired = (new QueueV4CleanRepository($pdo))->expireLeases();
$expiredRows = $pdo->query(
    'SELECT state,attempt_count,last_error_class FROM queue_v4_clean_jobs ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);
$assert($expired === 2
    && array_column($expiredRows, 'state') === ['waiting', 'waiting']
    && array_map('intval', array_column($expiredRows, 'attempt_count')) === [1, 1]
    && $expiredRows[0]['last_error_class'] === 'pre_transport_lease_expired'
    && $expiredRows[1]['last_error_class'] === 'remote_result_uncertain_safe_get',
    'expired_read_penalized:' . json_encode($expiredRows));

// The inclusive historical request is thirteen calendar months and drains
// exactly one page per invocation of the bounded sales stage.
$resetTransportState();
$period = new DatePeriod(
    new DateTimeImmutable('2025-08-01T00:00:00Z'),
    new DateInterval('P1M'),
    new DateTimeImmutable('2026-09-01T00:00:00Z'),
);
$monthlyJobs = 0;
foreach ($period as $month) {
    $pdo->prepare(
        'INSERT INTO sync_sales_audit_runs
         (meli_account_id,company_id,period_year,period_month,timezone_used,normalizer_version,
          local_from,local_to,utc_from,utc_to,status,capture_started_at)
         VALUES (?,?,?,?,"America/Bogota","test",?,?,?, ?,"pending",UTC_TIMESTAMP())'
    )->execute([
        $accountId, $companyId, (int) $month->format('Y'), (int) $month->format('n'),
        $month->format('Y-m-d H:i:s'), $month->modify('+1 month')->format('Y-m-d H:i:s'),
        $month->format('Y-m-d H:i:s'), $month->modify('+1 month')->format('Y-m-d H:i:s'),
    ]);
    $runId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO sync_sales_audit_jobs
         (sync_sales_audit_run_id,meli_account_id,company_id,status,page_limit,next_run_at)
         VALUES (?,?,?,"pending",50,UTC_TIMESTAMP())'
    )->execute([$runId, $accountId, $companyId]);
    $monthlyJobs++;
}
$monthlyTransport = new QueueV4SalesTransport2385('success');
$monthlyClaimed = 0;
for ($cycle = 0; $cycle < 13; $cycle++) {
    QueueV4CleanCycleBudget::start(10);
    try {
        $cycleResult = (new SalesAuditRunService(
            static fn(int $id): MeliApiClient => new MeliApiClient($id, $monthlyTransport)
        ))->processDue(1, microtime(true) + 30);
        $monthlyClaimed += (int) ($cycleResult['claimed'] ?? 0);
        $assert((int) ($cycleResult['claimed'] ?? 0) <= 1, 'sales_stage_claimed_more_than_one_page');
    } finally {
        QueueV4CleanCycleBudget::clear();
    }
}
$monthlyCompleted = (int) $pdo->query(
    'SELECT COUNT(*) FROM sync_sales_audit_jobs WHERE status="complete"'
)->fetchColumn();
$assert($monthlyJobs === 13 && $monthlyClaimed === 13 && $monthlyCompleted === 13
    && $monthlyTransport->physicalCalls === 13,
    'thirteen_month_sales_did_not_converge:' . json_encode([$monthlyJobs,$monthlyClaimed,$monthlyCompleted,$monthlyTransport->physicalCalls]));

$started = microtime(true);
$health = (new QueueV4CleanHealthSnapshotService($pdo))->snapshot(null, [$companyId], [$accountId]);
$elapsed = microtime(true) - $started;
$journalSales = (int) $pdo->query(
    "SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE source_kind='sales_audit'"
)->fetchColumn();
$assert($elapsed < 1.0 && count($health['account_stats']) === 1
    && (int) $health['oauth']['accounts'] <= 1
    && $journalSales === 13
    && (int) ($health['totals']['http_last_hour'] ?? -1) === 13,
    'queue_v4_health_snapshot_slo_or_tenant_failed:' . json_encode(['elapsed' => $elapsed,'health' => $health]));

$contract = file_get_contents($root . '/app/Services/ApiHealthService.php') ?: '';
$assert(substr_count($contract, "if (PHP_SAPI !== 'cli')") >= 4
    && str_contains($contract, 'new ApiIncidentReadModelService()'),
    'web_health_materialized_gate_missing');

$pdo->exec('DELETE FROM queue_v4_clean_recovery_events');
$pdo->exec('DELETE FROM queue_v4_clean_transport_events');
$pdo->exec('DELETE FROM queue_v4_clean_attempts');
$pdo->exec('DELETE FROM queue_v4_clean_jobs');
$pdo->exec('DELETE FROM sync_sales_audit_jobs');
$pdo->exec('DELETE FROM sync_sales_audit_runs');

echo 'Queue V4 transport/sales/health 2.38.5 PASS checks=' . $checks . PHP_EOL;
