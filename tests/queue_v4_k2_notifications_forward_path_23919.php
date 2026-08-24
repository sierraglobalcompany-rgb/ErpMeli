<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\CanonicalFifoDrainer;
use App\QueueV4Clean\QueueV4CleanNotificationAdmissionStage;
use App\QueueV4Clean\QueueV4CleanRepository;

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

if (($argv[1] ?? '') === '--admission-child') {
    putenv('APP_KEY=k2-notification-forward-race');
    $_ENV['APP_KEY'] = 'k2-notification-forward-race';
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['ML_WRITE_ENABLED'] = 'false';
    $pdo = $connect((string) getenv('K2_DSN'), (string) getenv('K2_USER'), (string) getenv('K2_PASS'));
    Database::setConnection($pdo);
    $start = (float) getenv('K2_START');
    while (microtime(true) < $start) {
        usleep(1000);
    }
    echo json_encode((new QueueV4CleanNotificationAdmissionStage($pdo))->run(10), JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: K2 exige un DSN MariaDB desechable sin dbname.\n");
    exit(2);
}

$database = 'erp_k2_notifications_' . bin2hex(random_bytes(5));
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$insertWork = static function (
    PDO $pdo,
    int $id,
    string $topic,
    string $type,
    string $remoteId,
    int $accountId = 1,
    string $status = 'pending',
    int $eventId = 0,
    int $occurrences = 1
): void {
    $eventId = $eventId > 0 ? $eventId : $id;
    $pdo->prepare('INSERT INTO meli_notification_events(id,work_item_id,status,disposition) VALUES (?,?,"queued",NULL)')
        ->execute([$eventId, $id]);
    $pdo->prepare(
        'INSERT INTO meli_notification_work_items
         (id,meli_account_id,account_scope_key,meli_user_id,canonical_topic,resource_type,remote_resource_id,
          latest_event_id,priority,occurrence_count,status,next_run_at,correlation_id,first_received_at,last_received_at)
         VALUES (?,?,?,?,?,?,?,?,10,?,?,UTC_TIMESTAMP(3),?,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))'
    )->execute([
        $id,
        $accountId > 0 ? $accountId : null,
        $accountId > 0 ? 'account:' . $accountId : 'user:1001',
        1001,
        $topic,
        $type,
        $remoteId,
        $eventId,
        $occurrences,
        $status,
        'k2-' . $id,
    ]);
};

