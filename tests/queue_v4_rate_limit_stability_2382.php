<?php

declare(strict_types=1);

$dsn = (string) (getenv('QUEUE_V4_CLEAN_TEST_DSN') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_V4_CLEAN_TEST_DSN is required\n");
    exit(2);
}

$temporaryRoot = sys_get_temp_dir() . '/erp-qv4-rate-2382-' . bin2hex(random_bytes(5));
mkdir($temporaryRoot, 0700, true);
define('ERP_INSTALLATION_ROOT', $temporaryRoot);
define('ERP_RELEASE_ROOT', dirname(__DIR__));
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanReviewService;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\ApiBudgetExhaustedException;
use App\Services\ApiRhythmDeferredException;
use App\Services\ApiRhythmPolicyService;
use App\Services\AppSettingsService;
use App\Services\CronDeadlineDeferredException;
use App\Services\MeliApiException;
use App\Services\MeliApiClient;
use App\Services\MeliHttpTransportInterface;

$pdo = new PDO(
    $dsn,
    (string) (getenv('QUEUE_V4_CLEAN_TEST_USER') ?: 'root'),
    (string) (getenv('QUEUE_V4_CLEAN_TEST_PASS') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
);
$pdo->exec("SET time_zone='+00:00'");
Database::setConnection($pdo);
putenv('ML_WRITE_ENABLED=false');

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$setting = new AppSettingsService();
foreach ([
    'api.rhythm.profile' => 'maximum',
    'api.rhythm.target_http_per_minute' => '40',
    'api.rhythm.current_adaptive_limit' => '40',
    'api.rhythm.minimum_interval_ms' => '1000',
    'api.rhythm.rolling_window_seconds' => '60',
    'api.rhythm.adaptive_enabled' => '0',
    'api.rhythm.orders_search_requests_per_15m' => '30',
    'api.rhythm.shared_429_backoff_seconds' => '300',
    'api.rhythm.shared_429_jitter_seconds' => '0',
] as $key => $value) $setting->set($key, $value, 'qv4_2382_test');
AppSettingsService::clearCache();

$pdo->exec('DELETE FROM api_remote_permits');
$pdo->exec('DELETE FROM api_rhythm_penalties');
$pdo->exec("DELETE FROM api_rhythm_states");
$pdo->exec('DELETE FROM queue_v4_clean_attempts');
$pdo->exec('DELETE FROM queue_v4_clean_jobs');
$pdo->exec('DELETE FROM queue_v4_clean_runs');
$pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED',scheduler_enabled=1 WHERE control_key='primary'");

$accounts = $pdo->query('SELECT company_id,id FROM meli_accounts ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_ASSOC);
$assert(count($accounts) === 3, 'three_account_fixture_missing');
$releaseInterval = static fn (): int => $pdo->exec("UPDATE api_rhythm_states SET next_allowed_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND)");

// A/B/C/M: un 429 en discovery bloquea localmente las otras cuentas y sales_audit.
$rhythm = new ApiRhythmPolicyService();
$first = $rhythm->reserve((int) $accounts[0]['id'], 'GET', '/orders/search', ['job_type' => 'fresh_orders_discovery']);
$assert($rhythm->dispatched($first), 'account1_not_dispatched');
$rhythm->finalizeKnownResult($first, 429, null);
$firstNext = $rhythm->rateLimitNextSafeAt($first, null);
$assert((strtotime($firstNext . ' UTC') ?: 0) >= time() + 299, 'conservative_backoff_missing');
$releaseInterval();
foreach ([1, 2] as $index) {
    $blocked = false;
    try {
        $rhythm->reserve((int) $accounts[$index]['id'], 'GET', '/orders/search', ['job_type' => 'fresh_orders_discovery']);
    } catch (ApiRhythmDeferredException $error) {
        $blocked = $error->nextSafeAt !== null && $error->reachedRemote === false;
    }
    $assert($blocked, 'shared_429_did_not_block_account_' . ($index + 1));
}
$salesAuditBlocked = false;
try {
    $rhythm->reserve((int) $accounts[1]['id'], 'GET', '/orders/search', ['job_type' => 'sales_audit']);
} catch (ApiRhythmDeferredException) {
    $salesAuditBlocked = true;
}
$assert($salesAuditBlocked, 'sales_audit_bypassed_shared_breaker');

// Retry-After superior a una hora se conserva como mínimo.
$assert(
    (strtotime($rhythm->rateLimitNextSafeAt($first, 7200) . ' UTC') ?: 0) >= time() + 7199,
    'retry_after_was_truncated'
);

// Reinicia autoridad para probar techo rodante compartido /orders/search.
$pdo->exec('DELETE FROM api_remote_permits');
$pdo->exec('DELETE FROM api_rhythm_penalties');
$pdo->exec('DELETE FROM api_rhythm_states');
for ($index = 0; $index < 30; $index++) {
    $permit = $rhythm->reserve((int) $accounts[$index % 3]['id'], 'GET', '/orders/search', ['job_type' => $index % 2 ? 'sales_audit' : 'fresh_orders_discovery']);
    $assert($rhythm->dispatched($permit), 'orders_search_dispatch_' . $index);
    $rhythm->finalizeKnownResult($permit, 200);
    $releaseInterval();
}
$blocked31 = false;
try {
    $rhythm->reserve((int) $accounts[0]['id'], 'GET', '/orders/search', ['job_type' => 'fresh_orders_discovery']);
} catch (ApiRhythmDeferredException $error) {
    $blocked31 = $error->blockingScope === 'rhythm_shared_orders_search_window';
}
$assert($blocked31, 'orders_search_request_31_reached_permit');
$pdo->exec("UPDATE api_remote_permits SET dispatched_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 901 SECOND) WHERE id=(SELECT oldest_id FROM (SELECT MIN(id) oldest_id FROM api_remote_permits) x)");
$releaseInterval();
$boundaryPermit = $rhythm->reserve((int) $accounts[0]['id'], 'GET', '/orders/search', ['job_type' => 'sales_audit']);
$assert($rhythm->dispatched($boundaryPermit), 'rolling_window_did_not_release_exact_oldest');
$rhythm->finalizeKnownResult($boundaryPermit, 200);

$repository = new QueueV4CleanRepository($pdo);
$resetJob = static function (string $identity) use ($pdo, $repository, $accounts): int {
    $pdo->exec('DELETE FROM queue_v4_clean_attempts');
    $pdo->exec('DELETE FROM queue_v4_clean_jobs');
    $pdo->exec('DELETE FROM queue_v4_clean_runs');
    return $repository->enqueue((int) $accounts[0]['company_id'], (int) $accounts[0]['id'], 'order_exact', '99001', $identity, ['order_id' => '99001'], 3);
};
$runDeferred = static function (Throwable $error, string $identity) use ($resetJob, $pdo, $repository, $assert): void {
    $jobId = $resetJob($identity);
    for ($index = 0; $index < 10; $index++) {
        $worker = new QueueV4CleanWorker($pdo, $repository, null, null, static function () use ($error): void { throw $error; });
        $worker->run('test', 1, 10);
        $pdo->prepare("UPDATE queue_v4_clean_jobs SET available_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id=?")->execute([$jobId]);
        $repository->releaseDueWaiting();
    }
    $row = $pdo->query("SELECT state,attempt_count FROM queue_v4_clean_jobs WHERE id={$jobId}")->fetch(PDO::FETCH_ASSOC);
    $assert((string) $row['state'] === 'ready' && (int) $row['attempt_count'] === 0, $identity . '_consumed_attempt');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state='review'")->fetchColumn() === 0, $identity . '_created_review');
};

$runDeferred(new ApiRhythmDeferredException('pace', gmdate('Y-m-d H:i:s', time() + 1), 'rhythm_test'), 'ten_rhythm');
$runDeferred(new ApiBudgetExhaustedException('budget', gmdate('Y-m-d H:i:s', time() + 1)), 'ten_budget');
$runDeferred(new CronDeadlineDeferredException(nextSafeAt: gmdate('Y-m-d H:i:s', time() + 1)), 'ten_deadline');
$runDeferred(new MeliApiException('known rate limit', 429), 'fallback_429');

// Functional failures keep consuming attempts; invalid payload remains dead.
$functionalId = $resetJob('functional');
for ($index = 0; $index < 3; $index++) {
    (new QueueV4CleanWorker($pdo, $repository, null, null, static function (): void { throw new RuntimeException('functional_failure'); }))->run('test', 1, 10);
    $pdo->prepare("UPDATE queue_v4_clean_jobs SET available_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id=?")->execute([$functionalId]);
    $repository->releaseDueWaiting();
}
$functional = $pdo->query("SELECT state,attempt_count FROM queue_v4_clean_jobs WHERE id={$functionalId}")->fetch(PDO::FETCH_ASSOC);
$assert((string) $functional['state'] === 'review' && (int) $functional['attempt_count'] === 3, 'functional_failure_policy_changed');
$payloadId = $resetJob('payload');
(new QueueV4CleanWorker($pdo, $repository, null, null, static function (): void { throw new RuntimeException('queue_v4_clean_payload_invalid'); }))->run('test', 1, 10);
$assert((string) $pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$payloadId}")->fetchColumn() === 'dead', 'invalid_payload_not_dead');

// Review recovery is exact and rejects ambiguous MeliApiException evidence.
$pdo->prepare("UPDATE queue_v4_clean_jobs SET state='review',attempt_count=1,last_error_class='meliapiexception' WHERE id=?")->execute([$payloadId]);
$summary = (new QueueV4CleanReviewService($pdo))->summary();
$assert((int) $summary['ambiguous_count'] >= 1, 'generic_meli_exception_not_ambiguous');

// O: recuperación histórica exacta y acotada. Sólo evidencia completa de
// aplazamiento técnico puede volver a waiting; un historial mixto permanece.
$recoverableId = $repository->enqueue(
    (int) $accounts[0]['company_id'],
    (int) $accounts[0]['id'],
    'order_exact',
    '99100',
    'historical_non_failure',
    ['order_id'=>'99100'],
    3
);
$pdo->prepare(
    "UPDATE queue_v4_clean_jobs
     SET state='review',attempt_count=2,last_error_class='apirhythmdeferredexception'
     WHERE id=?"
)->execute([$recoverableId]);
$historicalRunId = (int) $pdo->query('SELECT MAX(id) FROM queue_v4_clean_runs')->fetchColumn();
$assert($historicalRunId > 0, 'historical_recovery_run_fixture_missing');
$attemptInsert = $pdo->prepare(
    "INSERT INTO queue_v4_clean_attempts
     (job_id,company_id,meli_account_id,run_id,lease_owner,outcome,error_class,started_at,finished_at)
     VALUES (?,?,?,?,?,'review','apirhythmdeferredexception',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))"
);
$attemptInsert->execute([$recoverableId,(int)$accounts[0]['company_id'],(int)$accounts[0]['id'],$historicalRunId,'historic-a']);
$attemptInsert->execute([$recoverableId,(int)$accounts[0]['company_id'],(int)$accounts[0]['id'],$historicalRunId,'historic-b']);
$recovered = (new QueueV4CleanReviewService($pdo))->recoverExact(
    (int) $accounts[0]['company_id'],
    (int) $accounts[0]['id'],
    $recoverableId,
    gmdate('Y-m-d H:i:s', time() + 600)
);
$assert(($recovered['state'] ?? null) === 'waiting', 'exact_non_failure_review_not_recovered');
$recoveredRow = $pdo->query("SELECT state,attempt_count FROM queue_v4_clean_jobs WHERE id={$recoverableId}")->fetch(PDO::FETCH_ASSOC);
$assert((string)$recoveredRow['state'] === 'waiting' && (int)$recoveredRow['attempt_count'] === 0, 'review_recovery_postimage_invalid');

$mixedId = $repository->enqueue(
    (int) $accounts[1]['company_id'],
    (int) $accounts[1]['id'],
    'order_exact',
    '99101',
    'historical_mixed',
    ['order_id'=>'99101'],
    3
);
$pdo->prepare(
    "UPDATE queue_v4_clean_jobs
     SET state='review',attempt_count=2,last_error_class='apirhythmdeferredexception'
     WHERE id=?"
)->execute([$mixedId]);
$attemptInsert->execute([$mixedId,(int)$accounts[1]['company_id'],(int)$accounts[1]['id'],$historicalRunId,'mixed-a']);
$pdo->prepare(
    "INSERT INTO queue_v4_clean_attempts
     (job_id,company_id,meli_account_id,run_id,lease_owner,outcome,error_class,started_at,finished_at)
     VALUES (?,?,?,?,?,'review','runtimeexception',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))"
)->execute([$mixedId,(int)$accounts[1]['company_id'],(int)$accounts[1]['id'],$historicalRunId,'mixed-b']);
$mixedBlocked = false;
try {
    (new QueueV4CleanReviewService($pdo))->recoverExact(
        (int) $accounts[1]['company_id'],
        (int) $accounts[1]['id'],
        $mixedId,
        gmdate('Y-m-d H:i:s', time() + 600)
    );
} catch (RuntimeException $error) {
    $mixedBlocked = $error->getMessage() === 'queue_v4_review_recovery_attempt_mixed';
}
$assert($mixedBlocked, 'mixed_review_evidence_was_recovered');
$assert((string)$pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$mixedId}")->fetchColumn() === 'review', 'mixed_review_was_mutated');

