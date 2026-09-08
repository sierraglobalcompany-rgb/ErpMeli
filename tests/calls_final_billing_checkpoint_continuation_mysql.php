<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/calls_transport_wire_fixture.php';

use App\Core\Crypto;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\AppSettingsService;
use App\Services\Cap2DomainsWire;
use App\Services\CronDeadlineContext;
use App\Services\SaleFinancialStateService;

function calls_final_billing_continue_assert(bool $condition, string $message, array $context = []): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $message . ':' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
    echo 'PASS:' . $message . PHP_EOL;
}

function calls_final_billing_continue_seed_scope(PDO $pdo): void
{
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'Calls billing continuation',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'Calls billing account',99011,'conectado')");
    $pdo->prepare(
        'INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at)
         VALUES(9011,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))'
    )->execute([Crypto::encrypt('test-access'), Crypto::encrypt('test-refresh')]);
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED' WHERE control_key='primary'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");

    $settings = new AppSettingsService();
    foreach ([
        'automation.max_api_calls_per_cycle' => '1',
        'automation.api_calls_ceiling' => '55',
        'api.rhythm.pause_ms' => '0',
        'api.rhythm.burst_size' => '100',
        'api.rhythm.minimum_interval_ms' => '0',
        'api.rhythm.current_adaptive_limit' => '100',
        'api.rhythm.billing_min_interval_seconds' => '2',
        'api.rhythm.shared_429_jitter_seconds' => '0',
        'sales_financial.commercial_pipeline_enabled' => '1',
    ] as $key => $value) {
        $settings->set($key, $value, 'calls-final-billing-continuation');
    }
    AppSettingsService::clearCache();
}

/** @return array{source_id:int,queue_id:int,pack_id:string,orders:list<string>} */
function calls_final_billing_continue_seed_pack(PDO $pdo): array
{
    $packId = '991' . random_int(100, 999);
    $externalOrders = [$packId . '1', $packId . '2'];
    $pdo->prepare(
        "INSERT INTO meli_packs(meli_account_id,external_pack_id,status,integrity_status,expected_orders_count,linked_orders_count,expected_orders_json,synced_at,verified_at)
         VALUES(9011,?,'paid','complete',2,2,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    )->execute([$packId, json_encode($externalOrders, JSON_THROW_ON_ERROR)]);

    $orderInsert = $pdo->prepare(
        "INSERT INTO meli_orders
            (meli_account_id,external_order_id,external_pack_id,status,total_amount,paid_amount,currency_id,synced_at)
         VALUES(9011,?,?, 'paid',100,100,'COP',UTC_TIMESTAMP())"
    );
    $itemInsert = $pdo->prepare(
        "INSERT INTO meli_order_items
            (meli_order_id,meli_account_id,external_item_id,title,seller_sku,quantity,unit_price,sale_fee)
         VALUES(?,9011,?,?,?,1,100,10)"
    );
    foreach ($externalOrders as $externalOrder) {
        $orderInsert->execute([$externalOrder, $packId]);
        $orderId = (int) $pdo->lastInsertId();
        $itemInsert->execute([$orderId, 'ITEM-' . $externalOrder, 'Order ' . $externalOrder, 'SKU-' . $externalOrder]);
    }

    $state = (new SaleFinancialStateService())->projectSale(9001, 9011, 'P:' . $packId);
    $pdo->prepare(
        "INSERT INTO sale_financial_reconciliation_jobs
         (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at)
         VALUES(9001,9011,?,?,?,'pending','2000-01-01')"
    )->execute(['P:' . $packId, $packId, $state['input_version']]);
    $sourceId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
         VALUES(9001,9011,'domain_exact',?,? ,?,'ready','2000-01-01')"
    )->execute([
        (string) $sourceId,
        'billing-continuation-' . $sourceId . '-' . bin2hex(random_bytes(4)),
        json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR),
    ]);

    return [
        'source_id' => $sourceId,
        'queue_id' => (int) $pdo->lastInsertId(),
        'pack_id' => $packId,
        'orders' => $externalOrders,
    ];
}

function calls_final_billing_continue_run_worker(PDO $pdo): array
{
    CronDeadlineContext::start(45, 40, 8, 3);
    QueueV4CleanCycleBudget::start(1, 'automatic', microtime(true) + 45);
    try {
        return (new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo)))->run('test', 1, 40);
    } finally {
        QueueV4CleanCycleBudget::clear();
        CronDeadlineContext::clear();
    }
}

