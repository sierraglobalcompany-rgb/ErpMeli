<?php

declare(strict_types=1);

/**
 * Prueba conductual de dos invariantes del runtime:
 *
 * 1. Un trabajo de publicaciones cuyos elementos agotaron sus intentos debe
 *    quedar terminal y no volver a ser reclamado por Cron.
 * 2. El sondeo de disponibilidad debe consultar solo las colas solicitadas y
 *    reconocer el estado real "queued" de descripciones.
 *
 * Usa una base MariaDB desechable y no construye ningún cliente HTTP.
 */

$host = (string) (getenv('ERP_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string) (getenv('ERP_TEST_DB_PORT') ?: '33316');
$user = (string) (getenv('ERP_TEST_DB_USER') ?: 'root');
$pass = (string) (getenv('ERP_TEST_DB_PASS') ?: '');
$database = 'erp_runtime_queue_' . bin2hex(random_bytes(6));
$root = dirname(__DIR__);
$private = sys_get_temp_dir() . '/erp-runtime-queue-' . bin2hex(random_bytes(6));
$server = new PDO(
    'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$serverVersion = strtolower((string) $server->query('SELECT VERSION()')->fetchColumn());
if (!str_contains($serverVersion, 'mariadb') || version_compare($serverVersion, '11.8', '<')) {
    throw new RuntimeException('Esta prueba exige MariaDB 11.8 o superior.');
}

function runtimeQueueAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci');
    $_ENV['DB_HOST'] = $host;
    $_ENV['DB_PORT'] = $port;
    $_ENV['DB_NAME'] = $database;
    $_ENV['DB_USER'] = $user;
    $_ENV['DB_PASS'] = $pass;
    $_ENV['APP_ENV'] = 'local';
    $_ENV['APP_URL'] = 'http://localhost';
    $_ENV['ML_WRITE_ENABLED'] = 'false';
    $_ENV['ERP_PRIVATE_PATH'] = $private;
    define('ERP_SHARED_ROOT', $private);
    require $root . '/bootstrap.php';

    $pdo = \App\Core\Database::connection();
    $pdo->exec(<<<'SQL'
CREATE TABLE app_settings(
 setting_key VARCHAR(190) PRIMARY KEY,
 setting_value TEXT,
 is_encrypted TINYINT NOT NULL DEFAULT 0,
 setting_group VARCHAR(80) NOT NULL DEFAULT 'general',
 updated_at DATETIME NULL
);
CREATE TABLE meli_item_sync_jobs(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 meli_account_id BIGINT UNSIGNED NOT NULL,
 search_mode ENUM('offset','scan') NOT NULL DEFAULT 'offset',
 phase ENUM('discovering','details','complete','partial','error','cancelled') NOT NULL DEFAULT 'discovering',
 cursor_value TEXT NULL,
 offset_value INT UNSIGNED NOT NULL DEFAULT 0,
 discovered_count INT UNSIGNED NOT NULL DEFAULT 0,
 processed_count INT UNSIGNED NOT NULL DEFAULT 0,
 error_count INT UNSIGNED NOT NULL DEFAULT 0,
 next_run_at DATETIME NOT NULL,
 lock_token CHAR(32) NULL,
 locked_at DATETIME NULL,
 last_error_message VARCHAR(500) NULL,
 started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_item_sync_jobs_due(phase,next_run_at)
);
CREATE TABLE meli_item_sync_job_items(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 meli_item_sync_job_id BIGINT UNSIGNED NOT NULL,
 external_item_id VARCHAR(40) NOT NULL,
 status ENUM('pending','running','complete','error','skipped') NOT NULL DEFAULT 'pending',
 attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 last_error_message VARCHAR(500) NULL,
 processed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_item_sync_job_external(meli_item_sync_job_id,external_item_id),
 KEY idx_item_sync_job_items_due(meli_item_sync_job_id,status,id)
);
CREATE TABLE catalog_description_jobs(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 status VARCHAR(30) NOT NULL DEFAULT 'queued',
 next_run_at DATETIME NULL,
 lock_expires_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_catalog_description_jobs_due(status,next_run_at,lock_expires_at)
);
CREATE TABLE sync_sales_repair_jobs(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sync_sales_audit_id BIGINT UNSIGNED NULL,
 meli_account_id BIGINT UNSIGNED NOT NULL,
 period_year SMALLINT UNSIGNED NOT NULL,
 period_month TINYINT UNSIGNED NOT NULL,
 status ENUM('pending','running','complete','error','cancelled') NOT NULL DEFAULT 'pending',
 processed_items INT UNSIGNED NOT NULL DEFAULT 0,
 error_message VARCHAR(500) NULL,
 started_at DATETIME NULL,
 completed_at DATETIME NULL
);
CREATE TABLE sync_sales_repair_job_items(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sync_sales_repair_job_id BIGINT UNSIGNED NOT NULL,
 external_order_id VARCHAR(80) NOT NULL,
 status ENUM('pending','complete','error') NOT NULL DEFAULT 'pending',
 error_message VARCHAR(500) NULL,
 processed_at DATETIME NULL
);
CREATE TABLE meli_orders(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
);
CREATE TABLE order_datetime_repair_jobs(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 meli_account_id BIGINT UNSIGNED NOT NULL,
 period_year SMALLINT UNSIGNED NOT NULL,
 period_month TINYINT UNSIGNED NOT NULL,
 status ENUM('pending','running','complete','error','cancelled') NOT NULL DEFAULT 'pending',
 processed_orders INT UNSIGNED NOT NULL DEFAULT 0,
 error_message VARCHAR(500) NULL,
 started_at DATETIME NULL,
 completed_at DATETIME NULL
);
CREATE TABLE order_datetime_repair_items(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_datetime_repair_job_id BIGINT UNSIGNED NOT NULL,
 meli_order_id BIGINT UNSIGNED NOT NULL,
 status ENUM('pending','fixed','error') NOT NULL DEFAULT 'pending',
 error_message VARCHAR(500) NULL,
 processed_at DATETIME NULL
);
CREATE TABLE system_work_queue_runs(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 run_token VARCHAR(100) NOT NULL UNIQUE,
 origin VARCHAR(40) NOT NULL,
 status ENUM('running','completed','partial','failed','empty','skipped') NOT NULL DEFAULT 'running',
 candidate_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 selected_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 started_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 inspected_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 attempted_remote_call_count INT UNSIGNED NOT NULL DEFAULT 0,
 remote_call_count INT UNSIGNED NOT NULL DEFAULT 0,
 blocked_remote_call_count INT UNSIGNED NOT NULL DEFAULT 0,
 checkpoint_approved_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 completed_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 deferred_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 failed_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 not_started_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 duration_ms INT UNSIGNED NULL,
 safe_summary VARCHAR(500) NULL,
 started_at DATETIME NOT NULL,
 finished_at DATETIME NULL
);
CREATE TABLE system_work_queue_run_items(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 work_queue_run_id BIGINT UNSIGNED NOT NULL,
 queue_key VARCHAR(80) NOT NULL,
 lane VARCHAR(24) NULL,
 source_table VARCHAR(100) NOT NULL,
 source_id VARCHAR(100) NOT NULL,
 result ENUM('selected','started','inspected','deferred','completed','partial','failed','retried','skipped','not_started') NOT NULL DEFAULT 'selected',
 execution_result VARCHAR(60) NULL,
 position_no SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 selection_reason VARCHAR(120) NULL,
 backlog_before INT UNSIGNED NULL,
 backlog_after INT UNSIGNED NULL,
 backlog_after_state ENUM('complete','unavailable') NOT NULL DEFAULT 'unavailable',
 backlog_after_measured_at DATETIME(3) NULL,
 batch_limit INT UNSIGNED NULL,
 batch_configured INT NULL,
 batch_effective INT UNSIGNED NULL,
 batch_executed INT UNSIGNED NOT NULL DEFAULT 0,
 batch_limit_reason VARCHAR(40) NULL,
 work_unit VARCHAR(40) NULL,
 inspected_count INT UNSIGNED NOT NULL DEFAULT 0,
 deferred_count INT UNSIGNED NOT NULL DEFAULT 0,
 completed_count INT UNSIGNED NOT NULL DEFAULT 0,
 newly_discovered_count INT UNSIGNED NULL,
 deduplicated_count INT UNSIGNED NULL,
 attempted_remote_calls INT UNSIGNED NOT NULL DEFAULT 0,
 actual_api_calls INT UNSIGNED NOT NULL DEFAULT 0,
 known_response_count INT UNSIGNED NOT NULL DEFAULT 0,
 resources_received_count INT UNSIGNED NOT NULL DEFAULT 0,
 blocked_remote_calls INT UNSIGNED NOT NULL DEFAULT 0,
 checkpoint_approved_count INT UNSIGNED NOT NULL DEFAULT 0,
 campaign_id BIGINT UNSIGNED NULL,
 campaign_item_id BIGINT UNSIGNED NULL,
 next_opportunity_at DATETIME(3) NULL,
 started_at DATETIME(3) NULL,
 finished_at DATETIME(3) NULL,
 duration_ms INT UNSIGNED NULL,
 safe_message VARCHAR(500) NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL
);
CREATE TABLE system_cron_run_steps(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 cron_health_check_id BIGINT UNSIGNED NULL,
 run_token VARCHAR(100) NOT NULL,
 step_name VARCHAR(100) NOT NULL,
 lane VARCHAR(24) NULL,
 campaign_id BIGINT UNSIGNED NULL,
 campaign_item_id BIGINT UNSIGNED NULL,
 selection_reason VARCHAR(120) NULL,
 execution_result VARCHAR(60) NULL,
 priority SMALLINT NOT NULL,
 status VARCHAR(40) NOT NULL,
 selected_count INT UNSIGNED NOT NULL DEFAULT 0,
 started_count INT UNSIGNED NOT NULL DEFAULT 0,
 inspected_count INT UNSIGNED NOT NULL DEFAULT 0,
 deferred_count INT UNSIGNED NOT NULL DEFAULT 0,
 attempted_remote_call_count INT UNSIGNED NOT NULL DEFAULT 0,
 remote_call_count INT UNSIGNED NOT NULL DEFAULT 0,
 blocked_remote_call_count INT UNSIGNED NOT NULL DEFAULT 0,
 checkpoint_approved_count INT UNSIGNED NOT NULL DEFAULT 0,
 completed_count INT UNSIGNED NOT NULL DEFAULT 0,
 failed_count INT UNSIGNED NOT NULL DEFAULT 0,
 not_started_count INT UNSIGNED NOT NULL DEFAULT 0,
 processed_count INT UNSIGNED NOT NULL,
 error_count INT UNSIGNED NOT NULL,
 duration_ms INT UNSIGNED NOT NULL,
 stop_reason VARCHAR(120) NULL,
 next_opportunity_at DATETIME(3) NULL,
 safe_message VARCHAR(500) NULL,
 started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 finished_at DATETIME NULL
);
CREATE TABLE system_process_metrics(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
);
SQL);
    $pdo->exec(
        "INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group)
         VALUES('items.sync_job_max_attempts','3',0,'items');
         INSERT INTO meli_item_sync_jobs
           (id,meli_account_id,phase,next_run_at)
         VALUES(1,10,'partial',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE));
         INSERT INTO meli_item_sync_job_items
           (meli_item_sync_job_id,external_item_id,status,attempts,last_error_message)
         VALUES(1,'MCO-TERMINAL','error',3,'fallo terminal');
         INSERT INTO catalog_description_jobs(status,next_run_at,lock_expires_at)
         VALUES('queued',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE),NULL);
         INSERT INTO sync_sales_repair_jobs
           (id,sync_sales_audit_id,meli_account_id,period_year,period_month,status)
         VALUES(1,NULL,10,2026,1,'running');
         INSERT INTO sync_sales_repair_job_items
           (sync_sales_repair_job_id,external_order_id,status,error_message)
         VALUES(1,'ORDER-FAILED','error','fallo heredado');
         INSERT INTO order_datetime_repair_jobs
           (id,meli_account_id,period_year,period_month,status)
         VALUES(1,10,2026,1,'running');
         INSERT INTO order_datetime_repair_items
           (order_datetime_repair_job_id,meli_order_id,status,error_message)
         VALUES(1,999,'error','evidencia no disponible');"
    );

    $itemJobs = new \App\Services\MeliItemSyncJobService();
    $refresh = new ReflectionMethod($itemJobs, 'refresh');
    $refresh->invoke($itemJobs, 1);

    $phase = (string) $pdo->query('SELECT phase FROM meli_item_sync_jobs WHERE id=1')->fetchColumn();
    runtimeQueueAssert($phase === 'error', 'El trabajo sin intentos recuperables no quedó terminal.');
    $due = $itemJobs->processDue();
    runtimeQueueAssert(
        ($due['phase'] ?? '') === 'empty' && (int) ($due['job_id'] ?? -1) === 0,
        'Cron volvió a reclamar un trabajo terminal.'
    );

    $availability = new \App\Services\CronWorkAvailabilityService();
    $catalog = $availability->snapshot(['catalog_descriptions']);
    runtimeQueueAssert(
        ($catalog['catalog_descriptions']['known'] ?? false) === true
        && (int) ($catalog['catalog_descriptions']['work_count'] ?? 0) === 1,
        'El selector no reconoció una descripción realmente encolada.'
    );

    $questionsBefore = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
    $localOnly = $availability->snapshot(['operational_maintenance']);
    $questionsAfter = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
    runtimeQueueAssert(isset($localOnly['operational_maintenance']), 'Falta la sonda local solicitada.');
    runtimeQueueAssert(
        ($questionsAfter - $questionsBefore) <= 1,
        'El selector local consultó colas no solicitadas.'
    );

    $salesRepair = (new \App\Services\SalesRepairService())->processDue(5);
    runtimeQueueAssert(
        ($salesRepair['status'] ?? '') === 'error'
        && (int) $pdo->query('SELECT COUNT(*) FROM sync_sales_repair_jobs WHERE id=1 AND status="error"')->fetchColumn() === 1,
        'La reparación de ventas quedó completa aunque contenía elementos con error.'
    );

    $dateRepair = (new \App\Services\OrderDateRepairService())->processDue(5);
    runtimeQueueAssert(
        ($dateRepair['status'] ?? '') === 'error'
        && (int) $pdo->query('SELECT COUNT(*) FROM order_datetime_repair_jobs WHERE id=1 AND status="error"')->fetchColumn() === 1,
        'La reparación de fechas quedó completa aunque contenía elementos con error.'
    );

    $runService = new \App\Services\WorkQueueRunService();
    $runId = $runService->begin('runtime-waiting-budget', 'test');
    $runService->selected($runId, ['key' => 'financial_recalc'], 1);
    $runService->result($runId, 'financial_recalc', [
        'status' => 'waiting_budget',
        'errors' => 0,
        'message' => 'Esperando presupuesto.',
    ]);
    $runService->finish($runId, [
        'coordinator' => [
            'steps' => [
                'financial_recalc' => ['status' => 'waiting_budget', 'errors' => 0],
            ],
            'used_ms' => 4,
        ],
        'end_reason' => 'waiting_budget',
    ]);
    runtimeQueueAssert(
        (string) $pdo->query(
            "SELECT CONCAT(r.status,':',i.result,':',r.deferred_count)
             FROM system_work_queue_runs r
             JOIN system_work_queue_run_items i ON i.work_queue_run_id=r.id
             WHERE r.id=" . (int) $runId
        )->fetchColumn() === 'partial:deferred:1',
        'Esperar presupuesto se registró falsamente como trabajo completado.'
    );

    $coordinator = new \App\Services\CronWorkCoordinator(null, 10);
    $coordinator->run('operational_maintenance', 1, static fn(float $deadline): array => [
        'status' => 'completed',
        'processed' => 1,
        'errors' => 0,
    ]);
    runtimeQueueAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM system_cron_run_steps')->fetchColumn() === 1,
        'Cron no guardó su bitácora canónica.'
    );
    runtimeQueueAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM system_process_metrics')->fetchColumn() === 0,
        'Cron duplicó el mismo paso en system_process_metrics.'
    );

    echo "runtime_queue_terminal_states_2263_mysql_integration: OK\n";
} catch (Throwable $error) {
    fwrite(STDERR, "runtime_queue_terminal_states_2263_mysql_integration: " . $error->getMessage() . "\n");
    throw $error;
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
