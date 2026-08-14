<?php

declare(strict_types=1);

$dsn = (string) (getenv('QUEUE_V4_CLEAN_TEST_DSN') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_V4_CLEAN_TEST_DSN is required\n");
    exit(2);
}

$root = dirname(__DIR__);
$private = sys_get_temp_dir() . '/erp-simple-repair-2388-' . bin2hex(random_bytes(6));
mkdir($private, 0700, true);
define('ERP_TEST_RUNTIME', true);
define('ERP_INSTALLATION_ROOT', $private);
define('ERP_SHARED_ROOT', $private);
define('ERP_RELEASE_ROOT', $root);
putenv('ERP_PRIVATE_PATH=' . $private);
putenv('APP_KEY=erp-simple-repair-2388-local-only');
putenv('ML_WRITE_ENABLED=false');
putenv('CRON_V3_ENABLED=false');
putenv('CRON_V3_SHADOW_ENABLED=false');
$_ENV['APP_KEY'] = 'erp-simple-repair-2388-local-only';
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
use App\Services\AppSettingsService;
use App\Services\MeliApiClient;
use App\Services\MeliHttpTransportInterface;
use App\Services\OrderSyncService;
use App\Services\SalesAuditExactRepairService;

$pdo = new PDO(
    $dsn,
    (string) (getenv('QUEUE_V4_CLEAN_TEST_USER') ?: 'root'),
    (string) (getenv('QUEUE_V4_CLEAN_TEST_PASS') ?: ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
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

$assert((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 297,
    'schema_297_required');
$assert(glob($root . '/database/migrations/298_*.sql') === [], 'migration_298_must_not_exist');

$pdo->exec("INSERT INTO companies (name,nit,status) VALUES ('Repair 2388','2388',1)");
$companyId = (int) $pdo->lastInsertId();
$pdo->prepare(
    "INSERT INTO meli_accounts
     (company_id,account_name,meli_user_id,nickname,site_id,country_id,status)
     VALUES (?,'Repair exact',2388001,'repair2388','MCO','CO','conectado')"
)->execute([$companyId]);
$accountId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO meli_tokens
     (meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,refresh_version)
     VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR),1)'
)->execute([$accountId, Crypto::encrypt('access-2388'), Crypto::encrypt('refresh-2388')]);

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
    'sales_audit.repair_max_attempts' => '3',
] as $key => $value) {
    $settings->set($key, $value, 'simple_repair_2388_test');
}
AppSettingsService::clearCache();

final class SimpleExactRepairTransport2388 implements MeliHttpTransportInterface
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
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        QueueV4CleanDispatchFence::immediatelyBeforeCurl($method, $path);
        $this->physicalCalls++;
        $status = $this->mode === 'rate' ? 429 : 200;
        QueueV4CleanDispatchFence::responseKnown($status);
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
                'buyer' => ['id' => 2388, 'nickname' => 'buyer2388'],
                'shipping' => ['id' => ((int) $externalId) + 500000],
                'order_items' => [[
                    'item' => ['id' => 'MCO' . $externalId, 'title' => 'Repair 2388'],
                    'quantity' => 1,
                    'unit_price' => 100,
                ]],
                'payments' => [[
                    'id' => ((int) $externalId) + 700000,
                    'status' => 'approved',
                    'transaction_amount' => 100,
                    'date_approved' => '2026-08-01T10:02:00Z',
                ]],
                'tags' => [],
            ] : ['error' => 'too_many_requests', 'message' => 'rate limited'],
            'headers' => $status === 429 ? ['retry-after' => '120'] : [],
            'curl_error' => '',
            'duration_ms' => 1,
            'wire_bytes' => 20,
            'decoded_bytes' => 20,
        ];
    }
}

$transport = new SimpleExactRepairTransport2388();
$service = new SalesAuditExactRepairService(
    static fn(int $id): OrderSyncService => new OrderSyncService($id, new MeliApiClient($id, $transport))
);

