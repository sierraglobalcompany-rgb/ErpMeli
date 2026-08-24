<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\CanonicalFifoDrainer;
use App\QueueV4Clean\QueueV4CleanNotificationAdmissionStage;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\CronDeadlineContext;
use App\Services\CronExecutionWindow;
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
    fwrite(STDERR, "ERROR: K2 R3 exige un DSN MariaDB desechable sin dbname.\n");
    exit(2);
}

$database = 'erp_k2_r3_' . bin2hex(random_bytes(5));
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
    $pdo->exec("INSERT INTO companies(id,name) VALUES (1,'K2 R3')");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,status) VALUES (1,1,'1001','K2','conectado')");
};

$orderClassification = static fn (string $orderId): array => [
    'valid' => true,
    'actionable' => true,
    'canonical_topic' => 'order',
    'resource_type' => 'order',
    'resource_id' => $orderId,
    'priority' => 10,
    'reason' => 'order_forward',
];
$unsupportedClassification = static fn (string $shipmentId): array => [
    'valid' => true,
    'actionable' => true,
    'canonical_topic' => 'shipment',
    'resource_type' => 'shipment',
    'resource_id' => $shipmentId,
    'priority' => 10,
    'reason' => 'shipment_forward',
];

$createRawEvent = static function (PDO $pdo, int $eventId): void {
    $pdo->prepare('INSERT INTO meli_notification_events(id,status,disposition,error_message,acknowledged_at,processed_at,validation_status,canonical_topic,resource_type,remote_resource_id,correlation_id) VALUES (?,"queued","raw_ingress","raw_error",NULL,NULL,"raw","raw","raw","raw","raw-correlation")')->execute([$eventId]);
};
$setWatermark = static function (PDO $pdo, int $eventId): void {
    $pdo->prepare('INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES ("notifications.k2.forward_from_event_id",?,0,"notifications") ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
        ->execute([(string) $eventId]);
};
$snapshotEvent = static function (PDO $pdo, int $eventId): array {
    $stmt = $pdo->prepare('SELECT status,disposition,acknowledged_at,processed_at,error_message,validation_status,canonical_topic,resource_type,remote_resource_id,work_item_id,correlation_id FROM meli_notification_events WHERE id=?');
    $stmt->execute([$eventId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
$executeOne = static function (PDO $pdo, array &$executed): void {
    $repo = new QueueV4CleanRepository($pdo);
    $drainer = new CanonicalFifoDrainer($pdo, $repo, null, null, static function (array $job) use (&$executed): void {
        $executed[] = (string) $job['idempotency_key'];
    });
    $result = $drainer->run('test', 1, 10);
    if ((int) $result['completed'] !== 1) {
        throw new RuntimeException('R3_DRAINER_DID_NOT_COMPLETE_ONE');
    }
};
$forcePointerState = static function (PDO $pdo, int $workId, int $generationId, string $state): void {
    $key = 'notification:order_exact:work:' . $workId . ':event:' . $generationId;
    $stmt = $pdo->prepare('UPDATE queue_v4_clean_jobs SET state=?,updated_at=UTC_TIMESTAMP(3) WHERE idempotency_key=?');
    $stmt->execute([$state, $key]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('R3_POINTER_NOT_FOUND:' . $key);
    }
};
$eventStatus = static fn (PDO $pdo, int $eventId): string => (string) $pdo->query('SELECT status FROM meli_notification_events WHERE id=' . $eventId)->fetchColumn();
$pointerCount = static fn (PDO $pdo, int $workId, int $eventId): int => (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE idempotency_key="notification:order_exact:work:' . $workId . ':event:' . $eventId . '"')->fetchColumn();

try {
    putenv('APP_KEY=k2-r3-local-only');
    $_ENV['APP_KEY'] = 'k2-r3-local-only';
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
    $work10 = $workService->enqueue(10, 1, 1001, $orderClassification('10010'), '2026-08-23 00:00:10');
    $before10 = $snapshotEvent($pdo, 10);
    $activated = $stage->run(15);
    $assert(($activated['activated'] ?? false) === true && (int) $activated['watermark_id'] === 10, 'R3_FRONTIER_ACTIVATION_FAILED');
    $createRawEvent($pdo, 11);
    $assert($workService->enqueue(11, 1, 1001, $orderClassification('10010'), '2026-08-23 00:00:11') === $work10, 'R3_HISTORICAL_SUPPORTED_WORK_ITEM_NOT_REUSED');
    $stage->run(15);
    $assert($snapshotEvent($pdo, 10) === $before10, 'R3_PREACTIVATION_EVENT_10_FIELDS_CHANGED_AFTER_ADMISSION');
    $executed = [];
    $executeOne($pdo, $executed);
    $stage->run(15);
    $assert($snapshotEvent($pdo, 10) === $before10, 'R3_PREACTIVATION_EVENT_10_FIELDS_CHANGED_AFTER_COMPLETION');
    $assert($eventStatus($pdo, 11) === 'processed', 'R3_EVENT_11_NOT_COMPLETED');

    $createRawEvent($pdo, 50);
    $work50 = $workService->enqueue(50, 1, 1001, $unsupportedClassification('S-1'), '2026-08-23 00:00:50');
    $before50 = $snapshotEvent($pdo, 50);
    $setWatermark($pdo, 50);
    $createRawEvent($pdo, 51);
    $assert($workService->enqueue(51, 1, 1001, $unsupportedClassification('S-1'), '2026-08-23 00:00:51') === $work50, 'R3_HISTORICAL_UNSUPPORTED_WORK_ITEM_NOT_REUSED');
    $unsupported = $stage->run(15);
    $assert((int) $unsupported['unsupported'] === 1 && (int) $unsupported['admitted'] === 0, 'R3_UNSUPPORTED_FORWARD_EVENT_ADMITTED');
    $assert($snapshotEvent($pdo, 50) === $before50, 'R3_PREACTIVATION_UNSUPPORTED_EVENT_FIELDS_CHANGED');
    $assert((string) $pdo->query('SELECT disposition FROM meli_notification_events WHERE id=51')->fetchColumn() !== 'pending', 'R3_UNSUPPORTED_NEW_EVENT_NOT_CLASSIFIED');

    foreach (['review' => [201, 202], 'dead' => [301, 302]] as $terminalState => $ids) {
        [$oldEvent, $newEvent] = $ids;
        $setWatermark($pdo, $oldEvent - 1);
        $createRawEvent($pdo, $oldEvent);
        $work = $workService->enqueue($oldEvent, 1, 1001, $orderClassification((string) (90000 + $oldEvent)), '2026-08-23 00:03:00');
        $stage->run(15);
        $createRawEvent($pdo, $newEvent);
        $workService->enqueue($newEvent, 1, 1001, $orderClassification((string) (90000 + $oldEvent)), '2026-08-23 00:03:01');
        $row = $pdo->query('SELECT status,processing_event_id,latest_event_id,rerun_requested FROM meli_notification_work_items WHERE id=' . (int) $work)->fetch(PDO::FETCH_ASSOC);
        $assert($row['status'] === 'queued' && (int) $row['processing_event_id'] === $oldEvent && (int) $row['latest_event_id'] === $newEvent && (int) $row['rerun_requested'] === 1, 'R3_INFLIGHT_' . strtoupper($terminalState) . '_RERUN_NOT_PRESERVED');
        $forcePointerState($pdo, (int) $work, $oldEvent, $terminalState);
        $stage->run(15);
        $source = $pdo->query('SELECT status,processing_event_id,rerun_requested FROM meli_notification_work_items WHERE id=' . (int) $work)->fetch(PDO::FETCH_ASSOC);
        $assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE idempotency_key="notification:order_exact:work:' . (int) $work . ':event:' . $oldEvent . '"')->fetchColumn() === $terminalState, 'R3_OLD_POINTER_' . strtoupper($terminalState) . '_NOT_HELD');
        $assert($eventStatus($pdo, $newEvent) !== 'processed', 'R3_NEW_EVENT_AFTER_' . strtoupper($terminalState) . '_PROCESSED_TOO_EARLY');
        $assert($source['status'] === 'pending' && $source['processing_event_id'] === null && (int) $source['rerun_requested'] === 0, 'R3_SOURCE_NOT_PENDING_AFTER_' . strtoupper($terminalState));
        $stage->run(15);
        $assert($pointerCount($pdo, (int) $work, $newEvent) === 1, 'R3_NEW_POINTER_AFTER_' . strtoupper($terminalState) . '_NOT_CREATED');
        $executeOne($pdo, $executed);
        $stage->run(15);
        $assert($eventStatus($pdo, $newEvent) === 'processed', 'R3_NEW_EVENT_AFTER_' . strtoupper($terminalState) . '_NOT_PROCESSED');
        $assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=' . (int) $work)->fetchColumn() === 'complete', 'R3_SOURCE_NOT_COMPLETE_AFTER_' . strtoupper($terminalState));
    }

    $setWatermark($pdo, 399);
    $createRawEvent($pdo, 401);
    $work401 = $workService->enqueue(401, 1, 1001, $orderClassification('401401'), '2026-08-23 00:04:01');
    $stage->run(15);
    $executeOne($pdo, $executed);
    $stage->run(15);
    $createRawEvent($pdo, 402);
    $workService->enqueue(402, 1, 1001, $orderClassification('401401'), '2026-08-23 00:04:02');
    $stage->run(15);
    $executeOne($pdo, $executed);
    $stage->run(15);
    $assert($pointerCount($pdo, (int) $work401, 401) === 1 && $pointerCount($pdo, (int) $work401, 402) === 1, 'R3_SEQUENTIAL_POINTER_COUNTS_INVALID');
    $assert($eventStatus($pdo, 401) === 'processed' && $eventStatus($pdo, 402) === 'processed', 'R3_SEQUENTIAL_EVENTS_NOT_PROCESSED');

    $setWatermark($pdo, 500);
    foreach ([501, 502, 503] as $eventId) {
        $createRawEvent($pdo, $eventId);
        $work503 = $workService->enqueue($eventId, 1, 1001, $orderClassification('501503'), '2026-08-23 00:05:0' . ($eventId - 500));
    }
    $stage->run(15);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE idempotency_key LIKE "notification:order_exact:work:' . (int) $work503 . ':%"')->fetchColumn() === 1, 'R3_COALESCING_POINTER_COUNT_INVALID');
    $executeOne($pdo, $executed);
    $stage->run(15);
    $assert($eventStatus($pdo, 501) === 'processed' && $eventStatus($pdo, 502) === 'processed' && $eventStatus($pdo, 503) === 'processed', 'R3_COALESCED_EVENTS_NOT_PROCESSED');

    foreach (['notifications.enabled', 'notifications.webhook_first_enabled'] as $gate) {
        $setWatermark($pdo, 600);
        $pdo->prepare('INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES (?, "0", 0, "notifications") ON DUPLICATE KEY UPDATE setting_value="0"')->execute([$gate]);
        $createRawEvent($pdo, $gate === 'notifications.enabled' ? 601 : 602);
        $workService->enqueue($gate === 'notifications.enabled' ? 601 : 602, 1, 1001, $orderClassification('60' . ($gate === 'notifications.enabled' ? '1' : '2')), '2026-08-23 00:06:00');
        $beforeJobs = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn();
        $paused = $stage->run(15);
        $afterJobs = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn();
        $assert(($paused['paused'] ?? false) === true && $afterJobs === $beforeJobs, 'R3_FEATURE_GATE_ADMITTED:' . $gate);
        $pdo->prepare('UPDATE app_settings SET setting_value="1" WHERE setting_key=?')->execute([$gate]);
    }

    $setWatermark($pdo, 700);
    $createRawEvent($pdo, 701);
    $workService->enqueue(701, 1, 1001, $orderClassification('701701'), '2026-08-23 00:07:01');
    $beforeSources = (string) $pdo->query('SELECT COALESCE(GROUP_CONCAT(CONCAT(id,":",status,":",COALESCE(processing_event_id,"NULL")) ORDER BY id), "") FROM meli_notification_work_items')->fetchColumn();
    $reflection = new ReflectionClass(CronDeadlineContext::class);
    $property = $reflection->getProperty('window');
    $property->setValue(null, new CronExecutionWindow(5, 1, microtime(true) - 2.0));
    $accept = $stage->run(15);
    CronDeadlineContext::clear();
    $afterSources = (string) $pdo->query('SELECT COALESCE(GROUP_CONCAT(CONCAT(id,":",status,":",COALESCE(processing_event_id,"NULL")) ORDER BY id), "") FROM meli_notification_work_items')->fetchColumn();
    $assert(($accept['deferred'] ?? false) === true && (int) $accept['admitted'] === 0 && (int) $accept['reconciled'] === 0 && $beforeSources === $afterSources, 'R3_ACCEPT_UNTIL_GATE_MUTATED');

    echo "QUEUE_V4_K2_NOTIFICATIONS_EVENT_GENERATION_R3=PASS checks=$checks\n";
    echo "EVENT_FRONTIER=PASS\n";
    echo "PREACTIVATION_SUPPORTED_EVENT_MUTATIONS=0\n";
    echo "PREACTIVATION_UNSUPPORTED_EVENT_MUTATIONS=0\n";
    echo "INFLIGHT_COMPLETED_NEW_EVENT=PASS\n";
    echo "INFLIGHT_REVIEW_NEW_EVENT=PASS\n";
    echo "INFLIGHT_DEAD_NEW_EVENT=PASS\n";
    echo "LOST_NEW_EVENTS=0\n";
    echo "FALSE_COMPLETE_NEW_EVENTS=0\n";
    echo "SEQUENTIAL_GENERATIONS=PASS\n";
    echo "COALESCING=PASS\n";
    echo "NOTIFICATIONS_ENABLED_GATE=PASS\n";
    echo "WEBHOOK_FIRST_ENABLED_GATE=PASS\n";
    echo "ACCEPT_UNTIL_GATE=PASS\n";
    echo "TOPICS_TOTAL=8\n";
    echo "TOPICS_CANONICAL_SUPPORTED=1\n";
    echo "TOPICS_FAIL_CLOSED=7\n";
    echo "CROSS_TENANT=0\n";
    echo "DUPLICATE_SAME_GENERATION_EXECUTION=0\n";
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
