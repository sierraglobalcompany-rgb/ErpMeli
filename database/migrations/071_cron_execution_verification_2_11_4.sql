-- ERP Meli 2.11.4 — Cron verificable, pruebas rápidas y estado confiable.

SET @has_execution_source = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND COLUMN_NAME='execution_source'
);
SET @sql_execution_source = IF(
    @has_execution_source=0,
    'ALTER TABLE cron_health_checks ADD COLUMN execution_source VARCHAR(40) NOT NULL DEFAULT ''legacy'' AFTER job_name',
    'SELECT 1'
);
PREPARE stmt_execution_source FROM @sql_execution_source;
EXECUTE stmt_execution_source;
DEALLOCATE PREPARE stmt_execution_source;

SET @has_run_token = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND COLUMN_NAME='run_token'
);
SET @sql_run_token = IF(
    @has_run_token=0,
    'ALTER TABLE cron_health_checks ADD COLUMN run_token VARCHAR(64) NULL AFTER execution_source',
    'SELECT 1'
);
PREPARE stmt_run_token FROM @sql_run_token;
EXECUTE stmt_run_token;
DEALLOCATE PREPARE stmt_run_token;

SET @has_heartbeat_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND COLUMN_NAME='heartbeat_at'
);
SET @sql_heartbeat_at = IF(
    @has_heartbeat_at=0,
    'ALTER TABLE cron_health_checks ADD COLUMN heartbeat_at DATETIME NULL AFTER started_at',
    'SELECT 1'
);
PREPARE stmt_heartbeat_at FROM @sql_heartbeat_at;
EXECUTE stmt_heartbeat_at;
DEALLOCATE PREPARE stmt_heartbeat_at;

SET @has_result_state = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND COLUMN_NAME='result_state'
);
SET @sql_result_state = IF(
    @has_result_state=0,
    'ALTER TABLE cron_health_checks ADD COLUMN result_state VARCHAR(30) NULL AFTER status',
    'SELECT 1'
);
PREPARE stmt_result_state FROM @sql_result_state;
EXECUTE stmt_result_state;
DEALLOCATE PREPARE stmt_result_state;

SET @has_exit_code = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND COLUMN_NAME='exit_code'
);
SET @sql_exit_code = IF(
    @has_exit_code=0,
    'ALTER TABLE cron_health_checks ADD COLUMN exit_code SMALLINT NULL AFTER result_state',
    'SELECT 1'
);
PREPARE stmt_exit_code FROM @sql_exit_code;
EXECUTE stmt_exit_code;
DEALLOCATE PREPARE stmt_exit_code;

SET @has_expected_interval = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND COLUMN_NAME='expected_interval_minutes'
);
SET @sql_expected_interval = IF(
    @has_expected_interval=0,
    'ALTER TABLE cron_health_checks ADD COLUMN expected_interval_minutes SMALLINT UNSIGNED NULL AFTER exit_code',
    'SELECT 1'
);
PREPARE stmt_expected_interval FROM @sql_expected_interval;
EXECUTE stmt_expected_interval;
DEALLOCATE PREPARE stmt_expected_interval;

SET @has_next_expected = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND COLUMN_NAME='next_expected_at'
);
SET @sql_next_expected = IF(
    @has_next_expected=0,
    'ALTER TABLE cron_health_checks ADD COLUMN next_expected_at DATETIME NULL AFTER expected_interval_minutes',
    'SELECT 1'
);
PREPARE stmt_next_expected FROM @sql_next_expected;
EXECUTE stmt_next_expected;
DEALLOCATE PREPARE stmt_next_expected;

SET @has_automatic_streak = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND COLUMN_NAME='automatic_streak'
);
SET @sql_automatic_streak = IF(
    @has_automatic_streak=0,
    'ALTER TABLE cron_health_checks ADD COLUMN automatic_streak SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER next_expected_at',
    'SELECT 1'
);
PREPARE stmt_automatic_streak FROM @sql_automatic_streak;
EXECUTE stmt_automatic_streak;
DEALLOCATE PREPARE stmt_automatic_streak;

SET @has_run_token_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND INDEX_NAME='uq_cron_health_run_token'
);
SET @sql_run_token_index = IF(
    @has_run_token_index=0,
    'ALTER TABLE cron_health_checks ADD UNIQUE KEY uq_cron_health_run_token (run_token)',
    'SELECT 1'
);
PREPARE stmt_run_token_index FROM @sql_run_token_index;
EXECUTE stmt_run_token_index;
DEALLOCATE PREPARE stmt_run_token_index;

SET @has_source_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND INDEX_NAME='idx_cron_health_source_started'
);
SET @sql_source_index = IF(
    @has_source_index=0,
    'ALTER TABLE cron_health_checks ADD KEY idx_cron_health_source_started (job_name,execution_source,started_at)',
    'SELECT 1'
);
PREPARE stmt_source_index FROM @sql_source_index;
EXECUTE stmt_source_index;
DEALLOCATE PREPARE stmt_source_index;

SET @has_heartbeat_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND INDEX_NAME='idx_cron_health_heartbeat'
);
SET @sql_heartbeat_index = IF(
    @has_heartbeat_index=0,
    'ALTER TABLE cron_health_checks ADD KEY idx_cron_health_heartbeat (job_name,heartbeat_at)',
    'SELECT 1'
);
PREPARE stmt_heartbeat_index FROM @sql_heartbeat_index;
EXECUTE stmt_heartbeat_index;
DEALLOCATE PREPARE stmt_heartbeat_index;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('cron.main_interval_minutes', '5', 'cron', 0),
('cron.notifications_interval_minutes', '1', 'cron', 0),
('cron.verification_required_streak', '2', 'cron', 0),
('cron.running_stale_seconds', '180', 'cron', 0),
('cron.compact_cli_output', '1', 'cron', 0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.11.4', 'Cron verificable, prueba manual separada, heartbeat y salida CLI compacta.');
