<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\SessionGenerationService;

function cap2_health_create_schema(PDO $pdo): void
{
    $statements = [
        'CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(100),email VARCHAR(190),password_hash VARCHAR(255),role VARCHAR(30),status TINYINT,is_temporary TINYINT DEFAULT 0)',
        'CREATE TABLE companies (id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(100),status TINYINT NOT NULL)',
        'CREATE TABLE meli_accounts (id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,account_name VARCHAR(100),meli_user_id VARCHAR(191),status VARCHAR(30),UNIQUE KEY uq_tenant(company_id,id))',
        'CREATE TABLE meli_tokens (meli_account_id BIGINT UNSIGNED PRIMARY KEY,refresh_token_encrypted TEXT,expires_at DATETIME(3),refresh_version BIGINT UNSIGNED NOT NULL DEFAULT 1)',
        'CREATE TABLE user_company_access (user_id BIGINT UNSIGNED,company_id BIGINT UNSIGNED,access_role VARCHAR(30),PRIMARY KEY(user_id,company_id))',
        'CREATE TABLE user_meli_account_access (user_id BIGINT UNSIGNED,meli_account_id BIGINT UNSIGNED,access_role VARCHAR(30),PRIMARY KEY(user_id,meli_account_id))',
        'CREATE TABLE app_settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,is_encrypted TINYINT NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT "general",updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)',
        'CREATE TABLE queue_v4_clean_control (control_key VARCHAR(32) PRIMARY KEY,engine_state VARCHAR(20),readiness_state VARCHAR(30),scheduler_enabled TINYINT,last_scheduler_at DATETIME(3),updated_at DATETIME(3))',
        'CREATE TABLE queue_v4_clean_jobs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED,meli_account_id BIGINT UNSIGNED,state VARCHAR(20),lease_expires_at DATETIME(3),completed_at DATETIME(3))',
        'CREATE TABLE queue_v4_clean_attempts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED,meli_account_id BIGINT UNSIGNED,started_at DATETIME(3),dispatch_state VARCHAR(30),outcome VARCHAR(30))',
        'CREATE TABLE queue_v4_clean_transport_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED,meli_account_id BIGINT UNSIGNED,physical_started_at DATETIME(3),response_known_at DATETIME(3),method VARCHAR(10),endpoint_key VARCHAR(100),dispatch_state VARCHAR(30),http_status SMALLINT)',
        'CREATE TABLE queue_v4_clean_readiness_runs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,state VARCHAR(30))',
        'CREATE TABLE queue_v4_clean_readiness_accounts (readiness_run_id BIGINT UNSIGNED,company_id BIGINT UNSIGNED,meli_account_id BIGINT UNSIGNED,outcome VARCHAR(20),PRIMARY KEY(readiness_run_id,company_id,meli_account_id))',
        'CREATE TABLE oauth_refresh_operations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED,meli_account_id BIGINT UNSIGNED,state VARCHAR(30),next_attempt_at DATETIME(3),last_http_status SMALLINT,last_error_class VARCHAR(100),completed_at DATETIME(3))',
        'CREATE TABLE sync_sales_audit_runs (id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED,meli_account_id BIGINT UNSIGNED)',
        'CREATE TABLE sync_sales_audit_jobs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,sync_sales_audit_run_id BIGINT UNSIGNED,company_id BIGINT UNSIGNED,meli_account_id BIGINT UNSIGNED,status VARCHAR(30),next_run_at DATETIME(3))',
        'CREATE TABLE sync_sales_repair_jobs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED,meli_account_id BIGINT UNSIGNED,source_kind VARCHAR(20),status VARCHAR(30),next_run_at DATETIME(3))',
        'CREATE TABLE api_request_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,scope_kind VARCHAR(20) NOT NULL,company_id BIGINT UNSIGNED NULL,meli_account_id BIGINT UNSIGNED NULL,http_status INT NULL,reached_remote TINYINT NOT NULL DEFAULT 0,created_at DATETIME NOT NULL,KEY idx_cap2_remote_scope(http_status,reached_remote,created_at,scope_kind,company_id,meli_account_id))',
        'CREATE TABLE api_incident_materializer_state (singleton_id TINYINT UNSIGNED PRIMARY KEY,last_log_id BIGINT UNSIGNED NOT NULL)',
    ];
    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
    // Calls-plan health authority also reads physical billing evidence. These
    // empty real tables represent complete absence, not a mocked healthy result.
    $pdo->exec('ALTER TABLE api_request_logs ADD endpoint_path VARCHAR(255),ADD request_id VARCHAR(80),ADD retry_after_seconds INT');
    $pdo->exec('CREATE TABLE api_remote_permits(id BIGINT AUTO_INCREMENT PRIMARY KEY,company_id BIGINT,meli_account_id BIGINT,endpoint_key VARCHAR(100),permit_token CHAR(40),http_status INT,retry_after_seconds INT,completed_at DATETIME(3),dispatched_at DATETIME(3))');
    $pdo->exec('CREATE TABLE api_rhythm_penalties(scope_key VARCHAR(190) PRIMARY KEY,blocked_until DATETIME(3))');
    $pdo->exec('CREATE TABLE api_rhythm_states(scope_key VARCHAR(190) PRIMARY KEY,next_allowed_at DATETIME(3),block_pause_until DATETIME(3))');
}

