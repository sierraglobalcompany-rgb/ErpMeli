-- ERP Meli 2.11.8 — Worker Webhook-First y OAuth sin transacciones de red.
-- Aditiva, idempotente y sin modificaciones de datos comerciales.

SET @has_notification_consecutive_failures = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_notification_work_items'
      AND COLUMN_NAME='consecutive_failures'
);
SET @sql_notification_consecutive_failures = IF(
    @has_notification_consecutive_failures=0,
    'ALTER TABLE meli_notification_work_items ADD COLUMN consecutive_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt_notification_consecutive_failures FROM @sql_notification_consecutive_failures;
EXECUTE stmt_notification_consecutive_failures;
DEALLOCATE PREPARE stmt_notification_consecutive_failures;

SET @has_notification_last_success = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_notification_work_items'
      AND COLUMN_NAME='last_success_at'
);
SET @sql_notification_last_success = IF(
    @has_notification_last_success=0,
    'ALTER TABLE meli_notification_work_items ADD COLUMN last_success_at DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt_notification_last_success FROM @sql_notification_last_success;
EXECUTE stmt_notification_last_success;
DEALLOCATE PREPARE stmt_notification_last_success;

SET @has_notification_processing_event = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_notification_work_items'
      AND COLUMN_NAME='processing_event_id'
);
SET @sql_notification_processing_event = IF(
    @has_notification_processing_event=0,
    'ALTER TABLE meli_notification_work_items ADD COLUMN processing_event_id BIGINT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt_notification_processing_event FROM @sql_notification_processing_event;
EXECUTE stmt_notification_processing_event;
DEALLOCATE PREPARE stmt_notification_processing_event;

SET @has_oauth_processing_token = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_oauth_states'
      AND COLUMN_NAME='processing_token_hash'
);
SET @sql_oauth_processing_token = IF(
    @has_oauth_processing_token=0,
    'ALTER TABLE meli_oauth_states ADD COLUMN processing_token_hash CHAR(64) NULL',
    'SELECT 1'
);
PREPARE stmt_oauth_processing_token FROM @sql_oauth_processing_token;
EXECUTE stmt_oauth_processing_token;
DEALLOCATE PREPARE stmt_oauth_processing_token;

SET @has_oauth_processing_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_oauth_states'
      AND COLUMN_NAME='processing_at'
);
SET @sql_oauth_processing_at = IF(
    @has_oauth_processing_at=0,
    'ALTER TABLE meli_oauth_states ADD COLUMN processing_at DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt_oauth_processing_at FROM @sql_oauth_processing_at;
EXECUTE stmt_oauth_processing_at;
DEALLOCATE PREPARE stmt_oauth_processing_at;

SET @has_oauth_last_error = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_oauth_states'
      AND COLUMN_NAME='last_error_message'
);
SET @sql_oauth_last_error = IF(
    @has_oauth_last_error=0,
    'ALTER TABLE meli_oauth_states ADD COLUMN last_error_message VARCHAR(500) NULL',
    'SELECT 1'
);
PREPARE stmt_oauth_last_error FROM @sql_oauth_last_error;
EXECUTE stmt_oauth_last_error;
DEALLOCATE PREPARE stmt_oauth_last_error;

SET @has_oauth_processing_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_oauth_states'
      AND INDEX_NAME='idx_oauth_state_processing'
);
SET @sql_oauth_processing_index = IF(
    @has_oauth_processing_index=0,
    'ALTER TABLE meli_oauth_states ADD KEY idx_oauth_state_processing (processing_at,consumed_at,expires_at)',
    'SELECT 1'
);
PREPARE stmt_oauth_processing_index FROM @sql_oauth_processing_index;
EXECUTE stmt_oauth_processing_index;
DEALLOCATE PREPARE stmt_oauth_processing_index;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('oauth.refresh_lock_wait_seconds','3','oauth',0),
('oauth.token_expiry_skew_seconds','120','oauth',0),
('notifications.transaction_error_recovery_enabled','1','notifications',0),
('notifications.transaction_error_recovery_batch','25','notifications',0),
('cron.release_integrity_required_version','2.11.8','cron',0),
('cron.release_integrity_required_migration','075_notification_worker_transaction_recovery_2_11_8.sql','cron',0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.11.8','Corrige renovación OAuth transaccional, cierre atómico y recuperación selectiva del worker Webhook-First.');