function calls_final_billing_continue_run_worker_with_factory(PDO $pdo, callable $financialFactory): array
{
    CronDeadlineContext::start(45, 40, 8, 3);
    QueueV4CleanCycleBudget::start(1, 'automatic', microtime(true) + 45);
    try {
        return (new QueueV4CleanWorker(
            $pdo,
            new QueueV4CleanRepository($pdo),
            null,
            null,
            null,
            null,
            $financialFactory
        ))->run('test', 1, 40);
    } finally {
        QueueV4CleanCycleBudget::clear();
        CronDeadlineContext::clear();
    }
}

/** @return array{source_id:int,queue_id:int} */
function calls_final_billing_continue_seed_due_progress_source(PDO $pdo): array
{
    $externalOrderId = (string) random_int(880000, 889999);
    $pdo->prepare(
        "INSERT INTO sale_financial_reconciliation_jobs
         (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at)
         VALUES(9001,9011,?,?,?,'pending','2000-01-01')"
    )->execute(['O:' . $externalOrderId, $externalOrderId, str_repeat('a', 64)]);
    $sourceId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
         VALUES(9001,9011,'domain_exact',?,? ,?,'ready','2000-01-01')"
    )->execute([
        (string) $sourceId,
        'billing-progress-due-' . $sourceId . '-' . bin2hex(random_bytes(4)),
        json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR),
    ]);

    return ['source_id' => $sourceId, 'queue_id' => (int) $pdo->lastInsertId()];
}

function calls_final_billing_continue_configure_wire(): void
{
    Cap2DomainsWire::$calls = [];
    Cap2DomainsWire::$responses = ['/billing/integration/group/ML/order/details' => [200, []]];
    Cap2DomainsWire::$onWire = static function (): void {
        $last = Cap2DomainsWire::$calls[array_key_last(Cap2DomainsWire::$calls)] ?? [];
        $orderId = (string) (($last['query']['order_ids'] ?? '') ?: '0');
        Cap2DomainsWire::$status = 200;
        Cap2DomainsWire::$raw = json_encode([[
            'order_id' => $orderId,
            'detail_id' => 'fee-' . $orderId,
            'detail_type' => 'SALE_FEE',
            'description' => 'Cargo por venta ' . $orderId,
            'amount' => 10,
            'date_created' => '2026-09-08T00:00:00Z',
        ]], JSON_THROW_ON_ERROR);
    };
}

$root = 'D:/Codex/tmp/erp-meli/calls-20260906/billing-continuation';
foreach ([
    'APP_ENV' => 'test',
    'ML_WRITE_ENABLED' => 'false',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '33079',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'DB_NAME' => 'erp_meli_k1d_test_calls_billing_continuation_' . bin2hex(random_bytes(4)),
    'APP_KEY' => 'calls-disposable-test-only',
    'PRIVATE_STORAGE_PATH' => $root . '/private',
    'MELI_API_BASE' => 'https://calls-wire.invalid',
] as $key => $value) {
    putenv($key . '=' . $value);
}
if (!defined('ERP_INSTALLATION_ROOT')) {
    define('ERP_INSTALLATION_ROOT', $root . '/install-' . bin2hex(random_bytes(4)));
}
if (!is_dir(ERP_INSTALLATION_ROOT)) {
    mkdir(ERP_INSTALLATION_ROOT, 0777, true);
}

