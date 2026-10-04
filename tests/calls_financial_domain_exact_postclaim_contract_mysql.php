<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/calls_transport_wire_fixture.php';

use App\Core\Crypto;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\ApiManualPauseService;
use App\Services\ApiRhythmPolicyService;
use App\Services\AppSettingsService;
use App\Services\CallsWireOptions;
use App\Services\Cap2DomainsWire;
use App\Services\CronDeadlineContext;
use App\Services\MeliApiClient;
use App\Services\SaleFinancialService;
use App\Services\SaleFinancialStateService;
use App\Services\SchemaInspectorService;

function postclaim_assert(bool $ok, string $name, array $context = []): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL:' . $name . ':' . json_encode($context, JSON_THROW_ON_ERROR));
    }
    echo 'PASS:' . $name . PHP_EOL;
}

function postclaim_seed_scope(PDO $pdo): void
{
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'Postclaim contract fixture',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'Postclaim account',99011,'conectado')");
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
        'oauth.token_expiry_skew_seconds' => '120',
        'sales_financial.commercial_pipeline_enabled' => '1',
    ] as $key => $value) {
        $settings->set($key, $value, 'calls-financial-postclaim-contract');
    }
    AppSettingsService::clearCache();
}

/** @return array{source_id:int,queue_id:int,pack_id:string,order_id:string} */
function postclaim_seed_work(PDO $pdo): array
{
    $packId = '993' . random_int(100, 999);
    $externalOrder = $packId . '001';

    $pdo->prepare(
        "INSERT INTO meli_packs(meli_account_id,external_pack_id,status,integrity_status,expected_orders_count,linked_orders_count,expected_orders_json,synced_at,verified_at)
         VALUES(9011,?,'paid','complete',1,1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    )->execute([$packId, json_encode([$externalOrder], JSON_THROW_ON_ERROR)]);

    $pdo->prepare(
        "INSERT INTO meli_orders
            (meli_account_id,external_order_id,external_pack_id,status,total_amount,paid_amount,currency_id,synced_at)
         VALUES(9011,?,?,'paid',100,100,'COP',UTC_TIMESTAMP())"
    )->execute([$externalOrder, $packId]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO meli_order_items
            (meli_order_id,meli_account_id,external_item_id,title,seller_sku,quantity,unit_price,sale_fee)
         VALUES(?,9011,?,?,?,1,100,10)"
    )->execute([$orderId, 'ITEM-' . $externalOrder, 'Order ' . $externalOrder, 'SKU-' . $externalOrder]);

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
        'postclaim-' . $sourceId . '-' . bin2hex(random_bytes(4)),
        json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR),
    ]);

    return [
        'source_id' => $sourceId,
        'queue_id' => (int) $pdo->lastInsertId(),
        'pack_id' => $packId,
        'order_id' => $externalOrder,
    ];
}