function cap2_health_seed(PDO $pdo): void
{
    $pdo->exec("INSERT INTO users VALUES (7,'Admin','admin@local.test','x','admin',1,0)");
    $pdo->exec("INSERT INTO companies VALUES (1,'Uno',1),(2,'Dos',1),(3,'Inactiva',0),(4,'Activa sin cuenta',1),(5,'Activa sin trabajo',1)");
    $pdo->exec("INSERT INTO meli_accounts VALUES (11,1,'Cuenta 11','u11','conectado'),(22,2,'Cuenta 22','u22','conectado'),(33,3,'Cuenta 33','u33','conectado'),(55,5,'Cuenta 55','u55','conectado')");
    $pdo->exec("INSERT INTO meli_tokens VALUES (11,'enc',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY),1),(22,'enc',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY),1),(33,'enc',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY),1),(55,'enc',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY),1)");
    $pdo->exec("INSERT INTO user_company_access VALUES (7,1,'admin'),(7,2,'admin'),(7,3,'admin'),(7,4,'admin'),(7,5,'admin')");
    $pdo->exec("INSERT INTO queue_v4_clean_control VALUES ('primary','ACTIVE','CERTIFIED',1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO queue_v4_clean_readiness_runs(id,state) VALUES (1,'CERTIFIED')");
    $pdo->exec("INSERT INTO queue_v4_clean_readiness_accounts VALUES (1,1,11,'PASS'),(1,2,22,'PASS'),(1,3,33,'PASS')");
    $pdo->exec("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,state,lease_expires_at) VALUES (1,11,'ready',NULL),(2,22,'ready',NULL),(3,33,'ready',NULL)");
    $pdo->exec("INSERT INTO sync_sales_audit_runs VALUES (1,1,11)");
    $pdo->exec("INSERT INTO api_incident_materializer_state VALUES (1,0)");
}

function cap2_health_authenticate(): void
{
    $_SESSION = [
        '_csrf' => 'cap2-health-csrf',
        'user' => [
            'id' => 7,
            'name' => 'Admin',
            'email' => 'admin@local.test',
            'role' => 'admin',
            'is_temporary' => 0,
            'session_generation' => (new SessionGenerationService())->current(),
        ],
    ];
}

function cap2_health_connect_existing(): PDO
{
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_NAME')),
        (string) getenv('DB_USER'),
        (string) getenv('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("SET SESSION time_zone='+00:00'");
    Database::setConnection($pdo);
    return $pdo;
}
