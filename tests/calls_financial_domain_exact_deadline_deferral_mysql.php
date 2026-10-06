<?php
declare(strict_types=1);

namespace App\Services {
    // Synthetic clock moves the deadline boundary without touching production data.
    function time(): int { return \time() + (int) CallsWireOptions::$clockOffset; }
}

namespace App\QueueV4Clean {
    function time(): int { return \App\Services\time(); }
    function microtime(bool $float = false): float|string { return \App\Services\microtime($float); }
}

namespace {
require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/calls_transport_wire_fixture.php';

use App\Core\Crypto;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\AppSettingsService;
use App\Services\Cap2DomainsWire;
use App\Services\CallsWireOptions;
use App\Services\CronDeadlineContext;
use App\Services\MeliApiClient;
use App\Services\SaleFinancialService;
use App\Services\SaleFinancialStateService;

function financial_deadline_assert(bool $ok, string $name, array $context = []): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL:' . $name . ':' . json_encode($context, JSON_THROW_ON_ERROR));
    }
    echo 'PASS:' . $name . PHP_EOL;
}

function financial_deadline_seed_scope(PDO $pdo): void
{
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'Deadline financial fixture',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'Deadline billing account',99011,'conectado')");
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
        'api.rhythm.billing_min_interval_seconds' => '1',
        'api.rhythm.shared_429_jitter_seconds' => '0',
        'sales_financial.commercial_pipeline_enabled' => '1',
    ] as $key => $value) {
        $settings->set($key, $value, 'calls-financial-deadline');
    }
    AppSettingsService::clearCache();
}

/** @return array{source_id:int,queue_id:int,pack_id:string,orders:list<string>} */
function financial_deadline_seed_pack(PDO $pdo, int $orderCount = 2): array
{
    $packId = '992' . random_int(100, 999);
    $externalOrders = [];
    for ($i = 1; $i <= $orderCount; $i++) {
        $externalOrders[] = $packId . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
    }

    $pdo->prepare(
        "INSERT INTO meli_packs(meli_account_id,external_pack_id,status,integrity_status,expected_orders_count,linked_orders_count,expected_orders_json,synced_at,verified_at)
         VALUES(9011,?,'paid','complete',?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    )->execute([$packId, $orderCount, $orderCount, json_encode($externalOrders, JSON_THROW_ON_ERROR)]);

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
         (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at,attempts)
         VALUES(9001,9011,?,?,?,'pending','2000-01-01',3)"
    )->execute(['P:' . $packId, $packId, $state['input_version']]);
    $sourceId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
         VALUES(9001,9011,'domain_exact',?,?,?,'ready','2000-01-01')"
    )->execute([
        (string) $sourceId,
        'deadline-financial-' . $sourceId . '-' . bin2hex(random_bytes(4)),
        json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR),
    ]);

    return [
        'source_id' => $sourceId,
        'queue_id' => (int) $pdo->lastInsertId(),
        'pack_id' => $packId,
        'orders' => $externalOrders,
    ];
}

function financial_deadline_configure_wire(): void
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

