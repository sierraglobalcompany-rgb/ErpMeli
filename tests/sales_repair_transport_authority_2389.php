<?php

declare(strict_types=1);

$dsn = (string) (getenv('QUEUE_V4_CLEAN_TEST_DSN') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_V4_CLEAN_TEST_DSN is required\n");
    exit(2);
}

$root = dirname(__DIR__);
$private = sys_get_temp_dir() . '/erp-sales-repair-fence-2389-' . bin2hex(random_bytes(5));
mkdir($private, 0700, true);
define('ERP_TEST_RUNTIME', true);
define('ERP_INSTALLATION_ROOT', $private);
define('ERP_SHARED_ROOT', $private);
define('ERP_RELEASE_ROOT', $root);
putenv('ERP_PRIVATE_PATH=' . $private);
putenv('APP_KEY=erp-sales-repair-fence-2389-local-only');
putenv('ML_WRITE_ENABLED=false');
putenv('CRON_V3_ENABLED=false');
putenv('CRON_V3_SHADOW_ENABLED=false');
$_ENV['APP_KEY'] = 'erp-sales-repair-fence-2389-local-only';
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
use App\Services\ApiExecutionMetadataContext;
use App\Services\AppSettingsService;
use App\Services\MeliApiClient;
use App\Services\MeliHttpTransportInterface;
use App\Services\OrderSyncService;
use App\Services\SalesAuditExactRepairService;

$pdo = new PDO(
    $dsn,
    (string) (getenv('QUEUE_V4_CLEAN_TEST_USER') ?: 'root'),
    (string) (getenv('QUEUE_V4_CLEAN_TEST_PASS') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
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
$assert((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 298, 'schema_298_required');
$enum = (string) $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_transport_events'
       AND COLUMN_NAME='source_kind'"
)->fetchColumn();
$assert(str_contains($enum, "'sales_repair'"), 'sales_repair_journal_enum_missing');
$pdo->exec(
    "UPDATE sync_sales_repair_jobs
     SET status='cancelled',lock_owner=NULL,lock_expires_at=NULL
     WHERE source_kind='exact' AND status NOT IN ('complete','partial','cancelled')"
);

$suffix = random_int(100000, 999999);
$pdo->prepare('INSERT INTO companies (name,nit,status) VALUES (?,?,1)')
    ->execute(['Repair fence 2389', '2389-' . $suffix]);
$companyId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO meli_accounts
     (company_id,account_name,meli_user_id,nickname,site_id,country_id,status)
     VALUES (?,?,?,?,"MCO","CO","conectado")'
)->execute([$companyId, 'Repair fence', 238900000 + $suffix, 'repair-' . $suffix]);
$accountId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO meli_tokens
     (meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,refresh_version)
     VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR),1)'
)->execute([$accountId, Crypto::encrypt('access-2389'), Crypto::encrypt('refresh-2389')]);

$settings = new AppSettingsService();
foreach ([
    'api.rhythm.profile' => 'maximum',
    'api.rhythm.target_http_per_minute' => '90',
    'api.rhythm.current_adaptive_limit' => '90',
    'api.rhythm.minimum_interval_ms' => '1',
    'api.rhythm.rolling_window_seconds' => '60',
    'api.rhythm.adaptive_enabled' => '0',
    'sales_audit.repair_max_attempts' => '3',
] as $key => $value) {
    $settings->set($key, $value, 'sales_repair_fence_2389_test');
}
AppSettingsService::clearCache();
$resetRemoteAuthorities = static function () use ($pdo): void {
    $pdo->exec('DELETE FROM api_rhythm_penalties');
    $pdo->exec('DELETE FROM api_rhythm_states');
    $pdo->exec('DELETE FROM api_budget_windows');
    $pdo->exec('DELETE FROM api_remote_permits');
    $pdo->exec('DELETE FROM api_request_pacing_state');
    $pdo->exec('DELETE FROM api_circuit_breakers');
    $pdo->exec('DELETE FROM api_manual_pauses');
};
$resetRemoteAuthorities();

final class SalesRepairFenceTransport2389 implements MeliHttpTransportInterface
{
    public int $physicalCalls = 0;
    public string $mode = 'ok';

