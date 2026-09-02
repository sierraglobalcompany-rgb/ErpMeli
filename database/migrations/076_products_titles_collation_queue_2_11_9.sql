-- ERP Meli 2.11.9 — Productos legibles, collation operativa y recuperación canaria.
-- Aditiva e idempotente. No modifica datos comerciales.

CREATE TABLE IF NOT EXISTS meli_notification_recovery_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    signature VARCHAR(80) NOT NULL,
    mode ENUM('canary','full') NOT NULL DEFAULT 'canary',
    status ENUM('canary_running','canary_passed','canary_failed','recovering','paused','blocked','complete') NOT NULL,
    candidate_count INT UNSIGNED NOT NULL DEFAULT 0,
    account_count INT UNSIGNED NOT NULL DEFAULT 0,
    queued_count INT UNSIGNED NOT NULL DEFAULT 0,
    completed_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    safe_message VARCHAR(500) NULL,
    started_at DATETIME NULL,
    paused_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notification_recovery_status (status,updated_at),
    KEY idx_notification_recovery_signature (signature,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_notification_recovery_run_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recovery_run_id BIGINT UNSIGNED NOT NULL,
    work_item_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    original_diagnostic_id VARCHAR(80) NULL,
    is_canary TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('frozen','queued','processing','complete','satisfied_local','collation_error','other_error') NOT NULL DEFAULT 'frozen',
    safe_message VARCHAR(500) NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_recovery_item (recovery_run_id,work_item_id),
    KEY idx_notification_recovery_item_status (recovery_run_id,status,is_canary),
    KEY idx_notification_recovery_item_account (meli_account_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Normalizar únicamente tablas de coordinación que comparan claves textuales.
SET @normalize_api_budget = (
    SELECT IF(COUNT(*)=1 AND MAX(TABLE_COLLATION)<>'utf8mb4_unicode_ci',
        'ALTER TABLE api_budget_windows CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'SELECT 1')
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_budget_windows'
);
PREPARE stmt_normalize_api_budget FROM @normalize_api_budget;
EXECUTE stmt_normalize_api_budget;
DEALLOCATE PREPARE stmt_normalize_api_budget;

SET @normalize_notification_work = (
    SELECT IF(COUNT(*)=1 AND MAX(TABLE_COLLATION)<>'utf8mb4_unicode_ci',
        'ALTER TABLE meli_notification_work_items CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'SELECT 1')
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_notification_work_items'
);
PREPARE stmt_normalize_notification_work FROM @normalize_notification_work;
EXECUTE stmt_normalize_notification_work;
DEALLOCATE PREPARE stmt_normalize_notification_work;

SET @normalize_notification_events = (
    SELECT IF(COUNT(*)=1 AND MAX(TABLE_COLLATION)<>'utf8mb4_unicode_ci',
        'ALTER TABLE meli_notification_events CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'SELECT 1')
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_notification_events'
);
PREPARE stmt_normalize_notification_events FROM @normalize_notification_events;
EXECUTE stmt_normalize_notification_events;
DEALLOCATE PREPARE stmt_normalize_notification_events;

SET @has_cron_work_count = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_task_state' AND COLUMN_NAME='last_work_count'
);
SET @sql_cron_work_count = IF(@has_cron_work_count=0,
    'ALTER TABLE cron_task_state ADD COLUMN last_work_count INT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt_cron_work_count FROM @sql_cron_work_count;
EXECUTE stmt_cron_work_count;
DEALLOCATE PREPARE stmt_cron_work_count;

SET @has_cron_oldest_due = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_task_state' AND COLUMN_NAME='oldest_due_at'
);
SET @sql_cron_oldest_due = IF(@has_cron_oldest_due=0,
    'ALTER TABLE cron_task_state ADD COLUMN oldest_due_at DATETIME NULL',
    'SELECT 1');
PREPARE stmt_cron_oldest_due FROM @sql_cron_oldest_due;
EXECUTE stmt_cron_oldest_due;
DEALLOCATE PREPARE stmt_cron_oldest_due;

SET @has_cron_selection_reason = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_task_state' AND COLUMN_NAME='last_selection_reason'
);
SET @sql_cron_selection_reason = IF(@has_cron_selection_reason=0,
    'ALTER TABLE cron_task_state ADD COLUMN last_selection_reason VARCHAR(60) NULL',
    'SELECT 1');
PREPARE stmt_cron_selection_reason FROM @sql_cron_selection_reason;
EXECUTE stmt_cron_selection_reason;
DEALLOCATE PREPARE stmt_cron_selection_reason;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('ui.products_title_clamp_enabled','1','ui',0),
('ui.products_title_desktop_lines','1','ui',0),
('ui.products_title_mobile_lines','2','ui',0),
('notifications.collation_recovery_enabled','1','notifications',0),
('notifications.collation_recovery_batch','25','notifications',0),
('cron.backlog_aware_scheduler_enabled','1','cron',0),
('cron.order_queue_max_wait_minutes','10','cron',0),
('cron.release_integrity_required_version','2.11.9','cron',0),
('cron.release_integrity_required_migration','076_products_titles_collation_queue_2_11_9.sql','cron',0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.11.9','Corrige collations operativas, recupera Webhook-First por canarios, prioriza backlog y estabiliza títulos de Productos ML.');