try {
    putenv('APP_KEY=k2-notification-forward-local-only');
    $_ENV['APP_KEY'] = 'k2-notification-forward-local-only';
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['ML_WRITE_ENABLED'] = 'false';

    $server = $connect($dsn, $user, $pass);
    $server->exec('CREATE DATABASE ' . $quote($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = $connect($dsn . ';dbname=' . $database, $user, $pass);
    Database::setConnection($pdo);

    $pdo->exec("CREATE TABLE companies(id BIGINT UNSIGNED NOT NULL PRIMARY KEY,name VARCHAR(191) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_accounts(id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_user_id VARCHAR(64) NULL,account_name VARCHAR(191) NULL,status VARCHAR(32) NOT NULL,PRIMARY KEY(id),UNIQUE KEY uq_meli_accounts_company_id(company_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE app_settings(setting_key VARCHAR(120) NOT NULL PRIMARY KEY,setting_value LONGTEXT NULL,is_encrypted TINYINT(1) NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT 'general') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_control(control_key VARCHAR(32) NOT NULL PRIMARY KEY,engine_state ENUM('STOPPED','CERTIFIED','ACTIVE') NOT NULL DEFAULT 'STOPPED',readiness_state ENUM('NOT_READY','READY_TO_TEST','TESTING','CERTIFIED','FAILED') NOT NULL DEFAULT 'NOT_READY',scheduler_enabled TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_jobs(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,job_type ENUM('fresh_orders_discovery','order_exact','domain_exact') NOT NULL,resource_id VARCHAR(191) NULL,idempotency_key VARCHAR(191) NOT NULL,state ENUM('ready','running','waiting','review','dead','completed') NOT NULL DEFAULT 'ready',attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),lease_owner VARCHAR(96) NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,lease_expires_at DATETIME(3) NULL,payload_json JSON NOT NULL,last_error_class VARCHAR(100) NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),completed_at DATETIME(3) NULL,PRIMARY KEY(id),UNIQUE KEY uq_qv4_job_identity(company_id,meli_account_id,job_type,idempotency_key),KEY idx_qv4_fifo(state,available_at,id),KEY idx_qv4_tenant(company_id,meli_account_id,state,id),KEY idx_qv4_lease(state,lease_expires_at,id),UNIQUE KEY uq_qv4_job_tenant_id(id,company_id,meli_account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_attempts(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,job_id BIGINT UNSIGNED NOT NULL,run_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,lease_owner VARCHAR(96) NOT NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,outcome ENUM('running','completed','waiting','review','dead','lease_expired') NOT NULL DEFAULT 'running',error_class VARCHAR(100) NULL,dispatch_state ENUM('NOT_DISPATCHED','DISPATCHED','KNOWN','UNKNOWN') NOT NULL DEFAULT 'NOT_DISPATCHED',started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL,source_closed_at DATETIME(3) NULL,PRIMARY KEY(id),UNIQUE KEY uq_qv4_attempt(job_id,lease_owner),KEY idx_qv4_attempt_tenant(company_id,meli_account_id,started_at),KEY idx_qv4_attempt_run(run_id,company_id,meli_account_id),CONSTRAINT fk_qv4_attempt_job FOREIGN KEY(job_id) REFERENCES queue_v4_clean_jobs(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_runs(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,launcher ENUM('scheduler','manual','test') NOT NULL,worker_ref VARCHAR(96) NOT NULL,status ENUM('running','completed','failed','stopped') NOT NULL DEFAULT 'running',jobs_claimed INT UNSIGNED NOT NULL DEFAULT 0,jobs_completed INT UNSIGNED NOT NULL DEFAULT 0,jobs_deferred INT UNSIGNED NOT NULL DEFAULT 0,started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL,PRIMARY KEY(id),KEY idx_qv4_runs_status(status,started_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_leases(lease_key VARCHAR(64) NOT NULL PRIMARY KEY,owner_ref VARCHAR(96) NULL,acquired_at DATETIME(3) NULL,heartbeat_at DATETIME(3) NULL,expires_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_notification_events(id BIGINT UNSIGNED NOT NULL PRIMARY KEY,work_item_id BIGINT UNSIGNED NULL,status VARCHAR(30) NOT NULL DEFAULT 'queued',disposition VARCHAR(80) NULL,error_message VARCHAR(500) NULL,acknowledged_at DATETIME(3) NULL,processed_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_notification_work_items(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,account_scope_key VARCHAR(80) NOT NULL,meli_user_id BIGINT UNSIGNED NULL,canonical_topic VARCHAR(40) NOT NULL,resource_type VARCHAR(40) NOT NULL,remote_resource_id VARCHAR(100) NOT NULL,latest_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,latest_event_sent_at DATETIME(3) NULL,priority INT NOT NULL DEFAULT 50,occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,status VARCHAR(40) NOT NULL DEFAULT 'pending',next_run_at DATETIME(3) NULL,correlation_id VARCHAR(80) NULL,last_result VARCHAR(100) NULL,first_received_at DATETIME(3) NULL,last_received_at DATETIME(3) NULL,locked_by VARCHAR(96) NULL,locked_at DATETIME(3) NULL,lock_expires_at DATETIME(3) NULL,last_error_code VARCHAR(100) NULL,last_error_message VARCHAR(500) NULL,last_error_diagnostic_id VARCHAR(80) NULL,last_error_stage VARCHAR(80) NULL,last_processed_at DATETIME(3) NULL,last_success_at DATETIME(3) NULL,completed_at DATETIME(3) NULL,processing_event_id BIGINT UNSIGNED NULL,rerun_requested TINYINT(1) NOT NULL DEFAULT 0,consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,attempts INT UNSIGNED NOT NULL DEFAULT 0,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),UNIQUE KEY uq_notification_resource(meli_account_id,resource_type,remote_resource_id),KEY idx_notification_k2(status,next_run_at,priority,id),KEY idx_notification_scope(meli_account_id,status,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,readiness_state,scheduler_enabled) VALUES ('primary','ACTIVE','CERTIFIED',1)");
    $pdo->exec("INSERT INTO companies(id,name) VALUES (1,'K2')");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,status) VALUES (1,1,'1001','K2','conectado'),(2,1,'1002','K2 B','paused')");

    $stage = new QueueV4CleanNotificationAdmissionStage($pdo);
    $insertWork($pdo, 1, 'order', 'order', '10001');
    $first = $stage->run(10);
    $assert(($first['activated'] ?? false) === true && (int) $first['watermark_id'] === 1, 'K2_FORWARD_WATERMARK_NOT_ESTABLISHED');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn() === 0, 'K2_HISTORICAL_CANONICAL_ADMISSION_NOT_ZERO');
    $assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=1')->fetchColumn() === 'pending', 'K2_HISTORICAL_ROW_MUTATED');

    $insertWork($pdo, 2, 'order', 'order', '10002', 1, 'pending', 2, 2);
    $insertWork($pdo, 3, 'shipment', 'shipment', '20003');
    $second = $stage->run(10);
    $assert((int) $second['admitted'] === 1, 'K2_ORDER_ADMISSION_COUNT');
    $assert((int) $second['unsupported'] === 1, 'K2_UNSUPPORTED_COUNT');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='order_exact' AND idempotency_key='notification:order_exact:work:2'")->fetchColumn() === 1, 'K2_ORDER_POINTER_NOT_UNIQUE');
    $assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=2')->fetchColumn() === 'queued', 'K2_SOURCE_NOT_QUEUED_AFTER_ADMISSION');
    $assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=3')->fetchColumn() === 'paused', 'K2_UNSUPPORTED_NOT_PAUSED');

    $noop = $stage->run(10);
    $assert((int) $noop['admitted'] === 0 && (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE idempotency_key='notification:order_exact:work:2'")->fetchColumn() === 1, 'K2_DUPLICATE_WEBHOOK_ADMISSION_NOT_IDEMPOTENT');

    $executed = [];
    $repo = new QueueV4CleanRepository($pdo);
    $drainer = new CanonicalFifoDrainer($pdo, $repo, null, null, static function (array $job) use (&$executed): void {
        $executed[] = (string) $job['idempotency_key'];
    });
    $result = $drainer->run('test', 1, 10);
    $assert((int) $result['completed'] === 1 && $executed === ['notification:order_exact:work:2'], 'K2_CANONICAL_DRAINER_DID_NOT_EXECUTE_ORDER');
    $reconciled = $stage->run(10);
    $assert((int) $reconciled['completed'] === 1, 'K2_SOURCE_POINTER_CONVERGENCE_NOT_RECONCILED');
    $assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=2')->fetchColumn() === 'complete', 'K2_SOURCE_NOT_COMPLETED_AFTER_POINTER_COMPLETED');
    $assert((string) $pdo->query('SELECT status FROM meli_notification_events WHERE id=2')->fetchColumn() === 'processed', 'K2_EVENT_NOT_PROCESSED_AFTER_POINTER_COMPLETED');

    $insertWork($pdo, 4, 'order', 'order', '10004');
    $start = microtime(true) + 0.35;
    $outputs = [];
    $processes = [];
    foreach (['a', 'b'] as $suffix) {
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = array_merge(getenv() ?: [], [
            'K2_DSN' => $dsn . ';dbname=' . $database,
            'K2_USER' => $user,
            'K2_PASS' => $pass,
            'K2_START' => (string) $start,
            'APP_KEY' => 'k2-notification-forward-race',
            'ML_WRITE_ENABLED' => 'false',
        ]);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --admission-child';
        $process = proc_open($cmd, $descriptor, $pipes, $root, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('K2_RACE_CHILD_START_FAILED');
        }
        $processes[] = [$process, $pipes, $suffix];
    }
    foreach ($processes as [$process, $pipes, $suffix]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $outputs[$suffix] = ['exit' => $exit, 'stdout' => trim((string) $stdout), 'stderr' => trim((string) $stderr)];
    }
    $assert(($outputs['a']['exit'] ?? 1) === 0 && ($outputs['b']['exit'] ?? 1) === 0, 'K2_RACE_CHILD_EXIT:' . json_encode($outputs));
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE idempotency_key='notification:order_exact:work:4'")->fetchColumn() === 1, 'K2_TWO_PROCESS_RACE_DUPLICATED_POINTER');
    $assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=4')->fetchColumn() === 'queued', 'K2_TWO_PROCESS_RACE_SOURCE_NOT_QUEUED');

    echo "QUEUE_V4_K2_NOTIFICATIONS_FORWARD_PATH=PASS checks=$checks\n";
    echo "HISTORICAL_ROWS_MUTATED=0\n";
    echo "HISTORICAL_CANONICAL_ADMISSIONS=0\n";
    echo "DUPLICATE_WEBHOOK_CANONICAL_ADMISSIONS=1\n";
    echo "TWO_PROCESS_ADMISSION_RACE=PASS\n";
    echo "SOURCE_POINTER_CONVERGENCE=PASS\n";
} finally {
    try {
        if (isset($server) && $server instanceof PDO) {
            $server->exec('DROP DATABASE IF EXISTS ' . $quote($database));
        }
    } catch (Throwable) {
    }
}
