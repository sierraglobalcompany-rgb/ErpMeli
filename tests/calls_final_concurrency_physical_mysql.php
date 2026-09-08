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

function calls_final_concurrency_assert(bool $condition, string $label, array $context = []): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $label . ':' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
    echo 'PASS:' . $label . PHP_EOL;
}

function calls_final_concurrency_wait(PDO $pdo, string $column, int $expected, float $seconds = 20.0): void
{
    calls_final_concurrency_assert(in_array($column, ['ready_count', 'go_flag', 'attempted_count'], true), 'known_barrier_column');
    $deadline = microtime(true) + $seconds;
    do {
        if ((int) $pdo->query('SELECT ' . $column . ' FROM calls_final_concurrency_barrier WHERE id=1')->fetchColumn() >= $expected) {
            return;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('barrier_timeout_' . $column);
}

function calls_final_concurrency_order_payload(int $externalOrderId): array
{
    return [
        'id' => $externalOrderId,
        'status' => 'paid',
        'date_created' => '2026-09-08T00:00:00.000Z',
        'date_closed' => '2026-09-08T00:01:00.000Z',
        'last_updated' => '2026-09-08T00:02:00.000Z',
        'total_amount' => 100,
        'paid_amount' => 100,
        'currency_id' => 'COP',
        'buyer' => ['id' => 970001],
        'seller' => ['id' => 99011],
        'pack_id' => null,
        'order_items' => [[
            'item' => ['id' => 'MCO' . $externalOrderId, 'title' => 'Concurrency order ' . $externalOrderId, 'seller_sku' => 'CONC-' . $externalOrderId],
            'quantity' => 1,
            'unit_price' => 100,
            'sale_fee' => 10,
        ]],
    ];
}

function calls_final_concurrency_seed_scope(PDO $pdo): void
{
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'Calls concurrency',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'Calls concurrency account',99011,'conectado')");
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
    ] as $key => $value) {
        $settings->set($key, $value, 'calls-final-concurrency');
    }
    AppSettingsService::clearCache();
    $pdo->exec('CREATE TABLE calls_final_concurrency_barrier (id INT PRIMARY KEY, ready_count INT NOT NULL DEFAULT 0, go_flag INT NOT NULL DEFAULT 0, attempted_count INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE calls_final_concurrency_observer (id INT AUTO_INCREMENT PRIMARY KEY, scenario VARCHAR(20) NOT NULL, pid INT NOT NULL, path VARCHAR(255) NOT NULL, resource_id VARCHAR(80) NULL, request_id VARCHAR(80) NULL, meta_json JSON NULL, created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
}

function calls_final_concurrency_seed_one_job(PDO $pdo, int $scenario, int $externalOrderId): int
{
    $pdo->exec('DELETE FROM calls_final_concurrency_barrier');
    $pdo->exec("INSERT INTO calls_final_concurrency_barrier(id) VALUES(1)");
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
         VALUES(9001,9011,'order_exact',?,?,'{}','ready','2000-01-01')"
    )->execute([(string) $externalOrderId, 'calls-concurrency-' . $scenario . '-' . $externalOrderId]);

    return (int) $pdo->lastInsertId();
}

function calls_final_concurrency_run_child(): void
{
    $harness = K1dSafeTestDatabase::connectExistingFromEnvironment();
    $pdo = $harness->pdo();
    $scenario = (string) getenv('CALLS_CONCURRENCY_SCENARIO');
    $orderId = (int) getenv('CALLS_CONCURRENCY_ORDER_ID');
    $pdo->exec('UPDATE calls_final_concurrency_barrier SET ready_count=ready_count+1 WHERE id=1');
    calls_final_concurrency_wait($pdo, 'go_flag', 1);

    Cap2DomainsWire::$calls = [];
    Cap2DomainsWire::$responses = ['/orders/' . $orderId => [200, calls_final_concurrency_order_payload($orderId)]];
    Cap2DomainsWire::$onWire = static function () use ($pdo, $scenario, $orderId): void {
        $last = Cap2DomainsWire::$calls[array_key_last(Cap2DomainsWire::$calls)] ?? [];
        $meta = \App\Services\ApiExecutionMetadataContext::current();
        $pdo->prepare(
            'INSERT INTO calls_final_concurrency_observer(scenario,pid,path,resource_id,request_id,meta_json)
             VALUES(?,?,?,?,?,?)'
        )->execute([
            $scenario,
            getmypid(),
            (string) ($last['path'] ?? ''),
            (string) $orderId,
            (string) ($meta['request_id'] ?? ''),
            json_encode($meta, JSON_THROW_ON_ERROR),
        ]);
        usleep(300000);
    };

    $pdo->exec('UPDATE calls_final_concurrency_barrier SET attempted_count=attempted_count+1 WHERE id=1');
    CronDeadlineContext::start(45, 40, 8, 3);
    QueueV4CleanCycleBudget::start(1, 'automatic', microtime(true) + 45);
    try {
        $result = (new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo)))->run('test', 1, 40);
        echo 'CHILD_RESULT=' . json_encode([
            'pid' => getmypid(),
            'claimed' => (int) ($result['claimed'] ?? 0),
            'completed' => (int) ($result['completed'] ?? 0),
            'physical_http_calls' => (int) ($result['physical_http_calls'] ?? 0),
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } finally {
        Cap2DomainsWire::$onWire = null;
        QueueV4CleanCycleBudget::clear();
        CronDeadlineContext::clear();
    }
}

