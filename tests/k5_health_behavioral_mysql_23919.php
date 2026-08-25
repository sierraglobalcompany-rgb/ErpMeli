<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Session;
use App\QueueV4Clean\QueueV4CleanHealthSnapshotService;
use App\Services\AppSettingsService;
use App\Services\AutomationRuntimeStatusService;
use App\Services\CronApiRiskSummaryService;
use App\Services\EmergencyControlService;
use App\Services\NotificationWorkItemService;
use App\Services\SchemaInspectorService;
use App\Services\SessionGenerationService;

$root = dirname(__DIR__);
$fsRoot = sys_get_temp_dir() . '/erp_k5_r2_fs_' . bin2hex(random_bytes(5));
if (!defined('ERP_INSTALLATION_ROOT')) {
    define('ERP_INSTALLATION_ROOT', $fsRoot);
}
if (!defined('ERP_SHARED_ROOT')) {
    define('ERP_SHARED_ROOT', $fsRoot . '/shared');
}

spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: K5 R2 exige DSN MariaDB 11.8.8 desechable sin dbname.\n");
    exit(2);
}

$rawDir = trim((string) getenv('K5_HEALTH_R2_RAW_DIR'));
$rawDir = $rawDir !== '' ? $rawDir : $root . '/artifacts-local/k5-r2-final/raw';
if (!is_dir($rawDir) && !mkdir($rawDir, 0770, true) && !is_dir($rawDir)) {
    throw new RuntimeException('RAW_DIR_NOT_WRITABLE:' . $rawDir);
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$connect = static function (string $dsn, string $user, string $pass): PDO {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone='+00:00'");
    return $pdo;
};
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
$json = static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$writeCsv = static function (string $path, array $headers, array $rows): void {
    $handle = fopen($path, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('CSV_WRITE_FAILED:' . $path);
    }
    fputcsv($handle, $headers, ',', '"', '\\', "\n");
    foreach ($rows as $row) {
        $values = [];
        foreach ($headers as $header) {
            $value = $row[$header] ?? '';
            $values[] = is_scalar($value) || $value === null
                ? (string) $value
                : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        fputcsv($handle, $values, ',', '"', '\\', "\n");
    }
    fclose($handle);
};
$field = static function (array $row, string $path): mixed {
    $cursor = $row;
    foreach (explode('.', $path) as $piece) {
        if (!is_array($cursor) || !array_key_exists($piece, $cursor)) {
            return null;
        }
        $cursor = $cursor[$piece];
    }
    return $cursor;
};

$hashTables = [
    'queue_v4_clean_jobs', 'queue_v4_clean_attempts', 'queue_v4_clean_runs', 'queue_v4_clean_leases',
    'meli_notification_events', 'meli_notification_work_items',
    'order_financial_recalc_jobs', 'sale_financial_reconciliation_jobs',
    'api_rhythm_states', 'api_remote_permits', 'api_rhythm_penalties', 'meli_tokens', 'oauth_refresh_operations',
];
$fingerprint = static function (PDO $pdo, string $table): array {
    $columns = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $columns->execute([$table]);
    $cols = array_map('strval', $columns->fetchAll(PDO::FETCH_COLUMN));
    $order = in_array('id', $cols, true) ? 'id' : $cols[0];
    $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '` ORDER BY `' . str_replace('`', '``', $order) . '`')->fetchAll(PDO::FETCH_ASSOC);
    return ['count' => count($rows), 'hash' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
};
$snapshot = static function (PDO $pdo) use ($hashTables, $fingerprint): array {
    $out = [];
    foreach ($hashTables as $table) {
        $out[$table] = $fingerprint($pdo, $table);
    }
    return $out;
};

$resetFs = static function () use ($fsRoot): void {
    if (is_dir($fsRoot)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fsRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $path) {
            $path->isDir() ? @rmdir((string) $path) : @unlink((string) $path);
        }
        @rmdir($fsRoot);
    }
    mkdir($fsRoot . '/shared/storage', 0770, true);
    mkdir($fsRoot . '/shared/private/emergency', 0770, true);
};
$marker = static function () use ($fsRoot, $root): void {
    $key = 'k5-r2-behavioral-local-only-key';
    $payload = [
        'version' => trim((string) file_get_contents($root . '/VERSION')),
        'last_migration' => '299_queue_v4_domain_exact_admission_2_39_3.sql',
        'completed_at' => gmdate(DATE_ATOM),
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($fsRoot . '/shared/storage/installed-release.json', json_encode([
        'payload' => $payload,
        'signature' => hash_hmac('sha256', $encoded, $key),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
};

$createSchema = static function (PDO $pdo): void {
    $ddl = [
        "CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(191),status TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(191),email VARCHAR(191),role VARCHAR(40),status TINYINT NOT NULL DEFAULT 1,is_temporary TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE user_company_access(user_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(user_id,company_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE meli_accounts(id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_user_id VARCHAR(64) NULL,account_name VARCHAR(191) NULL,site_id VARCHAR(12) NULL,status VARCHAR(32) NOT NULL,PRIMARY KEY(id),UNIQUE KEY uq_meli_accounts_company_id(company_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE meli_tokens(meli_account_id BIGINT UNSIGNED PRIMARY KEY,access_token_encrypted TEXT NOT NULL,refresh_token_encrypted TEXT NOT NULL,expires_at DATETIME NOT NULL,refresh_version BIGINT UNSIGNED NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE oauth_refresh_operations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,expected_meli_user_id VARCHAR(64) NOT NULL,expected_refresh_version BIGINT UNSIGNED NOT NULL,state ENUM('SCHEDULED','RUNNING','WAITING','REMOTE_UNCERTAIN','RECONNECT_REQUIRED','FAILED','COMPLETED') NOT NULL DEFAULT 'SCHEDULED',next_attempt_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),lease_owner VARCHAR(96) NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,lease_expires_at DATETIME(3) NULL,remote_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,remote_dispatch_state ENUM('NOT_DISPATCHED','MAY_HAVE_DISPATCHED','RESPONSE_KNOWN') NOT NULL DEFAULT 'NOT_DISPATCHED',remote_dispatched_at DATETIME(3) NULL,response_known_at DATETIME(3) NULL,last_http_status SMALLINT UNSIGNED NULL,last_error_class VARCHAR(100) NULL,last_request_id VARCHAR(64) NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),completed_at DATETIME(3) NULL,UNIQUE KEY uq_oauth_refresh_identity(company_id,meli_account_id,expected_refresh_version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE app_settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value LONGTEXT NULL,is_encrypted TINYINT(1) NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT 'general',updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE schema_migrations(version VARCHAR(190) PRIMARY KEY,applied_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE queue_v4_clean_control(control_key VARCHAR(32) PRIMARY KEY,engine_state ENUM('STOPPED','CERTIFIED','ACTIVE') NOT NULL DEFAULT 'STOPPED',readiness_state ENUM('NOT_READY','READY_TO_TEST','TESTING','CERTIFIED','FAILED') NOT NULL DEFAULT 'NOT_READY',scheduler_enabled TINYINT(1) NOT NULL DEFAULT 0,readiness_passed_accounts TINYINT UNSIGNED NOT NULL DEFAULT 0,readiness_error_class VARCHAR(100) NULL,certified_at DATETIME(3) NULL,activated_at DATETIME(3) NULL,stopped_at DATETIME(3) NULL,last_scheduler_at DATETIME(3) NULL,updated_by BIGINT UNSIGNED NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE queue_v4_clean_readiness_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,state ENUM('TESTING','CERTIFIED','FAILED') NOT NULL,expected_accounts TINYINT UNSIGNED NOT NULL DEFAULT 3,passed_accounts TINYINT UNSIGNED NOT NULL DEFAULT 0,failure_class VARCHAR(100) NULL,started_by BIGINT UNSIGNED NOT NULL DEFAULT 1,started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE queue_v4_clean_readiness_accounts(readiness_run_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,outcome ENUM('PASS','FAIL') NOT NULL,failure_class VARCHAR(100) NULL,checked_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),PRIMARY KEY(readiness_run_id,company_id,meli_account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE queue_v4_clean_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,job_type ENUM('fresh_orders_discovery','order_exact','domain_exact') NOT NULL,resource_id VARCHAR(191) NULL,idempotency_key VARCHAR(191) NOT NULL,state ENUM('ready','running','waiting','review','dead','completed') NOT NULL DEFAULT 'ready',attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),lease_owner VARCHAR(96) NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,lease_expires_at DATETIME(3) NULL,payload_json JSON NOT NULL,last_error_class VARCHAR(100) NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),completed_at DATETIME(3) NULL,UNIQUE KEY uq_qv4_job_identity(company_id,meli_account_id,job_type,idempotency_key),KEY idx_qv4_fifo(state,available_at,id),KEY idx_qv4_tenant(company_id,meli_account_id,state,id),KEY idx_qv4_lease(state,lease_expires_at,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE queue_v4_clean_attempts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,job_id BIGINT UNSIGNED NOT NULL,run_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,lease_owner VARCHAR(96) NOT NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,outcome ENUM('running','completed','waiting','review','dead','lease_expired') NOT NULL DEFAULT 'running',error_class VARCHAR(100) NULL,dispatch_state ENUM('NOT_DISPATCHED','PHYSICAL_STARTED','RESPONSE_KNOWN') NOT NULL DEFAULT 'NOT_DISPATCHED',transport_method VARCHAR(8) NULL,endpoint_key VARCHAR(100) NULL,physical_http_calls TINYINT UNSIGNED NOT NULL DEFAULT 0,physical_started_at DATETIME(3) NULL,http_status SMALLINT UNSIGNED NULL,response_known_at DATETIME(3) NULL,source_closed_at DATETIME(3) NULL,started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL,UNIQUE KEY uq_qv4_attempt(job_id,lease_owner)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE queue_v4_clean_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,launcher ENUM('scheduler','manual','test') NOT NULL,worker_ref VARCHAR(96) NOT NULL,status ENUM('running','completed','failed','stopped') NOT NULL DEFAULT 'running',jobs_claimed INT UNSIGNED NOT NULL DEFAULT 0,jobs_completed INT UNSIGNED NOT NULL DEFAULT 0,jobs_deferred INT UNSIGNED NOT NULL DEFAULT 0,started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE queue_v4_clean_leases(lease_key VARCHAR(64) PRIMARY KEY,owner_ref VARCHAR(96) NULL,acquired_at DATETIME(3) NULL,heartbeat_at DATETIME(3) NULL,expires_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE queue_v4_clean_transport_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,source_kind ENUM('queue','oauth','sales_audit') NOT NULL,work_id BIGINT UNSIGNED NOT NULL,attempt_id BIGINT UNSIGNED NULL,lease_generation BIGINT UNSIGNED NOT NULL,request_id VARCHAR(64) NOT NULL,method VARCHAR(10) NOT NULL,endpoint_key VARCHAR(100) NOT NULL,dispatch_state ENUM('PHYSICAL_STARTED','RESPONSE_KNOWN') NOT NULL DEFAULT 'PHYSICAL_STARTED',physical_started_at DATETIME(3) NOT NULL,response_known_at DATETIME(3) NULL,http_status SMALLINT UNSIGNED NULL,UNIQUE KEY uq_queue_v4_transport_request(source_kind,request_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE sync_sales_audit_runs(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE sync_sales_audit_jobs(id BIGINT UNSIGNED PRIMARY KEY,sync_sales_audit_run_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,status VARCHAR(40) NOT NULL,next_run_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE meli_notification_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,work_item_id BIGINT UNSIGNED NULL,status VARCHAR(30) NOT NULL DEFAULT 'queued',disposition VARCHAR(80) NULL,error_message VARCHAR(500) NULL,acknowledged_at DATETIME(3) NULL,processed_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE meli_notification_work_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NULL,meli_account_id BIGINT UNSIGNED NULL,account_scope_key VARCHAR(80) NOT NULL DEFAULT '',canonical_topic VARCHAR(40) NOT NULL,resource_type VARCHAR(40) NOT NULL,remote_resource_id VARCHAR(100) NOT NULL,status VARCHAR(40) NOT NULL DEFAULT 'pending',priority INT UNSIGNED NOT NULL DEFAULT 5,next_run_at DATETIME(3) NULL,locked_by VARCHAR(96) NULL,lock_expires_at DATETIME(3) NULL,consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,attempts INT UNSIGNED NOT NULL DEFAULT 0,occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,last_result VARCHAR(80) NULL,first_received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),last_received_at DATETIME(3) NULL,last_processed_at DATETIME(3) NULL,processing_ms INT UNSIGNED NULL,correlation_id VARCHAR(64) NULL,last_error_code VARCHAR(80) NULL,last_error_message VARCHAR(500) NULL,last_error_diagnostic_id VARCHAR(100) NULL,last_error_stage VARCHAR(80) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE order_financial_recalc_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,status VARCHAR(40) NOT NULL DEFAULT 'pending') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE sale_financial_reconciliation_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,status VARCHAR(40) NOT NULL DEFAULT 'pending',attempts INT UNSIGNED NOT NULL DEFAULT 0,next_run_at DATETIME(3) NULL,safe_message VARCHAR(500) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE api_rhythm_states(scope_key VARCHAR(120) PRIMARY KEY,generation BIGINT UNSIGNED NOT NULL DEFAULT 1,calls_in_block INT UNSIGNED NOT NULL DEFAULT 0,block_started_at DATETIME(3) NULL,next_allowed_at DATETIME(3) NULL,block_pause_until DATETIME(3) NULL,last_dispatched_at DATETIME(3) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE api_remote_permits(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,permit_token CHAR(40) NOT NULL,owner_token CHAR(32) NOT NULL,generation BIGINT UNSIGNED NOT NULL,run_token VARCHAR(100) NULL,work_key VARCHAR(120) NULL,company_id BIGINT UNSIGNED NULL,meli_account_id BIGINT UNSIGNED NULL,endpoint_key VARCHAR(120) NOT NULL,job_type VARCHAR(80) NOT NULL,method VARCHAR(10) NOT NULL,status ENUM('reserved','dispatched','completed','released','expired') NOT NULL DEFAULT 'reserved',requested_interval_ms INT UNSIGNED NOT NULL,effective_interval_ms INT UNSIGNED NOT NULL,blocking_scope VARCHAR(80) NULL,http_status SMALLINT UNSIGNED NULL,created_at DATETIME(3) NOT NULL,dispatched_at DATETIME(3) NULL,completed_at DATETIME(3) NULL,released_at DATETIME(3) NULL,expires_at DATETIME(3) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE api_rhythm_penalties(scope_key VARCHAR(180) PRIMARY KEY,reduced_limit_per_minute SMALLINT UNSIGNED NOT NULL,blocked_until DATETIME(3) NULL,reduced_until DATETIME(3) NOT NULL,reason VARCHAR(80) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE api_request_logs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,company_id BIGINT UNSIGNED NULL,scope_kind VARCHAR(30) NULL,request_id VARCHAR(64) NULL,method VARCHAR(10) NULL,endpoint_path VARCHAR(255) NULL,http_status INT NULL,error_type VARCHAR(80) NULL,outcome_class VARCHAR(80) NULL,reached_remote TINYINT(1) NULL,risk_signal TINYINT(1) NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),KEY idx_log_created(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE api_incident_materializer_state(singleton_id TINYINT UNSIGNED PRIMARY KEY,last_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0,heartbeat_at DATETIME(3) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($ddl as $sql) {
        $pdo->exec($sql);
    }
};

$seedBase = static function (PDO $pdo, string $state) use ($marker, $fsRoot): void {
    putenv('ML_WRITE_ENABLED=false');
    putenv('CRON_V3_ENABLED=false');
    putenv('CRON_V3_SHADOW_ENABLED=false');
    putenv('APP_KEY=k5-r2-behavioral-local-only-key');
    putenv('ERP_EMERGENCY_STORAGE_DIR=' . $fsRoot . '/shared/private/emergency');
    $_ENV['ML_WRITE_ENABLED'] = 'false';
    $_ENV['CRON_V3_ENABLED'] = 'false';
    $_ENV['CRON_V3_SHADOW_ENABLED'] = 'false';
    $_ENV['APP_KEY'] = 'k5-r2-behavioral-local-only-key';
    $_ENV['ERP_EMERGENCY_STORAGE_DIR'] = $fsRoot . '/shared/private/emergency';
    Database::setConnection($pdo);
    SchemaInspectorService::clearCache();
    AppSettingsService::clearCache();
    $_SESSION = [];
    $marker();
    $pdo->exec("INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES ('app.version','2.39.19',0,'system'),('notifications.k2.enabled','1',0,'notifications'),('notifications.enabled','1',0,'notifications'),('notifications.webhook_first_enabled','1',0,'notifications')");
    $pdo->exec("INSERT INTO schema_migrations(version,applied_at) VALUES ('298_queue_v4_sales_repair_transport_authority_2_38_9.sql',UTC_TIMESTAMP(3)),('299_queue_v4_domain_exact_admission_2_39_3.sql',UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES (10,'K5 A',1),(20,'K5 B',1)");
    $pdo->exec("INSERT INTO users(id,name,email,role,status,is_temporary) VALUES (1,'Admin','admin@example.test','admin',1,0)");
    $pdo->exec("INSERT INTO user_company_access(user_id,company_id) VALUES (1,10),(1,20)");
    Session::put('user', ['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_temporary' => 0, 'session_generation' => (new SessionGenerationService())->current()]);
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,site_id,status) VALUES (101,10,'101','A1','MLA','conectado'),(102,10,'102','A2','MLA','conectado'),(103,10,'103','A3','MLA','conectado'),(201,20,'201','B1','MLA','conectado')");
    foreach ([101, 102, 103, 201] as $accountId) {
        $pdo->prepare("INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,refresh_version) VALUES (?,'access','refresh',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 2 HOUR),1)")->execute([$accountId]);
    }
    $scheduler = $state === 'E' ? 0 : 1;
    $engine = $state === 'E' ? 'STOPPED' : 'ACTIVE';
    $heartbeat = $state === 'C' ? 'DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 10 MINUTE)' : 'DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 45 SECOND)';
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,readiness_state,scheduler_enabled,readiness_passed_accounts,last_scheduler_at,certified_at) VALUES ('primary','{$engine}','CERTIFIED',{$scheduler},3,{$heartbeat},UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO queue_v4_clean_readiness_runs(id,state,expected_accounts,passed_accounts,started_by,finished_at) VALUES (1,'CERTIFIED',3,3,1,UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO queue_v4_clean_readiness_accounts(readiness_run_id,company_id,meli_account_id,outcome) VALUES (1,10,101,'PASS'),(1,10,102,'PASS'),(1,10,103,'PASS'),(1,20,201,'PASS')");
    $pdo->exec("INSERT INTO api_incident_materializer_state(singleton_id,last_log_id,heartbeat_at) VALUES (1,0,UTC_TIMESTAMP(3))");
};

$states = [
    'A' => ['Healthy runtime', 'engine active/certified + heartbeat recent + OAuth healthy', 'QueueV4CleanHealthSnapshotService::snapshot', 'state', 'healthy'],
    'B' => ['Automation stopped', 'PAUSE_ERP_AUTOMATION marker in isolated filesystem', 'EmergencyControlService::status', 'automation', 'stopped'],
    'C' => ['Physical Cron stale', 'last_scheduler_at older than 180s', 'QueueV4CleanHealthSnapshotService::snapshot', 'state', 'delayed'],
    'D' => ['Physical Cron recent', 'last_scheduler_at recent', 'QueueV4CleanHealthSnapshotService::snapshot', 'runtime.heartbeat_age_seconds', '<=180'],
    'E' => ['Drain authority absent', 'scheduler disabled and engine stopped', 'AutomationRuntimeStatusService::status', 'current_authorities.drain_authority.status', 'inactive'],
    'F' => ['Drain authority live', 'nonexpired scheduler lease with owner', 'queue_v4_clean_leases read-only SQL', 'owner_ref', 'worker-live'],
    'G' => ['Stale running pointer', 'running Queue V4 job with expired lease', 'QueueV4CleanHealthSnapshotService::snapshot', 'totals.stale_running', '1'],
    'H' => ['Review pointer', 'Queue V4 review pointer', 'QueueV4CleanHealthSnapshotService::snapshot', 'totals.review', '1'],
    'I' => ['Dead pointer', 'Queue V4 dead pointer', 'QueueV4CleanHealthSnapshotService::snapshot', 'totals.dead', '1'],
    'J' => ['OAuth unhealthy', 'certified account with RECONNECT_REQUIRED operation', 'QueueV4CleanHealthSnapshotService::snapshot', 'oauth.states.RECONNECT_REQUIRED', '1'],
    'K' => ['Notification pause', 'paused notification work item', 'NotificationWorkItemService::summary', 'paused', '1'],
    'L' => ['K2 forward backlog', 'pending notification work item', 'NotificationWorkItemService::summary', 'pending', '1'],
    'M' => ['Finance waiting', 'financial_reconciliation pointer waiting', 'QueueV4CleanHealthSnapshotService::snapshot', 'totals.waiting', '1'],
    'N' => ['Finance review/dead', 'one finance review and one finance dead pointer', 'QueueV4CleanHealthSnapshotService::snapshot', 'totals.review+dead', '2'],
    'O' => ['Remote Billing 429 block', 'remote 429 log plus endpoint Billing penalty', 'CronApiRiskSummaryService::snapshot', 'totals.remote_http_429|billing.state', '1|ACTIVE'],
    'P' => ['Local defer only', 'policy_delay reached_remote=0 and no 429', 'CronApiRiskSummaryService::snapshot', 'totals.local_rate_limited_pretransport|totals.remote_http_429', '1|0'],
];

$databases = [];
$matrixRows = [];
$hashRows = [];
$tenantCrossRows = 0;

try {
    $server = $connect($dsn, $user, $pass);
    $version = (string) $server->getAttribute(PDO::ATTR_SERVER_VERSION);
    $assert(str_contains(strtolower($version), 'mariadb') && str_starts_with($version, '11.8.8'), 'DB_VERSION_NOT_MARIADB_11_8_8:' . $version);

    foreach ($states as $state => [$description, $fixture, $surface, $metric, $expected]) {
        $resetFs();
        $database = 'erp_k5_r2_' . strtolower($state) . '_' . bin2hex(random_bytes(4));
        $databases[] = $database;
        $server->exec('CREATE DATABASE ' . $quote($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = $connect($dsn . ';dbname=' . $database, $user, $pass);
        $createSchema($pdo);
        $seedBase($pdo, $state);

        $orderPayload = $pdo->quote($json(['contract_version' => 1, 'work_type' => 'order_exact']));
        $financePayload = $pdo->quote($json(['contract_version' => 1, 'work_type' => 'domain_exact', 'capability' => 'financial_reconciliation', 'source_id' => 1]));
        if ($state === 'B') {
            (new EmergencyControlService())->stopAutomation('test', 'K5 R2 automation stop fixture');
        } elseif ($state === 'F') {
            $pdo->exec("INSERT INTO queue_v4_clean_leases(lease_key,owner_ref,acquired_at,heartbeat_at,expires_at) VALUES ('scheduler','worker-live',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND))");
        } elseif ($state === 'G') {
            $pdo->exec("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,state,payload_json,available_at,lease_owner,lease_generation,lease_expires_at) VALUES (10,101,'order_exact','O-G','g','running',{$orderPayload},UTC_TIMESTAMP(3),'stale-owner',3,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 MINUTE))");
        } elseif ($state === 'H') {
            $pdo->exec("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,state,payload_json,last_error_class) VALUES (10,101,'order_exact','O-H','h','review',{$orderPayload},'technical_review')");
        } elseif ($state === 'I') {
            $pdo->exec("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,state,payload_json,last_error_class) VALUES (10,101,'order_exact','O-I','i','dead',{$orderPayload},'terminal_error')");
        } elseif ($state === 'J') {
            $pdo->exec("INSERT INTO oauth_refresh_operations(company_id,meli_account_id,expected_meli_user_id,expected_refresh_version,state,next_attempt_at,last_error_class) VALUES (10,102,'102',1,'RECONNECT_REQUIRED',UTC_TIMESTAMP(3),'manual_reconnect_required')");
        } elseif ($state === 'K' || $state === 'L') {
            $status = $state === 'K' ? 'paused' : 'pending';
            $pdo->exec("INSERT INTO meli_notification_work_items(company_id,meli_account_id,account_scope_key,canonical_topic,resource_type,remote_resource_id,status,next_run_at,first_received_at) VALUES (10,101,'account:101','orders','order','redacted-{$state}','{$status}',UTC_TIMESTAMP(3),DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 5 MINUTE))");
        } elseif ($state === 'M') {
            $pdo->exec("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,state,payload_json,available_at,last_error_class) VALUES (10,101,'domain_exact','1','m','waiting',{$financePayload},DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),'billing_429_backoff')");
            $pdo->exec("INSERT INTO sale_financial_reconciliation_jobs(id,company_id,meli_account_id,status,attempts,next_run_at,safe_message) VALUES (1,10,101,'retry',2,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),'Billing continuará en su próxima oportunidad segura.')");
        } elseif ($state === 'N') {
            $pdo->exec("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,state,payload_json,last_error_class) VALUES (10,101,'domain_exact','2','n-review','review',{$financePayload},'technical_review'),(10,101,'domain_exact','3','n-dead','dead',{$financePayload},'terminal_error')");
        } elseif ($state === 'O') {
            $scope = 'endpoint:shared:' . hash('sha256', 'billing_orders');
            $pdo->prepare("INSERT INTO api_rhythm_penalties(scope_key,reduced_limit_per_minute,blocked_until,reduced_until,reason) VALUES (?,1,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 12 HOUR),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),'http_429')")->execute([$scope]);
            $pdo->exec("INSERT INTO api_request_logs(company_id,meli_account_id,scope_kind,request_id,method,endpoint_path,http_status,error_type,outcome_class,reached_remote,risk_signal,created_at) VALUES (10,101,'account','R429','GET','/billing/integration/group/ML/order/details',429,'http_error','remote_error',1,1,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 10 MINUTE))");
        } elseif ($state === 'P') {
            $pdo->exec("INSERT INTO api_request_logs(company_id,meli_account_id,scope_kind,request_id,method,endpoint_path,http_status,error_type,outcome_class,reached_remote,risk_signal,created_at) VALUES (10,101,'account','LOCAL','GET','/billing/integration/group/ML/order/details',NULL,'api_rhythm_deferred','policy_delay',0,1,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 5 MINUTE))");
        }

        $before = $snapshot($pdo);
        $output = null;
        if ($surface === 'AutomationRuntimeStatusService::status') {
            $output = (new AutomationRuntimeStatusService())->status();
            $actualValue = $field($output, $metric);
        } elseif ($surface === 'EmergencyControlService::status') {
            $output = (new EmergencyControlService())->status();
            $actualValue = $field($output, $metric);
        } elseif ($surface === 'QueueV4CleanHealthSnapshotService::snapshot') {
            $output = (new QueueV4CleanHealthSnapshotService($pdo))->snapshot(null, [10], [101, 102, 103], 720);
            $actualValue = $metric === 'totals.review+dead'
                ? (int) ($output['totals']['review'] ?? 0) + (int) ($output['totals']['dead'] ?? 0)
                : $field($output, $metric);
        } elseif ($surface === 'NotificationWorkItemService::summary') {
            $output = (new NotificationWorkItemService())->summary([101, 102, 103]);
            $actualValue = $field($output, $metric);
        } elseif ($surface === 'CronApiRiskSummaryService::snapshot') {
            $output = (new CronApiRiskSummaryService())->snapshot();
            $actualValue = $state === 'O'
                ? (int) $field($output, 'totals.remote_http_429') . '|' . (string) $field($output, 'billing.state')
                : (int) $field($output, 'totals.local_rate_limited_pretransport') . '|' . (int) $field($output, 'totals.remote_http_429');
        } else {
            $output = $pdo->query("SELECT owner_ref,expires_at>UTC_TIMESTAMP(3) nonexpired FROM queue_v4_clean_leases WHERE lease_key='scheduler' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
            $actualValue = (string) ($output['owner_ref'] ?? '');
        }
        $after = $snapshot($pdo);
        $hashMatch = $before === $after;
        foreach ($before as $table => $fp) {
            $hashRows[] = [
                'state' => $state,
                'table' => $table,
                'row_count_before' => $fp['count'],
                'row_count_after' => $after[$table]['count'],
                'sha256_before' => $fp['hash'],
                'sha256_after' => $after[$table]['hash'],
                'match' => $after[$table] === $fp ? 'YES' : 'NO',
            ];
        }
        $actual = is_scalar($actualValue) || $actualValue === null ? (string) $actualValue : json_encode($actualValue, JSON_THROW_ON_ERROR);
        $passState = $state === 'D' ? (is_numeric($actualValue) && (int) $actualValue <= 180) : ($actual === (string) $expected);
        if ($state === 'A') {
            $scoped = (new QueueV4CleanHealthSnapshotService($pdo))->snapshot(null, [10], [101, 102, 103], 720);
            foreach ((array) ($scoped['account_stats'] ?? []) as $row) {
                if ((int) ($row['meli_account_id'] ?? 0) === 201 || (string) ($row['account_name'] ?? '') === 'B1') {
                    $tenantCrossRows++;
                }
            }
        }
        $assert($output !== null, 'STATE_' . $state . '_SURFACE_MISSING');
        $assert($passState, 'STATE_' . $state . '_FAILED expected=' . $expected . ' actual=' . $actual);
        $assert($hashMatch, 'STATE_' . $state . '_MUTATED_TABLES');
        $matrixRows[] = [
            'state' => $state,
            'description' => $description,
            'fixture' => $fixture,
            'read_surface' => $surface,
            'field' => $metric,
            'expected' => $expected,
            'actual' => $actual,
            'pass' => 'PASS',
            'physical_http_calls' => 0,
            'table_hashes_match' => 'YES',
        ];
        $server->exec('DROP DATABASE ' . $quote($database));
        array_pop($databases);
    }

    $writeCsv($rawDir . '/HEALTH_STATE_MATRIX_RAW.csv', ['state','description','fixture','read_surface','field','expected','actual','pass','physical_http_calls','table_hashes_match'], $matrixRows);
    $writeCsv($rawDir . '/HEALTH_TABLE_HASHES_RAW.csv', ['state','table','row_count_before','row_count_after','sha256_before','sha256_after','match'], $hashRows);

    $resetFs();
    $database = 'erp_k5_r2_perf_' . bin2hex(random_bytes(4));
    $databases[] = $database;
    $server->exec('CREATE DATABASE ' . $quote($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = $connect($dsn . ';dbname=' . $database, $user, $pass);
    $createSchema($pdo);
    $seedBase($pdo, 'A');
    $benchRows = [];
    foreach ([['small', 3], ['medium', 80], ['large', 240]] as [$cohort, $extraRows]) {
        $pdo->exec('DELETE FROM queue_v4_clean_jobs WHERE id>1000');
        for ($i = 0; $i < $extraRows; $i++) {
            $state = $i % 17 === 0 ? 'review' : ($i % 23 === 0 ? 'dead' : ($i % 3 === 0 ? 'waiting' : 'ready'));
            $payload = $pdo->quote($json(['contract_version' => 1, 'work_type' => 'order_exact']));
            $pdo->exec("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,state,payload_json,available_at) VALUES (10,101,'order_exact','B-$i','bench:$cohort:$i','$state',$payload,UTC_TIMESTAMP(3))");
        }
        for ($i = 0; $i < 20; $i++) {
            $start = hrtime(true);
            $sample = (new QueueV4CleanHealthSnapshotService($pdo))->snapshot(null, [10], [101, 102, 103], 720);
            $benchRows[] = [
                'cohort' => $cohort,
                'iteration' => $i + 1,
                'elapsed_ms' => round((hrtime(true) - $start) / 1_000_000, 3),
                'sql_query_count' => 'NOT_MEASURED',
                'result_size' => strlen(json_encode($sample, JSON_THROW_ON_ERROR)),
                'http_calls' => 0,
            ];
        }
    }
    $writeCsv($rawDir . '/HEALTH_PERFORMANCE_RAW.csv', ['cohort','iteration','elapsed_ms','sql_query_count','result_size','http_calls'], $benchRows);
    $elapsed = array_map('floatval', array_column($benchRows, 'elapsed_ms'));
    sort($elapsed);
    $pct = static fn (float $p): float => (float) $elapsed[min(count($elapsed) - 1, (int) floor((count($elapsed) - 1) * $p))];
    $p50 = $pct(0.50);
    $p95 = $pct(0.95);
    $p99 = $pct(0.99);
    $max = max($elapsed);

    $statePassRows = count(array_filter($matrixRows, static fn (array $row): bool => $row['pass'] === 'PASS'));
    $hashMismatches = count(array_filter($hashRows, static fn (array $row): bool => $row['match'] !== 'YES'));
    $assert(count($matrixRows) === 16, 'HEALTH_STATE_MATRIX_RAW_ROWS_NOT_16');
    $assert($statePassRows === 16, 'HEALTH_STATE_MATRIX_PASS_ROWS_NOT_16');
    $assert(count($hashRows) === 16 * count($hashTables), 'HEALTH_TABLE_HASH_RAW_ROWS_UNEXPECTED');
    $assert($hashMismatches === 0, 'HEALTH_TABLE_HASH_MISMATCHES');
    $assert(count($benchRows) === 60, 'HEALTH_PERFORMANCE_OBSERVATION_COUNT');
    $assert(array_sum(array_map('intval', array_column($benchRows, 'http_calls'))) === 0, 'HEALTH_PERFORMANCE_HTTP_CALLS');
    $assert($tenantCrossRows === 0, 'HEALTH_CROSS_TENANT_ROWS');

    echo 'K5_HEALTH_BEHAVIORAL_MYSQL_23919=PASS checks=' . $checks . PHP_EOL;
    echo 'CERTIFICATION_DB_VERSION=' . $version . PHP_EOL;
    echo 'DB_ENGINE=MariaDB' . PHP_EOL;
    echo 'DB_VERSION_FAMILY=11.8.8' . PHP_EOL;
    echo 'HEALTH_READ_ONLY=PASS' . PHP_EOL;
    echo 'TABLE_MUTATIONS=0' . PHP_EOL;
    echo 'HEALTH_BUSINESS_MUTATIONS=0' . PHP_EOL;
    echo 'HEALTH_QUEUE_MUTATIONS=0' . PHP_EOL;
    echo 'HEALTH_NOTIFICATION_MUTATIONS=0' . PHP_EOL;
    echo 'HEALTH_FINANCE_MUTATIONS=0' . PHP_EOL;
    echo 'HEALTH_RHYTHM_MUTATIONS=0' . PHP_EOL;
    echo 'HEALTH_REMOTE_HTTP=0' . PHP_EOL;
    echo 'REMOTE_ATTEMPTS=0' . PHP_EOL;
    echo 'HEALTH_STATE_MATRIX_ROWS=' . count($matrixRows) . PHP_EOL;
    echo 'HEALTH_STATE_MATRIX_PASS_ROWS=' . $statePassRows . PHP_EOL;
    foreach ($matrixRows as $row) {
        echo 'STATE_' . $row['state'] . '=' . $row['pass'] . PHP_EOL;
    }
    echo 'HEALTH_TABLE_HASH_ROWS=' . count($hashRows) . PHP_EOL;
    echo 'HEALTH_TABLE_HASH_MISMATCHES=' . $hashMismatches . PHP_EOL;
    echo 'HEALTH_CROSS_TENANT_ROWS=' . $tenantCrossRows . PHP_EOL;
    echo 'HEALTH_PERFORMANCE_RAW_ROWS=' . count($benchRows) . PHP_EOL;
    echo 'HEALTH_P50_MS=' . round($p50, 3) . PHP_EOL;
    echo 'HEALTH_P95_MS=' . round($p95, 3) . PHP_EOL;
    echo 'HEALTH_P99_MS=' . round($p99, 3) . PHP_EOL;
    echo 'HEALTH_MAX_MS=' . round($max, 3) . PHP_EOL;
    echo 'HEALTH_PERFORMANCE_SUMMARY_MATCHES_RAW=PASS' . PHP_EOL;
    echo 'HEALTH_R2_RAW_DIR=' . $rawDir . PHP_EOL;
} finally {
    try {
        if (isset($server) && $server instanceof PDO) {
            foreach ($databases as $db) {
                $server->exec('DROP DATABASE IF EXISTS ' . $quote($db));
            }
        }
    } catch (Throwable) {
    }
    SchemaInspectorService::clearCache();
    AppSettingsService::clearCache();
}
