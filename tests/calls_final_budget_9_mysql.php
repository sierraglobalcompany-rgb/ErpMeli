<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/calls_transport_wire_fixture.php';

use App\Core\Crypto;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\ApiExecutionMetadataContext;
use App\Services\AppSettingsService;
use App\Services\AutomationCallBudgetService;
use App\Services\Cap2DomainsWire;
use App\Services\CapacityPolicyService;
use App\Services\CronDeadlineContext;
use App\Services\MeliApiClient;
use App\Services\SaleFinancialStateService;

function budget9_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

function budget9_seed_scope(PDO $pdo): void
{
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'Calls budget9',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'Calls budget9',99011,'conectado')");
    $pdo->prepare(
        'INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at)
         VALUES(9011,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))'
    )->execute([Crypto::encrypt('test-access'), Crypto::encrypt('test-refresh')]);
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED' WHERE control_key='primary'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");
    $pdo->exec("UPDATE app_settings SET setting_value='0' WHERE setting_key IN ('alerts.email.enabled','api.guard.jitter_min_ms','api.guard.jitter_max_ms')");
    $settings = new AppSettingsService();
    foreach ([
        'api.rhythm.pause_ms' => '0',
        'api.rhythm.burst_size' => '100',
        'api.rhythm.minimum_interval_ms' => '0',
        'api.rhythm.current_adaptive_limit' => '100',
        'api.rhythm.shared_429_backoff_seconds' => '1800',
        'api.rhythm.shared_429_jitter_seconds' => '0',
        'sales_financial.commercial_pipeline_enabled' => '1',
    ] as $key => $value) {
        $settings->set($key, $value, 'calls-final-budget9');
    }
    AppSettingsService::clearCache();
}

function budget9_reset_guards(PDO $pdo): void
{
    $pdo->exec('DELETE FROM api_remote_permits');
    $pdo->exec('DELETE FROM api_request_logs');
    $pdo->exec('DELETE FROM api_rhythm_penalties');
    $pdo->exec("UPDATE api_rhythm_states SET next_allowed_at=NULL,block_pause_until=NULL,calls_in_block=0 WHERE scope_key='global'");
    Cap2DomainsWire::$calls = [];
    Cap2DomainsWire::$responses = [];
    Cap2DomainsWire::$onWire = null;
    QueueV4CleanCycleBudget::clear();
    CronDeadlineContext::clear();
}

/** @return array<string,mixed> */
function budget9_order_payload(int $externalOrderId): array
{
    return [
        'id' => $externalOrderId,
        'date_created' => '2026-09-01T00:00:00.000Z',
        'date_closed' => '2026-09-01T00:01:00.000Z',
        'last_updated' => '2026-09-01T00:02:00.000Z',
        'status' => 'paid',
        'status_detail' => null,
        'total_amount' => 100,
        'paid_amount' => 100,
        'currency_id' => 'COP',
        'buyer' => ['id' => 990000 + $externalOrderId, 'nickname' => 'buyer-' . $externalOrderId],
        'shipping' => ['id' => null],
        'tags' => [],
        'order_items' => [[
            'item' => [
                'id' => 'ITEM-' . $externalOrderId,
                'title' => 'Budget item ' . $externalOrderId,
                'seller_sku' => 'SKU-' . $externalOrderId,
                'listing_type_id' => 'gold_special',
            ],
            'quantity' => 1,
            'unit_price' => 100,
            'full_unit_price' => 100,
            'sale_fee' => 0,
        ]],
        'payments' => [],
    ];
}

function budget9_seed_ready_order_job(PDO $pdo, int $externalOrderId): int
{
    $path = '/orders/' . $externalOrderId;
    Cap2DomainsWire::$responses[$path] = [200, budget9_order_payload($externalOrderId)];
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
         VALUES(9001,9011,'order_exact',?,?,'{}','ready','2000-01-01')"
    )->execute([
        (string) $externalOrderId,
        'budget9-worker-order-' . $externalOrderId . '-' . bin2hex(random_bytes(4)),
    ]);
    return (int) $pdo->lastInsertId();
}

function budget9_seed_financial_source(PDO $pdo, int $externalOrderId): int
{
    $pdo->prepare(
        "INSERT INTO meli_orders
         (meli_account_id,external_order_id,status,total_amount,paid_amount,currency_id,synced_at)
         VALUES (9011,?,'paid',100,100,'COP',UTC_TIMESTAMP())"
    )->execute([(string) $externalOrderId]);
    $state = (new SaleFinancialStateService())->projectSale(9001, 9011, 'O:' . $externalOrderId);
    $pdo->prepare(
        "INSERT INTO sale_financial_reconciliation_jobs
         (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at)
         VALUES (9001,9011,?,?,?,'pending','2000-01-01')"
    )->execute(['O:' . $externalOrderId, (string) $externalOrderId, $state['input_version']]);
    $sourceId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
         VALUES (9001,9011,'domain_exact',?,? ,?,'ready','2000-01-01')"
    )->execute([
        (string) $sourceId,
        'budget9-billing-source-' . $sourceId . '-' . bin2hex(random_bytes(4)),
        json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR),
    ]);
    return $sourceId;
}

