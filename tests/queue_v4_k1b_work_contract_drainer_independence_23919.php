<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\CanonicalWorkContract;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: K1B exige un DSN MariaDB desechable sin dbname.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_k1b_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-k1b-' . bin2hex(random_bytes(5));
@mkdir($temporary, 0770, true);

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';

try {
    putenv('APP_KEY=queue-v4-k1b-local-only');
    $_ENV['APP_KEY'] = 'queue-v4-k1b-local-only';
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['ML_WRITE_ENABLED'] = 'false';
    define('ERP_SHARED_ROOT', $temporary);
    spl_autoload_register(static function (string $class) use ($root): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });

    $server = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $server->exec('CREATE DATABASE ' . $quote($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone='+00:00'");
    Database::setConnection($pdo);
    $pdo->exec("CREATE TABLE companies(id BIGINT UNSIGNED NOT NULL PRIMARY KEY,name VARCHAR(191) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_accounts(id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_user_id VARCHAR(64) NULL,account_name VARCHAR(191) NULL,status VARCHAR(32) NOT NULL,PRIMARY KEY(id),UNIQUE KEY uq_meli_accounts_company_id(company_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_control(control_key VARCHAR(32) NOT NULL PRIMARY KEY,engine_state ENUM('STOPPED','CERTIFIED','ACTIVE') NOT NULL DEFAULT 'STOPPED',readiness_state ENUM('NOT_READY','READY_TO_TEST','TESTING','CERTIFIED','FAILED') NOT NULL DEFAULT 'NOT_READY',scheduler_enabled TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_jobs(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,job_type ENUM('fresh_orders_discovery','order_exact','domain_exact') NOT NULL,resource_id VARCHAR(191) NULL,idempotency_key VARCHAR(191) NOT NULL,state ENUM('ready','running','waiting','review','dead','completed') NOT NULL DEFAULT 'ready',attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),lease_owner VARCHAR(96) NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,lease_expires_at DATETIME(3) NULL,payload_json JSON NOT NULL,last_error_class VARCHAR(100) NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),completed_at DATETIME(3) NULL,PRIMARY KEY(id),UNIQUE KEY uq_qv4_job_identity(company_id,meli_account_id,job_type,idempotency_key),KEY idx_qv4_fifo(state,available_at,id),KEY idx_qv4_tenant(company_id,meli_account_id,state,id),KEY idx_qv4_lease(state,lease_expires_at,id),UNIQUE KEY uq_qv4_job_tenant_id(id,company_id,meli_account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_attempts(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,job_id BIGINT UNSIGNED NOT NULL,run_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,lease_owner VARCHAR(96) NOT NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,outcome ENUM('running','completed','waiting','review','dead','lease_expired') NOT NULL DEFAULT 'running',error_class VARCHAR(100) NULL,dispatch_state ENUM('NOT_DISPATCHED','DISPATCHED','KNOWN','UNKNOWN') NOT NULL DEFAULT 'NOT_DISPATCHED',started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL,source_closed_at DATETIME(3) NULL,PRIMARY KEY(id),UNIQUE KEY uq_qv4_attempt(job_id,lease_owner),KEY idx_qv4_attempt_tenant(company_id,meli_account_id,started_at),KEY idx_qv4_attempt_run(run_id,company_id,meli_account_id),CONSTRAINT fk_qv4_attempt_job FOREIGN KEY(job_id) REFERENCES queue_v4_clean_jobs(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_runs(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,launcher ENUM('scheduler','manual','test') NOT NULL,worker_ref VARCHAR(96) NOT NULL,status ENUM('running','completed','failed','stopped') NOT NULL DEFAULT 'running',jobs_claimed INT UNSIGNED NOT NULL DEFAULT 0,jobs_completed INT UNSIGNED NOT NULL DEFAULT 0,jobs_deferred INT UNSIGNED NOT NULL DEFAULT 0,started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL,PRIMARY KEY(id),KEY idx_qv4_runs_status(status,started_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_leases(lease_key VARCHAR(64) NOT NULL PRIMARY KEY,owner_ref VARCHAR(96) NULL,acquired_at DATETIME(3) NULL,heartbeat_at DATETIME(3) NULL,expires_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,readiness_state,scheduler_enabled) VALUES ('primary','ACTIVE','CERTIFIED',1)");
    $pdo->exec("INSERT INTO companies(id,name) VALUES (1,'K1B')");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,status) VALUES (1,1,1001,'K1B','conectado')");

    $repo = new QueueV4CleanRepository($pdo);
    $seen = [];
    for ($i = 1; $i <= 100; $i++) {
        $repo->enqueue(1, 1, 'order_exact', (string) $i, 'k1b:' . $i, ['order_id' => (string) $i]);
    }
    $worker = new QueueV4CleanWorker($pdo, $repo, null, null, static function (array $job) use (&$seen): void {
        $seen[] = (int) $job['resource_id'];
    });
    $v4a = $worker->run('scheduler', 15, 10);
    $v4b = $worker->run('scheduler', 10, 10);
    $v4 = [
        'claimed' => $v4a['claimed'] + $v4b['claimed'],
        'completed' => $v4a['completed'] + $v4b['completed'],
        'deferred' => $v4a['deferred'] + $v4b['deferred'],
    ];

    $manualDrain = static function (QueueV4CleanRepository $repository, string $launcher, string $owner, int $limit) use (&$seen): array {
        if (!$repository->acquireDrainAuthority($owner)) {
            return ['claimed' => 0, 'completed' => 0, 'deferred' => 0];
        }
        $runId = $repository->beginRun($launcher, $owner);
        $claimed = $completed = 0;
        try {
            while ($claimed < $limit) {
                $job = $repository->claim($runId, $owner, 60);
                if ($job === null) {
                    break;
                }
                $seen[] = (int) $job['resource_id'];
                $repository->complete($job, $runId);
                $claimed++;
                $completed++;
            }
            $repository->finishRun($runId, 'completed');
            return ['claimed' => $claimed, 'completed' => $completed, 'deferred' => 0];
        } finally {
            $repository->releaseDrainAuthority($owner);
        }
    };
    $manual = $manualDrain($repo, 'manual', 'k1b-manual', 25);
    $future = $manualDrain($repo, 'test', 'k1b-future', 50);
    $assert($v4['claimed'] === 25 && $manual['claimed'] === 25 && $future['claimed'] === 50, 'K1B_HANDOFF_COUNTS');
    $assert($seen === range(1, 100), 'K1B_FIFO_ORDER_EXACT');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state<>"completed"')->fetchColumn() === 0, 'K1B_ALL_COMPLETED_ONCE');

    $repo->enqueue(1, 1, 'order_exact', '201', 'k1b-race-a', ['order_id' => '201']);
    $assert($repo->acquireDrainAuthority('k1b-winner'), 'K1B_WINNER_ACQUIRED');
    $assert(!$repo->acquireDrainAuthority('k1b-loser'), 'K1B_LOSER_REJECTED');
    $loserRun = $repo->beginRun('manual', 'k1b-loser');
    $assert($repo->claim($loserRun, 'k1b-loser', 60) === null, 'K1B_LOSER_CLAIMED_WORK');
    $repo->finishRun($loserRun, 'stopped');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_attempts WHERE lease_owner='k1b-loser'")->fetchColumn() === 0, 'K1B_LOSER_MUTATED_ATTEMPTS');
    $repo->releaseDrainAuthority('k1b-winner');

    $repo->enqueue(1, 1, 'order_exact', '301', 'k1b-stale', ['order_id' => '301']);
    $assert($repo->acquireDrainAuthority('k1b-stale-owner'), 'K1B_STALE_OWNER_ACQUIRED');
    $pdo->exec("UPDATE queue_v4_clean_leases SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE lease_key='" . CanonicalWorkContract::DRAIN_LEASE_KEY . "'");
    $assert($repo->acquireDrainAuthority('k1b-takeover'), 'K1B_TAKEOVER_ACQUIRED');
    $staleRun = $repo->beginRun('test', 'k1b-stale-owner');
    $assert($repo->compactLocalFifoPrefix($staleRun, 'k1b-stale-owner', 60)['claimed'] === 0, 'K1B_STALE_COMPACTED');
    $assert($repo->claim($staleRun, 'k1b-stale-owner', 60) === null, 'K1B_STALE_CLAIMED');
    $repo->finishRun($staleRun, 'stopped');
    $repo->releaseDrainAuthority('k1b-takeover');

    $pdo->exec('DELETE FROM queue_v4_clean_attempts');
    $pdo->exec('DELETE FROM queue_v4_clean_jobs');
    $badPayload = json_encode(['capability' => 'unsupported'], JSON_THROW_ON_ERROR);
    $goodPayload = json_encode(['order_id' => '402'], JSON_THROW_ON_ERROR);
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json)
         VALUES (1,1,'domain_exact','401','k1b-unsupported-head',?),(1,1,'order_exact','402','k1b-supported-behind',?)"
    )->execute([$badPayload, $goodPayload]);
    $assert($repo->acquireDrainAuthority('k1b-unsupported'), 'K1B_UNSUPPORTED_AUTHORITY_ACQUIRED');
    $unsupportedRun = $repo->beginRun('test', 'k1b-unsupported');
    $assert($repo->claim($unsupportedRun, 'k1b-unsupported', 60) === null, 'K1B_UNSUPPORTED_HEAD_CLAIMED');
    $repo->finishRun($unsupportedRun, 'stopped');
    $repo->releaseDrainAuthority('k1b-unsupported');
    $states = $pdo->query('SELECT resource_id,state FROM queue_v4_clean_jobs ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert($states === ['401' => 'ready', '402' => 'ready'], 'K1B_UNSUPPORTED_LEAPFROGGED');

    echo "CANONICAL_WORK_CONTRACT_VERSION=" . CanonicalWorkContract::VERSION . PHP_EOL;
    echo "CANONICAL_FIFO_COUNT=1" . PHP_EOL;
    echo "ACTIVE_FIFO_DRAINERS_MAX=1" . PHP_EOL;
    echo "K1B_HANDOFF_FIFO=PASS" . PHP_EOL;
    echo "K1B_DRAINER_SINGLETON=PASS" . PHP_EOL;
    echo "K1B_STALE_OWNER_FENCED=PASS" . PHP_EOL;
    echo "K1B_UNSUPPORTED_HEAD_FAIL_CLOSED=PASS" . PHP_EOL;
    echo "REAL_MELI_HTTP=0" . PHP_EOL;
    echo "CHECKS={$checks}" . PHP_EOL;
} finally {
    if (isset($server)) {
        $server->exec('DROP DATABASE IF EXISTS ' . $quote($database));
    }
}
