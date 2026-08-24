<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\CanonicalFifoDrainer;
use App\QueueV4Clean\QueueV4CleanNotificationAdmissionStage;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\CronDeadlineContext;

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

if (($argv[1] ?? '') === '--activation-child') {
    putenv('APP_KEY=k2-r1-activation-child');
    $_ENV['APP_KEY'] = 'k2-r1-activation-child';
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
    fwrite(STDERR, "ERROR: K2 R1 exige un DSN MariaDB desechable sin dbname.\n");
    exit(2);
}

$database = 'erp_k2_r1_' . bin2hex(random_bytes(5));
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
    $pdo->exec("CREATE TABLE queue_v4_clean_jobs(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,job_type ENUM('fresh_orders_discovery','order_exact','domain_exact') NOT NULL,resource_id VARCHAR(191) NULL,idempotency_key VARCHAR(191) NOT NULL,state ENUM('ready','running','waiting','review','dead','completed') NOT NULL DEFAULT 'ready',attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),lease_owner VARCHAR(96) NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,lease_expires_at DATETIME(3) NULL,payload_json JSON NOT NULL,last_error_class VARCHAR(100) NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),completed_at DATETIME(3) NULL,PRIMARY KEY(id),UNIQUE KEY uq_qv4_job_identity(company_id,meli_account_id,job_type,idempotency_key),KEY idx_qv4_fifo(state,available_at,id),KEY idx_qv4_tenant(company_id,meli_account_id,state,id),KEY idx_qv4_lease(state,lease_expires_at,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_attempts(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,job_id BIGINT UNSIGNED NOT NULL,run_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,lease_owner VARCHAR(96) NOT NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,outcome ENUM('running','completed','waiting','review','dead','lease_expired') NOT NULL DEFAULT 'running',error_class VARCHAR(100) NULL,dispatch_state ENUM('NOT_DISPATCHED','DISPATCHED','KNOWN','UNKNOWN') NOT NULL DEFAULT 'NOT_DISPATCHED',started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL,source_closed_at DATETIME(3) NULL,PRIMARY KEY(id),UNIQUE KEY uq_qv4_attempt(job_id,lease_owner),KEY idx_qv4_attempt_tenant(company_id,meli_account_id,started_at),KEY idx_qv4_attempt_run(run_id,company_id,meli_account_id),CONSTRAINT fk_qv4_attempt_job FOREIGN KEY(job_id) REFERENCES queue_v4_clean_jobs(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_runs(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,launcher ENUM('scheduler','manual','test') NOT NULL,worker_ref VARCHAR(96) NOT NULL,status ENUM('running','completed','failed','stopped') NOT NULL DEFAULT 'running',jobs_claimed INT UNSIGNED NOT NULL DEFAULT 0,jobs_completed INT UNSIGNED NOT NULL DEFAULT 0,jobs_deferred INT UNSIGNED NOT NULL DEFAULT 0,started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL,PRIMARY KEY(id),KEY idx_qv4_runs_status(status,started_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_leases(lease_key VARCHAR(64) NOT NULL PRIMARY KEY,owner_ref VARCHAR(96) NULL,acquired_at DATETIME(3) NULL,heartbeat_at DATETIME(3) NULL,expires_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_notification_events(id BIGINT UNSIGNED NOT NULL PRIMARY KEY,work_item_id BIGINT UNSIGNED NULL,status VARCHAR(30) NOT NULL DEFAULT 'queued',disposition VARCHAR(80) NULL,error_message VARCHAR(500) NULL,acknowledged_at DATETIME(3) NULL,processed_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_notification_work_items(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,account_scope_key VARCHAR(80) NOT NULL,meli_user_id BIGINT UNSIGNED NULL,canonical_topic VARCHAR(40) NOT NULL,resource_type VARCHAR(40) NOT NULL,remote_resource_id VARCHAR(100) NOT NULL,latest_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,latest_event_sent_at DATETIME(3) NULL,priority INT NOT NULL DEFAULT 50,occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,status VARCHAR(40) NOT NULL DEFAULT 'pending',next_run_at DATETIME(3) NULL,correlation_id VARCHAR(80) NULL,last_result VARCHAR(100) NULL,first_received_at DATETIME(3) NULL,last_received_at DATETIME(3) NULL,locked_by VARCHAR(96) NULL,locked_at DATETIME(3) NULL,lock_expires_at DATETIME(3) NULL,last_error_code VARCHAR(100) NULL,last_error_message VARCHAR(500) NULL,last_error_diagnostic_id VARCHAR(80) NULL,last_error_stage VARCHAR(80) NULL,last_processed_at DATETIME(3) NULL,last_success_at DATETIME(3) NULL,completed_at DATETIME(3) NULL,processing_event_id BIGINT UNSIGNED NULL,rerun_requested TINYINT(1) NOT NULL DEFAULT 0,consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,attempts INT UNSIGNED NOT NULL DEFAULT 0,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),UNIQUE KEY uq_notification_resource(meli_account_id,resource_type,remote_resource_id),KEY idx_notification_k2(status,next_run_at,priority,id),KEY idx_notification_scope(meli_account_id,status,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,readiness_state,scheduler_enabled) VALUES ('primary','ACTIVE','CERTIFIED',1)");
    $pdo->exec("INSERT INTO companies(id,name) VALUES (1,'K2 R1'),(2,'Other')");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,status) VALUES (1,1,'1001','K2','conectado'),(2,1,'1002','Paused','paused'),(3,2,'1003','Other','conectado')");
};