function budget9_run_worker(PDO $pdo, int $budget): array
{
    CronDeadlineContext::start(45, 40, 8, 3);
    QueueV4CleanCycleBudget::start($budget, 'automatic', microtime(true) + 45);
    try {
        return (new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo)))->run(
            'test',
            $budget,
            40
        );
    } finally {
        QueueV4CleanCycleBudget::clear();
        CronDeadlineContext::clear();
    }
}

/** @return array{job:int,attempt:int,meta:array<string,mixed>} */
function budget9_queue_attempt(PDO $pdo, int $resourceId, string $owner = 'budget9'): array
{
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,lease_owner,lease_generation,lease_expires_at,available_at)
         VALUES(9001,9011,'order_exact',?,?,'{}','running',?,1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 MINUTE),'2000-01-01')"
    )->execute([(string) $resourceId, 'budget9-' . $resourceId . '-' . bin2hex(random_bytes(4)), $owner]);
    $job = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_attempts(job_id,run_id,company_id,meli_account_id,lease_owner,lease_generation)
         VALUES(?,9998,9001,9011,?,1)"
    )->execute([$job, $owner]);
    $attempt = (int) $pdo->lastInsertId();

    return [
        'job' => $job,
        'attempt' => $attempt,
        'meta' => [
            'source' => 'queue_v4_clean',
            'company_id' => 9001,
            'account_id' => 9011,
            'queue_v4_job_id' => $job,
            'queue_v4_attempt_id' => $attempt,
            'queue_v4_lease_owner' => $owner,
            'queue_v4_lease_generation' => 1,
        ],
    ];
}

function budget9_get_order(PDO $pdo, int $resourceId, int $status = 200, string $owner = 'budget9'): void
{
    $path = '/orders/' . $resourceId;
    Cap2DomainsWire::$responses[$path] = [$status, ['id' => $resourceId, 'message' => 'budget9']];
    $attempt = budget9_queue_attempt($pdo, $resourceId, $owner);
    ApiExecutionMetadataContext::run(
        $attempt['meta'],
        static fn (): array => (new MeliApiClient(9011))->get('/orders/' . $resourceId)
    );
}

if (($argv[1] ?? '') === 'second-429-process') {
    $harness = K1dSafeTestDatabase::connectExistingFromEnvironment();
    $pdo = $harness->pdo();
    Cap2DomainsWire::$calls = [];
    Cap2DomainsWire::$responses = [];
    Cap2DomainsWire::$onWire = null;
    QueueV4CleanCycleBudget::clear();
    QueueV4CleanCycleBudget::start(9, 'automatic', microtime(true) + 45);
    $error = null;
    try {
        budget9_get_order($pdo, 929999, 200, 'budget9-second-process');
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        $wire = count(Cap2DomainsWire::$calls);
        QueueV4CleanCycleBudget::clear();
    }
    budget9_assert($wire === 0, 'SECOND_REAL_PHP_PROCESS_WIRE_NOT_ZERO:' . ($error?->getMessage() ?? 'none'));
    echo "SECOND_REAL_PHP_PROCESS_WIRE=0\n";
    exit(0);
}

$root = rtrim(str_replace('\\', '/', (string) (getenv('CALLS_VERIFY_QA_ROOT') ?: 'D:/Codex/tmp/erp-meli/calls-20260906')), '/') . '/budget9';
if (!(str_starts_with($root, 'D:/Codex/') || str_starts_with($root, 'C:/codex/capacity-save-kiss/')) || in_array('..', explode('/', $root), true)) throw new RuntimeException('EXPLICIT_LOCAL_QA_ROOT_REQUIRED');
foreach ([
    'APP_ENV' => 'test',
    'ML_WRITE_ENABLED' => 'false',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '33079',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'DB_NAME' => 'erp_meli_k1d_test_calls_budget9_' . bin2hex(random_bytes(4)),
    'APP_KEY' => 'calls-disposable-test-only',
    'PRIVATE_STORAGE_PATH' => $root . '/private',
    'MELI_API_BASE' => 'https://calls-wire.invalid',
] as $key => $value) {
    putenv($key . '=' . $value);
}

define('ERP_INSTALLATION_ROOT', $root . '/install-' . bin2hex(random_bytes(4)));
mkdir(ERP_INSTALLATION_ROOT, 0777, true);

