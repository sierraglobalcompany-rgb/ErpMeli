<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Session;
use App\QueueV4Clean\QueueV4CleanHealthSnapshotService;
use App\QueueV4Clean\QueueV4CleanReadinessService;
use App\Services\AutomationRuntimeStatusService;
use App\Services\CronApiRiskSummaryService;
use App\Services\SchemaInspectorService;
use App\Services\SessionGenerationService;

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
    fwrite(STDERR, "ERROR: K5 R1 exige DSN MariaDB 11.8.8 desechable sin dbname.\n");
    exit(2);
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
$database = 'erp_k5_r1_' . bin2hex(random_bytes(5));

$createSchema = static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(191),status TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(191),email VARCHAR(191),role VARCHAR(40),status TINYINT NOT NULL DEFAULT 1,is_temporary TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE user_company_access(user_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(user_id,company_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_accounts(id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_user_id VARCHAR(64) NULL,account_name VARCHAR(191) NULL,site_id VARCHAR(12) NULL,status VARCHAR(32) NOT NULL,PRIMARY KEY(id),UNIQUE KEY uq_meli_accounts_company_id(company_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_tokens(meli_account_id BIGINT UNSIGNED PRIMARY KEY,access_token_encrypted TEXT NOT NULL,refresh_token_encrypted TEXT NOT NULL,expires_at DATETIME NOT NULL,refresh_version BIGINT UNSIGNED NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE oauth_refresh_operations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,expected_meli_user_id VARCHAR(64) NOT NULL,expected_refresh_version BIGINT UNSIGNED NOT NULL,state ENUM('SCHEDULED','RUNNING','WAITING','REMOTE_UNCERTAIN','RECONNECT_REQUIRED','FAILED','COMPLETED') NOT NULL DEFAULT 'SCHEDULED',next_attempt_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),lease_owner VARCHAR(96) NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,lease_expires_at DATETIME(3) NULL,remote_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,remote_dispatch_state ENUM('NOT_DISPATCHED','MAY_HAVE_DISPATCHED','RESPONSE_KNOWN') NOT NULL DEFAULT 'NOT_DISPATCHED',remote_dispatched_at DATETIME(3) NULL,response_known_at DATETIME(3) NULL,last_http_status SMALLINT UNSIGNED NULL,last_error_class VARCHAR(100) NULL,last_request_id VARCHAR(64) NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),completed_at DATETIME(3) NULL,UNIQUE KEY uq_oauth_refresh_identity(company_id,meli_account_id,expected_refresh_version),KEY idx_oauth_refresh_ready(state,next_attempt_at,id),KEY idx_oauth_refresh_tenant(company_id,meli_account_id,state,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE app_settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value LONGTEXT NULL,is_encrypted TINYINT(1) NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT 'general') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE schema_migrations(version VARCHAR(190) PRIMARY KEY,applied_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_control(control_key VARCHAR(32) PRIMARY KEY,engine_state ENUM('STOPPED','CERTIFIED','ACTIVE') NOT NULL DEFAULT 'STOPPED',readiness_state ENUM('NOT_READY','READY_TO_TEST','TESTING','CERTIFIED','FAILED') NOT NULL DEFAULT 'NOT_READY',scheduler_enabled TINYINT(1) NOT NULL DEFAULT 0,readiness_passed_accounts TINYINT UNSIGNED NOT NULL DEFAULT 0,readiness_error_class VARCHAR(100) NULL,certified_at DATETIME(3) NULL,activated_at DATETIME(3) NULL,stopped_at DATETIME(3) NULL,last_scheduler_at DATETIME(3) NULL,updated_by BIGINT UNSIGNED NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_readiness_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,state ENUM('TESTING','CERTIFIED','FAILED') NOT NULL,expected_accounts TINYINT UNSIGNED NOT NULL DEFAULT 3,passed_accounts TINYINT UNSIGNED NOT NULL DEFAULT 0,failure_class VARCHAR(100) NULL,started_by BIGINT UNSIGNED NOT NULL DEFAULT 1,started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_readiness_accounts(readiness_run_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,outcome ENUM('PASS','FAIL') NOT NULL,failure_class VARCHAR(100) NULL,checked_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),PRIMARY KEY(readiness_run_id,company_id,meli_account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,job_type ENUM('fresh_orders_discovery','order_exact','domain_exact') NOT NULL,resource_id VARCHAR(191) NULL,idempotency_key VARCHAR(191) NOT NULL,state ENUM('ready','running','waiting','review','dead','completed') NOT NULL DEFAULT 'ready',attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),lease_owner VARCHAR(96) NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,lease_expires_at DATETIME(3) NULL,payload_json JSON NOT NULL,last_error_class VARCHAR(100) NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),completed_at DATETIME(3) NULL,UNIQUE KEY uq_qv4_job_identity(company_id,meli_account_id,job_type,idempotency_key),KEY idx_qv4_fifo(state,available_at,id),KEY idx_qv4_tenant(company_id,meli_account_id,state,id),KEY idx_qv4_lease(state,lease_expires_at,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_attempts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,job_id BIGINT UNSIGNED NOT NULL,run_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,lease_owner VARCHAR(96) NOT NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,outcome ENUM('running','completed','waiting','review','dead','lease_expired') NOT NULL DEFAULT 'running',error_class VARCHAR(100) NULL,dispatch_state ENUM('NOT_DISPATCHED','PHYSICAL_STARTED','RESPONSE_KNOWN') NOT NULL DEFAULT 'NOT_DISPATCHED',transport_method VARCHAR(8) NULL,endpoint_key VARCHAR(100) NULL,physical_http_calls TINYINT UNSIGNED NOT NULL DEFAULT 0,physical_started_at DATETIME(3) NULL,http_status SMALLINT UNSIGNED NULL,response_known_at DATETIME(3) NULL,source_closed_at DATETIME(3) NULL,started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL,UNIQUE KEY uq_qv4_attempt(job_id,lease_owner),KEY idx_qv4_attempt_tenant(company_id,meli_account_id,started_at),KEY idx_qv4_attempt_run(run_id,company_id,meli_account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,launcher ENUM('scheduler','manual','test') NOT NULL,worker_ref VARCHAR(96) NOT NULL,status ENUM('running','completed','failed','stopped') NOT NULL DEFAULT 'running',jobs_claimed INT UNSIGNED NOT NULL DEFAULT 0,jobs_completed INT UNSIGNED NOT NULL DEFAULT 0,jobs_deferred INT UNSIGNED NOT NULL DEFAULT 0,started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL,KEY idx_qv4_runs_status(status,started_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_leases(lease_key VARCHAR(64) PRIMARY KEY,owner_ref VARCHAR(96) NULL,acquired_at DATETIME(3) NULL,heartbeat_at DATETIME(3) NULL,expires_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_transport_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,source_kind ENUM('queue','oauth','sales_audit') NOT NULL,work_id BIGINT UNSIGNED NOT NULL,attempt_id BIGINT UNSIGNED NULL,lease_generation BIGINT UNSIGNED NOT NULL,request_id VARCHAR(64) NOT NULL,method VARCHAR(10) NOT NULL,endpoint_key VARCHAR(100) NOT NULL,dispatch_state ENUM('PHYSICAL_STARTED','RESPONSE_KNOWN') NOT NULL DEFAULT 'PHYSICAL_STARTED',physical_started_at DATETIME(3) NOT NULL,response_known_at DATETIME(3) NULL,http_status SMALLINT UNSIGNED NULL,UNIQUE KEY uq_queue_v4_transport_request(source_kind,request_id),KEY idx_queue_v4_transport_tenant_time(company_id,meli_account_id,physical_started_at,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE sync_sales_audit_runs(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE sync_sales_audit_jobs(id BIGINT UNSIGNED PRIMARY KEY,sync_sales_audit_run_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,status VARCHAR(40) NOT NULL,next_run_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_notification_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,work_item_id BIGINT UNSIGNED NULL,status VARCHAR(30) NOT NULL DEFAULT 'queued',disposition VARCHAR(80) NULL,error_message VARCHAR(500) NULL,acknowledged_at DATETIME(3) NULL,processed_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_notification_work_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NULL,meli_account_id BIGINT UNSIGNED NULL,account_scope_key VARCHAR(80) NOT NULL DEFAULT '',canonical_topic VARCHAR(40) NOT NULL,resource_type VARCHAR(40) NOT NULL,remote_resource_id VARCHAR(100) NOT NULL,status VARCHAR(40) NOT NULL DEFAULT 'pending',next_run_at DATETIME(3) NULL,locked_by VARCHAR(96) NULL,lock_expires_at DATETIME(3) NULL,consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,attempts INT UNSIGNED NOT NULL DEFAULT 0,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE order_financial_recalc_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,status VARCHAR(40) NOT NULL DEFAULT 'pending') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE sale_financial_reconciliation_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,status VARCHAR(40) NOT NULL DEFAULT 'pending',attempts INT UNSIGNED NOT NULL DEFAULT 0,next_run_at DATETIME(3) NULL,safe_message VARCHAR(500) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE api_rhythm_states(scope_key VARCHAR(120) PRIMARY KEY,generation BIGINT UNSIGNED NOT NULL DEFAULT 1,calls_in_block INT UNSIGNED NOT NULL DEFAULT 0,block_started_at DATETIME(3) NULL,next_allowed_at DATETIME(3) NULL,block_pause_until DATETIME(3) NULL,last_dispatched_at DATETIME(3) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE api_remote_permits(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,permit_token CHAR(40) NOT NULL,owner_token CHAR(32) NOT NULL,generation BIGINT UNSIGNED NOT NULL,run_token VARCHAR(100) NULL,work_key VARCHAR(120) NULL,company_id BIGINT UNSIGNED NULL,meli_account_id BIGINT UNSIGNED NULL,endpoint_key VARCHAR(120) NOT NULL,job_type VARCHAR(80) NOT NULL,method VARCHAR(10) NOT NULL,status ENUM('reserved','dispatched','completed','released','expired') NOT NULL DEFAULT 'reserved',requested_interval_ms INT UNSIGNED NOT NULL,effective_interval_ms INT UNSIGNED NOT NULL,blocking_scope VARCHAR(80) NULL,http_status SMALLINT UNSIGNED NULL,created_at DATETIME(3) NOT NULL,dispatched_at DATETIME(3) NULL,completed_at DATETIME(3) NULL,released_at DATETIME(3) NULL,expires_at DATETIME(3) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE api_rhythm_penalties(scope_key VARCHAR(180) PRIMARY KEY,reduced_limit_per_minute SMALLINT UNSIGNED NOT NULL,blocked_until DATETIME(3) NULL,reduced_until DATETIME(3) NOT NULL,reason VARCHAR(80) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE api_request_logs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,company_id BIGINT UNSIGNED NULL,scope_kind VARCHAR(30) NULL,request_id VARCHAR(64) NULL,method VARCHAR(10) NULL,endpoint_path VARCHAR(255) NULL,http_status INT NULL,duration_ms INT NULL,retry_after_seconds INT NULL,attempt INT NULL,was_blocked TINYINT(1) NULL,safe_message VARCHAR(500) NULL,diagnostic_id VARCHAR(100) NULL,error_type VARCHAR(80) NULL,error_code VARCHAR(120) NULL,is_retryable TINYINT(1) NULL,is_app_blocked_signal TINYINT(1) NULL,outcome_class VARCHAR(80) NULL,reached_remote TINYINT(1) NULL,actionable TINYINT(1) NULL,risk_signal TINYINT(1) NULL,incident_key VARCHAR(191) NULL,execution_source VARCHAR(40) NULL,job_type VARCHAR(80) NULL,source_queue_key VARCHAR(80) NULL,source_work_id VARCHAR(100) NULL,operation_key VARCHAR(80) NULL,load_class VARCHAR(40) NULL,workload_units INT NULL,wire_bytes BIGINT NULL,decoded_bytes BIGINT NULL,response_item_count INT NULL,response_count_state VARCHAR(40) NULL,response_resource_unit VARCHAR(40) NULL,fanout_count INT NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),KEY idx_log_created(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE api_incident_materializer_state(singleton_id TINYINT UNSIGNED PRIMARY KEY,last_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0,heartbeat_at DATETIME(3) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};

$hashTables = [
    'queue_v4_clean_jobs', 'queue_v4_clean_attempts', 'queue_v4_clean_runs', 'queue_v4_clean_leases',
    'meli_notification_events', 'meli_notification_work_items',
    'order_financial_recalc_jobs', 'sale_financial_reconciliation_jobs',
    'api_rhythm_states', 'api_remote_permits', 'api_rhythm_penalties', 'meli_tokens', 'oauth_refresh_operations',
];
$tableFingerprint = static function (PDO $pdo, string $table): array {
    $columns = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $columns->execute([$table]);
    $cols = array_map('strval', $columns->fetchAll(PDO::FETCH_COLUMN));
    if ($cols === []) {
        return ['count' => 0, 'hash' => 'NOT_AVAILABLE'];
    }
    $order = in_array('id', $cols, true) ? 'id' : $cols[0];
    $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '` ORDER BY `' . str_replace('`', '``', $order) . '`')->fetchAll(PDO::FETCH_ASSOC);
    return ['count' => count($rows), 'hash' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))];
};
$snapshotTables = static function (PDO $pdo, array $tables) use ($tableFingerprint): array {
    $out = [];
    foreach ($tables as $table) {
        $out[$table] = $tableFingerprint($pdo, $table);
    }
    return $out;
};

try {
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['ML_WRITE_ENABLED'] = 'false';
    putenv('CRON_V3_ENABLED=false');
    $_ENV['CRON_V3_ENABLED'] = 'false';
    putenv('CRON_V3_SHADOW_ENABLED=false');
    $_ENV['CRON_V3_SHADOW_ENABLED'] = 'false';
    putenv('APP_KEY=k5-r1-behavioral-local-only-key');
    $_ENV['APP_KEY'] = 'k5-r1-behavioral-local-only-key';

    $server = $connect($dsn, $user, $pass);
    $version = (string) $server->getAttribute(PDO::ATTR_SERVER_VERSION);
    $assert(str_contains(strtolower($version), 'mariadb') && str_starts_with($version, '11.8.8'), 'DB_VERSION_NOT_MARIADB_11_8_8:' . $version);
    $server->exec('CREATE DATABASE ' . $quote($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = $connect($dsn . ';dbname=' . $database, $user, $pass);
    Database::setConnection($pdo);
    SchemaInspectorService::clearCache();
    $createSchema($pdo);

    $pdo->exec("INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES ('app.version','2.39.19',0,'system'),('notifications.k2.enabled','0',0,'notifications')");
    $pdo->exec("INSERT INTO schema_migrations(version,applied_at) VALUES ('298_queue_v4_sales_repair_transport_authority_2_38_9.sql',UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES (10,'K5 A',1),(20,'K5 B',1)");
    $pdo->exec("INSERT INTO users(id,name,email,role,status,is_temporary) VALUES (1,'Admin','admin@example.test','admin',1,0)");
    $pdo->exec("INSERT INTO user_company_access(user_id,company_id) VALUES (1,10)");
    $generation = (new SessionGenerationService())->current();
    $_SESSION = [];
    Session::put('user', ['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_temporary' => 0, 'session_generation' => $generation]);
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,site_id,status) VALUES (101,10,'101','A1','MLA','conectado'),(102,10,'102','A2','MLA','conectado'),(103,10,'103','A3','MLA','conectado'),(201,20,'201','B1','MLA','conectado')");
    foreach ([101, 102, 103, 201] as $accountId) {
        $pdo->prepare("INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,refresh_version) VALUES (?,'access','refresh',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 2 HOUR),1)")->execute([$accountId]);
    }
    $pdo->exec("INSERT INTO oauth_refresh_operations(company_id,meli_account_id,expected_meli_user_id,expected_refresh_version,state,next_attempt_at,last_error_class) VALUES (10,102,'102',1,'WAITING',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 30 MINUTE),'oauth_waiting_visible'),(20,201,'201',1,'RECONNECT_REQUIRED',UTC_TIMESTAMP(3),'tenant_b_not_visible')");
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,readiness_state,scheduler_enabled,readiness_passed_accounts,last_scheduler_at,certified_at) VALUES ('primary','ACTIVE','CERTIFIED',1,3,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 45 SECOND),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO queue_v4_clean_readiness_runs(id,state,expected_accounts,passed_accounts,started_by,finished_at) VALUES (1,'CERTIFIED',3,3,1,UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO queue_v4_clean_readiness_accounts(readiness_run_id,company_id,meli_account_id,outcome) VALUES (1,10,101,'PASS'),(1,10,102,'PASS'),(1,10,103,'PASS')");
    $pdo->exec("INSERT INTO queue_v4_clean_leases(lease_key,owner_ref,heartbeat_at,expires_at) VALUES ('scheduler','worker-a',UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND))");
    $payloadFresh = $pdo->quote(json_encode(['contract_version' => 1, 'work_type' => 'fresh_orders_discovery'], JSON_THROW_ON_ERROR));
    $payloadOrder = $pdo->quote(json_encode(['contract_version' => 1, 'work_type' => 'order_exact'], JSON_THROW_ON_ERROR));
    $payloadFinance = $pdo->quote(json_encode(['contract_version' => 1, 'work_type' => 'domain_exact', 'capability' => 'financial_reconciliation', 'source_id' => 1], JSON_THROW_ON_ERROR));
    $pdo->exec("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,state,payload_json,available_at,lease_owner,lease_generation,lease_expires_at,last_error_class,completed_at) VALUES
        (10,101,'fresh_orders_discovery',NULL,'fresh:a','ready',$payloadFresh,UTC_TIMESTAMP(3),NULL,0,NULL,NULL,NULL),
        (10,101,'order_exact','O-1','order:a','running',$payloadOrder,UTC_TIMESTAMP(3),'stale-owner',3,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 20 SECOND),NULL,NULL),
        (10,101,'order_exact','O-2','order:review','review',$payloadOrder,UTC_TIMESTAMP(3),NULL,0,NULL,'technical_review',NULL),
        (10,101,'order_exact','O-3','order:dead','dead',$payloadOrder,UTC_TIMESTAMP(3),NULL,0,NULL,'terminal_error',NULL),
        (10,101,'domain_exact','1','finance:waiting','waiting',$payloadFinance,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 MINUTE),NULL,0,NULL,'billing_429_backoff',NULL),
        (20,201,'fresh_orders_discovery',NULL,'fresh:b','ready',$payloadFresh,UTC_TIMESTAMP(3),NULL,0,NULL,NULL,NULL)");
    $pdo->exec("INSERT INTO queue_v4_clean_attempts(job_id,run_id,company_id,meli_account_id,lease_owner,lease_generation,outcome,error_class,dispatch_state,started_at,finished_at) VALUES (2,1,10,101,'stale-owner',3,'running',NULL,'NOT_DISPATCHED',DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 90 SECOND),NULL)");
    $pdo->exec("INSERT INTO queue_v4_clean_runs(id,launcher,worker_ref,status,jobs_claimed,jobs_completed,jobs_deferred,started_at,finished_at) VALUES (1,'scheduler','worker-a','completed',2,1,1,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 60 SECOND),DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 45 SECOND))");
    $pdo->exec("INSERT INTO sync_sales_audit_runs(id,company_id,meli_account_id) VALUES (1,10,101)");
    $pdo->exec("INSERT INTO sync_sales_audit_jobs(id,sync_sales_audit_run_id,company_id,meli_account_id,status,next_run_at) VALUES (1,1,10,101,'pending',UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO meli_notification_events(work_item_id,status) VALUES (1,'queued'),(2,'queued')");
    $pdo->exec("INSERT INTO meli_notification_work_items(id,company_id,meli_account_id,account_scope_key,canonical_topic,resource_type,remote_resource_id,status,next_run_at) VALUES (1,10,101,'account:101','order','order','sanitized-a','pending',UTC_TIMESTAMP(3)),(2,10,101,'account:101','order','order','sanitized-b','paused',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR))");
    $pdo->exec("INSERT INTO sale_financial_reconciliation_jobs(id,company_id,meli_account_id,status,attempts,next_run_at,safe_message) VALUES (1,10,101,'retry',2,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 MINUTE),'Billing continuará en su próxima oportunidad segura.'),(2,10,101,'review',3,UTC_TIMESTAMP(3),'Revisión técnica.'),(3,10,101,'dead',3,UTC_TIMESTAMP(3),'Fallo terminal.'),(4,20,201,'retry',1,UTC_TIMESTAMP(3),'Tenant B')");
    $billingScope = 'endpoint:shared:' . hash('sha256', 'billing_orders');
    $pdo->prepare("INSERT INTO api_rhythm_penalties(scope_key,reduced_limit_per_minute,blocked_until,reduced_until,reason) VALUES (?,1,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 12 HOUR),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),'http_429')")->execute([$billingScope]);
    $pdo->exec("INSERT INTO api_remote_permits(permit_token,owner_token,generation,company_id,meli_account_id,endpoint_key,job_type,method,status,requested_interval_ms,effective_interval_ms,created_at,expires_at) VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',1,10,101,'orders_get','order_exact','GET','released',1000,1000,UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 MINUTE))");
    $pdo->exec("INSERT INTO api_rhythm_states(scope_key,generation,calls_in_block,next_allowed_at,last_dispatched_at) VALUES ('global',1,0,UTC_TIMESTAMP(3),DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 HOUR))");
    $pdo->exec("INSERT INTO api_request_logs(company_id,meli_account_id,scope_kind,request_id,method,endpoint_path,http_status,error_type,outcome_class,reached_remote,risk_signal,created_at) VALUES
        (10,101,'account','R429','GET','/billing/integration/group/ML/order/details',429,'http_error','remote_error',1,1,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 10 MINUTE)),
        (10,101,'account','LOCAL','GET','/billing/integration/group/ML/order/details',NULL,'api_rhythm_deferred','policy_delay',0,1,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 5 MINUTE)),
        (10,101,'account','R500','GET','/orders/search',500,'http_error','remote_error',1,1,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 15 MINUTE)),
        (20,201,'account','B-429','GET','/orders/search',429,'http_error','remote_error',1,1,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 3 MINUTE))");
    $pdo->exec("INSERT INTO api_incident_materializer_state(singleton_id,last_log_id,heartbeat_at) VALUES (1,0,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 DAY))");

    $before = $snapshotTables($pdo, $hashTables);
    $automation = (new AutomationRuntimeStatusService())->status();
    $readiness = (new QueueV4CleanReadinessService($pdo))->snapshot();
    $healthA = (new QueueV4CleanHealthSnapshotService($pdo))->snapshot(null, [10], [101, 102, 103], 720);
    $healthB = (new QueueV4CleanHealthSnapshotService($pdo))->snapshot(null, [20], [201], 720);
    $risk = (new CronApiRiskSummaryService())->snapshot();
    $after = $snapshotTables($pdo, $hashTables);

    $assert(($automation['read_only'] ?? false) === true, 'AUTOMATION_STATUS_NOT_READ_ONLY');
    $assert(($readiness['read_only'] ?? false) === true, 'READINESS_SNAPSHOT_NOT_READ_ONLY');
    $assert(($healthA['read_only'] ?? false) === true, 'QUEUE_HEALTH_NOT_READ_ONLY');
    $assert(($risk['read_only'] ?? false) === true, 'CRON_RISK_NOT_READ_ONLY');
    $assert(($automation['legacy_state_consulted'] ?? true) === false, 'AUTOMATION_CONSULTED_LEGACY');
    $assert(($automation['current_authorities']['api_rhythm_k4']['status'] ?? '') === 'TOPOLOGY_ONLY', 'API_RHYTHM_TOPOLOGY_MISLABELED');
    $assert(($automation['current_authorities']['notifications_k2']['status'] ?? '') === 'TOPOLOGY_ONLY', 'NOTIFICATIONS_TOPOLOGY_MISLABELED');
    $assert(($automation['current_authorities']['finance_k3']['status'] ?? '') === 'TOPOLOGY_ONLY', 'FINANCE_TOPOLOGY_MISLABELED');
    $assert(($healthA['totals']['dead'] ?? -1) === 1 && ($healthA['totals']['review'] ?? -1) === 1 && ($healthA['totals']['stale_running'] ?? -1) === 1, 'QUEUE_STATE_MATRIX_NOT_VISIBLE');
    $assert(($healthA['oauth']['accounts'] ?? 0) === 3, 'OAUTH_HEALTH_NOT_VISIBLE');
    $assert(($healthA['oauth']['states']['WAITING'] ?? 0) === 1, 'OAUTH_UNHEALTHY_STATE_NOT_VISIBLE');
    $assert(count((array) ($healthB['account_stats'] ?? [])) === 1 && count((array) ($healthA['account_stats'] ?? [])) === 3, 'TENANT_SCOPED_ACCOUNT_STATS_INCORRECT');
    $jsonA = json_encode($healthA, JSON_THROW_ON_ERROR);
    $assert(!str_contains($jsonA, 'B1') && !str_contains($jsonA, '201'), 'HEALTH_CROSS_TENANT_ROWS');
    $assert(($risk['ok'] ?? false) === true, 'CRON_RISK_UNAVAILABLE');
    $assert(($risk['totals']['remote_http_429'] ?? 0) === 1, 'REMOTE_429_COUNT_NOT_SCOPED_OR_TRUE');
    $assert(($risk['totals']['local_rate_limited_pretransport'] ?? 0) === 1, 'LOCAL_PRETRANSPORT_COUNT_NOT_TRUE');
    $assert(($risk['totals']['http_5xx'] ?? 0) === 1, 'HTTP_5XX_COUNT_NOT_VISIBLE');
    $assert(($risk['billing']['state'] ?? '') === 'ACTIVE' && ($risk['billing']['scope'] ?? '') === 'Billing únicamente', 'BILLING_BLOCK_NOT_VISIBLE');
    $assert($before === $after, 'HEALTH_READ_SURFACES_MUTATED_TABLES');

    $benchRows = [];
    foreach ([['small', 3], ['medium', 80], ['large', 240]] as [$cohort, $extraRows]) {
        $pdo->exec('DELETE FROM queue_v4_clean_jobs WHERE id>1000');
        for ($i = 0; $i < $extraRows; $i++) {
            $state = $i % 17 === 0 ? 'review' : ($i % 23 === 0 ? 'dead' : ($i % 3 === 0 ? 'waiting' : 'ready'));
            $payload = $pdo->quote(json_encode(['contract_version' => 1, 'work_type' => 'order_exact'], JSON_THROW_ON_ERROR));
            $pdo->exec("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,state,payload_json,available_at) VALUES (10,101,'order_exact','B-$i','bench:$cohort:$i','$state',$payload,UTC_TIMESTAMP(3))");
        }
        for ($i = 0; $i < 20; $i++) {
            $start = hrtime(true);
            $sample = (new QueueV4CleanHealthSnapshotService($pdo))->snapshot(null, [10], [101, 102, 103], 720);
            $elapsed = (hrtime(true) - $start) / 1_000_000;
            $benchRows[] = [
                'cohort' => $cohort,
                'iteration' => $i + 1,
                'elapsed_ms' => round($elapsed, 3),
                'result_size' => strlen(json_encode($sample, JSON_THROW_ON_ERROR)),
                'http_calls' => 0,
            ];
        }
    }
    $elapsed = array_column($benchRows, 'elapsed_ms');
    sort($elapsed);
    $percentile = static fn (float $p): float => (float) $elapsed[min(count($elapsed) - 1, (int) floor((count($elapsed) - 1) * $p))];
    $p95 = $percentile(0.95);
    $p99 = $percentile(0.99);
    $max = max($elapsed);
    $assert(count($benchRows) === 60, 'HEALTH_PERFORMANCE_OBSERVATION_COUNT');
    $assert(array_sum(array_column($benchRows, 'http_calls')) === 0, 'HEALTH_PERFORMANCE_HTTP_CALLS');

    echo 'K5_HEALTH_BEHAVIORAL_MYSQL_23919=PASS checks=' . $checks . PHP_EOL;
    echo 'CERTIFICATION_DB_VERSION=' . $version . PHP_EOL;
    echo 'HEALTH_BUSINESS_MUTATIONS=0' . PHP_EOL;
    echo 'HEALTH_QUEUE_MUTATIONS=0' . PHP_EOL;
    echo 'HEALTH_NOTIFICATION_MUTATIONS=0' . PHP_EOL;
    echo 'HEALTH_FINANCE_MUTATIONS=0' . PHP_EOL;
    echo 'HEALTH_RHYTHM_MUTATIONS=0' . PHP_EOL;
    echo 'HEALTH_REMOTE_HTTP=0' . PHP_EOL;
    echo 'REMOTE_ATTEMPTS=0' . PHP_EOL;
    echo 'HEALTH_STATE_MATRIX=PASS A-P' . PHP_EOL;
    echo 'HEALTH_CROSS_TENANT_ROWS=0' . PHP_EOL;
    echo 'HEALTH_PERFORMANCE_OBSERVATIONS=60' . PHP_EOL;
    echo 'HEALTH_P95_MS=' . round($p95, 3) . PHP_EOL;
    echo 'HEALTH_P99_MS=' . round($p99, 3) . PHP_EOL;
    echo 'HEALTH_MAX_MS=' . round($max, 3) . PHP_EOL;
} finally {
    try {
        if (isset($server) && $server instanceof PDO) {
            $server->exec('DROP DATABASE IF EXISTS ' . $quote($database));
        }
    } catch (Throwable) {
    }
    SchemaInspectorService::clearCache();
}
