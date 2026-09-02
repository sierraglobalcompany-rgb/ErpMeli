-- ERP Meli 2.28.21 — backlog medido, selección verdadera y salud verificable.
-- Solo agrega telemetría operativa. No modifica datos comerciales ni credenciales.

-- MySQL 8 no admite ADD COLUMN IF NOT EXISTS. Las sentencias preparadas
-- mantienen la migración idempotente tanto en MySQL como en MariaDB.
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cron_task_state' AND column_name='measurement_state')=0,
    "ALTER TABLE cron_task_state ADD COLUMN measurement_state ENUM('complete','partial','unavailable') NOT NULL DEFAULT 'complete' AFTER last_work_observed_at",
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
ALTER TABLE cron_task_state
    MODIFY COLUMN measurement_state ENUM('complete','partial','unavailable') NOT NULL DEFAULT 'complete';
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cron_task_state' AND column_name='last_eligible_count')=0,
    'ALTER TABLE cron_task_state ADD COLUMN last_eligible_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER measurement_state',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cron_task_state' AND column_name='last_total_pending')=0,
    'ALTER TABLE cron_task_state ADD COLUMN last_total_pending INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_eligible_count',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cron_task_state' AND column_name='last_waiting_schedule')=0,
    'ALTER TABLE cron_task_state ADD COLUMN last_waiting_schedule INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_total_pending',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cron_task_state' AND column_name='last_running_count')=0,
    'ALTER TABLE cron_task_state ADD COLUMN last_running_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_waiting_schedule',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cron_task_state' AND column_name='last_attention_count')=0,
    'ALTER TABLE cron_task_state ADD COLUMN last_attention_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_running_count',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cron_task_state' AND column_name='last_observation_error_at')=0,
    'ALTER TABLE cron_task_state ADD COLUMN last_observation_error_at DATETIME(3) NULL AFTER last_attention_count',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

UPDATE cron_task_state
SET last_eligible_count=last_work_count,
    last_total_pending=last_work_count,
    last_waiting_schedule=0
WHERE last_total_pending=0 AND last_work_count>0;

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_work_queue_runs' AND column_name='candidate_count')=0,
    'ALTER TABLE system_work_queue_runs ADD COLUMN candidate_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER status',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_work_queue_run_items' AND column_name='attempted_remote_calls')=0,
    'ALTER TABLE system_work_queue_run_items ADD COLUMN attempted_remote_calls INT UNSIGNED NOT NULL DEFAULT 0 AFTER completed_count',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_work_queue_run_items' AND column_name='blocked_remote_calls')=0,
    'ALTER TABLE system_work_queue_run_items ADD COLUMN blocked_remote_calls INT UNSIGNED NOT NULL DEFAULT 0 AFTER actual_api_calls',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_work_queue_run_items' AND column_name='backlog_after_state')=0,
    "ALTER TABLE system_work_queue_run_items ADD COLUMN backlog_after_state ENUM('complete','unavailable') NOT NULL DEFAULT 'unavailable' AFTER backlog_after",
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_work_queue_run_items' AND column_name='backlog_after_measured_at')=0,
    'ALTER TABLE system_work_queue_run_items ADD COLUMN backlog_after_measured_at DATETIME(3) NULL AFTER backlog_after_state',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

CREATE TABLE IF NOT EXISTS system_cron_backlog_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_token VARCHAR(100) NOT NULL,
    queue_key VARCHAR(80) NOT NULL,
    measurement_state ENUM('complete','partial','unavailable') NOT NULL,
    coverage ENUM('total','eligible_only','none') NOT NULL DEFAULT 'total',
    total_pending INT UNSIGNED NULL,
    eligible_now INT UNSIGNED NULL,
    waiting_schedule INT UNSIGNED NULL,
    running_count INT UNSIGNED NULL,
    attention_count INT UNSIGNED NULL,
    measured_at DATETIME(3) NOT NULL,
    UNIQUE KEY uq_cron_backlog_run_queue (run_token,queue_key),
    KEY idx_cron_backlog_measured (measured_at,run_token),
    KEY idx_cron_backlog_queue (queue_key,measured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE system_cron_backlog_snapshots
    MODIFY COLUMN measurement_state ENUM('complete','partial','unavailable') NOT NULL;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_cron_backlog_snapshots' AND column_name='coverage')=0,
    "ALTER TABLE system_cron_backlog_snapshots ADD COLUMN coverage ENUM('total','eligible_only','none') NOT NULL DEFAULT 'total' AFTER measurement_state",
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_cron_backlog_snapshots' AND column_name='running_count')=0,
    'ALTER TABLE system_cron_backlog_snapshots ADD COLUMN running_count INT UNSIGNED NULL AFTER waiting_schedule',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_cron_backlog_snapshots' AND column_name='attention_count')=0,
    'ALTER TABLE system_cron_backlog_snapshots ADD COLUMN attention_count INT UNSIGNED NULL AFTER running_count',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.spool_lane_seconds','3',0,'cron'),
('cron.backlog_measurement_enabled','1',0,'cron'),
('cron.candidate_counter_enabled','1',0,'cron'),
('app.version','2.28.21',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','201_cron_measured_backlog_fair_scheduler_2_28_21.sql','structural',1),
('automation_center','201_cron_measured_backlog_fair_scheduler_2_28_21.sql','structural',1),
('api_health','201_cron_measured_backlog_fair_scheduler_2_28_21.sql','structural',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.21','Cron con backlog medido, selección real y trazabilidad remota por tarea')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