$harness = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $harness->pdo();
    (new App\Services\Migrator($pdo, __DIR__ . '/../database/migrations'))->run(301);
    calls_final_billing_continue_seed_scope($pdo);
    $fixture = calls_final_billing_continue_seed_pack($pdo);
    calls_final_billing_continue_configure_wire();

    $sourceBefore = $pdo->query('SELECT attempts,retry_until,remote_pending_since FROM sale_financial_reconciliation_jobs WHERE id=' . (int) $fixture['source_id'])->fetch(PDO::FETCH_ASSOC);
    $queueBefore = $pdo->query('SELECT attempt_count FROM queue_v4_clean_jobs WHERE id=' . (int) $fixture['queue_id'])->fetch(PDO::FETCH_ASSOC);

    $first = calls_final_billing_continue_run_worker($pdo);
    $sourceAfterFirst = $pdo->query('SELECT status,next_run_at,attempts,retry_until,remote_pending_since,lock_owner,lease_expires_at FROM sale_financial_reconciliation_jobs WHERE id=' . (int) $fixture['source_id'])->fetch(PDO::FETCH_ASSOC);
    $queueAfterFirst = $pdo->query('SELECT state,available_at,attempt_count,last_error_class FROM queue_v4_clean_jobs WHERE id=' . (int) $fixture['queue_id'])->fetch(PDO::FETCH_ASSOC);
    $checkpointCount = (int) $pdo->query("SELECT COUNT(*) FROM sale_financial_evidence WHERE company_id=9001 AND meli_account_id=9011 AND sale_key='P:{$fixture['pack_id']}' AND evidence_type='billing_capture' AND evidence_json LIKE '%\"format\":\"billing_order_v2\"%'")->fetchColumn();
    $publicationCount = (int) $pdo->query("SELECT COUNT(*) FROM meli_sale_financial_history h JOIN meli_sale_financials f ON f.id=h.meli_sale_financial_id WHERE f.company_id=9001 AND f.meli_account_id=9011 AND f.sale_key='P:{$fixture['pack_id']}' AND h.source='billing_official'")->fetchColumn();
    $firstOrderId = (string) (Cap2DomainsWire::$calls[0]['query']['order_ids'] ?? '');

    calls_final_billing_continue_assert(count(Cap2DomainsWire::$calls) === 1, 'first_window_one_physical_get', Cap2DomainsWire::$calls);
    calls_final_billing_continue_assert($firstOrderId === $fixture['orders'][0] && !str_contains($firstOrderId, ','), 'first_window_single_fifo_order_id', ['order_id' => $firstOrderId]);
    calls_final_billing_continue_assert($checkpointCount === 1, 'first_window_one_checkpoint');
    calls_final_billing_continue_assert($publicationCount === 0, 'first_window_no_final_publication');
    calls_final_billing_continue_assert((string) ($sourceAfterFirst['status'] ?? '') === 'retry', 'source_waits_after_checkpoint_progress', $sourceAfterFirst ?: []);
    calls_final_billing_continue_assert((string) ($queueAfterFirst['state'] ?? '') === 'waiting', 'queue_waits_after_checkpoint_progress', $queueAfterFirst ?: []);
    calls_final_billing_continue_assert((string) ($queueAfterFirst['last_error_class'] ?? '') === 'domain_source_waiting:financial_reconciliation:billing_checkpoint_progress', 'queue_preserves_checkpoint_progress_classification', $queueAfterFirst ?: []);
    calls_final_billing_continue_assert((int) ($sourceAfterFirst['attempts'] ?? -1) === (int) ($sourceBefore['attempts'] ?? -2), 'source_attempt_penalty_refunded_after_progress', ['before' => $sourceBefore, 'after' => $sourceAfterFirst]);
    calls_final_billing_continue_assert((int) ($queueAfterFirst['attempt_count'] ?? -1) === (int) ($queueBefore['attempt_count'] ?? -2), 'queue_attempt_penalty_refunded_after_progress', ['before' => $queueBefore, 'after' => $queueAfterFirst]);
    calls_final_billing_continue_assert(($sourceAfterFirst['retry_until'] ?? null) === ($sourceBefore['retry_until'] ?? null), 'retry_until_not_started_by_progress', ['before' => $sourceBefore, 'after' => $sourceAfterFirst]);
    calls_final_billing_continue_assert(($sourceAfterFirst['remote_pending_since'] ?? null) === ($sourceBefore['remote_pending_since'] ?? null), 'remote_pending_not_started_by_progress', ['before' => $sourceBefore, 'after' => $sourceAfterFirst]);
    $delaySeconds = strtotime((string) $sourceAfterFirst['next_run_at'] . ' UTC') - time();
    calls_final_billing_continue_assert($delaySeconds >= 0 && $delaySeconds <= 8, 'source_continuation_uses_billing_interval_not_error_backoff', ['delay_seconds' => $delaySeconds, 'source' => $sourceAfterFirst, 'worker' => $first]);
    $queueDelaySeconds = strtotime((string) $queueAfterFirst['available_at'] . ' UTC') - time();
    calls_final_billing_continue_assert($queueDelaySeconds >= 0 && $queueDelaySeconds <= 8, 'queue_continuation_uses_billing_interval_not_900s_fallback', ['delay_seconds' => $queueDelaySeconds, 'queue' => $queueAfterFirst, 'worker' => $first]);

    $beforeEarlyWire = count(Cap2DomainsWire::$calls);
    $early = calls_final_billing_continue_run_worker($pdo);
    calls_final_billing_continue_assert(count(Cap2DomainsWire::$calls) === $beforeEarlyWire, 'before_safe_time_zero_new_http', ['early' => $early, 'source' => $sourceAfterFirst, 'queue' => $queueAfterFirst]);

    $dueAt = max(
        strtotime((string) $sourceAfterFirst['next_run_at'] . ' UTC') ?: time(),
        strtotime((string) $queueAfterFirst['available_at'] . ' UTC') ?: time()
    );
    while (time() <= $dueAt + 1) {
        usleep(100000);
    }

    $second = calls_final_billing_continue_run_worker($pdo);
    $wireOrderIds = array_map(static fn (array $call): string => (string) ($call['query']['order_ids'] ?? ''), Cap2DomainsWire::$calls);
    $checkpointCount = (int) $pdo->query("SELECT COUNT(*) FROM sale_financial_evidence WHERE company_id=9001 AND meli_account_id=9011 AND sale_key='P:{$fixture['pack_id']}' AND evidence_type='billing_capture' AND evidence_json LIKE '%\"format\":\"billing_order_v2\"%'")->fetchColumn();
    $publicationCount = (int) $pdo->query("SELECT COUNT(*) FROM meli_sale_financial_history h JOIN meli_sale_financials f ON f.id=h.meli_sale_financial_id WHERE f.company_id=9001 AND f.meli_account_id=9011 AND f.sale_key='P:{$fixture['pack_id']}' AND h.source='billing_official'")->fetchColumn();

    calls_final_billing_continue_assert($wireOrderIds === $fixture['orders'], 'second_window_processes_next_order_once', ['wire_order_ids' => $wireOrderIds, 'expected' => $fixture['orders'], 'second' => $second]);
    calls_final_billing_continue_assert($checkpointCount === 2, 'two_order_checkpoints_persisted');
    calls_final_billing_continue_assert($publicationCount === 1, 'final_publication_once_after_last_checkpoint');

    $beforeReplayWire = count(Cap2DomainsWire::$calls);
    $replay = calls_final_billing_continue_run_worker($pdo);
    $publicationAfterReplay = (int) $pdo->query("SELECT COUNT(*) FROM meli_sale_financial_history h JOIN meli_sale_financials f ON f.id=h.meli_sale_financial_id WHERE f.company_id=9001 AND f.meli_account_id=9011 AND f.sale_key='P:{$fixture['pack_id']}' AND h.source='billing_official'")->fetchColumn();
    calls_final_billing_continue_assert(count(Cap2DomainsWire::$calls) === $beforeReplayWire, 'replay_zero_new_http', ['replay' => $replay]);
    calls_final_billing_continue_assert($publicationAfterReplay === 1, 'replay_zero_new_publication');

    $dueProgress = calls_final_billing_continue_seed_due_progress_source($pdo);
    $factory = static function () use ($pdo, $dueProgress): object {
        return new class($pdo, $dueProgress['source_id']) {
            public function __construct(private PDO $pdo, private int $sourceId)
            {
            }

            public function processDomainExactBatch(array $sourceIds, int $companyId, int $accountId, bool $allowSuccessor = true): array
            {
                $this->pdo->prepare(
                    "UPDATE sale_financial_reconciliation_jobs
                     SET status='retry',next_run_at=UTC_TIMESTAMP(3),lock_owner=NULL,lease_expires_at=NULL,heartbeat_at=NULL
                     WHERE id=? AND company_id=? AND meli_account_id=?"
                )->execute([$this->sourceId, $companyId, $accountId]);

                return [
                    'summary' => ['processed' => 1, 'completed' => 0, 'errors' => 0, 'deferred' => 1, 'stop_reason' => 'billing_checkpoint_progress'],
                    'outcomes' => [
                        $this->sourceId => [
                            'state' => 'waiting',
                            'classification' => 'domain_source_waiting:financial_reconciliation:billing_checkpoint_progress',
                            'next_safe_at' => gmdate('Y-m-d H:i:s', time() - 1),
                        ],
                    ],
                ];
            }
        };
    };
    $dueResult = calls_final_billing_continue_run_worker_with_factory($pdo, $factory);
    $dueQueue = $pdo->query('SELECT state,available_at,last_error_class FROM queue_v4_clean_jobs WHERE id=' . (int) $dueProgress['queue_id'])->fetch(PDO::FETCH_ASSOC);
    $dueQueueDelaySeconds = strtotime((string) ($dueQueue['available_at'] ?? '') . ' UTC') - time();
    calls_final_billing_continue_assert((string) ($dueQueue['last_error_class'] ?? '') === 'domain_source_waiting:financial_reconciliation:billing_checkpoint_progress', 'due_progress_preserves_specific_worker_classification', $dueQueue ?: []);
    calls_final_billing_continue_assert($dueQueueDelaySeconds >= 0 && $dueQueueDelaySeconds <= 8, 'due_progress_avoids_generic_900s_worker_fallback', ['delay_seconds' => $dueQueueDelaySeconds, 'queue' => $dueQueue, 'worker' => $dueResult]);

    echo "STATUS=PASS CALLS_FINAL_BILLING_CHECKPOINT_CONTINUATION MYSQL=REAL REAL_MELI_HTTP=0\n";
} finally {
    Cap2DomainsWire::$onWire = null;
    QueueV4CleanCycleBudget::clear();
    CronDeadlineContext::clear();
    $harness->cleanup();
}
