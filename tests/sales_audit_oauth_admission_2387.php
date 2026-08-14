<?php

declare(strict_types=1);

$dsn = (string) (getenv('QUEUE_V4_CLEAN_TEST_DSN') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_V4_CLEAN_TEST_DSN is required\n");
    exit(2);
}

$root = dirname(__DIR__);
$private = sys_get_temp_dir() . '/erp-sales-oauth-2387-' . bin2hex(random_bytes(6));
mkdir($private, 0700, true);
define('ERP_TEST_RUNTIME', true);
define('ERP_INSTALLATION_ROOT', $private);
define('ERP_SHARED_ROOT', $private);
define('ERP_RELEASE_ROOT', $root);
putenv('ERP_PRIVATE_PATH=' . $private);
putenv('APP_KEY=erp-sales-oauth-2387-local-only');
putenv('ML_WRITE_ENABLED=false');
$_ENV['APP_KEY'] = 'erp-sales-oauth-2387-local-only';
$_ENV['ML_WRITE_ENABLED'] = 'false';
require $root . '/bootstrap.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
});

use App\Core\Crypto;
use App\Core\Database;
use App\Services\AppSettingsService;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\ApiBudgetExhaustedException;
use App\Services\OAuthRefreshRequiredException;
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
    'SELECT company_id,id FROM meli_accounts ORDER BY id LIMIT 3'
)->fetchAll(PDO::FETCH_ASSOC);
$assert(count($accounts) === 3, 'three_accounts_required');

(new AppSettingsService())->set('oauth.token_expiry_skew_seconds', '120', 'sales_oauth_2387_test');
AppSettingsService::clearCache();

$reset = static function () use ($pdo): void {
    $pdo->exec("DELETE FROM queue_v4_clean_transport_events WHERE source_kind='sales_audit'");
    $pdo->exec('DELETE FROM sync_sales_audit_run_pages');
    $pdo->exec('DELETE FROM sync_sales_audit_run_days');
    $pdo->exec('DELETE FROM sync_sales_audit_run_orders');
    $pdo->exec('DELETE FROM sync_sales_audit_jobs');
    $pdo->exec('DELETE FROM sync_sales_audit_runs');
    $pdo->exec('DELETE FROM oauth_refresh_operations');
};

$setToken = static function (array $account, int $seconds) use ($pdo): void {
    $pdo->prepare("UPDATE meli_accounts SET status='conectado' WHERE company_id=? AND id=?")
        ->execute([(int) $account['company_id'], (int) $account['id']]);
    $pdo->prepare(
        'INSERT INTO meli_tokens
         (meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,refresh_version)
         VALUES (?,?,?,?,100)
         ON DUPLICATE KEY UPDATE access_token_encrypted=VALUES(access_token_encrypted),
           refresh_token_encrypted=VALUES(refresh_token_encrypted),expires_at=VALUES(expires_at),refresh_version=100'
    )->execute([
        (int) $account['id'],
        Crypto::encrypt('test-access-' . $account['id']),
        Crypto::encrypt('test-refresh-' . $account['id']),
        gmdate('Y-m-d H:i:s', time() + $seconds),
    ]);
};

$scheduleOAuth = static function (array $account, int $seconds = 120) use ($pdo): string {
    $next = gmdate('Y-m-d H:i:s', time() + $seconds);
    $pdo->prepare(
        "INSERT INTO oauth_refresh_operations
         (company_id,meli_account_id,expected_meli_user_id,expected_refresh_version,state,next_attempt_at)
         VALUES (?,?,?,100,'WAITING',?)"
    )->execute([(int) $account['company_id'], (int) $account['id'], 'seller-' . $account['id'], $next]);
    return $next;
};

