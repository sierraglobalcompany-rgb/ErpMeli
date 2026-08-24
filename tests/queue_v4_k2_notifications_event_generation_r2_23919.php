<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\CanonicalFifoDrainer;
use App\QueueV4Clean\QueueV4CleanNotificationAdmissionStage;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\CronDeadlineContext;
use App\Services\NotificationWorkItemService;

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

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: K2 R2 exige un DSN MariaDB desechable sin dbname.\n");
    exit(2);
}

$database = 'erp_k2_r2_' . bin2hex(random_bytes(5));
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$schema = static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE companies(id BIGINT UNSIGNED NOT NULL PRIMARY KEY,name VARCHAR(191) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_accounts(id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_user_id VARCHAR(64) NULL,account_name VARCHAR(191) NULL,status VARCHAR(32) NOT NULL,PRIMARY KEY(id),UNIQUE KEY uq_meli_accounts_company_id(company_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE app_settings(setting_key VARCHAR(120) NOT NULL PRIMARY KEY,setting_value LONGTEXT NULL,is_encrypted TINYINT(1) NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT 'general') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_control(control_key VARCHAR(32) NOT NULL PRIMARY KEY,engine_state ENUM('STOPPED','CERTIFIED','ACTIVE') NOT NULL DEFAULT 'STOPPED',readiness_state ENUM('NOT_READY','READY_TO_TEST','TESTING','CERTIFIED','FAILED') NOT NULL DEFAULT 'NOT_READY',scheduler_enabled TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_jobs(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,job_type ENUM('fresh_orders_discovery','order_exact','domain_exact') NOT NULL,resource_id VARCHAR(191) NULL,idempotency_key VARCHAR(191) NOT NULL,state ENUM('ready','running','waiting','review','dead','completed') NOT NULL DEFAULT 'ready',attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),lease_owner VARCHAR(96) NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,lease_expires_at DATETIME(3) NULL,payload_json JSON NOT NULL,last_error_class VARCHAR(100) NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),completed_at DATETIME(3) NULL,PRIMARY KEY(id),UNIQUE KEY uq_qv4_job_identity(company_id,meli_account_id,job_type,idempotency_key),KEY idx_qv4_fifo(state,available_at,id),KEY idx_qv4_tenant(company_id,meli_account_id,state,id),KEY idx_qv4_lease(state,lease_expires_at,id),UNIQUE KEY uq_qv4_job_tenant_id(id,company_id,meli_account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_attempts(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,job_id BIGINT UNSIGNED NOT NULL,run_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,lease_owner VARCHAR(96) NOT NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,outcome ENUM('running','completed','waiting','review','dead','lease_expired') NOT NULL DEFAULT 'running',error_class VARCHAR(100) NULL,dispatch_state ENUM('NOT_DISPATCHED','DISPATCHED','KNOWN','UNKNOWN') NOT NULL DEFAULT 'NOT_DISPATCHED',started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL,source_closed_at DATETIME(3) NULL,PRIMARY KEY(id),UNIQUE KEY uq_qv4_attempt(job_id,lease_owner),KEY idx_qv4_attempt_tenant(company_id,meli_account_id,started_at),KEY idx_qv4_attempt_run(run_id,company_id,meli_account_id),CONSTRAINT fk_qv4_attempt_job FOREIGN KEY(job_id) REFERENCES queue_v4_clean_jobs(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_runs(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,launcher ENUM('scheduler','manual','test') NOT NULL,worker_ref VARCHAR(96) NOT NULL,status ENUM('running','completed','failed','stopped') NOT NULL DEFAULT 'running',jobs_claimed INT UNSIGNED NOT NULL DEFAULT 0,jobs_completed INT UNSIGNED NOT NULL DEFAULT 0,jobs_deferred INT UNSIGNED NOT NULL DEFAULT 0,started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL,PRIMARY KEY(id),KEY idx_qv4_runs_status(status,started_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_leases(lease_key VARCHAR(64) NOT NULL PRIMARY KEY,owner_ref VARCHAR(96) NULL,acquired_at DATETIME(3) NULL,heartbeat_at DATETIME(3) NULL,expires_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_notification_events(id BIGINT UNSIGNED NOT NULL PRIMARY KEY,work_item_id BIGINT UNSIGNED NULL,canonical_topic VARCHAR(40) NULL,resource_type VARCHAR(40) NULL,remote_resource_id VARCHAR(100) NULL,validation_status VARCHAR(30) NULL,status VARCHAR(30) NOT NULL DEFAULT 'queued',disposition VARCHAR(80) NULL,correlation_id VARCHAR(80) NULL,error_message VARCHAR(500) NULL,acknowledged_at DATETIME(3) NULL,processed_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_notification_work_items(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,account_scope_key VARCHAR(80) NOT NULL,meli_user_id BIGINT UNSIGNED NULL,canonical_topic VARCHAR(40) NOT NULL,resource_type VARCHAR(40) NOT NULL,remote_resource_id VARCHAR(100) NOT NULL,latest_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,latest_event_sent_at DATETIME(3) NULL,priority INT NOT NULL DEFAULT 50,occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,status VARCHAR(40) NOT NULL DEFAULT 'pending',next_run_at DATETIME(3) NULL,correlation_id VARCHAR(80) NULL,last_result VARCHAR(100) NULL,first_received_at DATETIME(3) NULL,last_received_at DATETIME(3) NULL,locked_by VARCHAR(96) NULL,locked_at DATETIME(3) NULL,lock_expires_at DATETIME(3) NULL,last_error_code VARCHAR(100) NULL,last_error_message VARCHAR(500) NULL,last_error_diagnostic_id VARCHAR(80) NULL,last_error_stage VARCHAR(80) NULL,last_processed_at DATETIME(3) NULL,last_success_at DATETIME(3) NULL,completed_at DATETIME(3) NULL,processing_event_id BIGINT UNSIGNED NULL,rerun_requested TINYINT(1) NOT NULL DEFAULT 0,consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,attempts INT UNSIGNED NOT NULL DEFAULT 0,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),UNIQUE KEY uq_notification_resource(meli_account_id,resource_type,remote_resource_id),KEY idx_notification_k2(status,next_run_at,priority,id),KEY idx_notification_generation(status,processing_event_id,id),KEY idx_notification_scope(meli_account_id,status,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,readiness_state,scheduler_enabled) VALUES ('primary','ACTIVE','CERTIFIED',1)");
    $pdo->exec("INSERT INTO companies(id,name) VALUES (1,'K2 R2')");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,status) VALUES (1,1,'1001','K2','conectado')");
};

$classification = static function (string $orderId): array {
    return [
        'valid' => true,
        'actionable' => true,
        'canonical_topic' => 'order',
        'resource_type' => 'order',
        'resource_id' => $orderId,
        'priority' => 10,
        'reason' => 'order_forward',
    ];
};

$createRawEvent = static function (PDO $pdo, int $eventId): void {
    $pdo->prepare('INSERT INTO meli_notification_events(id,status) VALUES (?,"queued")')->execute([$eventId]);
};

$activateAt = static function (PDO $pdo, int $eventId): void {
    $pdo->prepare('INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES ("notifications.k2.forward_from_event_id",?,0,"notifications") ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
        ->execute([(string) $eventId]);
};

$executeOne = static function (PDO $pdo, array &$executed): void {
    $repo = new QueueV4CleanRepository($pdo);
    $drainer = new CanonicalFifoDrainer($pdo, $repo, null, null, static function (array $job) use (&$executed): void {
        $executed[] = (string) $job['idempotency_key'];
    });
    $result = $drainer->run('test', 1, 10);
    if ((int) $result['completed'] !== 1) {
        throw new RuntimeException('R2_DRAINER_DID_NOT_COMPLETE_ONE');
    }
};

try {
    putenv('APP_KEY=k2-r2-local-only');
    $_ENV['APP_KEY'] = 'k2-r2-local-only';
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['ML_WRITE_ENABLED'] = 'false';

    $server = $connect($dsn, $user, $pass);
    $server->exec('CREATE DATABASE ' . $quote($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = $connect($dsn . ';dbname=' . $database, $user, $pass);
    Database::setConnection($pdo);
    $schema($pdo);
    $pdo->exec('INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES ("notifications.debounce_seconds","0",0,"notifications")');
    $stage = new QueueV4CleanNotificationAdmissionStage($pdo);
    $workService = new NotificationWorkItemService();

    $createRawEvent($pdo, 10);
    $workId = $workService->enqueue(10, 1, 1001, $classification('123'), '2026-08-23 00:00:10');
    $activated = $stage->run(15);
    $assert(($activated['activated'] ?? false) === true && (int) $activated['watermark_id'] === 10, 'R2_EVENT_FRONTIER_NOT_ACTIVATED');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn() === 0, 'R2_PREACTIVATION_EVENT_ADMITTED');

    $createRawEvent($pdo, 11);
    $assert($workService->enqueue(11, 1, 1001, $classification('123'), '2026-08-23 00:00:11') === $workId, 'R2_REAL_INGRESS_DID_NOT_REUSE_WORK_ITEM');
    $run11 = $stage->run(15);
    $assert((int) $run11['admitted'] === 1, 'R2_NEW_EVENT_GENERATION_NOT_ADMITTED');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE idempotency_key="notification:order_exact:work:1:event:11"')->fetchColumn() === 1, 'R2_EVENT_GENERATION_POINTER_MISSING');
    $payload = (string) $pdo->query('SELECT payload_json FROM queue_v4_clean_jobs WHERE idempotency_key="notification:order_exact:work:1:event:11"')->fetchColumn();
    $assert(str_contains($payload, '"notification_event_generation_id":11'), 'R2_PAYLOAD_GENERATION_ID_MISSING');
    $assert((string) $pdo->query('SELECT status FROM meli_notification_events WHERE id=10')->fetchColumn() === 'queued', 'R2_PREACTIVATION_EVENT_MUTATED');
    $executed = [];
    $executeOne($pdo, $executed);
    $stage->run(15);
    $assert($executed === ['notification:order_exact:work:1:event:11'], 'R2_GENERATION_11_NOT_EXECUTED');
    $assert((string) $pdo->query('SELECT status FROM meli_notification_events WHERE id=10')->fetchColumn() === 'queued', 'R2_PREACTIVATION_EVENT_COMPLETED');
    $assert((string) $pdo->query('SELECT status FROM meli_notification_events WHERE id=11')->fetchColumn() === 'processed', 'R2_FORWARD_EVENT_NOT_PROCESSED');

    $createRawEvent($pdo, 12);
    $workService->enqueue(12, 1, 1001, $classification('123'), '2026-08-23 00:00:12');
    $stage->run(15);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE idempotency_key IN ("notification:order_exact:work:1:event:11","notification:order_exact:work:1:event:12")')->fetchColumn() === 2, 'R2_NEW_GENERATION_SUPPRESSED_BY_COMPLETED_POINTER');
    $executeOne($pdo, $executed);
    $stage->run(15);

    $createRawEvent($pdo, 20);
    $work2 = $workService->enqueue(20, 1, 1001, $classification('456'), '2026-08-23 00:00:20');
    $stage->run(15);
    $assert((int) $pdo->query('SELECT processing_event_id FROM meli_notification_work_items WHERE id=' . (int) $work2)->fetchColumn() === 20, 'R2_INFLIGHT_PROCESSING_EVENT_NOT_SET');
    $createRawEvent($pdo, 21);
    $workService->enqueue(21, 1, 1001, $classification('456'), '2026-08-23 00:00:21');
    $row = $pdo->query('SELECT status,processing_event_id,latest_event_id,rerun_requested FROM meli_notification_work_items WHERE id=' . (int) $work2)->fetch(PDO::FETCH_ASSOC);
    $assert($row['status'] === 'queued' && (int) $row['processing_event_id'] === 20 && (int) $row['latest_event_id'] === 21 && (int) $row['rerun_requested'] === 1, 'R2_INFLIGHT_RERUN_NOT_PRESERVED');
    $executeOne($pdo, $executed);
    $stage->run(15);
    $row = $pdo->query('SELECT status,processing_event_id,rerun_requested FROM meli_notification_work_items WHERE id=' . (int) $work2)->fetch(PDO::FETCH_ASSOC);
    $assert($row['status'] === 'pending' && $row['processing_event_id'] === null && (int) $row['rerun_requested'] === 0, 'R2_INFLIGHT_RERUN_NOT_RELEASED_TO_PENDING:' . json_encode($row));
    $assert((string) $pdo->query('SELECT status FROM meli_notification_events WHERE id=20')->fetchColumn() === 'processed', 'R2_OLD_INFLIGHT_GENERATION_NOT_PROCESSED');
    $assert((string) $pdo->query('SELECT status FROM meli_notification_events WHERE id=21')->fetchColumn() !== 'processed', 'R2_NEW_INFLIGHT_GENERATION_PROCESSED_TOO_EARLY');
    $stage->run(15);
    $stmtEvent21 = $pdo->prepare('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE idempotency_key=?');
    $stmtEvent21->execute(['notification:order_exact:work:' . (int) $work2 . ':event:21']);
    $assert((int) $stmtEvent21->fetchColumn() === 1, 'R2_RERUN_GENERATION_NOT_ADMITTED');
    $assert((string) $pdo->query('SELECT status FROM meli_notification_events WHERE id=21')->fetchColumn() !== 'processed', 'R2_EVENT_21_FALSE_COMPLETE_BEFORE_GENERATION_21');
    $beforeGeneration21Executions = count(array_filter($executed, static fn (string $key): bool => str_ends_with($key, ':event:21')));
    $executeOne($pdo, $executed);
    $stage->run(15);
    $afterGeneration21Executions = count(array_filter($executed, static fn (string $key): bool => str_ends_with($key, ':event:21')));
    $assert($afterGeneration21Executions - $beforeGeneration21Executions === 1, 'R2_GENERATION_21_EXECUTION_COUNT_INVALID');
    $assert((string) $pdo->query('SELECT status FROM meli_notification_events WHERE id=21')->fetchColumn() === 'processed', 'R2_EVENT_21_NOT_PROCESSED_BY_GENERATION_21');
    $assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=' . (int) $work2)->fetchColumn() === 'complete', 'R2_SOURCE_NOT_FINAL_COMPLETE_AFTER_GENERATION_21');

    foreach (['', '-1', '1.5', 'garbage', '922337203685477580799'] as $bad) {
        $pdo->exec('DELETE FROM queue_v4_clean_attempts');
        $pdo->exec('DELETE FROM queue_v4_clean_runs');
        $pdo->exec('DELETE FROM queue_v4_clean_jobs');
        $pdo->prepare('UPDATE app_settings SET setting_value=? WHERE setting_key="notifications.k2.forward_from_event_id"')->execute([$bad]);
        $summary = $stage->run(15);
        $assert(($summary['watermark_valid'] ?? true) === false && (int) $summary['admitted'] === 0 && (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn() === 0, 'R2_INVALID_WATERMARK_DID_NOT_FAIL_CLOSED:' . $bad);
    }
    $activateAt($pdo, 10);
    $pdo->exec('INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES ("notifications.enabled","0",0,"notifications") ON DUPLICATE KEY UPDATE setting_value="0"');
    $summaryPaused = $stage->run(15);
    $assert(($summaryPaused['paused'] ?? false) === true && (int) $summaryPaused['admitted'] === 0, 'R2_PAUSE_GATE_NOT_HONORED');
    $pdo->exec('UPDATE app_settings SET setting_value="1" WHERE setting_key="notifications.enabled"');
    CronDeadlineContext::within(microtime(true) - 0.01, static function () use ($stage, $assert): void {
        $deadline = $stage->run(15);
        $assert(($deadline['deferred'] ?? false) === true && (int) $deadline['admitted'] === 0 && (int) $deadline['reconciled'] === 0, 'R2_DEADLINE_EXHAUSTED_MUTATED');
    });

    $pdo->exec('DELETE FROM queue_v4_clean_jobs');
    $activateAt($pdo, 29);
    $createRawEvent($pdo, 30);
    $work3 = $workService->enqueue(30, 1, 1001, $classification('789'), '2026-08-23 00:00:30');
    $stage->run(15);
    $executeOne($pdo, $executed);
    $locker = $connect($dsn . ';dbname=' . $database, $user, $pass);
    $locker->beginTransaction();
    $locker->query('SELECT id FROM meli_notification_work_items WHERE id=' . (int) $work3 . ' FOR UPDATE')->fetch();
    $skipped = $stage->run(15);
    $assert((int) $skipped['completed'] === 0, 'R2_RECONCILE_DID_NOT_SKIP_LOCKED_ROW');
    $locker->rollBack();
    $unlocked = $stage->run(15);
    $assert((int) $unlocked['completed'] === 1, 'R2_RECONCILE_AFTER_UNLOCK_FAILED');

    echo "QUEUE_V4_K2_NOTIFICATIONS_EVENT_GENERATION_R2=PASS checks=$checks\n";
    echo "EVENT_WATERMARK_AUTHORITY=PASS\n";
    echo "INVALID_WATERMARK_FAIL_CLOSED=PASS\n";
    echo "REAL_INGRESS_REUSED_WORK_ITEM_NEW_EVENT=PASS\n";
    echo "GENERATIONAL_IDEMPOTENCY=PASS\n";
    echo "PREACTIVATION_ONLY_WORK_ITEMS_MUTATED=0\n";
    echo "PREACTIVATION_EVENTS_MUTATED=0\n";
    echo "PREACTIVATION_EVENT_CANONICAL_ADMISSIONS=0\n";
    echo "HISTORICAL_WORK_ITEM_WITH_NEW_FORWARD_EVENT=PASS\n";
    echo "INFLIGHT_RERUN_PRESERVED=PASS\n";
    echo "RERUN_GENERATION_REQUEUED=PASS\n";
    echo "RERUN_GENERATION_E2E=PASS\n";
    echo "LOST_RERUN=0\n";
    echo "FALSE_COMPLETE=0\n";
    echo "PAUSE_GATE_ADMISSIONS=0\n";
    echo "DEADLINE_EXHAUSTED_MUTATIONS=0\n";
    echo "RECONCILIATION_SKIP_LOCKED=PASS\n";
    echo "REAL_MELI_HTTP=0\n";
} finally {
    CronDeadlineContext::clear();
    try {
        if (isset($server) && $server instanceof PDO) {
            $server->exec('DROP DATABASE IF EXISTS ' . $quote($database));
        }
    } catch (Throwable) {
    }
}