/** @return array{run_id:int,job_id:int,item_ids:list<int>} */
$seedExact = static function (
    array $externalIds,
    int $jobCompanyId,
    string $sourceKind = 'exact',
) use ($pdo, $companyId, $accountId): array {
    static $month = 1;
    $currentMonth = $month++;
    $pdo->prepare(
        'INSERT INTO sync_sales_audit_runs
         (meli_account_id,company_id,period_year,period_month,mode,status,remote_coverage,
          coverage_validation_state,local_presence,temporal_quality,reconciliation_status,
          timezone_used,normalizer_version,local_from,local_to,utc_from,utc_to,
          remote_unique_total,checked_total,capture_started_at,capture_finished_at)
         VALUES (?,?,2026,?,"exact","complete","complete","valid","missing","correct","ready",
                 "America/Bogota","test","2026-01-01","2026-02-01","2026-01-01","2026-02-01",
                 ?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
    )->execute([$accountId, $companyId, $currentMonth, count($externalIds), count($externalIds)]);
    $runId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO sync_sales_repair_jobs
         (sync_sales_audit_run_id,source_kind,meli_account_id,company_id,period_year,period_month,
          status,next_run_at,total_items)
         VALUES (?,?,?,?,2026,?,"pending",UTC_TIMESTAMP(),?)'
    )->execute([$runId, $sourceKind, $accountId, $jobCompanyId, $currentMonth, count($externalIds)]);
    $jobId = (int) $pdo->lastInsertId();
    $itemIds = [];
    foreach ($externalIds as $externalId) {
        $pdo->prepare(
            'INSERT INTO sync_sales_audit_run_orders
             (sync_sales_audit_run_id,meli_account_id,external_order_id,classification,checked_at)
             VALUES (?,?,?,"missing_remote",UTC_TIMESTAMP())'
        )->execute([$runId, $accountId, (string) $externalId]);
        $runOrderId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO sync_sales_repair_job_items
             (sync_sales_repair_job_id,external_order_id,sync_sales_audit_run_order_id,action,status,next_run_at)
             VALUES (?,?,?,"fetch_missing","pending",UTC_TIMESTAMP())'
        )->execute([$jobId, (string) $externalId, $runOrderId]);
        $itemIds[] = (int) $pdo->lastInsertId();
    }
    return ['run_id' => $runId, 'job_id' => $jobId, 'item_ids' => $itemIds];
};