$seed = static function (array $account, string $status = 'pending', int $month = 1) use ($pdo): array {
    $pdo->prepare(
        'INSERT INTO sync_sales_audit_runs
         (meli_account_id,company_id,period_year,period_month,timezone_used,normalizer_version,
          local_from,local_to,utc_from,utc_to,status,capture_started_at)
         VALUES (?,?,2026,?,"America/Bogota","test","2026-01-01","2026-02-01",
                 "2026-01-01","2026-02-01",?,UTC_TIMESTAMP())'
    )->execute([(int) $account['id'], (int) $account['company_id'], $month, $status === 'error' ? 'error' : 'pending']);
    $runId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO sync_sales_audit_jobs
         (sync_sales_audit_run_id,meli_account_id,company_id,status,page_limit,next_run_at)
         VALUES (?,?,?,?,50,UTC_TIMESTAMP())'
    )->execute([$runId, (int) $account['id'], (int) $account['company_id'], $status]);
    return [$runId, (int) $pdo->lastInsertId()];
};

final class SalesAuditClient2387
{
    public int $calls = 0;

    public function __construct(
        private readonly int $accountId,
        private readonly string $mode,
        private readonly ?PDO $pdo = null,
        private readonly ?int $otherAccountId = null,
    ) {}

    public function get(string $path, array $query = [], array $meta = []): array
    {
        if ($this->mode === 'oauth') {
            throw new OAuthRefreshRequiredException($this->accountId);
        }
        if ($this->mode === 'mismatch') {
            throw new OAuthRefreshRequiredException((int) $this->otherAccountId);
        }
        if ($this->mode === 'dispatch_then_oauth') {
            $this->pdo?->exec(
                "UPDATE sync_sales_audit_jobs
                 SET remote_dispatch_state='PHYSICAL_STARTED'
                 WHERE status='running' AND meli_account_id=" . $this->accountId
            );
            throw new OAuthRefreshRequiredException($this->accountId);
        }
        $this->calls++;
        return ['results' => [], 'paging' => ['total' => 0]];
    }

    public function lastResponseMetadata(): array
    {
        return ['status' => 200, 'headers' => []];
    }
}

// A. Only an expired account is due: it is deferred before claim and does not
// consume an attempt or emit a diagnostic/HTTP call.
$reset();
$expired = $accounts[0];
$setToken($expired, 30);
$next = $scheduleOAuth($expired, 180);
[, $expiredJob] = $seed($expired);
$clientA = new SalesAuditClient2387((int) $expired['id'], 'success');
$resultA = (new SalesAuditRunService(static fn(int $id): object => $clientA))->processDue(1, microtime(true) + 20);
$rowA = $pdo->query('SELECT * FROM sync_sales_audit_jobs WHERE id=' . $expiredJob)->fetch(PDO::FETCH_ASSOC);
$assert(($resultA['status'] ?? '') === 'deferred' && ($resultA['stop_reason'] ?? '') === 'oauth_refresh_required'
    && (int) ($resultA['claimed'] ?? -1) === 0 && (int) ($resultA['errors'] ?? -1) === 0
    && $rowA['status'] === 'waiting_budget' && (int) $rowA['attempts'] === 0
    && (int) $rowA['consecutive_failures'] === 0 && $rowA['diagnostic_id'] === null
    && $rowA['last_error_class'] === 'oauth_refresh_required' && $rowA['next_run_at'] === $next
    && $clientA->calls === 0,
    'expired_only_not_deferred_safely:' . json_encode([$resultA, $rowA, $clientA->calls]));

