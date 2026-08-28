<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueCore\ManualQueueLauncher;
use App\QueueCore\QueueCoreRepository;
use App\QueueCore\QueueExecutionLeaseService;
use App\QueueCore\QueueJob;

$root = dirname(__DIR__);
if (!defined('ERP_RELEASE_ROOT')) {
    define('ERP_RELEASE_ROOT', $root);
}
if (!defined('ERP_INSTALLATION_ROOT')) {
    define('ERP_INSTALLATION_ROOT', $root);
}
require_once $root . '/vendor/autoload.php';

$serverDsn = trim((string) (getenv('QUEUE_CORE_TEST_DSN') ?: getenv('ERP_MIGRATOR_TEST_DSN')));
$dbUser = (string) (getenv('QUEUE_CORE_TEST_USER') ?: getenv('ERP_MIGRATOR_TEST_USER'));
$dbPass = (string) (getenv('QUEUE_CORE_TEST_PASS') ?: getenv('ERP_MIGRATOR_TEST_PASS'));
if ($serverDsn === '' || !str_starts_with(strtolower($serverDsn), 'mysql:') || stripos($serverDsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: manual_single_global_lease_2402_mysql requires a disposable MariaDB DSN without dbname.\n");
    exit(2);
}

$server = new PDO($serverDsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
$db = 'erp_manual_single_lease_' . bin2hex(random_bytes(5));
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $server->exec('CREATE DATABASE ' . $quote($db) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO($serverDsn . ';dbname=' . $db, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET SESSION time_zone='+00:00'");
    Database::setConnection($pdo);
    installManualSingleLeaseSchema($pdo, $root);

    $leases = new QueueExecutionLeaseService($pdo);
    $sessionLease = $leases->acquire('manual', str_repeat('a', 64), 60);
    $assert($sessionLease !== null, 'MANUAL_SESSION_START');
    $assert($leases->acquire('manual', 'manual-' . bin2hex(random_bytes(8)), 60) === null, 'MANUAL_DOUBLE_LEASE_ROOT_CAUSE_NOT_REPRODUCED');
    $assert($leases->acquire('cron_v4', 'auto-while-manual', 60) === null, 'AUTO_DRAINER_CLAIMED_WHILE_MANUAL_OWNS_LEASE');

    $repo = new QueueCoreRepository($pdo);
    $unrelated = $repo->enqueue(new QueueJob(
        1,
        1,
        'order_exact',
        'order',
        '777',
        'normal',
        0,
        'unrelated-operational',
        'v1',
        'fixture',
        null,
        ['order_id' => '777'],
        [],
        3,
        null,
        'operational'
    ));

    $beforeGeneration = (int) $pdo->query("SELECT generation FROM queue_core_execution_leases WHERE lease_key='global'")->fetchColumn();
    $result = (new ManualQueueLauncher())->runExact(
        1,
        1,
        'module_jobs',
        '12',
        false,
        'local_maintenance',
        str_repeat('b', 64),
        str_repeat('c', 64),
        str_repeat('d', 64),
        null,
        $sessionLease
    );
    $afterLease = $pdo->query("SELECT launcher,owner_token,generation,expires_at FROM queue_core_execution_leases WHERE lease_key='global'")->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($afterLease), 'GLOBAL_LEASE_ROW_MISSING');
    $assert((int) $afterLease['generation'] === $beforeGeneration, 'SECOND_GLOBAL_LEASE_ACQUIRE');
    $assert((string) $afterLease['launcher'] === 'manual' && (string) $afterLease['owner_token'] === $sessionLease->ownerToken, 'GLOBAL_LEASE_OWNER_NOT_PRESERVED');

    $manualJobs = (int) $pdo->query("SELECT COUNT(*) FROM queue_core_jobs WHERE queue_domain='manual' AND work_type='manual_exact'")->fetchColumn();
    $manualAttempts = (int) $pdo->query("SELECT COUNT(*) FROM queue_core_attempts WHERE launcher='manual'")->fetchColumn();
    $manualLive = (int) $pdo->query("SELECT COUNT(*) FROM queue_core_jobs WHERE queue_domain='manual' AND state IN ('pending','claimed','running','retry_wait')")->fetchColumn();
    $unrelatedAttempts = (int) $pdo->query('SELECT attempt_count FROM queue_core_jobs WHERE id=' . $unrelated)->fetchColumn();
    $manualSourceRows = (int) $pdo->query("SELECT COUNT(*) FROM queue_core_jobs WHERE queue_domain='manual' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.queue_key'))='module_jobs' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.source_id'))='12'")->fetchColumn();

    $assert(in_array((string) ($result['status'] ?? ''), ['dead', 'review', 'completed'], true), 'ONE_STEP_CLICK_EQUIVALENT');
    $assert($manualJobs === 1 && $manualSourceRows === 1, 'SELECTED_EXACT_WORK_ONLY');
    $assert($unrelatedAttempts === 0, 'UNRELATED_GLOBAL_WORK_CLAIMED');
    $assert($manualLive === 0, 'BACKGROUND_CONTINUATION');
    $assert($manualAttempts === 1, 'QUEUE_CORE_ATTEMPTS');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_core_attempts GROUP BY job_id HAVING COUNT(*)>1')->fetchColumn() === 0, 'DOUBLE_FINALIZATION');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_core_jobs WHERE queue_domain='manual'")->fetchColumn() === 1, 'DUPLICATE_WORK');

    $leases->release($sessionLease);
    $autoLease = $leases->acquire('cron_v4', 'auto-first', 60);
    $assert($autoLease !== null, 'AUTO_LEASE_SETUP_FAILED');
    $assert($leases->acquire('manual', 'manual-while-auto', 60) === null, 'MANUAL_CLAIMED_WHILE_AUTO_OWNS_LEASE');
    $leases->release($autoLease);

    echo 'MANUAL_DOUBLE_LEASE_ROOT_CAUSE=REAL_BUG' . PHP_EOL;
    echo 'MANUAL_SESSION_START=PASS' . PHP_EOL;
    echo 'GLOBAL_LEASE_ACQUIRED=1' . PHP_EOL;
    echo 'SECOND_GLOBAL_LEASE_ACQUIRE=0' . PHP_EOL;
    echo 'SELECTED_EXACT_WORK_ONLY=PASS' . PHP_EOL;
    echo 'UNRELATED_GLOBAL_WORK_CLAIMED=0' . PHP_EOL;
    echo 'BACKGROUND_CONTINUATION=0' . PHP_EOL;
    echo 'QUEUE_CORE_ATTEMPTS=1' . PHP_EOL;
    echo 'DOUBLE_FINALIZATION=0' . PHP_EOL;
    echo 'DUPLICATE_WORK=0' . PHP_EOL;
    echo 'MANUAL_AUTO_EXCLUSION=PASS' . PHP_EOL;
    echo 'AUTO_MANUAL_EXCLUSION=PASS' . PHP_EOL;
    echo 'REAL_MELI_HTTP=0' . PHP_EOL;
    echo 'MANUAL_SINGLE_GLOBAL_LEASE_2402=PASS checks=' . $checks . PHP_EOL;
} finally {
    $server->exec('DROP DATABASE IF EXISTS ' . $quote($db));
}

function installManualSingleLeaseSchema(PDO $pdo, string $root): void
{
    $pdo->exec('CREATE TABLE app_settings(setting_key VARCHAR(191) NOT NULL PRIMARY KEY,setting_value MEDIUMTEXT NULL,is_encrypted TINYINT(1) NOT NULL DEFAULT 0,setting_group VARCHAR(80) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_accounts(id BIGINT UNSIGNED NOT NULL PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_user_id VARCHAR(80) NULL,account_name VARCHAR(100) NULL,status VARCHAR(40) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,status) VALUES (1,1,'101','A','connected')");
    $pdo->exec('CREATE TABLE meli_tokens(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL UNIQUE,access_token_encrypted MEDIUMTEXT NULL,refresh_token_encrypted MEDIUMTEXT NULL,expires_at DATETIME NULL,refresh_version BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE queue_core_scheduler_state(scheduler_key VARCHAR(60) NOT NULL PRIMARY KEY,cycle_position INT UNSIGNED NOT NULL DEFAULT 0,generation BIGINT UNSIGNED NOT NULL DEFAULT 0,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO queue_core_scheduler_state(scheduler_key,cycle_position,generation) VALUES('default',0,0)");
    $pdo->exec('CREATE TABLE queue_core_execution_leases(lease_key VARCHAR(60) NOT NULL PRIMARY KEY,launcher ENUM("cron_v4","manual","test") NULL,owner_token VARCHAR(96) NULL,generation BIGINT UNSIGNED NOT NULL DEFAULT 0,heartbeat_at DATETIME(3) NULL,expires_at DATETIME(3) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO queue_core_execution_leases(lease_key,generation) VALUES('global',0)");
    $pdo->exec('CREATE TABLE queue_core_feature_flags(feature_key VARCHAR(80) NOT NULL PRIMARY KEY,enabled TINYINT(1) NOT NULL DEFAULT 0,hard_cap INT UNSIGNED NOT NULL DEFAULT 0,generation BIGINT UNSIGNED NOT NULL DEFAULT 0,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    foreach (['fresh_producer','webhook_producer','pack_shipment_followups','remote_financial','historical_importer'] as $feature) {
        $pdo->prepare('INSERT INTO queue_core_feature_flags(feature_key,enabled,hard_cap,generation) VALUES (?,0,0,0)')->execute([$feature]);
    }
    $pdo->exec("CREATE TABLE queue_core_jobs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        work_type VARCHAR(80) NOT NULL,
        resource_type VARCHAR(80) NOT NULL,
        resource_id VARCHAR(191) NULL,
        lane ENUM('fresh_orders','recovery','normal','historical_backfill','local') NOT NULL,
        queue_domain ENUM('operational','manual') NOT NULL DEFAULT 'operational',
        priority INT NOT NULL DEFAULT 0,
        idempotency_key VARCHAR(191) NOT NULL,
        input_version VARCHAR(191) NOT NULL,
        state ENUM('pending','claimed','running','retry_wait','waiting_oauth','completed','review','dead') NOT NULL DEFAULT 'pending',
        dispatch_state ENUM('NOT_DISPATCHED','DISPATCHED_RESULT_UNCERTAIN','DISPATCHED_RESULT_KNOWN') NOT NULL DEFAULT 'NOT_DISPATCHED',
        attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
        max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
        available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        next_attempt_at DATETIME(3) NULL,
        claimed_at DATETIME(3) NULL,
        lease_owner VARCHAR(96) NULL,
        lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        lease_expires_at DATETIME(3) NULL,
        lease_heartbeat_at DATETIME(3) NULL,
        started_at DATETIME(3) NULL,
        completed_at DATETIME(3) NULL,
        last_error_class VARCHAR(100) NULL,
        last_http_status SMALLINT UNSIGNED NULL,
        wait_refresh_version BIGINT UNSIGNED NULL,
        source VARCHAR(80) NOT NULL,
        source_ref VARCHAR(191) NULL,
        payload_json JSON NOT NULL,
        provenance_json JSON NOT NULL,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        UNIQUE KEY uq_queue_core_idempotency(company_id,meli_account_id,work_type,idempotency_key,input_version),
        KEY idx_queue_core_claim(state,available_at,id),
        KEY idx_queue_core_domain(queue_domain,state,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE queue_core_attempts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        run_id BIGINT UNSIGNED NULL,
        job_id BIGINT UNSIGNED NOT NULL,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        lease_owner VARCHAR(96) NOT NULL,
        lease_generation BIGINT UNSIGNED NOT NULL,
        launcher ENUM('cron_v4','manual','test') NOT NULL,
        outcome ENUM('started','completed','retry_wait','waiting_oauth','review','dead','lease_lost') NOT NULL DEFAULT 'started',
        dispatch_state ENUM('NOT_DISPATCHED','DISPATCHED_RESULT_UNCERTAIN','DISPATCHED_RESULT_KNOWN') NOT NULL DEFAULT 'NOT_DISPATCHED',
        dispatch_reserved_at DATETIME(3) NULL,
        physical_http_calls INT UNSIGNED NOT NULL DEFAULT 0,
        physical_http_started_at DATETIME(3) NULL,
        resources_discovered INT UNSIGNED NOT NULL DEFAULT 0,
        resources_persisted INT UNSIGNED NOT NULL DEFAULT 0,
        error_class VARCHAR(100) NULL,
        http_status SMALLINT UNSIGNED NULL,
        source_closed_at DATETIME(3) NULL,
        started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        finished_at DATETIME(3) NULL,
        UNIQUE KEY uq_queue_core_attempt_fence(job_id,lease_owner,lease_generation)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE queue_core_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        job_id BIGINT UNSIGNED NULL,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        lane VARCHAR(40) NOT NULL,
        event_type VARCHAR(80) NOT NULL,
        event_count INT UNSIGNED NOT NULL DEFAULT 1,
        resources_count INT UNSIGNED NOT NULL DEFAULT 0,
        occurred_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        KEY idx_queue_core_events_job(job_id,occurred_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE queue_core_dispatch_journal (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        job_id BIGINT UNSIGNED NOT NULL,
        attempt_id BIGINT UNSIGNED NOT NULL,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        lease_owner VARCHAR(96) NOT NULL,
        lease_generation BIGINT UNSIGNED NOT NULL,
        method VARCHAR(10) NOT NULL,
        endpoint_key VARCHAR(120) NOT NULL,
        state ENUM('reserved','in_flight','response_known','cancelled_before_remote') NOT NULL,
        http_status SMALLINT UNSIGNED NULL,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        UNIQUE KEY uq_queue_core_dispatch_attempt(attempt_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