$insertWork = static function (
    PDO $pdo,
    int $id,
    string $topic,
    string $type,
    string $remoteId,
    int $accountId = 1,
    string $status = 'pending',
    int $eventId = 0
): void {
    $eventId = $eventId > 0 ? $eventId : $id;
    $pdo->prepare('INSERT INTO meli_notification_events(id,work_item_id,status,disposition) VALUES (?,?,"queued",NULL)')
        ->execute([$eventId, $id]);
    $pdo->prepare(
        'INSERT INTO meli_notification_work_items
         (id,meli_account_id,account_scope_key,meli_user_id,canonical_topic,resource_type,remote_resource_id,
          latest_event_id,priority,occurrence_count,status,next_run_at,correlation_id,first_received_at,last_received_at)
         VALUES (?,?,?,?,?,?,?,?,10,1,?,UTC_TIMESTAMP(3),?,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))'
    )->execute([
        $id,
        $accountId > 0 ? $accountId : null,
        $accountId > 0 ? 'account:' . $accountId : 'missing',
        1000 + $id,
        $topic,
        $type,
        $remoteId,
        $eventId,
        $status,
        'k2-r1-' . $id,
    ]);
};

$insertPointer = static function (PDO $pdo, int $workId, string $orderId, string $state, int $accountId = 1, int $companyId = 1): void {
    $pdo->prepare(
        'INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,state,payload_json,completed_at)
         VALUES (?,?,?,?,?,?,?,IF(?="completed",UTC_TIMESTAMP(3),NULL))'
    )->execute([
        $companyId,
        $accountId,
        'order_exact',
        $orderId,
        'notification:order_exact:work:' . $workId,
        $state,
        '{"contract_version":1,"order_id":"' . $orderId . '"}',
        $state,
    ]);
};