    public function request(
        string $method,
        string $url,
        array $data,
        array $headers,
        bool $form,
        array $timeouts,
    ): array {
        if ($this->mode === 'pre_curl') {
            throw new RuntimeException('fixture_pre_curl');
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        QueueV4CleanDispatchFence::immediatelyBeforeCurl($method, $path);
        $this->physicalCalls++;
        if ($this->mode === 'physical_uncertain') {
            throw new RuntimeException('fixture_after_physical_start');
        }
        $status = match ($this->mode) {
            'rate_limit' => 429,
            'not_found' => 404,
            default => 200,
        };
        QueueV4CleanDispatchFence::responseKnown($status);
        if ($this->mode === 'known_then_fail') {
            throw new RuntimeException('fixture_after_known_response');
        }
        $externalId = basename($path);
        return [
            'status' => $status,
            'body' => $status === 200 ? [
                'id' => $externalId,
                'date_created' => '2026-08-01T10:00:00Z',
                'last_updated' => '2026-08-01T10:01:00Z',
                'status' => 'paid',
                'status_detail' => 'paid',
                'total_amount' => 100,
                'paid_amount' => 100,
                'currency_id' => 'COP',
                'buyer' => ['id' => 2389, 'nickname' => 'buyer2389'],
                'shipping' => ['id' => ((int) $externalId) + 500000],
                'order_items' => [[
                    'item' => ['id' => 'MCO' . $externalId, 'title' => 'Repair 2389'],
                    'quantity' => 1,
                    'unit_price' => 100,
                ]],
                'payments' => [],
                'tags' => [],
            ] : [
                'error' => $status === 429 ? 'too_many_requests' : 'not_found',
                'message' => $status === 429 ? 'rate limited' : 'order unavailable',
            ],
            'headers' => $status === 429 ? ['retry-after' => '120'] : [],
            'curl_error' => '',
            'duration_ms' => 1,
            'wire_bytes' => 20,
            'decoded_bytes' => 20,
        ];
    }
}

$transport = new SalesRepairFenceTransport2389();
$service = new SalesAuditExactRepairService(
    static fn(int $id): OrderSyncService => new OrderSyncService($id, new MeliApiClient($id, $transport)),
);

/** @return array{job_id:int,item_id:int} */
$seed = static function (
    string $externalId,
    ?int $storedCompanyId = null,
    string $sourceKind = 'exact',
) use ($pdo, $companyId, $accountId): array {
    static $period = 1;
    $month = $period++;
    $pdo->prepare(
        'INSERT INTO sync_sales_audit_runs
         (meli_account_id,company_id,period_year,period_month,mode,status,remote_coverage,
          coverage_validation_state,local_presence,temporal_quality,reconciliation_status,
          timezone_used,normalizer_version,local_from,local_to,utc_from,utc_to,
          remote_unique_total,checked_total,capture_started_at,capture_finished_at)
         VALUES (?,?,2026,?,"exact","complete","complete","valid","missing","correct","ready",
                 "America/Bogota","test","2026-01-01","2026-02-01","2026-01-01","2026-02-01",
                 1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
    )->execute([$accountId, $companyId, $month]);
    $runId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO sync_sales_repair_jobs
         (sync_sales_audit_run_id,source_kind,meli_account_id,company_id,period_year,period_month,status,next_run_at,total_items)
         VALUES (?,?,?, ?,2026,?,"pending",UTC_TIMESTAMP(),1)'
    )->execute([$runId, $sourceKind, $accountId, $storedCompanyId ?? $companyId, $month]);
    $jobId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO sync_sales_audit_run_orders
         (sync_sales_audit_run_id,meli_account_id,external_order_id,classification,checked_at)
         VALUES (?,?,?,"missing_remote",UTC_TIMESTAMP())'
    )->execute([$runId, $accountId, $externalId]);
    $runOrderId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO sync_sales_repair_job_items
         (sync_sales_repair_job_id,external_order_id,sync_sales_audit_run_order_id,action,status,next_run_at)
         VALUES (?,?,?,"fetch_missing","pending",UTC_TIMESTAMP())'
    )->execute([$jobId, $externalId, $runOrderId]);
    return ['job_id' => $jobId, 'item_id' => (int) $pdo->lastInsertId()];
};

