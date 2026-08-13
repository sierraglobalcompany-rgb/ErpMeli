<?php

declare(strict_types=1);

$dsn = (string) (getenv('QUEUE_V4_CLEAN_TEST_DSN') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_V4_CLEAN_TEST_DSN is required\n");
    exit(2);
}

$temporaryRoot = sys_get_temp_dir() . '/erp-qv4-capacity-' . bin2hex(random_bytes(5));
mkdir($temporaryRoot, 0700, true);
define('ERP_INSTALLATION_ROOT', $temporaryRoot);
define('ERP_RELEASE_ROOT', dirname(__DIR__));

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanProducer;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\CronDeadlineContext;
use App\Services\MeliReadClientInterface;

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
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$cleanOperational = static function () use ($pdo): void {
    $pdo->exec('DELETE FROM queue_v4_clean_attempts');
    $pdo->exec('DELETE FROM queue_v4_clean_jobs');
    $pdo->exec('DELETE FROM queue_v4_clean_runs');
    $pdo->exec(
        "UPDATE queue_v4_clean_leases
         SET owner_ref=NULL,acquired_at=NULL,heartbeat_at=NULL,expires_at=NULL
         WHERE lease_key='scheduler'"
    );
    $pdo->exec(
        "UPDATE queue_v4_clean_control
         SET engine_state='ACTIVE',readiness_state='CERTIFIED',scheduler_enabled=1
         WHERE control_key='primary'"
    );
};

$activeDepth = static function () use ($pdo): int {
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM queue_v4_clean_jobs
         WHERE state IN ('ready','running','waiting','review','dead')"
    )->fetchColumn();
};