// B. A newer eligible account may advance while an older account waits for
// OAuth; this selection is separate from the commercial FIFO.
$reset();
$valid = $accounts[1];
$setToken($expired, 30);
$setToken($valid, 7200);
$scheduleOAuth($expired, 180);
[, $olderExpiredJob] = $seed($expired, 'pending', 2);
[, $newerValidJob] = $seed($valid, 'pending', 3);
$pdo->exec('UPDATE sync_sales_audit_jobs SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),updated_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) WHERE id=' . $olderExpiredJob);
$clientB = new SalesAuditClient2387((int) $valid['id'], 'success');
$resultB = (new SalesAuditRunService(static fn(int $id): object => $clientB))->processDue(1, microtime(true) + 20);
$statesB = $pdo->query('SELECT id,status,attempts FROM sync_sales_audit_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$assert((int) ($resultB['claimed'] ?? 0) === 1 && ($resultB['status'] ?? '') === 'complete'
    && (int) $statesB[0]['id'] === $olderExpiredJob && $statesB[0]['status'] === 'pending' && (int) $statesB[0]['attempts'] === 0
    && (int) $statesB[1]['id'] === $newerValidJob && $statesB[1]['status'] === 'complete'
    && $clientB->calls === 1,
    'valid_account_not_selected_over_expired:' . json_encode([$resultB, $statesB]));

// C. Token becomes unusable after claim: persisted NOT_DISPATCHED authority
// permits a precise non-failure refund.
$reset();
$setToken($valid, 7200);
$nextRace = $scheduleOAuth($valid, 240);
[, $raceJob] = $seed($valid, 'pending', 4);
$pdo->exec('UPDATE sync_sales_audit_jobs SET attempts=3,consecutive_failures=2 WHERE id=' . $raceJob);
$clientC = new SalesAuditClient2387((int) $valid['id'], 'oauth');
$resultC = (new SalesAuditRunService(static fn(int $id): object => $clientC))->processDue(1, microtime(true) + 20);
$rowC = $pdo->query('SELECT * FROM sync_sales_audit_jobs WHERE id=' . $raceJob)->fetch(PDO::FETCH_ASSOC);
$assert(($resultC['status'] ?? '') === 'deferred' && ($resultC['stop_reason'] ?? '') === 'oauth_refresh_required'
    && (int) ($resultC['claimed'] ?? 0) === 1 && (int) ($resultC['errors'] ?? -1) === 0
    && ($resultC['abort_scheduler'] ?? true) === false && $rowC['status'] === 'waiting_budget'
    && (int) $rowC['attempts'] === 3 && (int) $rowC['consecutive_failures'] === 2
    && $rowC['diagnostic_id'] === null && $rowC['last_error_class'] === 'oauth_refresh_required'
    && $rowC['next_run_at'] === $nextRace,
    'oauth_race_not_refunded:' . json_encode([$resultC, $rowC]));

// D. Cross-account exception provenance is an invariant failure and does not
// refund the claimed attempt.
$reset();
$setToken($valid, 7200);
$scheduleOAuth($valid, 240);
[, $mismatchJob] = $seed($valid, 'pending', 5);
$clientD = new SalesAuditClient2387((int) $valid['id'], 'mismatch', null, (int) $expired['id']);
$resultD = (new SalesAuditRunService(static fn(int $id): object => $clientD))->processDue(1, microtime(true) + 20);
$rowD = $pdo->query('SELECT * FROM sync_sales_audit_jobs WHERE id=' . $mismatchJob)->fetch(PDO::FETCH_ASSOC);
$assert(($resultD['abort_scheduler'] ?? false) === true
    && ($resultD['stop_reason'] ?? '') === 'sales_audit_oauth_tenant_fence_failed'
    && $rowD['status'] === 'error' && (int) $rowD['attempts'] === 1,
    'oauth_tenant_mismatch_not_blocked:' . json_encode([$resultD, $rowD]));

// E. After the OAuth supervisor stage, a required refresh without an active
// operation is a control-plane invariant.
$reset();
$setToken($valid, 7200);
[, $missingJob] = $seed($valid, 'pending', 6);
$clientE = new SalesAuditClient2387((int) $valid['id'], 'oauth');
$resultE = (new SalesAuditRunService(static fn(int $id): object => $clientE))->processDue(1, microtime(true) + 20);
$rowE = $pdo->query('SELECT * FROM sync_sales_audit_jobs WHERE id=' . $missingJob)->fetch(PDO::FETCH_ASSOC);
$assert(($resultE['abort_scheduler'] ?? false) === true
    && ($resultE['stop_reason'] ?? '') === 'oauth_dependency_authority_missing'
    && $rowE['status'] === 'error' && (int) $rowE['attempts'] === 1,
    'missing_oauth_authority_not_blocked:' . json_encode([$resultE, $rowE]));