QueueV4CleanCycleBudget::start(5);
try {
    $empty = $service->processDue(1);
    $assert(($empty['status'] ?? '') === 'empty' && (int) ($empty['jobs'] ?? -1) === 0,
        'empty_repair_changed_scheduler_path');

    $exact = $seedExact(['238801', '238802'], $companyId);
    $legacy = $seedExact(['238899'], $companyId, 'legacy');
    $result = $service->processDue(1);
    $exactStates = $pdo->query(
        'SELECT status FROM sync_sales_repair_job_items WHERE sync_sales_repair_job_id=' . $exact['job_id'] . ' ORDER BY id'
    )->fetchAll(PDO::FETCH_COLUMN);
    $assert(($result['jobs'] ?? 0) === 1 && ($result['processed'] ?? 0) === 1
        && $transport->physicalCalls === 1 && $exactStates === ['complete', 'pending'],
        'queue_v4_exact_repair_not_bounded_to_one:' . json_encode([$result, $exactStates]));
    $assert((int) $pdo->query(
        "SELECT COUNT(*) FROM sync_sales_repair_job_items WHERE sync_sales_repair_job_id={$legacy['job_id']} AND status='pending'"
    )->fetchColumn() === 1, 'legacy_repair_was_consumed');
    $assert((int) $pdo->query(
        "SELECT COUNT(*) FROM meli_orders WHERE meli_account_id=$accountId AND external_order_id='238801'"
    )->fetchColumn() === 1, 'missing_order_not_persisted_by_queue_v4_entrypoint');

    $service->processDue(1);
    $replay = $seedExact(['238801'], $companyId);
    $callsBeforeReplay = $transport->physicalCalls;
    $replayResult = $service->processDue(1);
    $replayState = (string) $pdo->query(
        'SELECT status FROM sync_sales_repair_job_items WHERE id=' . $replay['item_ids'][0]
    )->fetchColumn();
    $assert(($replayResult['processed'] ?? 0) === 1 && $replayState === 'already_present'
        && $transport->physicalCalls === $callsBeforeReplay
        && (int) $pdo->query(
            "SELECT COUNT(*) FROM meli_orders WHERE meli_account_id=$accountId AND external_order_id='238801'"
        )->fetchColumn() === 1, 'existing_order_was_duplicated_or_refetched');

    $wrongTenant = $seedExact(['238850'], $companyId + 999);
    $assert(($service->processDue(1)['status'] ?? '') === 'empty'
        && (string) $pdo->query('SELECT status FROM sync_sales_repair_jobs WHERE id=' . $wrongTenant['job_id'])->fetchColumn() === 'pending',
        'tenant_mismatch_was_claimed');

    $pdo->prepare('UPDATE sync_sales_repair_jobs SET status="cancelled" WHERE id=?')
        ->execute([$wrongTenant['job_id']]);
    $oauth = $seedExact(['238860'], $companyId);
    $pdo->prepare('UPDATE meli_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE meli_account_id=?')
        ->execute([$accountId]);
    $callsBeforeOAuth = $transport->physicalCalls;
    $budgetBeforeOAuth = QueueV4CleanCycleBudget::snapshot()['used'];
    $oauthResult = $service->processDue(1);
    $oauthItem = $pdo->query(
        'SELECT status,attempts FROM sync_sales_repair_job_items WHERE id=' . $oauth['item_ids'][0]
    )->fetch(PDO::FETCH_ASSOC);
    $assert(($oauthResult['status'] ?? '') === 'waiting_oauth'
        && $oauthItem === ['status' => 'waiting_budget', 'attempts' => 0]
        && $transport->physicalCalls === $callsBeforeOAuth
        && QueueV4CleanCycleBudget::snapshot()['used'] === $budgetBeforeOAuth,
        'oauth_pretransport_failure_consumed_attempt_or_budget:' . json_encode([$oauthResult, $oauthItem]));

    $pdo->prepare('UPDATE sync_sales_repair_jobs SET status="cancelled" WHERE id=?')->execute([$oauth['job_id']]);
    $pdo->prepare('UPDATE meli_tokens SET expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR) WHERE meli_account_id=?')
        ->execute([$accountId]);
    $rate = $seedExact(['238870'], $companyId);
    $transport->mode = 'rate';
    $budgetBeforeRate = QueueV4CleanCycleBudget::snapshot()['used'];
    $rateResult = $service->processDue(1);
    $rateItem = $pdo->query(
        'SELECT status,attempts,next_run_at FROM sync_sales_repair_job_items WHERE id=' . $rate['item_ids'][0]
    )->fetch(PDO::FETCH_ASSOC);
    $assert(($rateResult['status'] ?? '') === 'waiting_budget'
        && $rateItem['status'] === 'waiting_budget' && (int) $rateItem['attempts'] === 0
        && QueueV4CleanCycleBudget::snapshot()['used'] === $budgetBeforeRate + 1
        && (strtotime((string) $rateItem['next_run_at'] . ' UTC') ?: 0) >= time() + 55,
        'rate_limit_did_not_reuse_budget_and_nonfailure_deferral:' . json_encode([$rateResult, $rateItem]));
} finally {
    QueueV4CleanCycleBudget::clear();
}

$schedulerSource = (string) file_get_contents($root . '/app/QueueV4Clean/QueueV4CleanScheduler.php');
$assert(str_contains($schedulerSource, 'SalesAuditExactRepairService())->processDue(1)')
    && str_contains($schedulerSource, '- $repairClaimed')
    && str_contains($schedulerSource, "'sales_repair' => \$salesRepair"),
    'scheduler_integration_or_claim_budget_contract_missing');

echo 'SIMPLE_EXACT_SALES_REPAIR_2388=PASS checks=' . $checks
    . ' max_exact_per_cycle=1 legacy_consumed=0 meli_writes=0 schema=297' . PHP_EOL;