/** @return array<string,mixed> */
function financial_deadline_source(PDO $pdo, int $sourceId): array
{
    $q = $pdo->prepare(
        'SELECT * FROM sale_financial_reconciliation_jobs
         WHERE id=? AND company_id=9001 AND meli_account_id=9011'
    );
    $q->execute([$sourceId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

/** @return array<string,mixed> */
function financial_deadline_pointer(PDO $pdo, int $queueId): array
{
    $q = $pdo->prepare(
        'SELECT id,state,available_at,attempt_count,last_error_class,lease_generation
         FROM queue_v4_clean_jobs
         WHERE id=? AND company_id=9001 AND meli_account_id=9011'
    );
    $q->execute([$queueId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

/** @return list<array<string,mixed>> */
function financial_deadline_attempts(PDO $pdo, int $queueId): array
{
    $q = $pdo->prepare(
        'SELECT id,dispatch_state,physical_http_calls,http_status,physical_started_at,response_known_at
         FROM queue_v4_clean_attempts
         WHERE job_id=? AND company_id=9001 AND meli_account_id=9011
         ORDER BY id'
    );
    $q->execute([$queueId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function financial_deadline_journal(PDO $pdo, int $queueId): array
{
    $q = $pdo->prepare(
        'SELECT id,dispatch_state,http_status
         FROM queue_v4_clean_transport_events
         WHERE source_kind="queue" AND work_id=? AND company_id=9001 AND meli_account_id=9011
         ORDER BY id'
    );
    $q->execute([$queueId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function financial_deadline_checkpoints(PDO $pdo, int $sourceId): array
{
    $source = financial_deadline_source($pdo, $sourceId);
    $q = $pdo->prepare(
        'SELECT id,source_id,evidence_status,evidence_json,captured_at
         FROM sale_financial_evidence
         WHERE company_id=9001 AND meli_account_id=9011 AND sale_key=? AND input_version=?
           AND evidence_type="billing_capture"
         ORDER BY id'
    );
    $q->execute([(string) $source['sale_key'], (string) $source['input_version']]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function financial_deadline_worker(PDO $pdo, ?callable $financialFactory = null): array
{
    CallsWireOptions::$clockOffset = 0.0;
    CronDeadlineContext::start(45, 40, 8, 3);
    QueueV4CleanCycleBudget::start(1, 'automatic', \App\Services\microtime(true) + 45);
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

function financial_deadline_deadline_factory(PDO $pdo, array $fixture, bool $replaceOwner = false): callable
{
    return static function () use ($pdo, $fixture, $replaceOwner): SaleFinancialService {
        return new SaleFinancialService(
            static function (int $accountId) use ($pdo, $fixture, $replaceOwner): MeliApiClient {
                $claimed = financial_deadline_source($pdo, (int) $fixture['source_id']);
                financial_deadline_assert(
                    ($claimed['status'] ?? '') === 'running'
                    && (int) ($claimed['attempts'] ?? -1) === 4
                    && trim((string) ($claimed['lock_owner'] ?? '')) !== ''
                    && trim((string) ($claimed['lease_expires_at'] ?? '')) !== ''
                    && trim((string) ($claimed['heartbeat_at'] ?? '')) !== '',
                    'source_claimed_before_deadline',
                    ['source' => $claimed]
                );

                if ($replaceOwner) {
                    $pdo->prepare(
                        "UPDATE sale_financial_reconciliation_jobs
                         SET lock_owner='replacement-owner',lease_generation=lease_generation+1,attempts=attempts+1
                         WHERE id=? AND company_id=9001 AND meli_account_id=9011 AND status='running'"
                    )->execute([(int) $fixture['source_id']]);
                }

                CallsWireOptions::$clockOffset += 50.0;
                return new MeliApiClient($accountId);
            }
        );
    };
}

$options = getopt('', ['case:']);
$cases = ['healthy', 'deadline', 'fence_loss', 'checkpoint_preservation'];

if (!isset($options['case'])) {
    $exit = 0;
    foreach ($cases as $childCase) {
        $child = proc_open(
            [PHP_BINARY, __FILE__, '--case=' . $childCase],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($child)) {
            $exit = 1;
            continue;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        echo $stdout;
        fwrite(STDERR, $stderr);
        if (proc_close($child) !== 0) {
            $exit = 1;
        }
    }
    exit($exit);
}

$case = (string) $options['case'];
financial_deadline_assert(in_array($case, $cases, true), 'known_case');

$root = (string) getenv('CALLS_QA_STORAGE_ROOT');
financial_deadline_assert($root !== '' && is_dir($root), 'explicit_owned_test_root');

putenv('DB_NAME=erp_meli_k1d_test_findeadline_' . bin2hex(random_bytes(5)));
putenv('APP_KEY=synthetic-financial-deadline-only');
putenv('MELI_API_BASE=https://no-network.invalid');
putenv('PRIVATE_STORAGE_PATH=' . $root . '/private');

define('ERP_INSTALLATION_ROOT', $root . '/install-' . $case . '-' . bin2hex(random_bytes(3)));
mkdir(ERP_INSTALLATION_ROOT, 0770, true);

$harness = K1dSafeTestDatabase::createFromEnvironment();

try {
    $pdo = $harness->pdo();
    (new App\Services\Migrator($pdo, __DIR__ . '/../database/migrations'))->run(302);
    financial_deadline_seed_scope($pdo);
    $f = financial_deadline_seed_pack($pdo, 2);
    financial_deadline_configure_wire();

    $before = financial_deadline_source($pdo, $f['source_id']);

    if ($case === 'healthy') {
        financial_deadline_worker($pdo);
        $after = financial_deadline_source($pdo, $f['source_id']);
        $pointer = financial_deadline_pointer($pdo, $f['queue_id']);
        $attempts = financial_deadline_attempts($pdo, $f['queue_id']);
        $journal = financial_deadline_journal($pdo, $f['queue_id']);

        financial_deadline_assert(count(Cap2DomainsWire::$calls) === 1, 'healthy_one_billing_call');
        financial_deadline_assert((string) Cap2DomainsWire::$calls[0]['query']['order_ids'] === $f['orders'][0], 'healthy_first_fifo_order');
        financial_deadline_assert(($after['status'] ?? '') === 'retry', 'healthy_source_retry');
        financial_deadline_assert($after['lock_owner'] === null && $after['lease_expires_at'] === null && $after['heartbeat_at'] === null, 'healthy_source_lease_released');
        financial_deadline_assert((int) $after['attempts'] === (int) $before['attempts'], 'healthy_source_attempt_refunded');
        financial_deadline_assert(($pointer['state'] ?? '') === 'waiting' && (int) ($pointer['attempt_count'] ?? -1) === 0, 'healthy_pointer_waiting');
        financial_deadline_assert(count($attempts) === 1 && $attempts[0]['dispatch_state'] === 'RESPONSE_KNOWN' && (int) $attempts[0]['physical_http_calls'] === 1 && (int) $attempts[0]['http_status'] === 200, 'healthy_response_known');
        financial_deadline_assert(count($journal) === 1 && $journal[0]['dispatch_state'] === 'RESPONSE_KNOWN' && (int) $journal[0]['http_status'] === 200, 'healthy_journal_known');
        financial_deadline_assert(count(financial_deadline_checkpoints($pdo, $f['source_id'])) === 1, 'healthy_checkpoint_created');
    } elseif ($case === 'deadline') {
        financial_deadline_worker($pdo, financial_deadline_deadline_factory($pdo, $f));
        $after = financial_deadline_source($pdo, $f['source_id']);
        $pointer = financial_deadline_pointer($pdo, $f['queue_id']);
        $attempts = financial_deadline_attempts($pdo, $f['queue_id']);
        $journal = financial_deadline_journal($pdo, $f['queue_id']);

        financial_deadline_assert(($after['status'] ?? '') === 'retry', 'deadline_source_retry', ['actual' => $after]);
        financial_deadline_assert($after['lock_owner'] === null && $after['lease_expires_at'] === null && $after['heartbeat_at'] === null, 'deadline_source_lease_released', ['actual' => $after]);
        financial_deadline_assert((int) $after['attempts'] === (int) $before['attempts'], 'deadline_source_attempt_refunded_once', ['before' => $before, 'after' => $after]);
        financial_deadline_assert(($pointer['state'] ?? '') === 'waiting' && (int) ($pointer['attempt_count'] ?? -1) === 0, 'deadline_pointer_waiting');
        financial_deadline_assert(($pointer['last_error_class'] ?? '') === 'capacity_deferred:cron_deadline', 'deadline_pointer_classification');
        financial_deadline_assert(strtotime((string) $after['next_run_at'] . ' UTC') <= strtotime((string) $pointer['available_at'] . ' UTC'), 'deadline_source_not_later_than_pointer', ['source' => $after['next_run_at'], 'pointer' => $pointer['available_at']]);
        financial_deadline_assert(count($attempts) === 1 && $attempts[0]['dispatch_state'] === 'NOT_DISPATCHED' && (int) $attempts[0]['physical_http_calls'] === 0 && $attempts[0]['physical_started_at'] === null && $attempts[0]['http_status'] === null && $attempts[0]['response_known_at'] === null, 'deadline_not_dispatched');
        financial_deadline_assert($journal === [] && Cap2DomainsWire::$calls === [], 'deadline_zero_transport');

        $sourceSnapshot = $after;
        $pointerSnapshot = $pointer;
        financial_deadline_worker($pdo);
        financial_deadline_assert(financial_deadline_source($pdo, $f['source_id']) === $sourceSnapshot, 'deadline_second_tick_source_unchanged');
        financial_deadline_assert(financial_deadline_pointer($pdo, $f['queue_id']) === $pointerSnapshot, 'deadline_second_tick_pointer_unchanged');
        financial_deadline_assert(Cap2DomainsWire::$calls === [], 'deadline_second_tick_zero_http');
    } elseif ($case === 'fence_loss') {
        financial_deadline_worker($pdo, financial_deadline_deadline_factory($pdo, $f, true));
        $after = financial_deadline_source($pdo, $f['source_id']);
        $pointer = financial_deadline_pointer($pdo, $f['queue_id']);
        $attempts = financial_deadline_attempts($pdo, $f['queue_id']);

        financial_deadline_assert(($after['status'] ?? '') === 'running', 'fence_loss_new_generation_remains_running', ['actual' => $after]);
        financial_deadline_assert(($after['lock_owner'] ?? '') === 'replacement-owner', 'fence_loss_replacement_owner_preserved');
        financial_deadline_assert((int) $after['lease_generation'] === (int) $before['lease_generation'] + 2, 'fence_loss_generation_preserved', ['before' => $before, 'after' => $after]);
        financial_deadline_assert((int) $after['attempts'] === (int) $before['attempts'] + 2, 'fence_loss_attempts_not_refunded_on_other_generation');
        financial_deadline_assert(
            ($pointer['state'] ?? '') === 'waiting'
                && count($attempts) === 1
                && $attempts[0]['dispatch_state'] === 'NOT_DISPATCHED'
                && (int) $attempts[0]['physical_http_calls'] === 0
                && financial_deadline_journal($pdo, $f['queue_id']) === [],
            'fence_loss_financial_authority_blocks_before_physical_dispatch',
            ['pointer' => $pointer, 'attempts' => $attempts]
        );
        financial_deadline_assert(count($attempts) === 1 && $attempts[0]['dispatch_state'] === 'NOT_DISPATCHED' && (int) $attempts[0]['physical_http_calls'] === 0, 'fence_loss_zero_http');
    } else {
        // First tick stores one real local checkpoint, then the second tick hits
        // deadline before the second order reaches the wire.
        financial_deadline_worker($pdo);
        $checkpointBefore = financial_deadline_checkpoints($pdo, $f['source_id']);
        financial_deadline_assert(count($checkpointBefore) === 1, 'checkpoint_seeded');

        $pdo->prepare(
            "UPDATE sale_financial_reconciliation_jobs
             SET status='retry',next_run_at='2000-01-01',lock_owner=NULL,lease_expires_at=NULL,heartbeat_at=NULL
             WHERE id=? AND company_id=9001 AND meli_account_id=9011"
        )->execute([$f['source_id']]);
        $pdo->prepare(
            "UPDATE queue_v4_clean_jobs
             SET state='ready',available_at='2000-01-01',lease_owner=NULL,lease_expires_at=NULL
             WHERE id=? AND company_id=9001 AND meli_account_id=9011"
        )->execute([$f['queue_id']]);

        Cap2DomainsWire::$calls = [];
        $beforeSecond = financial_deadline_source($pdo, $f['source_id']);
        financial_deadline_worker($pdo, financial_deadline_deadline_factory($pdo, $f));
        $after = financial_deadline_source($pdo, $f['source_id']);
        $pointer = financial_deadline_pointer($pdo, $f['queue_id']);
        $checkpointAfter = financial_deadline_checkpoints($pdo, $f['source_id']);

        financial_deadline_assert($checkpointAfter === $checkpointBefore, 'checkpoint_preserved_byte_for_byte');
        financial_deadline_assert(($after['status'] ?? '') === 'retry' && $after['lock_owner'] === null, 'checkpoint_deadline_source_retry');
        financial_deadline_assert((int) $after['attempts'] === (int) $beforeSecond['attempts'], 'checkpoint_deadline_attempt_refunded');
        financial_deadline_assert(($pointer['state'] ?? '') === 'waiting' && ($pointer['last_error_class'] ?? '') === 'capacity_deferred:cron_deadline', 'checkpoint_deadline_pointer_waiting');
        financial_deadline_assert(Cap2DomainsWire::$calls === [], 'checkpoint_deadline_no_second_http');
        financial_deadline_assert(count(financial_deadline_journal($pdo, $f['queue_id'])) === 1, 'checkpoint_only_original_journal_preserved');
    }

    echo 'STATUS=PASS CASE=' . $case . ' MYSQL=REAL REAL_MELI_HTTP=0 REAL_OAUTH=0' . PHP_EOL;
} finally {
    Cap2DomainsWire::$onWire = null;
    CallsWireOptions::$clockOffset = 0.0;
    QueueV4CleanCycleBudget::clear();
    CronDeadlineContext::clear();
    $harness->cleanup();
}
}