// F. A contradictory persisted dispatch fence cannot be called HTTP=0 and
// therefore cannot receive an attempt refund.
$reset();
$setToken($valid, 7200);
$scheduleOAuth($valid, 240);
[, $dispatchJob] = $seed($valid, 'pending', 7);
$clientF = new SalesAuditClient2387((int) $valid['id'], 'dispatch_then_oauth', $pdo);
$resultF = (new SalesAuditRunService(static fn(int $id): object => $clientF))->processDue(1, microtime(true) + 20);
$rowF = $pdo->query('SELECT * FROM sync_sales_audit_jobs WHERE id=' . $dispatchJob)->fetch(PDO::FETCH_ASSOC);
$assert(($resultF['abort_scheduler'] ?? false) === true
    && ($resultF['stop_reason'] ?? '') === 'sales_audit_dispatch_fence_failed'
    && $rowF['status'] === 'error' && (int) $rowF['attempts'] === 1,
    'contradictory_dispatch_not_blocked:' . json_encode([$resultF, $rowF]));

// G/H. Only the exact truncated 2.38.6 OAuth signature is repaired, exactly
// once. An unrelated historical error remains immutable.
$reset();
$setToken($valid, 30);
$repairNext = $scheduleOAuth($valid, 300);
[$repairRun, $repairJob] = $seed($valid, 'error', 8);
$legacyClass = 'App\\Services\\OAuthRefreshRequiredExcepti';
$pdo->prepare(
    "UPDATE sync_sales_audit_jobs
     SET attempts=3,consecutive_failures=2,diagnostic_id='legacy-oauth-diag',safe_error_message='legacy',
         last_error_class=?,remote_dispatch_state='NOT_DISPATCHED',last_http_status=NULL,lease_generation=4
     WHERE id=?"
)->execute([$legacyClass, $repairJob]);
$pdo->exec("UPDATE sync_sales_audit_runs SET status='error',diagnostic_id='legacy-oauth-diag',safe_error_message='legacy' WHERE id=" . $repairRun);
[$unrelatedRun, $unrelatedJob] = $seed($expired, 'error', 9);
$pdo->exec(
    "UPDATE sync_sales_audit_jobs SET attempts=7,consecutive_failures=4,last_error_class='unrelated_failure',
     diagnostic_id='unrelated-diag' WHERE id=" . $unrelatedJob
);
$resultG1 = (new SalesAuditRunService())->processDue(1, microtime(true) + 20);
$repairRow1 = $pdo->query('SELECT * FROM sync_sales_audit_jobs WHERE id=' . $repairJob)->fetch(PDO::FETCH_ASSOC);
$repairRunRow = $pdo->query('SELECT * FROM sync_sales_audit_runs WHERE id=' . $repairRun)->fetch(PDO::FETCH_ASSOC);
$unrelatedRow = $pdo->query('SELECT * FROM sync_sales_audit_jobs WHERE id=' . $unrelatedJob)->fetch(PDO::FETCH_ASSOC);
$resultG2 = (new SalesAuditRunService())->processDue(1, microtime(true) + 20);
$assert((int) ($resultG1['misclassified_oauth_repaired'] ?? 0) === 1
    && $repairRow1['status'] === 'waiting_budget' && $repairRow1['next_run_at'] === $repairNext
    && (int) $repairRow1['attempts'] === 2 && (int) $repairRow1['consecutive_failures'] === 1
    && $repairRow1['diagnostic_id'] === null && $repairRow1['safe_error_message'] === null
    && $repairRow1['last_error_class'] === 'oauth_refresh_required'
    && $repairRunRow['status'] === 'running' && $repairRunRow['diagnostic_id'] === null
    && (int) ($resultG2['misclassified_oauth_repaired'] ?? -1) === 0
    && $unrelatedRow['status'] === 'error' && (int) $unrelatedRow['attempts'] === 7
    && (int) $unrelatedRow['consecutive_failures'] === 4 && $unrelatedRow['diagnostic_id'] === 'unrelated-diag',
    'legacy_oauth_repair_not_exact_or_idempotent:' . json_encode([$resultG1, $repairRow1, $repairRunRow, $resultG2, $unrelatedRow]));

