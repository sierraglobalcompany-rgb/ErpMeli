<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/calls_transport_wire_fixture.php';

use App\Core\Crypto;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\AppSettingsService;

function calls_final_seed_assert(bool $condition, string $label, array $context = []): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $label . ':' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
}

function calls_final_seed_scope(PDO $pdo): void
{
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'Calls seed matrix',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'Calls seed account',99011,'conectado')");
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
        'api.rhythm.current_adaptive_limit' => '40',
        'api.rhythm.billing_min_interval_seconds' => '1',
        'api.rhythm.shared_429_jitter_seconds' => '0',
    ] as $key => $value) {
        $settings->set($key, $value, 'calls-final-seed');
    }
    AppSettingsService::clearCache();
}

function calls_final_seed_insert_order_job(PDO $pdo, int $externalOrderId, string $state, string $availableAt, ?string $leaseOwner = null): int
{
    $leaseSql = $leaseOwner === null ? 'NULL,NULL,0' : "'{$leaseOwner}',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND),1";
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at,lease_owner,lease_expires_at,lease_generation)
         VALUES(9001,9011,'order_exact',?,?,'{}',?,?,{$leaseSql})"
    )->execute([(string) $externalOrderId, 'calls-seed-' . $externalOrderId, $state, $availableAt]);

    return (int) $pdo->lastInsertId();
}

$root = 'D:/Codex/tmp/erp-meli/calls-20260906/seed-matrix';
foreach ([
    'APP_ENV' => 'test',
    'ML_WRITE_ENABLED' => 'false',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '33079',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'DB_NAME' => 'erp_meli_k1d_test_calls_seed_matrix_' . bin2hex(random_bytes(4)),
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
    calls_final_seed_scope($pdo);
    $repository = new QueueV4CleanRepository($pdo);
    $claims = 0;

    $observed = [];
    for ($seed = 1; $seed <= 100; $seed++) {
        $baseOrder = 980000 + ($seed * 10);
        $lateFirst = ($seed % 4) === 0;
        $tie = ($seed % 5) === 0;
        $eligibleAAt = $lateFirst ? '2000-01-01 00:00:02' : '2000-01-01 00:00:00';
        $eligibleBAt = $tie ? $eligibleAAt : '2000-01-01 00:00:01';

        $seedRows = [
            ['order' => $baseOrder + 1, 'queue' => calls_final_seed_insert_order_job($pdo, $baseOrder + 1, 'ready', $eligibleAAt), 'available_at' => $eligibleAAt, 'eligible' => true],
            ['order' => $baseOrder + 2, 'queue' => calls_final_seed_insert_order_job($pdo, $baseOrder + 2, 'ready', $eligibleBAt), 'available_at' => $eligibleBAt, 'eligible' => true],
            ['order' => $baseOrder + 3, 'queue' => calls_final_seed_insert_order_job($pdo, $baseOrder + 3, 'ready', '2099-01-01 00:00:00'), 'available_at' => '2099-01-01 00:00:00', 'eligible' => false],
            ['order' => $baseOrder + 4, 'queue' => calls_final_seed_insert_order_job($pdo, $baseOrder + 4, 'running', '2000-01-01 00:00:00', 'seed-live-lease'), 'available_at' => '2000-01-01 00:00:00', 'eligible' => false],
        ];
        $eligibleRows = array_values(array_filter($seedRows, static fn (array $row): bool => $row['eligible']));
        usort($eligibleRows, static function (array $a, array $b): int {
            return [$a['available_at'], $a['queue']] <=> [$b['available_at'], $b['queue']];
        });
        $expectedOrder = (int) $eligibleRows[0]['order'];
        $expectedQueue = (int) $eligibleRows[0]['queue'];

        $runId = $repository->beginRun('test', 'seed-' . $seed);
        $claimed = $repository->claim($runId, 'seed-' . $seed);
        if (is_array($claimed)) {
            $claims++;
            $repository->complete($claimed, $runId);
        }
        $observed[] = [
            'seed' => $seed,
            'expected_order' => $expectedOrder,
            'expected_queue' => $expectedQueue,
            'claimed' => $claimed,
        ];

        calls_final_seed_assert(is_array($claimed), 'seed_' . $seed . '_claimed_one_eligible_pointer', $observed[array_key_last($observed)]);
        calls_final_seed_assert((int) ($claimed['id'] ?? 0) === $expectedQueue, 'seed_' . $seed . '_fifo_available_at_then_id', $observed[array_key_last($observed)]);
        calls_final_seed_assert((string) ($claimed['resource_id'] ?? '') === (string) $expectedOrder, 'seed_' . $seed . '_claimed_expected_resource', $observed[array_key_last($observed)]);
        calls_final_seed_assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=' . $expectedQueue)->fetchColumn() === 'completed', 'seed_' . $seed . '_expected_queue_completed');
        $seedQueueIds = array_map(static fn (array $row): int => (int) $row['queue'], $seedRows);
        calls_final_seed_assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE id IN (' . implode(',', $seedQueueIds) . ') AND attempt_count>1')->fetchColumn() === 0, 'seed_' . $seed . '_no_duplicate_attempts');
        $remainingSeedQueueIds = array_values(array_filter($seedQueueIds, static fn (int $id): bool => $id !== $expectedQueue));
        $pdo->exec('UPDATE queue_v4_clean_jobs SET state=\'waiting\',available_at=\'2099-01-01\' WHERE id IN (' . implode(',', $remainingSeedQueueIds) . ') AND state=\'ready\'');
    }

    echo "SEEDS=100\n";
    echo "FIFO_CLAIMS={$claims}\n";
    echo "WIRE_CALLS=0\n";
    echo "STATUS=PASS CALLS_FINAL_SEED_MATRIX MYSQL=REAL REAL_MELI_HTTP=0\n";
} finally {
    $harness->cleanup();
}