try {
    $assert(
        (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version='295_inventory_warehouse_v1_2_38_0.sql'")->fetchColumn() === 1,
        'schema 295 prerequisite missing'
    );
    $assert(
        (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_readiness_accounts ra INNER JOIN queue_v4_clean_readiness_runs rr ON rr.id=ra.readiness_run_id WHERE rr.state='CERTIFIED' AND ra.outcome='PASS' AND rr.id=(SELECT MAX(id) FROM queue_v4_clean_readiness_runs WHERE state='CERTIFIED')")->fetchColumn() === 3,
        'certified three-account prerequisite missing'
    );

    $repository = new QueueV4CleanRepository($pdo);
    $producer = new QueueV4CleanProducer($pdo, $repository);
    $syncFactory = static fn (int $accountId): object => new class {
        public function syncOrderByIdForQueueV4Clean(string $orderId, array $authority): void {}
    };

    // Exact fan-out from one physical discovery GET. Each scenario starts
    // with an isolated queue so counts represent only that page's effect.
    foreach ([0, 1, 5, 20] as $fanout) {
        $cleanOperational();
        $ids = [];
        for ($index = 0; $index < $fanout; $index++) {
            $ids[] = ['id' => (string) (810000 + ($fanout * 100) + $index)];
        }
        $responses = [[
            'results' => $ids,
            'paging' => ['total' => $fanout, 'offset' => 0, 'limit' => 20],
        ]];
        $clientFactory = static function (int $accountId) use (&$responses): MeliReadClientInterface {
            return new class($responses) implements MeliReadClientInterface {
                public function __construct(private array &$responses) {}
                public function get(string $path, array $query = [], array $meta = []): array
                {
                    if ($path !== '/orders/search' || $this->responses === []) {
                        throw new RuntimeException('capacity_fixture_remote_contract');
                    }
                    return array_shift($this->responses);
                }
            };
        };
        $repository->enqueue(1, 1, 'fresh_orders_discovery', null, 'fanout:' . $fanout, [
            'from' => '2026-08-12T00:00:00+00:00',
            'to' => '2026-08-12T00:05:00+00:00',
            'offset' => 0,
            'limit' => 20,
        ]);
        $result = (new QueueV4CleanWorker($pdo, $repository, $clientFactory, $syncFactory))->run('test', 1, 10);
        $exact = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='order_exact' AND state='ready'")->fetchColumn();
        $assert($result['completed'] === 1 && $exact === $fanout, 'fanout ' . $fanout . ' was not exact');
    }

    // A full page creates twenty exact jobs plus one continuation. The second
    // page closes the same frontier and yields exactly five more exact jobs.
    $cleanOperational();
    $pageOne = [];
    $pageTwo = [];
    for ($index = 0; $index < 25; $index++) {
        if ($index < 20) {
            $pageOne[] = ['id' => (string) (820000 + $index)];
        } else {
            $pageTwo[] = ['id' => (string) (820000 + $index)];
        }
    }
    $responses = [
        ['results' => $pageOne, 'paging' => ['total' => 25, 'offset' => 0, 'limit' => 20]],
        ['results' => $pageTwo, 'paging' => ['total' => 25, 'offset' => 20, 'limit' => 20]],
    ];
    $clientFactory = static function (int $accountId) use (&$responses): MeliReadClientInterface {
        return new class($responses) implements MeliReadClientInterface {
            public function __construct(private array &$responses) {}
            public function get(string $path, array $query = [], array $meta = []): array
            {
                return array_shift($this->responses) ?: throw new RuntimeException('pagination_fixture_exhausted');
            }
        };
    };
    $repository->enqueue(1, 1, 'fresh_orders_discovery', null, 'pagination:root', [
        'from' => '2026-08-12T01:00:00+00:00', 'to' => '2026-08-12T01:05:00+00:00', 'offset' => 0, 'limit' => 20,
    ]);
    $pageWorker = new QueueV4CleanWorker($pdo, $repository, $clientFactory, $syncFactory);
    $pageWorker->run('test', 1, 10);
    $assert(
        (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='order_exact' AND state='ready'")->fetchColumn() === 20
        && (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='fresh_orders_discovery' AND state='ready'")->fetchColumn() === 1,
        'pagination first page authority invalid'
    );
    $pdo->exec("UPDATE queue_v4_clean_jobs SET state='completed',completed_at=UTC_TIMESTAMP(3) WHERE job_type='order_exact' AND state='ready'");
    $pageWorker->run('test', 1, 10);
    $assert(
        (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='order_exact'")->fetchColumn() === 25
        && (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='fresh_orders_discovery' AND state='ready'")->fetchColumn() === 0,
        'pagination continuation was not exact'
    );

    // Backpressure: forcing checkpoints due cannot mint another frontier while
    // one discovery/page remains unfinished for that exact tenant.
    $cleanOperational();
    $pdo->exec("UPDATE queue_v4_clean_checkpoints SET next_due_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE producer_key='fresh_orders'");
    $first = $producer->produce();
    $pdo->exec("UPDATE queue_v4_clean_checkpoints SET next_due_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE producer_key='fresh_orders'");
    $second = $producer->produce();
    $assert($first['created'] === 3 && $second['created'] === 0, 'tenant frontier backpressure failed');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='fresh_orders_discovery' AND state='ready'")->fetchColumn() === 3, 'more than one frontier per tenant exists');

    // Completed discovery with pending exact children is still the same open
    // round. A new fresh window must wait until those jobs drain.
    $pdo->exec(
        "UPDATE queue_v4_clean_jobs
         SET state='completed',completed_at=UTC_TIMESTAMP(3)
         WHERE job_type='fresh_orders_discovery' AND company_id=1 AND meli_account_id=1"
    );
    $repository->enqueue(1, 1, 'order_exact', '825001', 'round-child:825001', ['order_id' => '825001']);
    $pdo->exec("UPDATE queue_v4_clean_checkpoints SET next_due_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE producer_key='fresh_orders'");
    $producer->produce();
    $assert(
        (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='fresh_orders_discovery' AND company_id=1 AND meli_account_id=1 AND state='ready'")->fetchColumn() === 0,
        'new fresh frontier was created while an exact child remained pending'
    );

    // Worker ceiling is fifteen per run, aligned with the current application
    // read budget of 225/15 min. FIFO remains available_at,id and unchanged.
    $cleanOperational();
    for ($index = 0; $index < 20; $index++) {
        $repository->enqueue(1, 1, 'order_exact', (string) (830000 + $index), 'ceiling:' . $index, ['order_id' => (string) (830000 + $index)]);
    }
    $seen = [];
    $fastWorker = new QueueV4CleanWorker($pdo, $repository, null, null, static function (array $job) use (&$seen): void {
        $seen[] = (int) $job['id'];
    });
    $ceiling = $fastWorker->run('test', 999, 45);
    $sorted = $seen;
    sort($sorted, SORT_NUMERIC);
    $assert($ceiling['claimed'] === 15 && $ceiling['completed'] === 15, 'worker hard ceiling is not fifteen');
    $assert($seen === $sorted, 'FIFO order changed under increased capacity');

    // Five-second test window models the production deadline contract. Work
    // stops before the close margin; an in-flight fixture is never overlapped.
    $cleanOperational();
    for ($index = 0; $index < 10; $index++) {
        $repository->enqueue(1, 1, 'order_exact', (string) (840000 + $index), 'deadline:' . $index, ['order_id' => (string) (840000 + $index)]);
    }
    CronDeadlineContext::start(5, 3, 2, 1);
    $started = microtime(true);
    try {
        $deadlineResult = (new QueueV4CleanWorker($pdo, $repository, null, null, static function (): void {
            usleep(800000);
        }))->run('test', 15, 5);
    } finally {
        CronDeadlineContext::clear();
    }
    $elapsed = microtime(true) - $started;
    $assert($elapsed < 5.0 && $deadlineResult['claimed'] < 15, 'deadline budget was exceeded');

    // Backlog 500, zero incoming business traffic. The first cycle may add
    // exactly three discovery frontiers; backpressure then keeps production at
    // zero until those FIFO entries are reached. Net slope stays negative.
    $cleanOperational();
    for ($index = 0; $index < 500; $index++) {
        $accountId = ($index % 3) + 1;
        $companyId = [1 => 1, 2 => 4, 3 => 5][$accountId];
        $repository->enqueue($companyId, $accountId, 'order_exact', (string) (850000 + $index), 'backlog-zero:' . $index, ['order_id' => (string) (850000 + $index)]);
    }
    $pdo->exec("UPDATE queue_v4_clean_checkpoints SET next_due_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE producer_key='fresh_orders'");
    $drainWorker = new QueueV4CleanWorker($pdo, $repository, null, null, static fn (array $job): null => null);
    $zeroSeries = [$activeDepth()];
    for ($minute = 0; $minute < 10; $minute++) {
        $producer->produce();
        $drainWorker->run('test', 15, 45);
        $zeroSeries[] = $activeDepth();
    }
    $assert($zeroSeries[10] < $zeroSeries[0] && $zeroSeries[10] <= 353, 'backlog 500 did not converge with zero traffic');

    // Moderate traffic fixture: five new exact jobs per simulated minute.
    // Service 15/min remains above bounded input and backlog decreases.
    $cleanOperational();
    for ($index = 0; $index < 500; $index++) {
        $repository->enqueue(1, 1, 'order_exact', (string) (860000 + $index), 'backlog-moderate:' . $index, ['order_id' => (string) (860000 + $index)]);
    }
    $pdo->exec("UPDATE queue_v4_clean_checkpoints SET next_due_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE producer_key='fresh_orders'");
    $moderateSeries = [$activeDepth()];
    for ($minute = 0; $minute < 10; $minute++) {
        for ($arrival = 0; $arrival < 5; $arrival++) {
            $id = 870000 + ($minute * 10) + $arrival;
            $repository->enqueue(1, 1, 'order_exact', (string) $id, 'moderate-arrival:' . $id, ['order_id' => (string) $id]);
        }
        $producer->produce();
        $drainWorker->run('test', 15, 45);
        $moderateSeries[] = $activeDepth();
    }
    $assert($moderateSeries[10] < $moderateSeries[0] && $moderateSeries[10] <= 403, 'backlog 500 did not converge with moderate traffic');

    echo json_encode([
        'ok' => true,
        'checks' => $checks,
        'worker_hard_cap' => QueueV4CleanWorker::HARD_MAX_JOBS,
        'fanout_cases' => [0, 1, 5, 20],
        'pagination_total' => 25,
        'backpressure' => 'PASS',
        'fifo' => 'PASS',
        'deadline_elapsed_seconds' => round($elapsed, 3),
        'backlog_zero_traffic' => $zeroSeries,
        'backlog_moderate_traffic' => $moderateSeries,
        'remote_business_writes' => 0,
        'raw_storage_touched' => false,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    CronDeadlineContext::clear();
    @rmdir($temporaryRoot);
}