$events = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE source_kind='sales_audit'")->fetchColumn();
$assert($events === 0, 'sales_http_journal_should_be_zero_for_oauth_scenarios');

// I. Commercial Queue V4 preserves its FIFO claim and refunds the exact
// attempt when the head needs OAuth. Sales admission must not leak here.
$pdo->exec('DELETE FROM queue_v4_clean_attempts');
$pdo->exec('DELETE FROM queue_v4_clean_runs');
$pdo->exec('DELETE FROM queue_v4_clean_jobs');
$pdo->exec('DELETE FROM oauth_refresh_operations');
$pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE' WHERE control_key='primary'");
$setToken($valid, 30);
$scheduleOAuth($valid, 180);
$repository = new QueueV4CleanRepository($pdo);
$fifoJob = $repository->enqueue(
    (int) $valid['company_id'],
    (int) $valid['id'],
    'order_exact',
    '23870001',
    'sales-oauth-2387-worker-fifo',
    ['order_id' => '23870001'],
    3,
);
$workerResult = (new QueueV4CleanWorker(
    $pdo,
    $repository,
    null,
    null,
    static fn (array $job): never => throw new OAuthRefreshRequiredException((int) $job['meli_account_id']),
))->run('test', 1, 5);
$fifoRow = $pdo->query('SELECT state,attempt_count,last_error_class FROM queue_v4_clean_jobs WHERE id=' . $fifoJob)
    ->fetch(PDO::FETCH_ASSOC);
$fifoAttempt = $pdo->query('SELECT outcome,error_class FROM queue_v4_clean_attempts WHERE job_id=' . $fifoJob)
    ->fetch(PDO::FETCH_ASSOC);
$assert($workerResult === ['claimed' => 1, 'completed' => 0, 'deferred' => 1]
    && $fifoRow['state'] === 'waiting' && (int) $fifoRow['attempt_count'] === 0
    && $fifoRow['last_error_class'] === 'oauth_refresh_required'
    && $fifoAttempt['outcome'] === 'waiting' && $fifoAttempt['error_class'] === 'oauth_refresh_required',
    'commercial_fifo_oauth_regression:' . json_encode([$workerResult, $fifoRow, $fifoAttempt]));
$pdo->exec("UPDATE queue_v4_clean_control SET engine_state='STOPPED' WHERE control_key='primary'");

// J. The cycle-level physical budget remains exactly bounded by the requested
// five units; the sixth transport reservation fails before transport.
QueueV4CleanCycleBudget::start(5);
for ($slot = 0; $slot < 5; $slot++) {
    QueueV4CleanCycleBudget::claim();
}
$budgetBlocked = false;
try {
    QueueV4CleanCycleBudget::claim();
} catch (ApiBudgetExhaustedException) {
    $budgetBlocked = true;
}
$budget = QueueV4CleanCycleBudget::snapshot();
QueueV4CleanCycleBudget::clear();
$assert($budgetBlocked && $budget === ['limit' => 5, 'used' => 5, 'remaining' => 0],
    'shared_http_budget_5_not_enforced:' . json_encode($budget));

$reset();
echo 'Sales Audit OAuth admission 2.38.7 PASS checks=' . $checks
    . ' worker_fifo=pass http_budget_5=pass real_meli_http=0 business_writes=0 raw_storage_touched=false' . PHP_EOL;