// A/K: integración del cliente real con transporte falso. El primer 429
// cruza una vez la frontera simulada; la siguiente cuenta se aplaza por la
// autoridad compartida antes de llamar su transporte.
$pdo->exec('DELETE FROM api_remote_permits');
$pdo->exec('DELETE FROM api_rhythm_penalties');
$pdo->exec('DELETE FROM api_rhythm_states');
$setting->set('api.guard.enabled', '0', 'qv4_2382_test');
AppSettingsService::clearCache();
$firstTransport = new class implements MeliHttpTransportInterface {
    public int $calls = 0;
    public function request(string $method, string $url, array $data, array $headers, bool $form, array $timeouts): array
    {
        $this->calls++;
        return [
            'status'=>429,
            'body'=>['message'=>'rate limited','error'=>'too_many_requests'],
            'headers'=>['retry-after'=>'7200'],
            'curl_error'=>'',
            'duration_ms'=>2,
            'wire_bytes'=>16,
            'decoded_bytes'=>16,
        ];
    }
};
$blockedTransport = new class implements MeliHttpTransportInterface {
    public int $calls = 0;
    public function request(string $method, string $url, array $data, array $headers, bool $form, array $timeouts): array
    {
        $this->calls++;
        return ['status'=>200,'body'=>['results'=>[]],'headers'=>[],'curl_error'=>'','duration_ms'=>1,'wire_bytes'=>2,'decoded_bytes'=>2];
    }
};
$send = new ReflectionMethod(MeliApiClient::class, 'send');
$clientOne = new MeliApiClient((int) $accounts[0]['id'], $firstTransport);
$clientTwo = new MeliApiClient((int) $accounts[1]['id'], $blockedTransport);
$meta = [
    'source'=>'queue_v4_clean',
    'job_type'=>'fresh_orders_discovery',
    'company_id'=>(int)$accounts[0]['company_id'],
    'account_id'=>(int)$accounts[0]['id'],
];
$clientDeferred = null;
try {
    $send->invoke($clientOne, 'GET', 'https://api.mercadolibre.com/orders/search', [], [], false, false, $meta);
} catch (Throwable $error) {
    $clientDeferred = $error;
}
$assert($clientDeferred instanceof ApiRhythmDeferredException, 'queue_v4_client_429_not_deferred');
$assert($firstTransport->calls === 1, 'first_fake_transport_call_count_invalid');
$assert((strtotime($clientDeferred->nextSafeAt . ' UTC') ?: 0) >= time() + 7199, 'queue_v4_client_retry_after_not_preserved');
$meta['company_id'] = (int) $accounts[1]['company_id'];
$meta['account_id'] = (int) $accounts[1]['id'];
$secondDeferred = null;
try {
    $send->invoke($clientTwo, 'GET', 'https://api.mercadolibre.com/orders/search', [], [], false, false, $meta);
} catch (Throwable $error) {
    $secondDeferred = $error;
}
$assert($secondDeferred instanceof ApiRhythmDeferredException, 'shared_429_did_not_block_second_client');
$assert($blockedTransport->calls === 0, 'second_account_crossed_fake_transport');

fwrite(STDOUT, "PASS queue_v4_rate_limit_stability_2382 checks={$checks} fake_transport_calls=1 real_http_calls=0\n");