$item = static function (int $id) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT status,attempts,next_run_at FROM sync_sales_repair_job_items WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
$journal = static function (int $jobId, int $itemId) use ($pdo): array {
    $stmt = $pdo->prepare(
        "SELECT source_kind,work_id,attempt_id,lease_generation,method,endpoint_key,dispatch_state,http_status
         FROM queue_v4_clean_transport_events
         WHERE company_id=? AND meli_account_id=? AND source_kind='sales_repair'
           AND work_id=? AND attempt_id=? ORDER BY id"
    );
    $stmt->execute([$GLOBALS['companyId'], $GLOBALS['accountId'], $jobId, $itemId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

try {
    $ok = $seed('238901');
    QueueV4CleanCycleBudget::start(1);
    $result = $service->processDue(1);
    $events = $journal($ok['job_id'], $ok['item_id']);
    $assert(($result['processed'] ?? 0) === 1 && $transport->physicalCalls === 1,
        'success_not_bounded_to_one_http:' . json_encode([$result, $transport->physicalCalls, $item($ok['item_id'])]));
    $budgetAfterSuccess = QueueV4CleanCycleBudget::snapshot();
    $assert($budgetAfterSuccess['used'] === 1, 'cycle_budget_was_claimed_twice:' . json_encode($budgetAfterSuccess));
    $assert(count($events) === 1 && $events[0]['dispatch_state'] === 'RESPONSE_KNOWN'
        && $events[0]['method'] === 'GET' && $events[0]['endpoint_key'] === 'order_exact'
        && (int) $events[0]['lease_generation'] === 1, 'success_journal_authority_invalid:' . json_encode($events));
    $assert((int) $pdo->query(
        "SELECT COUNT(*) FROM meli_orders o
         INNER JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=$companyId
         WHERE o.meli_account_id=$accountId AND o.external_order_id='238901'"
    )->fetchColumn() === 1, 'http_200_order_was_not_persisted_exactly_once');
    QueueV4CleanCycleBudget::clear();
    $resetRemoteAuthorities();

    $missing = $seed('238908');
    $transport->mode = 'not_found';
    QueueV4CleanCycleBudget::start(1);
    $missingResult = $service->processDue(1);
    $missingEvents = $journal($missing['job_id'], $missing['item_id']);
    $assert(($missingResult['status'] ?? '') === 'partial' && $item($missing['item_id'])['status'] === 'unavailable'
        && count($missingEvents) === 1 && $missingEvents[0]['dispatch_state'] === 'RESPONSE_KNOWN'
        && (int) $missingEvents[0]['http_status'] === 404, 'http_404_did_not_use_existing_unavailable_path');
    QueueV4CleanCycleBudget::clear();
    $resetRemoteAuthorities();

    $pre = $seed('238902');
    $transport->mode = 'pre_curl';
    $calls = $transport->physicalCalls;
    QueueV4CleanCycleBudget::start(1);
    $preResult = $service->processDue(1);
    $assert(($preResult['status'] ?? '') === 'waiting_budget' && $transport->physicalCalls === $calls
        && QueueV4CleanCycleBudget::snapshot()['used'] === 0 && $journal($pre['job_id'], $pre['item_id']) === [],
        'pre_curl_failure_crossed_physical_boundary');
    $assert($item($pre['item_id'])['attempts'] === 0, 'pre_curl_failure_consumed_attempt');
    QueueV4CleanCycleBudget::clear();
    $resetRemoteAuthorities();

    $uncertain = $seed('238903');
    $transport->mode = 'physical_uncertain';
    QueueV4CleanCycleBudget::start(1);
    $uncertainResult = $service->processDue(1);
    $uncertainEvents = $journal($uncertain['job_id'], $uncertain['item_id']);
    $assert(($uncertainResult['status'] ?? '') === 'deferred' && $item($uncertain['item_id'])['status'] === 'retry'
        && $item($uncertain['item_id'])['attempts'] === 1, 'physical_uncertain_not_durable_retry');
    $assert(count($uncertainEvents) === 1 && $uncertainEvents[0]['dispatch_state'] === 'PHYSICAL_STARTED'
        && QueueV4CleanCycleBudget::snapshot()['used'] === 1, 'physical_uncertain_journal_invalid');
    QueueV4CleanCycleBudget::clear();
    $resetRemoteAuthorities();

    $known = $seed('238904');
    $transport->mode = 'known_then_fail';
    QueueV4CleanCycleBudget::start(1);
    $knownResult = $service->processDue(1);
    $knownEvents = $journal($known['job_id'], $known['item_id']);
    $assert(($knownResult['status'] ?? '') === 'deferred' && $item($known['item_id'])['attempts'] === 1
        && count($knownEvents) === 1 && $knownEvents[0]['dispatch_state'] === 'RESPONSE_KNOWN'
        && (int) $knownEvents[0]['http_status'] === 200,
        'known_response_failure_lost_authority:' . json_encode([$knownResult, $item($known['item_id']), $knownEvents]));
    QueueV4CleanCycleBudget::clear();
    $resetRemoteAuthorities();

    $rate = $seed('238907');
    $transport->mode = 'rate_limit';
    QueueV4CleanCycleBudget::start(1);
    $rateResult = $service->processDue(1);
    $rateEvents = $journal($rate['job_id'], $rate['item_id']);
    $assert(($rateResult['status'] ?? '') === 'waiting_budget' && $item($rate['item_id'])['attempts'] === 0
        && count($rateEvents) === 1 && $rateEvents[0]['dispatch_state'] === 'RESPONSE_KNOWN'
        && (int) $rateEvents[0]['http_status'] === 429
        && QueueV4CleanCycleBudget::snapshot()['used'] === 1, 'rate_limit_was_not_a_known_nonfailure_deferral');
    QueueV4CleanCycleBudget::clear();
    $resetRemoteAuthorities();

    $replay = $seed('238901');
    $transport->mode = 'ok';
    $callsBeforeReplay = $transport->physicalCalls;
    QueueV4CleanCycleBudget::start(1);
    $replayResult = $service->processDue(1);
    $assert(($replayResult['processed'] ?? 0) === 1 && $item($replay['item_id'])['status'] === 'already_present'
        && $transport->physicalCalls === $callsBeforeReplay && QueueV4CleanCycleBudget::snapshot()['used'] === 0
        && $journal($replay['job_id'], $replay['item_id']) === [], 'idempotent_replay_used_remote_transport');
    QueueV4CleanCycleBudget::clear();
    $resetRemoteAuthorities();

    $tenant = $seed('238905', $companyId + 99999);
    $transport->mode = 'ok';
    QueueV4CleanCycleBudget::start(1);
    $assert(($service->processDue(1)['status'] ?? '') === 'empty'
        && $journal($tenant['job_id'], $tenant['item_id']) === [], 'cross_tenant_repair_was_claimed');
    QueueV4CleanCycleBudget::clear();

    $legacy = $seed('238909', null, 'legacy');
    QueueV4CleanCycleBudget::start(1);
    $assert(($service->processDue(1)['status'] ?? '') === 'empty'
        && $item($legacy['item_id'])['status'] === 'pending'
        && $journal($legacy['job_id'], $legacy['item_id']) === [], 'legacy_repair_was_consumed_by_queue_v4');
    QueueV4CleanCycleBudget::clear();

    $stale = $seed('238906');
    $pdo->prepare(
        'UPDATE sync_sales_repair_jobs SET status="running",lock_owner="owner",lease_generation=3,
         lock_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND) WHERE id=?'
    )->execute([$stale['job_id']]);
    $pdo->prepare('UPDATE sync_sales_repair_job_items SET status="running" WHERE id=?')->execute([$stale['item_id']]);
    QueueV4CleanCycleBudget::start(1);
    $staleBlocked = false;
    try {
        ApiExecutionMetadataContext::run([
            'source' => 'queue_v4_clean_sales_repair',
            'company_id' => $companyId,
            'account_id' => $accountId,
            'sales_repair_job_id' => $stale['job_id'],
            'sales_repair_item_id' => $stale['item_id'],
            'sales_repair_lease_owner' => 'owner',
            'sales_repair_lease_generation' => 2,
            'transport_request_id' => 'stale-2389',
        ], static fn() => QueueV4CleanDispatchFence::immediatelyBeforeCurl('GET', '/orders/238906'));
    } catch (RuntimeException $error) {
        $staleBlocked = str_contains($error->getMessage(), 'fence_lost');
    }
    $assert($staleBlocked && QueueV4CleanCycleBudget::snapshot()['used'] === 0
        && $journal($stale['job_id'], $stale['item_id']) === [], 'stale_lease_crossed_transport_fence');
    QueueV4CleanCycleBudget::clear();
} finally {
    QueueV4CleanCycleBudget::clear();
}

echo 'SALES_REPAIR_TRANSPORT_AUTHORITY_2389=PASS checks=' . $checks
    . ' schema=298 max_http=1 budget_claims=1 meli_writes=0' . PHP_EOL;