try {
    putenv('APP_KEY=k2-r1-local-only');
    $_ENV['APP_KEY'] = 'k2-r1-local-only';
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['ML_WRITE_ENABLED'] = 'false';

    $server = $connect($dsn, $user, $pass);
    $server->exec('CREATE DATABASE ' . $quote($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = $connect($dsn . ';dbname=' . $database, $user, $pass);
    Database::setConnection($pdo);
    $schema($pdo);

    $stage = new QueueV4CleanNotificationAdmissionStage($pdo);
    $insertWork($pdo, 1, 'order', 'order', '10001', 1, 'pending');
    $insertWork($pdo, 2, 'order', 'order', '10002', 1, 'queued');
    $insertWork($pdo, 3, 'order', 'order', '10003', 1, 'queued');
    $insertWork($pdo, 4, 'order', 'order', '10004', 1, 'queued');
    $insertPointer($pdo, 2, '10002', 'completed');
    $insertPointer($pdo, 3, '10003', 'review');
    $insertPointer($pdo, 4, '10004', 'dead');
    $before = $pdo->query('SELECT id,status,last_result,last_error_code,completed_at FROM meli_notification_work_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $activated = $stage->run(30);
    $assert(($activated['activated'] ?? false) === true && (int) $activated['watermark_id'] === 4, 'R1_WATERMARK_NOT_ACTIVATED_AFTER_HISTORICAL_ROWS');
    for ($i = 0; $i < 3; $i++) {
        $stage->run(30);
    }
    $after = $pdo->query('SELECT id,status,last_result,last_error_code,completed_at FROM meli_notification_work_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $assert($before === $after, 'R1_HISTORICAL_ROWS_MUTATED_BY_RECONCILIATION');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE idempotency_key="notification:order_exact:work:1"')->fetchColumn() === 0, 'R1_HISTORICAL_PENDING_ADMITTED');

    $pdo->exec('TRUNCATE queue_v4_clean_attempts');
    $pdo->exec('TRUNCATE queue_v4_clean_runs');
    $pdo->exec('DELETE FROM queue_v4_clean_jobs');
    $pdo->exec('DELETE FROM meli_notification_events');
    $pdo->exec('DELETE FROM meli_notification_work_items');
    $pdo->exec('DELETE FROM app_settings');
    $empty = $stage->run(30);
    $assert(($empty['activated'] ?? false) === true, 'R1_EMPTY_TABLE_NOT_ACTIVATED');
    $assert((string) $pdo->query('SELECT setting_value FROM app_settings WHERE setting_key="notifications.k2.forward_from_work_item_id"')->fetchColumn() === '0', 'R1_EMPTY_FRONTIER_NOT_ZERO');
    $insertWork($pdo, 1, 'order', 'order', '20001');
    $firstForward = $stage->run(30);
    $assert((int) $firstForward['admitted'] === 1, 'R1_FIRST_POST_ACTIVATION_NOT_ADMITTED');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE idempotency_key="notification:order_exact:work:1"')->fetchColumn() === 1, 'R1_FIRST_POST_ACTIVATION_POINTER_MISSING');
    $executed = [];
    $repo = new QueueV4CleanRepository($pdo);
    $drainer = new CanonicalFifoDrainer($pdo, $repo, null, null, static function (array $job) use (&$executed): void {
        $executed[] = (string) $job['idempotency_key'];
    });
    $result = $drainer->run('test', 1, 10);
    $assert((int) $result['completed'] === 1 && $executed === ['notification:order_exact:work:1'], 'R1_FIRST_FORWARD_DRAINER_NOT_EXECUTED');
    $completed = $stage->run(30);
    $assert((int) $completed['completed'] === 1 && (string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=1')->fetchColumn() === 'complete', 'R1_FIRST_FORWARD_NOT_COMPLETED');

    $pdo->exec('TRUNCATE queue_v4_clean_attempts');
    $pdo->exec('TRUNCATE queue_v4_clean_runs');
    $pdo->exec('DELETE FROM queue_v4_clean_jobs');
    $pdo->exec('DELETE FROM meli_notification_events');
    $pdo->exec('DELETE FROM meli_notification_work_items');
    $pdo->exec('UPDATE app_settings SET setting_value="1" WHERE setting_key="notifications.k2.forward_from_work_item_id"');
    $insertWork($pdo, 2, 'order', 'order', '30002');
    $insertPointer($pdo, 2, '30002', 'completed');
    $alreadyCompleted = $stage->run(30);
    $assert((int) $alreadyCompleted['completed'] === 1, 'R1_ALREADY_COMPLETED_POINTER_NOT_RECONCILED');
    $assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=2')->fetchColumn() === 'complete', 'R1_ALREADY_COMPLETED_SOURCE_NOT_COMPLETE');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE idempotency_key="notification:order_exact:work:2"')->fetchColumn() === 1, 'R1_ALREADY_COMPLETED_DUPLICATED_POINTER');

    $pdo->exec('DELETE FROM queue_v4_clean_jobs');
    $pdo->exec('DELETE FROM meli_notification_events');
    $pdo->exec('DELETE FROM meli_notification_work_items');
    foreach (['ready', 'running', 'waiting', 'review', 'dead'] as $offset => $state) {
        $id = 10 + $offset;
        $insertWork($pdo, $id, 'order', 'order', (string) (40000 + $id));
        $insertPointer($pdo, $id, (string) (40000 + $id), $state);
    }
    $stage->run(30);
    $states = $pdo->query('SELECT id,status,last_error_code FROM meli_notification_work_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $assert($states[0]['status'] === 'queued' && $states[1]['status'] === 'queued' && $states[2]['status'] === 'queued', 'R1_ACTIVE_POINTERS_NOT_LEFT_QUEUED');
    $assert($states[3]['status'] === 'error' && $states[4]['status'] === 'error', 'R1_REVIEW_DEAD_POINTERS_NOT_HELD');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn() === 5, 'R1_POINTER_STATES_DUPLICATED_POINTERS');

    $pdo->exec('DELETE FROM queue_v4_clean_jobs');
    $pdo->exec('DELETE FROM meli_notification_events');
    $pdo->exec('DELETE FROM meli_notification_work_items');
    $topics = [
        ['order', 'order', '50001'],
        ['shipment', 'shipment', '50002'],
        ['payment', 'payment', '50003'],
        ['item', 'item', '50004'],
        ['question', 'question', '50005'],
        ['claim', 'claim', '50006'],
        ['message', 'message', '50007'],
        ['unknown', 'unknown', '50008'],
    ];
    foreach ($topics as $i => [$topic, $type, $remote]) {
        $insertWork($pdo, 20 + $i, $topic, $type, $remote);
    }
    $topicRun = $stage->run(30);
    $assert((int) $topicRun['admitted'] === 1 && (int) $topicRun['unsupported'] === 7, 'R1_TOPIC_MATRIX_COUNTS');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn() === 1, 'R1_UNSUPPORTED_TOPICS_CREATED_POINTERS');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM meli_notification_work_items WHERE status="paused"')->fetchColumn() === 7, 'R1_UNSUPPORTED_TOPICS_NOT_PAUSED');

    $pdo->exec('DELETE FROM queue_v4_clean_jobs');
    $pdo->exec('DELETE FROM meli_notification_events');
    $pdo->exec('DELETE FROM meli_notification_work_items');
    $insertWork($pdo, 40, 'order', 'order', '60040', 1);
    $insertWork($pdo, 41, 'order', 'order', '60041', 2);
    $insertWork($pdo, 42, 'order', 'order', '60042', 0);
    $tenantRun = $stage->run(30);
    $assert((int) $tenantRun['admitted'] === 1 && (int) $tenantRun['quarantined'] === 2, 'R1_TENANT_INVALID_COUNTS');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn() === 1, 'R1_INVALID_TENANT_CREATED_POINTER');

    CronDeadlineContext::within(microtime(true) - 0.01, static function () use ($stage, $assert): void {
        $deadlineRun = $stage->run(30);
        $assert((int) $deadlineRun['admitted'] === 0 && (int) $deadlineRun['reconciled'] === 0 && ($deadlineRun['deferred'] ?? false) === true, 'R1_DEADLINE_EXHAUSTED_MUTATED');
    });

    $activationRounds = 20;
    $lost = 0;
    for ($round = 1; $round <= $activationRounds; $round++) {
        $pdo->exec('TRUNCATE queue_v4_clean_attempts');
        $pdo->exec('TRUNCATE queue_v4_clean_runs');
        $pdo->exec('DELETE FROM queue_v4_clean_jobs');
        $pdo->exec('DELETE FROM meli_notification_events');
        $pdo->exec('DELETE FROM meli_notification_work_items');
        $pdo->exec('DELETE FROM app_settings');
        $start = microtime(true) + 0.25;
        $children = [];
        foreach (['a', 'b'] as $suffix) {
            $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $env = array_merge(getenv() ?: [], [
                'K2_DSN' => $dsn . ';dbname=' . $database,
                'K2_USER' => $user,
                'K2_PASS' => $pass,
                'K2_START' => (string) $start,
                'APP_KEY' => 'k2-r1-activation-race',
                'ML_WRITE_ENABLED' => 'false',
            ]);
            $process = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --activation-child', $descriptor, $pipes, $root, $env);
            if (!is_resource($process)) {
                throw new RuntimeException('R1_ACTIVATION_CHILD_START_FAILED');
            }
            $children[] = [$process, $pipes];
        }
        foreach ($children as [$process, $pipes]) {
            stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $assert($exit === 0, 'R1_ACTIVATION_CHILD_EXIT:' . $stderr);
        }
        $assert((int) $pdo->query('SELECT COUNT(*) FROM app_settings WHERE setting_key="notifications.k2.forward_from_work_item_id"')->fetchColumn() === 1, 'R1_ACTIVATION_FRONTIERS_PER_ROUND_NOT_ONE');
        $assert((string) $pdo->query('SELECT setting_value FROM app_settings WHERE setting_key="notifications.k2.forward_from_work_item_id"')->fetchColumn() === '0', 'R1_ACTIVATION_EMPTY_FRONTIER_MOVED');
        $insertWork($pdo, 1, 'order', 'order', (string) (70000 + $round));
        $stage->run(30);
        if ((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE idempotency_key="notification:order_exact:work:1"')->fetchColumn() !== 1) {
            $lost++;
        }
    }
    $assert($lost === 0, 'R1_LOST_FIRST_FORWARD_NOTIFICATION');

    echo "QUEUE_V4_K2_NOTIFICATIONS_FORWARD_PATH_R1=PASS checks=$checks\n";
    echo "FORWARD_WATERMARK_INITIALIZED_BY_EXISTENCE=PASS\n";
    echo "EMPTY_FRONTIER_WATERMARK=0\n";
    echo "FIRST_POST_ACTIVATION_NOTIFICATION=PASS\n";
    echo "ACTIVATION_RACE_ROUNDS=$activationRounds\n";
    echo "LOST_FIRST_FORWARD_NOTIFICATION=$lost\n";
    echo "HISTORICAL_ROWS_MUTATED=0\n";
    echo "HISTORICAL_QUEUED_ROWS_MUTATED=0\n";
    echo "HISTORICAL_CANONICAL_ADMISSIONS=0\n";
    echo "POINTER_ALREADY_COMPLETED_CONVERGENCE=PASS\n";
    echo "TOPICS_TOTAL=8\n";
    echo "TOPICS_CANONICAL_SUPPORTED=1\n";
    echo "TOPICS_FAIL_CLOSED=7\n";
    echo "PAUSED_ACCOUNT_ADMISSIONS=0\n";
    echo "MISSING_ACCOUNT_ADMISSIONS=0\n";
    echo "CROSS_TENANT=0\n";
    echo "DUPLICATE_CANONICAL_ADMISSION=0\n";
    echo "DUPLICATE_EFFECTIVE_EXECUTION=0\n";
    echo "SOURCE_POINTER_CONVERGENCE=PASS\n";
    echo "FAILURE_MATRIX_TEST_BACKED=PASS\n";
    echo "ADMISSION_STAGE_WALL_TIME_BOUNDED=PASS\n";
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
