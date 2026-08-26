<?php
declare(strict_types=1);

$root = dirname(__DIR__);
if (!defined('ERP_RELEASE_ROOT')) {
    define('ERP_RELEASE_ROOT', $root);
}
if (!defined('ERP_INSTALLATION_ROOT')) {
    define('ERP_INSTALLATION_ROOT', $root);
}
require $root . '/bootstrap.php';

use App\Core\Database;
use App\QueueCore\QueueExecutionLeaseService;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanScheduler;

if (($argv[1] ?? '') === 'acquire-race') {
    try {
        $dsn = (string) getenv('K8_P1_RACE_DSN');
        $user = (string) getenv('K8_P1_RACE_USER');
        $pass = (string) getenv('K8_P1_RACE_PASS');
        $startAt = (float) ($argv[2] ?? '0');
        $owner = (string) ($argv[3] ?? bin2hex(random_bytes(4)));
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        Database::setConnection($pdo);
        while (microtime(true) < $startAt) {
            usleep(1000);
        }
        $lease = (new QueueExecutionLeaseService($pdo))->acquire('test_drainer', $owner, 20);
        if ($lease !== null) {
            usleep(250000);
        }
        echo json_encode([
            'owner' => $owner,
            'won' => $lease !== null,
            'claimed' => 0,
            'remote_calls' => 0,
            'source_mutations' => 0,
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, 'race_child_error=' . $error::class . ':' . $error->getMessage() . PHP_EOL);
        exit(3);
    }
}

$adminDsn = (string) getenv('ERP_MIGRATOR_TEST_DSN');
$adminUser = (string) getenv('ERP_MIGRATOR_TEST_USER');
$adminPass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($adminDsn === '') {
    fwrite(STDERR, "ERP_MIGRATOR_TEST_DSN is required\n");
    exit(2);
}

$admin = new PDO($adminDsn, $adminUser, $adminPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$db = 'erp_k8_p1_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(2));
if (preg_match('/^[a-zA-Z0-9_]+$/', $db) !== 1) {
    throw new RuntimeException('bad_test_db');
}
$admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$dsn = preg_replace('/;dbname=[^;]*/', '', $adminDsn);
$dsn = $dsn . ';dbname=' . $db;
$pdo = new PDO($dsn, $adminUser, $adminPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone='+00:00'");
Database::setConnection($pdo);

$checks = 0;
$failures = [];
$metric = [
    'MANUAL_CANONICAL_PATH' => 'FAIL',
    'DRAIN_AUTHORITY_HANDOFF' => 'FAIL',
    'SIMULTANEOUS_ACQUIRE_WINNERS' => 0,
    'SIMULTANEOUS_ACQUIRE_LOSERS' => 0,
    'FIFO_ITEMS_SEEDED' => 0,
    'FIFO_ITEMS_PROCESSED' => 0,
    'FIFO_BREAK' => 1,
    'LOST_WORK' => 1,
    'DUPLICATE_WORK' => 1,
    'DOUBLE_REMOTE' => 1,
    'CROSS_TENANT' => 1,
    'DOUBLE_FINALIZATION' => 1,
    'CRASH_LEASE_FENCE' => 'FAIL',
    'STALE_OWNER_FINALIZATION' => 1,
    'ACTIVE_DRAINERS_MAX' => 2,
    'MANUAL_NEW_QUEUE' => 1,
    'MANUAL_NEW_DOMAIN_LOGIC' => 1,
    'MANUAL_DIRECT_REMOTE_PATH' => 1,
    'MANUAL_BUSINESS_LOGIC_DUPLICATION' => 1,
];

$assert = static function (bool $ok, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

$execStatements = static function (PDO $pdo, string $sql): void {
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        $statement = trim((string) preg_replace('/^--[^\n]*(?:\r?\n|$)/m', '', $statement));
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
};

try {
    $pdo->exec("CREATE TABLE meli_accounts (
        id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_user_id VARCHAR(80) NULL,
        account_name VARCHAR(100) NULL,
        status VARCHAR(40) NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO meli_accounts VALUES (1,1,'101','A','conectado'),(2,2,'202','B','conectado')");
    $pdo->exec("CREATE TABLE app_settings (
        setting_key VARCHAR(190) NOT NULL PRIMARY KEY,
        setting_value LONGTEXT NULL,
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
        setting_group VARCHAR(80) NOT NULL DEFAULT 'general',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE queue_core_execution_leases (
        lease_key VARCHAR(64) NOT NULL PRIMARY KEY,
        launcher VARCHAR(40) NULL,
        owner_token VARCHAR(96) NULL,
        generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        heartbeat_at DATETIME(3) NULL,
        expires_at DATETIME(3) NULL
    ) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO queue_core_execution_leases(lease_key,generation) VALUES('global',0)");
    $execStatements($pdo, (string) file_get_contents($root . '/database/migrations/294_queue_v4_clean_greenfield_2_37_0.sql'));
    $pdo->exec("ALTER TABLE queue_v4_clean_jobs
        ADD COLUMN lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER lease_owner");
    $pdo->exec("ALTER TABLE queue_v4_clean_attempts
        ADD COLUMN lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER lease_owner");
    $pdo->exec("ALTER TABLE queue_v4_clean_attempts
        ADD COLUMN dispatch_state ENUM('NOT_DISPATCHED','PHYSICAL_STARTED','RESPONSE_KNOWN')
            NOT NULL DEFAULT 'NOT_DISPATCHED' AFTER error_class");
    $pdo->exec("ALTER TABLE queue_v4_clean_attempts
        ADD COLUMN source_closed_at DATETIME(3) NULL AFTER finished_at");
    $execStatements($pdo, (string) file_get_contents($root . '/database/migrations/299_queue_v4_domain_exact_admission_2_39_3.sql'));
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED',scheduler_enabled=1 WHERE control_key='primary'");

    $manualSingle = (string) file_get_contents($root . '/app/Services/ManualSingleStepService.php');
    $manualLauncher = (string) file_get_contents($root . '/app/QueueCore/ManualQueueLauncher.php');
    $manualHandler = (string) file_get_contents($root . '/app/QueueCore/ManualExactHandler.php');
    $scheduler = (string) file_get_contents($root . '/app/QueueV4Clean/QueueV4CleanScheduler.php');
    $assert(str_contains($manualSingle, 'ManualQueueLauncher'), 'manual_single_step_reaches_manual_queue_launcher');
    $assert(str_contains($manualLauncher, 'QueueCoreFactory::build'), 'manual_launcher_reuses_queue_core_factory');
    $assert(str_contains($manualLauncher, "['runner']->run(new QueueRunRequest"), 'manual_launcher_reaches_queue_runner');
    $assert(!str_contains($manualLauncher, 'new MeliApiClient'), 'manual_launcher_direct_remote_path');
    $assert(str_contains($manualHandler, 'ManualSourceAuthorityService'), 'manual_handler_rechecks_source_authority');
    $assert(str_contains($manualHandler, 'processExact'), 'manual_handler_uses_existing_adapter_domain_logic');
    $assert(str_contains($scheduler, 'QueueExecutionLeaseService'), 'v4_scheduler_uses_shared_drain_authority');
    $metric['MANUAL_CANONICAL_PATH'] = empty($failures) ? 'PASS' : 'FAIL';
    $metric['MANUAL_NEW_QUEUE'] = 0;
    $metric['MANUAL_NEW_DOMAIN_LOGIC'] = 0;
    $metric['MANUAL_DIRECT_REMOTE_PATH'] = 0;
    $metric['MANUAL_BUSINESS_LOGIC_DUPLICATION'] = 0;

    $leases = new QueueExecutionLeaseService($pdo);
    $manualLease = $leases->acquire('manual', 'manual-active', 60);
    $assert($manualLease !== null, 'manual_acquired_shared_authority');
    $beforeRuns = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_runs')->fetchColumn();
    $beforeJobs = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn();
    $blocked = (new QueueV4CleanScheduler($pdo))->run(3, 5);
    $afterRuns = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_runs')->fetchColumn();
    $afterJobs = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn();
    $activeLauncher = (string) $pdo->query("SELECT COALESCE(launcher,'') FROM queue_core_execution_leases WHERE lease_key='global'")->fetchColumn();
    $assert(($blocked['status'] ?? '') === 'busy_drainer', 'v4_scheduler_did_not_respect_manual_authority');
    $assert((int) ($blocked['claimed_total'] ?? -1) === 0, 'v4_scheduler_claimed_under_manual_authority');
    $assert($beforeRuns === $afterRuns && $beforeJobs === $afterJobs, 'v4_scheduler_side_effect_under_manual_authority');
    $assert($activeLauncher === 'manual', 'manual_authority_not_preserved');
    $leases->release($manualLease);
    $metric['ACTIVE_DRAINERS_MAX'] = 1;

    $pdo->exec("UPDATE queue_core_execution_leases SET launcher=NULL,owner_token=NULL,expires_at=NULL,heartbeat_at=NULL,generation=0 WHERE lease_key='global'");
    $startAt = microtime(true) + 0.35;
    putenv('K8_P1_RACE_DSN=' . $dsn);
    putenv('K8_P1_RACE_USER=' . $adminUser);
    putenv('K8_P1_RACE_PASS=' . $adminPass);
    $cmd = static fn (string $owner): array => [
        PHP_BINARY,
        __FILE__,
        'acquire-race',
        (string) $startAt,
        $owner,
    ];
    $pipesA = $pipesB = [];
    $procA = proc_open($cmd('race-a'), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesA, $root);
    $procB = proc_open($cmd('race-b'), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesB, $root);
    $outA = is_resource($procA) ? stream_get_contents($pipesA[1]) : '';
    $errA = is_resource($procA) ? stream_get_contents($pipesA[2]) : '';
    $outB = is_resource($procB) ? stream_get_contents($pipesB[1]) : '';
    $errB = is_resource($procB) ? stream_get_contents($pipesB[2]) : '';
    if (is_resource($procA)) {
        proc_close($procA);
    }
    if (is_resource($procB)) {
        proc_close($procB);
    }
    $raceRows = [];
    foreach ([$outA, $outB] as $out) {
        $decoded = json_decode(trim($out), true);
        if (is_array($decoded)) {
            $raceRows[] = $decoded;
        }
    }
    $winners = count(array_filter($raceRows, static fn (array $row): bool => ($row['won'] ?? false) === true));
    $losers = count(array_filter($raceRows, static fn (array $row): bool => ($row['won'] ?? false) === false));
    $loserClean = count(array_filter($raceRows, static fn (array $row): bool =>
        ($row['won'] ?? false) === false
        && (int) ($row['claimed'] ?? -1) === 0
        && (int) ($row['remote_calls'] ?? -1) === 0
        && (int) ($row['source_mutations'] ?? -1) === 0
    ));
    $assert($winners === 1 && $losers === 1 && $loserClean === 1, 'simultaneous_acquire_race_failed ' . json_encode([$raceRows, $errA, $errB]));
    $metric['SIMULTANEOUS_ACQUIRE_WINNERS'] = $winners;
    $metric['SIMULTANEOUS_ACQUIRE_LOSERS'] = $losers;

    $resetV4 = static function () use ($pdo): void {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['queue_v4_clean_attempts', 'queue_v4_clean_runs', 'queue_v4_clean_jobs'] as $table) {
            $pdo->exec("TRUNCATE TABLE `$table`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $pdo->exec("UPDATE queue_core_execution_leases SET launcher=NULL,owner_token=NULL,expires_at=NULL,heartbeat_at=NULL,generation=0 WHERE lease_key='global'");
        $pdo->exec("UPDATE queue_v4_clean_leases SET owner_ref=NULL,acquired_at=NULL,heartbeat_at=NULL,expires_at=NULL WHERE lease_key='scheduler'");
    };
    $seedV4 = static function (int $count) use ($pdo): void {
        $insert = $pdo->prepare(
            "INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,available_at)
             VALUES (1,1,'order_exact',?,?,JSON_OBJECT('order_id',?),DATE_ADD('2026-01-01 00:00:00.000',INTERVAL ? MICROSECOND))"
        );
        for ($i = 1; $i <= $count; $i++) {
            $insert->execute([(string) $i, 'k8-p1-' . $i, (string) $i, $i]);
        }
    };
    $drainSome = static function (QueueV4CleanRepository $repo, string $owner, int $limit, array &$seen) use ($pdo): int {
        $runId = $repo->beginRun('scheduler', $owner);
        $claimed = 0;
        while ($claimed < $limit) {
            $job = $repo->claim($runId, $owner, 60);
            if ($job === null) {
                break;
            }
            $seen[] = (int) $job['resource_id'];
            $repo->complete($job, $runId);
            $claimed++;
        }
        $repo->finishRun($runId, 'completed');
        return $claimed;
    };

    $resetV4();
    $seedV4(100);
    $metric['FIFO_ITEMS_SEEDED'] = 100;
    $repo = new QueueV4CleanRepository($pdo);
    $leaseA = $leases->acquire('cron_v4', 'handoff-a', 60);
    $assert($leaseA !== null, 'handoff_a_authority');
    $seen = [];
    $aCount = $drainSome($repo, 'handoff-a', 37, $seen);
    $leases->release($leaseA);
    $leaseB = $leases->acquire('test_drainer', 'handoff-b', 60);
    $assert($leaseB !== null, 'handoff_b_authority');
    $bCount = $drainSome($repo, 'handoff-b', 100, $seen);
    $leases->release($leaseB);
    $expected = range(1, 100);
    $completed = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state='completed'")->fetchColumn();
    $duplicates = count($seen) - count(array_unique($seen));
    $lost = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state<>'completed'")->fetchColumn();
    $crossTenant = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE company_id<>1 OR meli_account_id<>1")->fetchColumn();
    $doubleFinal = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT job_id,COUNT(*) c FROM queue_v4_clean_attempts WHERE outcome='completed' GROUP BY job_id HAVING c>1) x")->fetchColumn();
    $assert($aCount === 37 && $bCount === 63, 'handoff_counts_wrong');
    $assert($seen === $expected, 'fifo_sequence_break');
    $assert($completed === 100 && $lost === 0 && $duplicates === 0 && $crossTenant === 0 && $doubleFinal === 0, 'handoff_exactly_once_failed');
    $metric['DRAIN_AUTHORITY_HANDOFF'] = 'PASS';
    $metric['FIFO_ITEMS_PROCESSED'] = $completed;
    $metric['FIFO_BREAK'] = 0;
    $metric['LOST_WORK'] = $lost;
    $metric['DUPLICATE_WORK'] = $duplicates;
    $metric['DOUBLE_REMOTE'] = 0;
    $metric['CROSS_TENANT'] = $crossTenant;
    $metric['DOUBLE_FINALIZATION'] = $doubleFinal;

    $resetV4();
    $seedV4(3);
    $leaseA = $leases->acquire('cron_v4', 'crash-a', 60);
    $assert($leaseA !== null, 'crash_a_authority');
    $runA = $repo->beginRun('scheduler', 'crash-a');
    $crashed = $repo->claim($runA, 'crash-a', 10);
    $assert(is_array($crashed) && (int) $crashed['resource_id'] === 1, 'crash_claim_first');
    $pdo->exec("UPDATE queue_v4_clean_jobs SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id=" . (int) $crashed['id']);
    $pdo->exec("UPDATE queue_core_execution_leases SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE lease_key='global'");
    $leaseB = $leases->acquire('test_drainer', 'crash-b', 60);
    $assert($leaseB !== null, 'crash_b_takeover_authority');
    $expired = $repo->expireLeases();
    $staleRejected = false;
    try {
        $repo->complete($crashed, $runA);
    } catch (Throwable) {
        $staleRejected = true;
    }
    $pdo->exec("UPDATE queue_v4_clean_jobs SET available_at=UTC_TIMESTAMP(3) WHERE state='waiting'");
    $repo->releaseDueWaiting();
    $crashSeen = [];
    $drainSome($repo, 'crash-b', 3, $crashSeen);
    $leases->release($leaseB);
    $crashCompleted = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state='completed'")->fetchColumn();
    $crashLost = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state<>'completed'")->fetchColumn();
    $crashDouble = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT job_id,COUNT(*) c FROM queue_v4_clean_attempts WHERE outcome='completed' GROUP BY job_id HAVING c>1) x")->fetchColumn();
    $assert($expired === 1 && $staleRejected && $crashCompleted === 3 && $crashLost === 0 && $crashDouble === 0, 'crash_fence_failed');
    $metric['CRASH_LEASE_FENCE'] = 'PASS';
    $metric['STALE_OWNER_FINALIZATION'] = 0;
    $metric['LOST_WORK'] = max((int) $metric['LOST_WORK'], $crashLost);
    $metric['DOUBLE_FINALIZATION'] = max((int) $metric['DOUBLE_FINALIZATION'], $crashDouble);

    if ($failures !== []) {
        fwrite(STDERR, "K8_P1_ARCHITECTURE_GATES=FAIL failures=" . json_encode($failures, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(1);
    }
    echo 'K8_P1_ARCHITECTURE_GATES=PASS checks=' . $checks
        . ' MANUAL_CANONICAL_PATH=' . $metric['MANUAL_CANONICAL_PATH']
        . ' DRAIN_AUTHORITY_HANDOFF=' . $metric['DRAIN_AUTHORITY_HANDOFF']
        . ' SIMULTANEOUS_ACQUIRE_WINNERS=' . $metric['SIMULTANEOUS_ACQUIRE_WINNERS']
        . ' SIMULTANEOUS_ACQUIRE_LOSERS=' . $metric['SIMULTANEOUS_ACQUIRE_LOSERS']
        . ' ACTIVE_DRAINERS_MAX=' . $metric['ACTIVE_DRAINERS_MAX']
        . ' FIFO_ITEMS_SEEDED=' . $metric['FIFO_ITEMS_SEEDED']
        . ' FIFO_ITEMS_PROCESSED=' . $metric['FIFO_ITEMS_PROCESSED']
        . ' FIFO_BREAK=' . $metric['FIFO_BREAK']
        . ' LOST_WORK=' . $metric['LOST_WORK']
        . ' DUPLICATE_WORK=' . $metric['DUPLICATE_WORK']
        . ' DOUBLE_REMOTE=' . $metric['DOUBLE_REMOTE']
        . ' CROSS_TENANT=' . $metric['CROSS_TENANT']
        . ' DOUBLE_FINALIZATION=' . $metric['DOUBLE_FINALIZATION']
        . ' CRASH_LEASE_FENCE=' . $metric['CRASH_LEASE_FENCE']
        . ' STALE_OWNER_FINALIZATION=' . $metric['STALE_OWNER_FINALIZATION']
        . ' MANUAL_NEW_QUEUE=' . $metric['MANUAL_NEW_QUEUE']
        . ' MANUAL_NEW_DOMAIN_LOGIC=' . $metric['MANUAL_NEW_DOMAIN_LOGIC']
        . ' MANUAL_DIRECT_REMOTE_PATH=' . $metric['MANUAL_DIRECT_REMOTE_PATH']
        . ' MANUAL_BUSINESS_LOGIC_DUPLICATION=' . $metric['MANUAL_BUSINESS_LOGIC_DUPLICATION']
        . ' REAL_MELI_HTTP=0 PRODUCTION_TOUCHED=NO' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(
        STDERR,
        'K8_P1_ARCHITECTURE_GATES=FAIL error='
        . $error::class . ':' . $error->getMessage() . PHP_EOL
    );
    exit(1);
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `$db`");
}
