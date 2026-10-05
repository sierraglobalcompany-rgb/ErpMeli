<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\SaleFinancialService;
use App\Services\SaleFinancialStateService;

function recovery_assert(bool $ok, string $name, array $context = []): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL:' . $name . ':' . json_encode($context, JSON_THROW_ON_ERROR));
    }
    echo 'PASS:' . $name . PHP_EOL;
}

function recovery_seed_scope(PDO $pdo): void
{
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'Financial recovery fixture',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'Recovery account',99011,'conectado')");
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED' WHERE control_key='primary'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");
}

/** @return array{source_id:int,queue_id:int,order_id:string} */
function recovery_seed_work(PDO $pdo, string $sourceStatus, bool $liveLease = false): array
{
    $externalOrder = '994' . random_int(100000, 999999);
    $pdo->prepare(
        "INSERT INTO meli_orders
            (meli_account_id,external_order_id,status,total_amount,paid_amount,currency_id,synced_at)
         VALUES(9011,?,'paid',100,100,'COP',UTC_TIMESTAMP())"
    )->execute([$externalOrder]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO meli_order_items
            (meli_order_id,meli_account_id,external_item_id,title,seller_sku,quantity,unit_price,sale_fee)
         VALUES(?,9011,?,?,?,1,100,10)"
    )->execute([$orderId, 'ITEM-' . $externalOrder, 'Order ' . $externalOrder, 'SKU-' . $externalOrder]);

    $saleKey = 'O:' . $externalOrder;
    $state = (new SaleFinancialStateService())->projectSale(9001, 9011, $saleKey);

    if ($sourceStatus === 'running') {
        $leaseSql = $liveLease
            ? 'DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)'
            : 'DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)';
        $pdo->prepare(
            "INSERT INTO sale_financial_reconciliation_jobs
             (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at,attempts,
              lock_owner,lease_generation,lease_expires_at,heartbeat_at)
             VALUES(9001,9011,?,?,?,'running','2000-01-01',4,'legacy-running-owner',7,{$leaseSql},DATE_SUB(UTC_TIMESTAMP(),INTERVAL 11 MINUTE))"
        )->execute([$saleKey, $externalOrder, $state['input_version']]);
    } else {
        $pdo->prepare(
            "INSERT INTO sale_financial_reconciliation_jobs
             (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at,attempts)
             VALUES(9001,9011,?,?,?,'retry','2000-01-01',3)"
        )->execute([$saleKey, $externalOrder, $state['input_version']]);
    }
    $sourceId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
         VALUES(9001,9011,'domain_exact',?,?,?,'waiting','2000-01-01')"
    )->execute([
        (string) $sourceId,
        'recovery-contract-' . $sourceId . '-' . bin2hex(random_bytes(4)),
        json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR),
    ]);

    return ['source_id' => $sourceId, 'queue_id' => (int) $pdo->lastInsertId(), 'order_id' => $externalOrder];
}

/** @return array<string,mixed> */
function recovery_source(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        'SELECT id,status,next_run_at,attempts,lock_owner,lease_generation,lease_expires_at,heartbeat_at
         FROM sale_financial_reconciliation_jobs WHERE id=? AND company_id=9001 AND meli_account_id=9011'
    );
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string,mixed> */
function recovery_pointer(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        'SELECT id,state,available_at,attempt_count,last_error_class,lease_owner,lease_generation,lease_expires_at
         FROM queue_v4_clean_jobs WHERE id=? AND company_id=9001 AND meli_account_id=9011'
    );
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

$options = getopt('', ['case:']);
$cases = ['retry_wakes', 'expired_running_not_woken', 'live_running_not_woken', 'running_not_claimable'];

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
        echo stream_get_contents($pipes[1]);
        fwrite(STDERR, stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($child) !== 0) {
            $exit = 1;
        }
    }
    exit($exit);
}

$case = (string) $options['case'];
recovery_assert(in_array($case, $cases, true), 'known_case');

$root = (string) getenv('CALLS_QA_STORAGE_ROOT');
recovery_assert($root !== '' && is_dir($root), 'explicit_owned_test_root');
putenv('DB_NAME=erp_meli_k1d_test_finrecovery_' . bin2hex(random_bytes(5)));
putenv('APP_KEY=synthetic-financial-recovery-only');
putenv('MELI_API_BASE=https://no-network.invalid');
putenv('PRIVATE_STORAGE_PATH=' . $root . '/private');