/** @return list<string> */
function calls_final_concurrency_spawn(string $script, int $count, int $scenario, int $orderId): array
{
    $children = [];
    $env = array_merge($_ENV, [
        'APP_ENV' => 'test',
        'ML_WRITE_ENABLED' => 'false',
        'DB_HOST' => (string) getenv('DB_HOST'),
        'DB_PORT' => (string) getenv('DB_PORT'),
        'DB_NAME' => (string) getenv('DB_NAME'),
        'DB_USER' => (string) getenv('DB_USER'),
        'DB_PASS' => (string) getenv('DB_PASS'),
        'APP_KEY' => (string) getenv('APP_KEY'),
        'PRIVATE_STORAGE_PATH' => (string) getenv('PRIVATE_STORAGE_PATH'),
        'MELI_API_BASE' => (string) getenv('MELI_API_BASE'),
        'CALLS_CONCURRENCY_SCENARIO' => (string) $scenario,
        'CALLS_CONCURRENCY_ORDER_ID' => (string) $orderId,
    ]);
    for ($i = 0; $i < $count; $i++) {
        $process = proc_open([PHP_BINARY, $script, 'child'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, $env);
        calls_final_concurrency_assert(is_resource($process), 'child_process_started');
        $children[] = [$process, $pipes];
    }
    $pdo = \App\Core\Database::connectionFresh();
    calls_final_concurrency_wait($pdo, 'ready_count', $count);
    $pdo->exec('UPDATE calls_final_concurrency_barrier SET go_flag=1 WHERE id=1');
    $outputs = [];
    foreach ($children as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        calls_final_concurrency_assert($exit === 0 && trim((string) $stderr) === '', 'child_process_clean_exit', ['exit' => $exit, 'stderr' => $stderr]);
        $outputs[] = (string) $stdout;
    }
    return $outputs;
}

if (($argv[1] ?? '') === 'child') {
    calls_final_concurrency_run_child();
    exit(0);
}

$root = 'D:/Codex/tmp/erp-meli/calls-20260906/concurrency-physical';
foreach ([
    'APP_ENV' => 'test',
    'ML_WRITE_ENABLED' => 'false',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '33079',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'DB_NAME' => 'erp_meli_k1d_test_calls_concurrency_' . bin2hex(random_bytes(4)),
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
    calls_final_concurrency_seed_scope($pdo);

    foreach ([2, 5, 20] as $scenario) {
        $orderId = 970000 + $scenario;
        $queueId = calls_final_concurrency_seed_one_job($pdo, $scenario, $orderId);
        $outputs = calls_final_concurrency_spawn(__FILE__, $scenario, $scenario, $orderId);
        $wireRows = (int) $pdo->query("SELECT COUNT(*) FROM calls_final_concurrency_observer WHERE scenario='{$scenario}'")->fetchColumn();
        $distinctPids = (int) $pdo->query("SELECT COUNT(DISTINCT pid) FROM calls_final_concurrency_observer WHERE scenario='{$scenario}'")->fetchColumn();
        $job = $pdo->query('SELECT state,attempt_count FROM queue_v4_clean_jobs WHERE id=' . $queueId)->fetch(PDO::FETCH_ASSOC);
        $completedOutputs = count(array_filter($outputs, static fn (string $out): bool => str_contains($out, '"completed":1')));
        $physicalOutputs = count(array_filter($outputs, static fn (string $out): bool => str_contains($out, '"physical_http_calls":1')));
        calls_final_concurrency_assert($wireRows === 1, 'concurrency_' . $scenario . '_single_physical_wire', ['wire_rows' => $wireRows, 'outputs' => $outputs]);
        calls_final_concurrency_assert($distinctPids === 1, 'concurrency_' . $scenario . '_one_process_reached_wire', ['distinct_pids' => $distinctPids, 'outputs' => $outputs]);
        calls_final_concurrency_assert((string) ($job['state'] ?? '') === 'completed', 'concurrency_' . $scenario . '_job_completed_once', $job ?: []);
        calls_final_concurrency_assert((int) ($job['attempt_count'] ?? 0) === 1, 'concurrency_' . $scenario . '_one_claim_attempt', $job ?: []);
        calls_final_concurrency_assert($completedOutputs === 1 && $physicalOutputs === 1, 'concurrency_' . $scenario . '_one_winner_receipt', ['completed' => $completedOutputs, 'physical' => $physicalOutputs, 'outputs' => $outputs]);
        echo "CONCURRENCY_{$scenario}_WIRE_ROWS={$wireRows}\n";
    }

    echo "STATUS=PASS CALLS_FINAL_CONCURRENCY_PHYSICAL MYSQL=REAL REAL_MELI_HTTP=0\n";
} finally {
    QueueV4CleanCycleBudget::clear();
    CronDeadlineContext::clear();
    $harness->cleanup();
}
