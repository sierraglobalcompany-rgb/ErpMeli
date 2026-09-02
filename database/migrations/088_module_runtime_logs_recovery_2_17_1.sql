SET @has_lease_generation = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_module_jobs' AND COLUMN_NAME='lease_generation'
);
SET @sql_lease_generation = IF(
    @has_lease_generation=0,
    'ALTER TABLE system_module_jobs ADD COLUMN lease_generation INT UNSIGNED NOT NULL DEFAULT 0 AFTER lock_owner',
    'SELECT 1'
);
PREPARE stmt_lease_generation FROM @sql_lease_generation;
EXECUTE stmt_lease_generation;
DEALLOCATE PREPARE stmt_lease_generation;

SET @has_lease_heartbeat = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_module_jobs' AND COLUMN_NAME='lease_heartbeat_at'
);
SET @sql_lease_heartbeat = IF(
    @has_lease_heartbeat=0,
    'ALTER TABLE system_module_jobs ADD COLUMN lease_heartbeat_at DATETIME NULL AFTER lock_expires_at',
    'SELECT 1'
);
PREPARE stmt_lease_heartbeat FROM @sql_lease_heartbeat;
EXECUTE stmt_lease_heartbeat;
DEALLOCATE PREPARE stmt_lease_heartbeat;

SET @has_superseded_job = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_module_jobs' AND COLUMN_NAME='superseded_by_job_id'
);
SET @sql_superseded_job = IF(
    @has_superseded_job=0,
    'ALTER TABLE system_module_jobs ADD COLUMN superseded_by_job_id BIGINT UNSIGNED NULL AFTER finished_at',
    'SELECT 1'
);
PREPARE stmt_superseded_job FROM @sql_superseded_job;
EXECUTE stmt_superseded_job;
DEALLOCATE PREPARE stmt_superseded_job;

SET @has_idx_module_fence = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_module_jobs' AND INDEX_NAME='idx_module_jobs_fence'
);
SET @sql_idx_module_fence = IF(
    @has_idx_module_fence=0,
    'ALTER TABLE system_module_jobs ADD KEY idx_module_jobs_fence (id,lock_owner,lease_generation)',
    'SELECT 1'
);
PREPARE stmt_idx_module_fence FROM @sql_idx_module_fence;
EXECUTE stmt_idx_module_fence;
DEALLOCATE PREPARE stmt_idx_module_fence;

SET @has_idx_api_error_request = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_error_logs' AND INDEX_NAME='idx_api_error_request_id'
);
SET @sql_idx_api_error_request = IF(
    @has_idx_api_error_request=0,
    'ALTER TABLE api_error_logs ADD KEY idx_api_error_request_id (request_id,id)',
    'SELECT 1'
);
PREPARE stmt_idx_api_error_request FROM @sql_idx_api_error_request;
EXECUTE stmt_idx_api_error_request;
DEALLOCATE PREPARE stmt_idx_api_error_request;

UPDATE system_module_jobs duplicate_job
JOIN (
    SELECT dedupe_key,MIN(id) keep_id
    FROM system_module_jobs
    WHERE status IN ('pending','retry','paused') AND dedupe_key IS NOT NULL
    GROUP BY dedupe_key HAVING COUNT(*)>1
) duplicates ON duplicates.dedupe_key=duplicate_job.dedupe_key
SET duplicate_job.status='cancelled',
    duplicate_job.stage='consolidated',
    duplicate_job.superseded_by_job_id=duplicates.keep_id,
    duplicate_job.active_dedupe_key=NULL,
    duplicate_job.safe_error_message=CONCAT('Consolidado con el trabajo canónico #',duplicates.keep_id,'.'),
    duplicate_job.finished_at=COALESCE(duplicate_job.finished_at,UTC_TIMESTAMP()),
    duplicate_job.updated_at=UTC_TIMESTAMP()
WHERE duplicate_job.id<>duplicates.keep_id
  AND duplicate_job.status IN ('pending','retry','paused');

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('logs.api.default_view','summary','logs',0),
('logs.api.default_hours','24','logs',0),
('logs.api.summary_cache_seconds','30','logs',0),
('module.jobs.lease_seconds','120','modules',0),
('module.jobs.event_reconcile_limit','50','modules',0),
('module.jobs.starvation_minutes','15','modules',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.17.1','Recuperación segura de migración 087, fencing modular y actividad API comprensible');
