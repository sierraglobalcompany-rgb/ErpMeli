-- ERP Meli 2.11.7 — Diagnóstico verificable del worker de notificaciones.
-- Aditiva, idempotente y sin cambios en datos comerciales.

SET @has_notification_error_diagnostic = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='meli_notification_work_items'
      AND COLUMN_NAME='last_error_diagnostic_id'
);
SET @sql_notification_error_diagnostic = IF(
    @has_notification_error_diagnostic=0,
    'ALTER TABLE meli_notification_work_items ADD COLUMN last_error_diagnostic_id VARCHAR(64) NULL AFTER last_error_message',
    'SELECT 1'
);
PREPARE stmt_notification_error_diagnostic FROM @sql_notification_error_diagnostic;
EXECUTE stmt_notification_error_diagnostic;
DEALLOCATE PREPARE stmt_notification_error_diagnostic;

SET @has_notification_error_stage = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='meli_notification_work_items'
      AND COLUMN_NAME='last_error_stage'
);
SET @sql_notification_error_stage = IF(
    @has_notification_error_stage=0,
    'ALTER TABLE meli_notification_work_items ADD COLUMN last_error_stage VARCHAR(40) NULL AFTER last_error_diagnostic_id',
    'SELECT 1'
);
PREPARE stmt_notification_error_stage FROM @sql_notification_error_stage;
EXECUTE stmt_notification_error_stage;
DEALLOCATE PREPARE stmt_notification_error_stage;

SET @has_notification_error_diagnostic_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='meli_notification_work_items'
      AND INDEX_NAME='idx_notification_error_diagnostic'
);
SET @sql_notification_error_diagnostic_index = IF(
    @has_notification_error_diagnostic_index=0,
    'ALTER TABLE meli_notification_work_items ADD KEY idx_notification_error_diagnostic (last_error_diagnostic_id,last_processed_at)',
    'SELECT 1'
);
PREPARE stmt_notification_error_diagnostic_index FROM @sql_notification_error_diagnostic_index;
EXECUTE stmt_notification_error_diagnostic_index;
DEALLOCATE PREPARE stmt_notification_error_diagnostic_index;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('cron.notifications_interval_minutes','5','cron',0),
('notifications.dedicated_cron_interval_minutes','5','notifications',0),
('notifications.heartbeat_stale_seconds','660','notifications',0),
('notifications.worker_clean_streak','0','notifications',0),
('notifications.worker_diagnostics_enabled','1','notifications',0),
('cron.release_integrity_required_version','2.11.7','cron',0),
('cron.release_integrity_required_migration','074_notification_worker_diagnostics_2_11_7.sql','cron',0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.11.7','Diagnóstico por recurso, intervalo Hostinger coherente y estado verificable del worker de notificaciones.');
