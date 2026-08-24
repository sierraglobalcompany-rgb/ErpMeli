<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\CanonicalFifoDrainer;
use App\QueueV4Clean\CanonicalWorkContract;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$connect = static function (string $dsn, string $user, string $pass): PDO {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone='+00:00'");
    return $pdo;
};

if (($argv[1] ?? '') === '--race-child') {
    putenv('APP_KEY=queue-v4-k1b-race-local-only');
    $_ENV['APP_KEY'] = 'queue-v4-k1b-race-local-only';
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['ML_WRITE_ENABLED'] = 'false';
    $pdo = $connect((string) getenv('K1B_RACE_DSN'), (string) getenv('K1B_RACE_USER'), (string) getenv('K1B_RACE_PASS'));
    $repo = new QueueV4CleanRepository($pdo);
    $owner = (string) getenv('K1B_RACE_OWNER');
    $start = (float) getenv('K1B_RACE_START');
    while (microtime(true) < $start) {
        usleep(1000);
    }
    $executed = 0;
    $drainer = new CanonicalFifoDrainer($pdo, $repo, null, null, static function (array $job) use (&$executed): void {
        $executed++;
    });
    $readyBefore = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state='ready' AND available_at<=UTC_TIMESTAMP(3)")->fetchColumn();
    $control = $repo->control();
    $result = $drainer->run('test', 1, 10);
    echo json_encode([
        'owner' => $owner,
        'ready_before' => $readyBefore,
        'control' => [(string) ($control['engine_state'] ?? ''), (string) ($control['readiness_state'] ?? '')],
        'result' => $result,
        'metrics' => $drainer->lastMetrics(),
        'executed' => $executed,
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: K1B exige un DSN MariaDB desechable sin dbname.\n");
    exit(2);
}

$database = 'erp_k1b_r1_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-k1b-r1-' . bin2hex(random_bytes(5));
@mkdir($temporary, 0770, true);

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';

$seedOrder = static function (QueueV4CleanRepository $repo, int $from, int $to, string $prefix = 'k1b'): void {
    for ($i = $from; $i <= $to; $i++) {
        $repo->enqueue(1, 1, 'order_exact', (string) $i, $prefix . ':' . $i, ['order_id' => (string) $i]);
    }
};

$resetQueue = static function (PDO $pdo): void {
    $pdo->exec('DELETE FROM queue_v4_clean_attempts');
    $pdo->exec('DELETE FROM queue_v4_clean_runs');
    $pdo->exec('DELETE FROM queue_v4_clean_jobs');
    $pdo->exec('DELETE FROM queue_v4_clean_leases');
};

try {
    putenv('APP_KEY=queue-v4-k1b-r1-local-only');
    $_ENV['APP_KEY'] = 'queue-v4-k1b-r1-local-only';
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['ML_WRITE_ENABLED'] = 'false';
    define('ERP_SHARED_ROOT', $temporary);

    $server = $connect($dsn, $user, $pass);
    $server->exec('CREATE DATABASE ' . $quote($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = $connect($dsn . ';dbname=' . $database, $user, $pass);
    Database::setConnection($pdo);

    $pdo->exec("CREATE TABLE companies(id BIGINT UNSIGNED NOT NULL PRIMARY KEY,name VARCHAR(191) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_accounts(id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_user_id VARCHAR(64) NULL,account_name VARCHAR(191) NULL,status VARCHAR(32) NOT NULL,PRIMARY KEY(id),UNIQUE KEY uq_meli_accounts_company_id(company_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_control(control_key VARCHAR(32) NOT NULL PRIMARY KEY,engine_state ENUM('STOPPED','CERTIFIED','ACTIVE') NOT NULL DEFAULT 'STOPPED',readiness_state ENUM('NOT_READY','READY_TO_TEST','TESTING','CERTIFIED','FAILED') NOT NULL DEFAULT 'NOT_READY',scheduler_enabled TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_jobs(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,job_type ENUM('fresh_orders_discovery','order_exact','domain_exact') NOT NULL,resource_id VARCHAR(191) NULL,idempotency_key VARCHAR(191) NOT NULL,state ENUM('ready','running','waiting','review','dead','completed') NOT NULL DEFAULT 'ready',attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),lease_owner VARCHAR(96) NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,lease_expires_at DATETIME(3) NULL,payload_json JSON NOT NULL,last_error_class VARCHAR(100) NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),completed_at DATETIME(3) NULL,PRIMARY KEY(id),UNIQUE KEY uq_qv4_job_identity(company_id,meli_account_id,job_type,idempotency_key),KEY idx_qv4_fifo(state,available_at,id),KEY idx_qv4_tenant(company_id,meli_account_id,state,id),KEY idx_qv4_lease(state,lease_expires_at,id),UNIQUE KEY uq_qv4_job_tenant_id(id,company_id,meli_account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_attempts(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,job_id BIGINT UNSIGNED NOT NULL,run_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,lease_owner VARCHAR(96) NOT NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,outcome ENUM('running','completed','waiting','review','dead','lease_expired') NOT NULL DEFAULT 'running',error_class VARCHAR(100) NULL,dispatch_state ENUM('NOT_DISPATCHED','DISPATCHED','KNOWN','UNKNOWN') NOT NULL DEFAULT 'NOT_DISPATCHED',started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL,source_closed_at DATETIME(3) NULL,PRIMARY KEY(id),UNIQUE KEY uq_qv4_attempt(job_id,lease_owner),KEY idx_qv4_attempt_tenant(company_id,meli_account_id,started_at),KEY idx_qv4_attempt_run(run_id,company_id,meli_account_id),CONSTRAINT fk_qv4_attempt_job FOREIGN KEY(job_id) REFERENCES queue_v4_clean_jobs(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_runs(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,launcher ENUM('scheduler','manual','test') NOT NULL,worker_ref VARCHAR(96) NOT NULL,status ENUM('running','completed','failed','stopped') NOT NULL DEFAULT 'running',jobs_claimed INT UNSIGNED NOT NULL DEFAULT 0,jobs_completed INT UNSIGNED NOT NULL DEFAULT 0,jobs_deferred INT UNSIGNED NOT NULL DEFAULT 0,started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL,PRIMARY KEY(id),KEY idx_qv4_runs_status(status,started_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_leases(lease_key VARCHAR(64) NOT NULL PRIMARY KEY,owner_ref VARCHAR(96) NULL,acquired_at DATETIME(3) NULL,heartbeat_at DATETIME(3) NULL,expires_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,readiness_state,scheduler_enabled) VALUES ('primary','ACTIVE','CERTIFIED',1)");
    $pdo->exec("INSERT INTO companies(id,name) VALUES (1,'K1B R1')");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,status) VALUES (1,1,1001,'K1B R1','conectado')");

    $repo = new QueueV4CleanRepository($pdo);
    $seen = [];
    $seedOrder($repo, 1, 100);
    $handler = static function (array $job) use (&$seen): void {
        $seen[] = (int) $job['resource_id'];
    };
    $v4Worker = new QueueV4CleanWorker($pdo, $repo, null, null, $handler);
    $manualKernel = new CanonicalFifoDrainer($pdo, $repo, null, null, $handler);
    $futureKernel = new CanonicalFifoDrainer($pdo, $repo, null, null, $handler);

    $drainUntil = static function (object $drainer, string $launcher, int $target): int {
        $claimed = 0;
        for ($turn = 0; $claimed < $target && $turn < 50; $turn++) {
            /** @var array{claimed:int,completed:int,deferred:int} $result */
            $result = $drainer->run($launcher, min(15, $target - $claimed), 10);
            if ($result['claimed'] < 1) {
                break;
            }
            $claimed += $result['claimed'];
        }
        return $claimed;
    };
    $phaseClaimed = [
        'v4' => $drainUntil($v4Worker, 'scheduler', 25),
        'manual' => $drainUntil($manualKernel, 'manual', 25),
        'future' => $drainUntil($futureKernel, 'test', 50),
    ];
    $assert($phaseClaimed === ['v4' => 25, 'manual' => 25, 'future' => 50], 'K1B_R1_HANDOFF_COUNTS:' . json_encode($phaseClaimed));
    $assert($seen === range(1, 100), 'K1B_R1_FIFO_ORDER_EXACT');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state<>"completed"')->fetchColumn() === 0, 'K1B_R1_ALL_COMPLETED_ONCE');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_attempts WHERE outcome='completed'")->fetchColumn() === 100, 'K1B_R1_ATTEMPT_COUNT');

    $resetQueue($pdo);
    $raceWins = $raceLoserClaimed = $raceLoserExecuted = 0;
    for ($round = 1; $round <= 20; $round++) {
        $repo->enqueue(1, 1, 'order_exact', (string) (1000 + $round), 'race:' . $round, ['order_id' => (string) (1000 + $round)]);
        $start = microtime(true) + 0.35;
        $outputs = [];
        $processes = [];
        foreach (['a', 'b'] as $suffix) {
            $owner = 'race-' . $round . '-' . $suffix;
            $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $env = array_merge(getenv() ?: [], [
                'K1B_RACE_DSN' => $dsn . ';dbname=' . $database,
                'K1B_RACE_USER' => $user,
                'K1B_RACE_PASS' => $pass,
                'K1B_RACE_OWNER' => $owner,
                'K1B_RACE_START' => (string) $start,
                'APP_KEY' => 'queue-v4-k1b-race-local-only',
                'ML_WRITE_ENABLED' => 'false',
            ]);
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --race-child';
            $process = proc_open($cmd, $descriptor, $pipes, $root, $env);
            if (!is_resource($process)) {
                throw new RuntimeException('K1B_R1_RACE_PROC_OPEN');
            }
            $processes[] = [$process, $pipes, $owner];
        }
        foreach ($processes as [$process, $pipes, $owner]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if ($exit !== 0) {
                throw new RuntimeException('K1B_R1_RACE_CHILD_FAILED:' . $owner . ':' . $stderr);
            }
            $decoded = json_decode(trim((string) $stdout), true, 16, JSON_THROW_ON_ERROR);
            $outputs[] = $decoded;
        }
        $roundWinners = 0;
        foreach ($outputs as $output) {
            $claimed = (int) ($output['result']['claimed'] ?? 0);
            $executed = (int) ($output['executed'] ?? 0);
            if ($claimed === 1) {
                $roundWinners++;
                $raceWins++;
            } else {
                $raceLoserClaimed += $claimed;
                $raceLoserExecuted += $executed;
            }
        }
        $assert($roundWinners === 1, 'K1B_R1_RACE_ONE_WINNER_ROUND_' . $round . ':' . json_encode($outputs));
    }
    $assert($raceWins === 20, 'K1B_R1_RACE_WIN_COUNT');
    $assert($raceLoserClaimed === 0 && $raceLoserExecuted === 0, 'K1B_R1_RACE_LOSER_MUTATION');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state='completed'")->fetchColumn() === 20, 'K1B_R1_RACE_COMPLETED');

    $resetQueue($pdo);
    $repo->enqueue(1, 1, 'order_exact', '301', 'takeover:301', ['order_id' => '301']);
    $assert($repo->acquireDrainAuthority('owner-a'), 'K1B_R1_OWNER_A_AUTHORITY');
    $runA = $repo->beginRun('test', 'owner-a');
    $jobA = $repo->claim($runA, 'owner-a', 60);
    $assert(is_array($jobA), 'K1B_R1_OWNER_A_CLAIM');
    $pdo->exec("UPDATE queue_v4_clean_leases SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE lease_key='" . CanonicalWorkContract::DRAIN_LEASE_KEY . "'");
    $assert(!$repo->acquireDrainAuthority('owner-b'), 'K1B_R1_TAKEOVER_DENIED_WITH_LIVE_JOB');
    $pdo->exec("UPDATE queue_v4_clean_jobs SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id=" . (int) $jobA['id']);
    $assert($repo->expireLeases() === 1, 'K1B_R1_LEASE_EXPIRED');
    $pdo->exec("UPDATE queue_v4_clean_jobs SET available_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id=" . (int) $jobA['id']);
    $repo->releaseDueWaiting();
    $takeoverSeen = [];
    $takeover = new CanonicalFifoDrainer($pdo, $repo, null, null, static function (array $job) use (&$takeoverSeen): void {
        $takeoverSeen[] = (int) $job['resource_id'];
    });
    $takeoverResult = $takeover->run('test', 1, 10);
    $assert($takeoverResult['claimed'] === 1 && $takeoverSeen === [301], 'K1B_R1_TAKEOVER_AFTER_JOB_LEASE_EXPIRY');

    $resetQueue($pdo);
    $payloadV2 = json_encode(['order_id' => '401', CanonicalWorkContract::PAYLOAD_VERSION_KEY => 2], JSON_THROW_ON_ERROR);
    $payloadV1 = json_encode(['order_id' => '402', CanonicalWorkContract::PAYLOAD_VERSION_KEY => 1], JSON_THROW_ON_ERROR);
    $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json) VALUES (1,1,'order_exact','401','unsupported-version-head',?),(1,1,'order_exact','402','valid-behind-version',?)")->execute([$payloadV2, $payloadV1]);
    $assert($repo->acquireDrainAuthority('unsupported-version'), 'K1B_R1_UNSUPPORTED_VERSION_AUTH');
    $unsupportedRun = $repo->beginRun('test', 'unsupported-version');
    $assert($repo->compactLocalFifoPrefix($unsupportedRun, 'unsupported-version', 60)['claimed'] === 0, 'K1B_R1_UNSUPPORTED_VERSION_COMPACTED');
    $assert($repo->claim($unsupportedRun, 'unsupported-version', 60) === null, 'K1B_R1_UNSUPPORTED_VERSION_CLAIMED');
    $repo->finishRun($unsupportedRun, 'stopped');
    $repo->releaseDrainAuthority('unsupported-version');
    $states = $pdo->query('SELECT resource_id,state FROM queue_v4_clean_jobs ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert($states === ['401' => 'ready', '402' => 'ready'], 'K1B_R1_UNSUPPORTED_VERSION_LEAPFROG');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_attempts')->fetchColumn() === 0, 'K1B_R1_UNSUPPORTED_VERSION_ATTEMPT');

    $resetQueue($pdo);
    $payloadFinancial = json_encode(['capability' => 'financial_recalc', 'source_id' => 501, CanonicalWorkContract::PAYLOAD_VERSION_KEY => 1], JSON_THROW_ON_ERROR);
    $payloadOrder = json_encode(['order_id' => '502', CanonicalWorkContract::PAYLOAD_VERSION_KEY => 1], JSON_THROW_ON_ERROR);
    $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json) VALUES (1,1,'domain_exact','501','unsupported-type-head',?),(1,1,'order_exact','502','valid-behind-type',?)")->execute([$payloadFinancial, $payloadOrder]);
    $assert($repo->acquireDrainAuthority('unsupported-type'), 'K1B_R1_UNSUPPORTED_TYPE_AUTH');
    $unsupportedTypeRun = $repo->beginRun('test', 'unsupported-type');
    $assert($repo->claim($unsupportedTypeRun, 'unsupported-type', 60, CanonicalWorkContract::VERSION, [CanonicalWorkContract::TYPE_ORDER_EXACT]) === null, 'K1B_R1_UNSUPPORTED_TYPE_CLAIMED');
    $repo->finishRun($unsupportedTypeRun, 'stopped');
    $repo->releaseDrainAuthority('unsupported-type');
    $states = $pdo->query('SELECT resource_id,state FROM queue_v4_clean_jobs ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert($states === ['501' => 'ready', '502' => 'ready'], 'K1B_R1_UNSUPPORTED_TYPE_LEAPFROG');

    echo "CANONICAL_WORK_CONTRACT_VERSION=" . CanonicalWorkContract::VERSION . PHP_EOL;
    echo "CANONICAL_FIFO_COUNT=1" . PHP_EOL;
    echo "ACTIVE_FIFO_DRAINERS_MAX=1" . PHP_EOL;
    echo "K1B_REAL_SHARED_KERNEL=PASS" . PHP_EOL;
    echo "K1B_HANDOFF_FIFO=PASS" . PHP_EOL;
    echo "K1B_REAL_TWO_PROCESS_RACE=PASS" . PHP_EOL;
    echo "K1B_RACE_ROUNDS=20" . PHP_EOL;
    echo "K1B_LOSER_CLAIMED=0" . PHP_EOL;
    echo "K1B_LOSER_EXECUTED=0" . PHP_EOL;
    echo "K1B_TAKEOVER_DENIED_WITH_LIVE_JOB=PASS" . PHP_EOL;
    echo "K1B_TAKEOVER_AFTER_JOB_LEASE_EXPIRY=PASS" . PHP_EOL;
    echo "K1B_UNSUPPORTED_VERSION_FAIL_CLOSED=PASS" . PHP_EOL;
    echo "K1B_UNSUPPORTED_TYPE_FAIL_CLOSED=PASS" . PHP_EOL;
    echo "MANUAL_DIRECT_COMPLETE_WITHOUT_EXECUTION=0" . PHP_EOL;
    echo "REAL_MELI_HTTP=0" . PHP_EOL;
    echo "CHECKS={$checks}" . PHP_EOL;
} finally {
    if (isset($server)) {
        $server->exec('DROP DATABASE IF EXISTS ' . $quote($database));
    }
}
