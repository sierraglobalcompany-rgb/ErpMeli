-- ERP Meli 2.11.5 — Cron Hostinger corto, sin solapamientos y diagnóstico CLI real.
-- Aditiva, idempotente y sin cambios en datos comerciales.

CREATE TABLE IF NOT EXISTS cron_task_state (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_key VARCHAR(100) NOT NULL,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    is_api_task TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'ready',
    next_run_at DATETIME NULL,
    last_started_at DATETIME NULL,
    last_finished_at DATETIME NULL,
    last_run_token VARCHAR(64) NULL,
    last_duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    last_processed INT UNSIGNED NOT NULL DEFAULT 0,
    last_errors INT UNSIGNED NOT NULL DEFAULT 0,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    checkpoint_json LONGTEXT NULL,
    last_error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cron_task_state_key (task_key),
    KEY idx_cron_task_due (status,next_run_at,priority),
    KEY idx_cron_task_finished (last_finished_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_current_step = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND COLUMN_NAME='current_step'
);
SET @sql_current_step = IF(
    @has_current_step=0,
    'ALTER TABLE cron_health_checks ADD COLUMN current_step VARCHAR(100) NULL AFTER heartbeat_at',
    'SELECT 1'
);
PREPARE stmt_current_step FROM @sql_current_step;
EXECUTE stmt_current_step;
DEALLOCATE PREPARE stmt_current_step;

SET @has_deadline_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND COLUMN_NAME='deadline_at'
);
SET @sql_deadline_at = IF(
    @has_deadline_at=0,
    'ALTER TABLE cron_health_checks ADD COLUMN deadline_at DATETIME NULL AFTER current_step',
    'SELECT 1'
);
PREPARE stmt_deadline_at FROM @sql_deadline_at;
EXECUTE stmt_deadline_at;
DEALLOCATE PREPARE stmt_deadline_at;

SET @has_end_reason = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND COLUMN_NAME='end_reason'
);
SET @sql_end_reason = IF(
    @has_end_reason=0,
    'ALTER TABLE cron_health_checks ADD COLUMN end_reason VARCHAR(80) NULL AFTER deadline_at',
    'SELECT 1'
);
PREPARE stmt_end_reason FROM @sql_end_reason;
EXECUTE stmt_end_reason;
DEALLOCATE PREPARE stmt_end_reason;

SET @has_lock_name = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND COLUMN_NAME='lock_name'
);
SET @sql_lock_name = IF(
    @has_lock_name=0,
    'ALTER TABLE cron_health_checks ADD COLUMN lock_name VARCHAR(120) NULL AFTER end_reason',
    'SELECT 1'
);
PREPARE stmt_lock_name FROM @sql_lock_name;
EXECUTE stmt_lock_name;
DEALLOCATE PREPARE stmt_lock_name;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('cron.max_runtime_seconds','40','cron',0),
('cron.accept_work_until_seconds','30','cron',0),
('cron.max_tasks_per_run','3','cron',0),
('cron.max_api_tasks_per_run','1','cron',0),
('cron.api_timeout_seconds','8','cron',0),
('cron.api_connect_timeout_seconds','3','cron',0),
('cron.main_interval_minutes','5','cron',0),
('cron.notifications_interval_minutes','5','cron',0),
('cron.orders_chunk_limit','1','cron',0),
('cron.financial_local_limit','5','cron',0),
('cron.billing_order_limit','10','cron',0),
('cron.products_limit','2','cron',0),
('cron.descriptions_limit','2','cron',0),
('cron.questions_account_limit','1','cron',0),
('cron.repairs_limit','5','cron',0),
('cron.notification_backfill_limit','50','cron',0),
('cron.maintenance_limit','100','cron',0),
('cron.notifications_worker_limit','5','cron',0),
('cron.notifications_max_runtime_seconds','35','cron',0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.11.5','Cron Hostinger acotado, locks globales, rotación persistente y diagnóstico CLI independiente.');
