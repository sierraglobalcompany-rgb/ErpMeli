-- ERP Meli 2.11.6 — Integridad verificable de release y componentes cron.
-- Aditiva, idempotente y sin cambios en datos comerciales.

SET @has_release_version = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND COLUMN_NAME='release_version'
);
SET @sql_release_version = IF(
    @has_release_version=0,
    'ALTER TABLE cron_health_checks ADD COLUMN release_version VARCHAR(30) NULL AFTER lock_name',
    'SELECT 1'
);
PREPARE stmt_release_version FROM @sql_release_version;
EXECUTE stmt_release_version;
DEALLOCATE PREPARE stmt_release_version;

SET @has_release_build_id = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND COLUMN_NAME='release_build_id'
);
SET @sql_release_build_id = IF(
    @has_release_build_id=0,
    'ALTER TABLE cron_health_checks ADD COLUMN release_build_id VARCHAR(80) NULL AFTER release_version',
    'SELECT 1'
);
PREPARE stmt_release_build_id FROM @sql_release_build_id;
EXECUTE stmt_release_build_id;
DEALLOCATE PREPARE stmt_release_build_id;

SET @has_component_checksum = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND COLUMN_NAME='component_checksum'
);
SET @sql_component_checksum = IF(
    @has_component_checksum=0,
    'ALTER TABLE cron_health_checks ADD COLUMN component_checksum VARCHAR(64) NULL AFTER release_build_id',
    'SELECT 1'
);
PREPARE stmt_component_checksum FROM @sql_component_checksum;
EXECUTE stmt_component_checksum;
DEALLOCATE PREPARE stmt_component_checksum;

SET @has_release_build_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks' AND INDEX_NAME='idx_cron_health_release_build'
);
SET @sql_release_build_index = IF(
    @has_release_build_index=0,
    'ALTER TABLE cron_health_checks ADD KEY idx_cron_health_release_build (job_name,release_build_id,started_at)',
    'SELECT 1'
);
PREPARE stmt_release_build_index FROM @sql_release_build_index;
EXECUTE stmt_release_build_index;
DEALLOCATE PREPARE stmt_release_build_index;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('cron.release_integrity_enabled','1','cron',0),
('cron.release_integrity_required_version','2.11.6','cron',0),
('cron.release_integrity_required_migration','073_cron_release_integrity_2_11_6.sql','cron',0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.11.6','Integridad verificable de release, workers cron y diagnóstico de instalaciones mezcladas.');