define('ERP_INSTALLATION_ROOT', $root . '/install-' . $case . '-' . bin2hex(random_bytes(3)));
mkdir(ERP_INSTALLATION_ROOT, 0770, true);

$harness = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $harness->pdo();
    (new App\Services\Migrator($pdo, __DIR__ . '/../database/migrations'))->run(301);
    recovery_seed_scope($pdo);

    if ($case === 'retry_wakes') {
        $f = recovery_seed_work($pdo, 'retry');
        $released = (new QueueV4CleanRepository($pdo))->releaseDueWaiting();
        $source = recovery_source($pdo, $f['source_id']);
        $pointer = recovery_pointer($pdo, $f['queue_id']);

        recovery_assert($released === 1, 'retry_released_once', ['released' => $released]);
        recovery_assert(($pointer['state'] ?? '') === 'ready', 'retry_pointer_ready', ['pointer' => $pointer]);
        recovery_assert(($source['status'] ?? '') === 'retry', 'retry_source_unchanged', ['source' => $source]);
        echo 'OBSERVED=' . json_encode(['case' => $case, 'released' => $released, 'source' => $source, 'pointer' => $pointer], JSON_THROW_ON_ERROR) . PHP_EOL;
    } elseif ($case === 'expired_running_not_woken') {
        $f = recovery_seed_work($pdo, 'running', false);
        $before = recovery_source($pdo, $f['source_id']);
        $released = (new QueueV4CleanRepository($pdo))->releaseDueWaiting();
        $after = recovery_source($pdo, $f['source_id']);
        $pointer = recovery_pointer($pdo, $f['queue_id']);

        echo 'OBSERVED=' . json_encode(['case' => $case, 'released' => $released, 'source_before' => $before, 'source_after' => $after, 'pointer' => $pointer], JSON_THROW_ON_ERROR) . PHP_EOL;
        recovery_assert($released === 0, 'expired_running_not_generically_released', ['released' => $released, 'pointer' => $pointer]);
        recovery_assert(($pointer['state'] ?? '') === 'waiting', 'expired_running_pointer_stays_waiting', ['pointer' => $pointer]);
        recovery_assert($after === $before, 'expired_running_source_unchanged');
    } elseif ($case === 'live_running_not_woken') {
        $f = recovery_seed_work($pdo, 'running', true);
        $before = recovery_source($pdo, $f['source_id']);
        $released = (new QueueV4CleanRepository($pdo))->releaseDueWaiting();
        $after = recovery_source($pdo, $f['source_id']);
        $pointer = recovery_pointer($pdo, $f['queue_id']);

        echo 'OBSERVED=' . json_encode(['case' => $case, 'released' => $released, 'source_before' => $before, 'source_after' => $after, 'pointer' => $pointer], JSON_THROW_ON_ERROR) . PHP_EOL;
        recovery_assert($released === 0, 'live_running_not_generically_released', ['released' => $released, 'pointer' => $pointer]);
        recovery_assert(($pointer['state'] ?? '') === 'waiting', 'live_running_pointer_stays_waiting', ['pointer' => $pointer]);
        recovery_assert($after === $before, 'live_running_source_unchanged');
    } else {
        $f = recovery_seed_work($pdo, 'running', false);
        $before = recovery_source($pdo, $f['source_id']);
        $result = (new SaleFinancialService())->processDomainExactBatch([$f['source_id']], 9001, 9011, true);
        $after = recovery_source($pdo, $f['source_id']);
        $pointer = recovery_pointer($pdo, $f['queue_id']);

        echo 'OBSERVED=' . json_encode(['case' => $case, 'result' => $result, 'source_before' => $before, 'source_after' => $after, 'pointer' => $pointer], JSON_THROW_ON_ERROR) . PHP_EOL;
        recovery_assert(($result['outcomes'] ?? null) === [], 'running_source_not_claimable');
        recovery_assert($after === $before, 'running_claim_attempt_source_unchanged');
        recovery_assert(($pointer['state'] ?? '') === 'waiting', 'running_claim_attempt_pointer_unchanged');
    }

    echo 'STATUS=PASS CASE=' . $case . ' MYSQL=REAL REAL_MELI_HTTP=0 REAL_OAUTH=0' . PHP_EOL;
} finally {
    $harness->cleanup();
}