$harness = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $harness->pdo();
    (new App\Services\Migrator($pdo, __DIR__ . '/../database/migrations'))->run(301);
    budget9_seed_scope($pdo);

    // Case A: 20 real Queue V4 work records exist before the cycle starts.
    // The integrated worker/scheduler path may claim the tenth record, but a
    // physical call budget of 9 must stop its wire attempt before curl_exec.
    budget9_reset_guards($pdo);
    for ($i = 1; $i <= 20; $i++) {
        budget9_seed_ready_order_job($pdo, 910000 + $i);
    }
    $workRecords = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='order_exact'")->fetchColumn();
    $workerResult = budget9_run_worker($pdo, 9);
    $wireCalls = count(Cap2DomainsWire::$calls);
    $wirePaths = array_column(Cap2DomainsWire::$calls, 'path');
    $nPlusOneRejected = !in_array('/orders/910010', $wirePaths, true);
    $notCompleted = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='order_exact' AND state<>'completed'")->fetchColumn();
    budget9_assert($workRecords === 20, 'CASE_A_WORK_RECORDS_NOT_20:' . $workRecords);
    budget9_assert($wireCalls === 9, 'CASE_A_WIRE_CALLS_NOT_NINE:' . $wireCalls);
    budget9_assert($nPlusOneRejected, 'CASE_A_N_PLUS_ONE_NOT_REJECTED');
    budget9_assert($notCompleted === 11, 'CASE_A_REMAINING_WORK_NOT_11:' . $notCompleted);
    budget9_assert((int) ($workerResult['physical_http_calls'] ?? 0) === 9, 'CASE_A_WORKER_RECEIPT_NOT_NINE:' . json_encode($workerResult));
    budget9_assert((string) ($workerResult['stop_reason'] ?? '') === 'call_budget_exhausted', 'CASE_A_STOP_REASON_NOT_CALL_BUDGET:' . json_encode($workerResult));
    budget9_assert(
        (int) ($workerResult['call_budget']['used'] ?? -1) === 9
            && (int) ($workerResult['call_budget']['remaining'] ?? -1) === 0,
        'CASE_A_WORKER_BUDGET_SNAPSHOT_NOT_EXHAUSTED:' . json_encode($workerResult)
    );

    // Case B: a first 429 at the third physical call stops all further wires,
    // and a second PHP process over the same DB fails closed before transport.
    budget9_reset_guards($pdo);
    QueueV4CleanCycleBudget::start(9, 'automatic', microtime(true) + 45);
    $first429 = false;
    foreach ([1 => 200, 2 => 200, 3 => 429] as $offset => $status) {
        try {
            budget9_get_order($pdo, 920000 + $offset, $status, 'budget9-case-b');
        } catch (App\Services\ApiRhythmDeferredException $error) {
            $first429 = $status === 429;
        } catch (Throwable $error) {
            if (!$first429) {
                throw $error;
            }
        }
    }
    budget9_assert($first429, 'CASE_B_429_NOT_OBSERVED');
    $beforeAfter429 = count(Cap2DomainsWire::$calls);
    try {
        budget9_get_order($pdo, 920004, 200, 'budget9-case-b');
    } catch (Throwable) {
        // Expected: the stopped budget/rhythm guard blocks before wire.
    }
    budget9_assert(count(Cap2DomainsWire::$calls) === $beforeAfter429, 'CASE_B_CALL_AFTER_429_REACHED_WIRE');
    $snapshot = QueueV4CleanCycleBudget::snapshot();
    $totalWire429 = count(Cap2DomainsWire::$calls);
    budget9_assert($totalWire429 === 3, 'CASE_B_TOTAL_WIRE_NOT_THREE:' . $totalWire429);
    budget9_assert(($snapshot['stopped_reason'] ?? '') === 'remote_429_global_pause', 'CASE_B_STOP_REASON_NOT_429');
    QueueV4CleanCycleBudget::clear();

    $second = [];
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' second-429-process';
    exec($cmd, $second, $secondExit);
    budget9_assert($secondExit === 0 && in_array('SECOND_REAL_PHP_PROCESS_WIRE=0', $second, true), 'CASE_B_SECOND_PROCESS_NOT_BLOCKED:' . implode('|', $second));

    // Case C: old resource/job-like settings do not become call budgets.
    budget9_reset_guards($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group)
         VALUES(?,?,0,?)
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),setting_group=VALUES(setting_group)'
    );
    foreach ([
        'automation.max_jobs' => '9',
        'manual.max_jobs' => '9',
        'manual_processing.preview_max_jobs' => '9',
        'financial_recalc.orders_per_run' => '9',
        'financial_recalc.max_orders_per_job' => '9',
        'catalog.page_limit' => '9',
        'legacy.block_size' => '9',
    ] as $key => $value) {
        $stmt->execute([$key, $value, 'legacy']);
    }
    AppSettingsService::clearCache();
    $automationDefault = (new AutomationCallBudgetService())->resolve();
    $manualDefault = (new CapacityPolicyService($pdo))->snapshot('manual');
    budget9_assert($automationDefault['max_calls'] === 1, 'CASE_C_AUTOMATION_LEGACY_CHANGED_CALL_CURRENT');
    budget9_assert($manualDefault['current'] === 1, 'CASE_C_MANUAL_LEGACY_CHANGED_CALL_CURRENT');
    $before = (new CapacityPolicyService($pdo))->snapshot('automation');
    (new CapacityPolicyService($pdo))->save('automation', 9, 55, $before['revision'], static fn (): array => ['allowed' => true, 'message' => '']);
    AppSettingsService::clearCache();
    budget9_assert((new AutomationCallBudgetService())->resolve()['max_calls'] === 9, 'CASE_C_EXPLICIT_CALL_KEY_NOT_AUTHORITY');

    // Case D: 50 real financial Queue V4 sources must never be grouped into
    // one Billing request. The default Billing rhythm may stop the same
    // execution after the first physical call; that is correct. Capacity is
    // a maximum, not a promise to exhaust all 50 calls in one 45-second pass.
    budget9_reset_guards($pdo);
    $pdo->exec('DELETE FROM queue_v4_clean_attempts');
    $pdo->exec('DELETE FROM queue_v4_clean_jobs');
    $pdo->exec('DELETE FROM sale_financial_reconciliation_jobs');
    $orderIds = range(930001, 930050);
    foreach ($orderIds as $orderId) {
        budget9_seed_financial_source($pdo, $orderId);
    }
    Cap2DomainsWire::$responses['/billing/integration/group/ML/order/details'] = [200, []];
    $billingWorkerResult = budget9_run_worker($pdo, 9);
    $billingCalls = array_values(array_filter(
        Cap2DomainsWire::$calls,
        static fn (array $call): bool => $call['path'] === '/billing/integration/group/ML/order/details'
    ));
    $billingCount = count($billingCalls);
    $billingOrderIds = (string) ($billingCalls[0]['query']['order_ids'] ?? '');
    $billingQueryCount = $billingOrderIds !== '' ? count(explode(',', $billingOrderIds)) : 0;
    $billingWireIds = isset($billingCalls[0]['query']['order_ids'])
        ? array_map('intval', explode(',', (string) $billingCalls[0]['query']['order_ids']))
        : [];
    budget9_assert($billingCount >= 1, 'CASE_D_BILLING_REQUESTS_ZERO');
    budget9_assert($billingQueryCount === 1, 'CASE_D_BILLING_ORDER_IDS_NOT_SINGLE:' . $billingQueryCount);
    budget9_assert($billingWireIds === [930001], 'CASE_D_BILLING_FIRST_ID_NOT_FIFO_SINGLE:' . json_encode($billingWireIds));
    budget9_assert($billingCount === 1, 'CASE_D_BILLING_REQUESTS_NOT_ONE:' . $billingCount);
    budget9_assert((int) ($billingWorkerResult['physical_http_calls'] ?? 0) === 1, 'CASE_D_BILLING_PHYSICAL_NOT_ONE:' . json_encode($billingWorkerResult));

    echo "STATUS=PASS CALLS_FINAL_BUDGET_9 MYSQL=REAL REAL_MELI_HTTP=0\n";
    echo "BUDGET_9_WORK_RECORDS={$workRecords}\n";
    echo "BUDGET_9_NO_429_WIRE_CALLS={$wireCalls}\n";
    echo "BUDGET_9_WIRE_10_ATTEMPTED=NO\n";
    echo "BUDGET_9_N_PLUS_ONE_REJECTED=YES\n";
    echo "BUDGET_9_USED=9\n";
    echo "BUDGET_9_REMAINING=0\n";
    echo "BUDGET_9_429_ON_THIRD_TOTAL_WIRE={$totalWire429}\n";
    echo "BUDGET_9_CALLS_AFTER_429=0\n";
    echo "BUDGET_9_DURABLE_PAUSE=YES\n";
    echo "BUDGET_9_SECOND_PROCESS_WIRE=0\n";
    echo "LEGACY_9_AUTOMATION_CALL_CURRENT=1\n";
    echo "LEGACY_9_MANUAL_CALL_CURRENT=1\n";
    echo "LEGACY_JOB_TO_CALL_DERIVATIONS=0\n";
    echo "BILLING_50_AVAILABLE_SOURCES=50\n";
    echo "BILLING_ORDER_IDS_PER_REQUEST=1\n";
    echo "BILLING_DEFAULT_RHYTHM_STOPS_AFTER_FIRST_REQUEST=YES\n";
} finally {
    QueueV4CleanCycleBudget::clear();
    $harness->cleanup();
}