/** @return array<string,mixed> */
function postclaim_source(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        'SELECT id,status,next_run_at,attempts,lock_owner,lease_generation,lease_expires_at,heartbeat_at,safe_message
         FROM sale_financial_reconciliation_jobs
         WHERE id=? AND company_id=9001 AND meli_account_id=9011'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

/** @return array<string,mixed> */
function postclaim_pointer(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        'SELECT id,state,available_at,attempt_count,last_error_class,lease_generation,lease_owner,lease_expires_at
         FROM queue_v4_clean_jobs
         WHERE id=? AND company_id=9001 AND meli_account_id=9011'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

/** @return list<array<string,mixed>> */
function postclaim_attempts(PDO $pdo, int $queueId): array
{
    $stmt = $pdo->prepare(
        'SELECT id,dispatch_state,physical_http_calls,http_status,physical_started_at,response_known_at
         FROM queue_v4_clean_attempts
         WHERE job_id=? AND company_id=9001 AND meli_account_id=9011 ORDER BY id'
    );
    $stmt->execute([$queueId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function postclaim_journal(PDO $pdo, int $queueId): array
{
    $stmt = $pdo->prepare(
        'SELECT id,dispatch_state,http_status,physical_started_at,response_known_at
         FROM queue_v4_clean_transport_events
         WHERE source_kind="queue" AND work_id=? AND company_id=9001 AND meli_account_id=9011 ORDER BY id'
    );
    $stmt->execute([$queueId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<string,mixed> */
function postclaim_observation(PDO $pdo, array $fixture, array $before): array
{
    return [
        'source_before' => $before,
        'source_after' => postclaim_source($pdo, (int) $fixture['source_id']),
        'pointer_after' => postclaim_pointer($pdo, (int) $fixture['queue_id']),
        'attempts' => postclaim_attempts($pdo, (int) $fixture['queue_id']),
        'journal' => postclaim_journal($pdo, (int) $fixture['queue_id']),
        'wire_calls' => Cap2DomainsWire::$calls,
    ];
}

function postclaim_print_observation(string $case, string $reachability, array $observation): void
{
    echo 'OBSERVED=' . json_encode([
        'case' => $case,
        'naturally_reachable' => $reachability,
        'source_before_status' => $observation['source_before']['status'] ?? null,
        'source_before_attempts' => $observation['source_before']['attempts'] ?? null,
        'source_after_status' => $observation['source_after']['status'] ?? null,
        'source_after_attempts' => $observation['source_after']['attempts'] ?? null,
        'source_after_owner' => $observation['source_after']['lock_owner'] ?? null,
        'pointer_state' => $observation['pointer_after']['state'] ?? null,
        'pointer_attempt_count' => $observation['pointer_after']['attempt_count'] ?? null,
        'pointer_error' => $observation['pointer_after']['last_error_class'] ?? null,
        'dispatch_state' => $observation['attempts'][0]['dispatch_state'] ?? null,
        'physical_http_calls' => $observation['attempts'][0]['physical_http_calls'] ?? null,
        'http_status' => $observation['attempts'][0]['http_status'] ?? null,
        'journal_rows' => count($observation['journal']),
        'wire_calls' => count($observation['wire_calls']),
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
}

function postclaim_configure_wire(string $case): void
{
    Cap2DomainsWire::$calls = [];
    Cap2DomainsWire::$status = 0;
    Cap2DomainsWire::$raw = '';
    Cap2DomainsWire::$curlError = '';
    Cap2DomainsWire::$onWire = null;
    CallsWireOptions::$onFinalOptions = null;

    $path = '/billing/integration/group/ML/order/details';
    if ($case === 'known_http_500') {
        Cap2DomainsWire::$responses = [$path => [500, ['message' => 'synthetic server failure']]];
        return;
    }
    if ($case === 'remote_uncertain') {
        Cap2DomainsWire::$responses = [$path => [0, [], 'synthetic connection reset after physical dispatch']];
        return;
    }
    Cap2DomainsWire::$responses = [$path => [200, []]];
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

function postclaim_run_worker(PDO $pdo, ?callable $factory = null): array
{
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
            $factory
        ))->run('test', 1, 40);
    } finally {
        QueueV4CleanCycleBudget::clear();
        CronDeadlineContext::clear();
    }
}

function postclaim_assert_safe_deferral(array $observation, string $prefix): void
{
    $before = $observation['source_before'];
    $after = $observation['source_after'];
    $pointer = $observation['pointer_after'];
    $attempt = $observation['attempts'][0] ?? [];

    postclaim_assert(($after['status'] ?? '') === 'retry', $prefix . '_source_retry', ['after' => $after]);
    postclaim_assert(($after['lock_owner'] ?? null) === null && ($after['lease_expires_at'] ?? null) === null
        && ($after['heartbeat_at'] ?? null) === null, $prefix . '_source_lease_cleared', ['after' => $after]);
    postclaim_assert((int) ($after['attempts'] ?? -1) === (int) ($before['attempts'] ?? -2),
        $prefix . '_source_attempt_refunded', ['before' => $before, 'after' => $after]);
    postclaim_assert(($pointer['state'] ?? '') === 'waiting', $prefix . '_pointer_waiting', ['pointer' => $pointer]);
    postclaim_assert(($attempt['dispatch_state'] ?? '') === 'NOT_DISPATCHED'
        && (int) ($attempt['physical_http_calls'] ?? -1) === 0,
        $prefix . '_not_dispatched', ['attempt' => $attempt]);
    postclaim_assert($observation['wire_calls'] === [], $prefix . '_zero_wire');
}

function postclaim_assert_known_500(array $observation): void
{
    $before = $observation['source_before'];
    $after = $observation['source_after'];
    $pointer = $observation['pointer_after'];
    $attempt = $observation['attempts'][0] ?? [];

    postclaim_assert(($after['status'] ?? '') === 'retry', 'http500_source_retry', ['after' => $after]);
    postclaim_assert(($after['lock_owner'] ?? null) === null && ($after['lease_expires_at'] ?? null) === null,
        'http500_source_lease_cleared', ['after' => $after]);
    postclaim_assert((int) ($after['attempts'] ?? -1) === (int) ($before['attempts'] ?? -2) + 1,
        'http500_source_attempt_not_refunded', ['before' => $before, 'after' => $after]);
    postclaim_assert(($pointer['state'] ?? '') === 'waiting', 'http500_pointer_waiting', ['pointer' => $pointer]);
    postclaim_assert(($attempt['dispatch_state'] ?? '') === 'RESPONSE_KNOWN'
        && (int) ($attempt['physical_http_calls'] ?? 0) === 1
        && (int) ($attempt['http_status'] ?? 0) === 500,
        'http500_response_known', ['attempt' => $attempt]);
    postclaim_assert(count($observation['wire_calls']) === 1, 'http500_one_wire');
}

function postclaim_assert_remote_uncertain(array $observation): void
{
    $before = $observation['source_before'];
    $after = $observation['source_after'];
    $pointer = $observation['pointer_after'];
    $attempt = $observation['attempts'][0] ?? [];

    postclaim_assert(($after['status'] ?? '') === 'review', 'uncertain_source_review', ['after' => $after]);
    postclaim_assert(($after['lock_owner'] ?? null) === null && ($after['lease_expires_at'] ?? null) === null,
        'uncertain_source_lease_cleared', ['after' => $after]);
    postclaim_assert((int) ($after['attempts'] ?? -1) === (int) ($before['attempts'] ?? -2) + 1,
        'uncertain_source_attempt_not_refunded', ['before' => $before, 'after' => $after]);
    postclaim_assert(($pointer['state'] ?? '') === 'review', 'uncertain_pointer_review', ['pointer' => $pointer]);
    postclaim_assert((int) ($attempt['physical_http_calls'] ?? 0) === 1
        && ($attempt['dispatch_state'] ?? '') !== 'NOT_DISPATCHED'
        && ($attempt['http_status'] ?? null) === null,
        'uncertain_physical_started_without_known_response', ['attempt' => $attempt]);
    postclaim_assert(count($observation['wire_calls']) === 1, 'uncertain_one_wire_boundary');
}

$options = getopt('', ['case:']);
$cases = [
    'healthy',
    'oauth_refresh',
    'manual_pause',
    'known_http_500',
    'remote_uncertain',
    'queue_pretransport',
    'budget_infrastructure',
    'budget_sibling',
];

if (!isset($options['case'])) {
    $failed = 0;
    foreach ($cases as $childCase) {
        $child = proc_open(
            [PHP_BINARY, __FILE__, '--case=' . $childCase],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($child)) {
            echo 'CASE_RESULT=' . $childCase . ':HARNESS_START_FAILED' . PHP_EOL;
            $failed++;
            continue;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        echo $stdout;
        if ($stderr !== '') {
            fwrite(STDERR, $stderr);
        }
        $code = proc_close($child);
        echo 'CASE_RESULT=' . $childCase . ':EXIT_' . $code . PHP_EOL;
        if ($code !== 0) {
            $failed++;
        }
    }
    echo 'POSTCLAIM_RED_MATRIX_FAILED_CASES=' . $failed . PHP_EOL;
    exit($failed === 0 ? 0 : 1);
}

$case = (string) $options['case'];
postclaim_assert(in_array($case, $cases, true), 'known_case');

$reachability = match ($case) {
    'healthy' => 'CONTROL',
    'oauth_refresh' => 'YES_REAL_TOKEN_EXPIRY_GATE',
    'manual_pause' => 'YES_REAL_MANUAL_PAUSE_GATE',
    'known_http_500' => 'YES_REAL_RESPONSE_KNOWN_PATH',
    'remote_uncertain' => 'YES_REAL_PHYSICAL_FAILURE_PATH',
    'queue_pretransport' => 'YES_REAL_PRE_CURL_FAILURE_PATH',
    'budget_infrastructure' => 'YES_REAL_RHYTHM_AUTHORITY_FAILURE',
    'budget_sibling' => 'UNKNOWN_PRODUCTION_TEST_SEAM_ONLY',
};

$root = (string) getenv('CALLS_QA_STORAGE_ROOT');
postclaim_assert($root !== '' && is_dir($root), 'explicit_owned_test_root');
putenv('DB_NAME=erp_meli_k1d_test_finpostclaim_' . bin2hex(random_bytes(5)));
putenv('APP_KEY=synthetic-financial-postclaim-only');
putenv('MELI_API_BASE=https://no-network.invalid');
putenv('PRIVATE_STORAGE_PATH=' . $root . '/private');
define('ERP_INSTALLATION_ROOT', $root . '/install-' . $case . '-' . bin2hex(random_bytes(3)));
mkdir(ERP_INSTALLATION_ROOT, 0770, true);

$harness = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $harness->pdo();
    (new App\Services\Migrator($pdo, __DIR__ . '/../database/migrations'))->run(301);
    postclaim_seed_scope($pdo);
    $fixture = postclaim_seed_work($pdo);
    postclaim_configure_wire($case);
    $before = postclaim_source($pdo, $fixture['source_id']);
    $factory = null;

    if ($case === 'oauth_refresh') {
        $pdo->exec("UPDATE meli_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE meli_account_id=9011");
    } elseif ($case === 'manual_pause') {
        (new ApiManualPauseService())->pause(9011, 15, 'Synthetic postclaim pause', null);
    } elseif ($case === 'queue_pretransport') {
        CallsWireOptions::$onFinalOptions = static function (): void {
            throw new RuntimeException('synthetic_pretransport_option_failure');
        };
    } elseif ($case === 'budget_infrastructure') {
        $reflection = new ReflectionClass(ApiRhythmPolicyService::class);
        $schema = $reflection->getProperty('schemaAvailable');
        $schema->setAccessible(true);
        postclaim_assert($schema->getValue() === null, 'budget_infrastructure_schema_cache_cold');
        $pdo->exec('DROP TABLE api_remote_permits');
        SchemaInspectorService::clearCache('api_remote_permits');
    } elseif ($case === 'budget_sibling') {
        $factory = static function (): SaleFinancialService {
            return new SaleFinancialService(
                static function (int $accountId): MeliApiClient {
                    QueueV4CleanCycleBudget::reserve(str_repeat('a', 40), 'financial_sibling_a');
                    QueueV4CleanCycleBudget::reserve(str_repeat('b', 40), 'financial_sibling_b');
                    return new MeliApiClient($accountId);
                }
            );
        };
    }

    postclaim_run_worker($pdo, $factory);
    $observation = postclaim_observation($pdo, $fixture, $before);
    postclaim_print_observation($case, $reachability, $observation);

    if ($case === 'healthy') {
        $attempt = $observation['attempts'][0] ?? [];
        postclaim_assert(($observation['source_after']['status'] ?? '') === 'complete', 'healthy_source_complete', ['observation' => $observation]);
        postclaim_assert(($observation['pointer_after']['state'] ?? '') === 'completed', 'healthy_pointer_completed', ['observation' => $observation]);
        postclaim_assert(($attempt['dispatch_state'] ?? '') === 'RESPONSE_KNOWN'
            && (int) ($attempt['physical_http_calls'] ?? 0) === 1
            && (int) ($attempt['http_status'] ?? 0) === 200, 'healthy_response_known');
        postclaim_assert(count($observation['wire_calls']) === 1, 'healthy_one_wire');
    } elseif ($case === 'known_http_500') {
        postclaim_assert_known_500($observation);
    } elseif ($case === 'remote_uncertain') {
        postclaim_assert_remote_uncertain($observation);
    } else {
        postclaim_assert_safe_deferral($observation, $case);
    }

    echo 'STATUS=PASS CASE=' . $case . ' REAL_MELI_HTTP=0 REAL_OAUTH=0' . PHP_EOL;
} finally {
    Cap2DomainsWire::$onWire = null;
    Cap2DomainsWire::$curlError = '';
    CallsWireOptions::$onFinalOptions = null;
    QueueV4CleanCycleBudget::clear();
    CronDeadlineContext::clear();
    $harness->cleanup();
}
