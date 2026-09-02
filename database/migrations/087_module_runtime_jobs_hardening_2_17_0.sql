SET @has_dedupe_key = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_module_jobs' AND COLUMN_NAME='dedupe_key'
);
SET @sql_dedupe_key = IF(
    @has_dedupe_key=0,
    'ALTER TABLE system_module_jobs ADD COLUMN dedupe_key CHAR(64) NULL AFTER meli_account_id',
    'SELECT 1'
);
PREPARE stmt_dedupe_key FROM @sql_dedupe_key;
EXECUTE stmt_dedupe_key;
DEALLOCATE PREPARE stmt_dedupe_key;

SET @has_stage = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_module_jobs' AND COLUMN_NAME='stage'
);
SET @sql_stage = IF(
    @has_stage=0,
    'ALTER TABLE system_module_jobs ADD COLUMN stage VARCHAR(80) NULL AFTER status',
    'SELECT 1'
);
PREPARE stmt_stage FROM @sql_stage;
EXECUTE stmt_stage;
DEALLOCATE PREPARE stmt_stage;

SET @has_checkpoint = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_module_jobs' AND COLUMN_NAME='checkpoint_json'
);
SET @sql_checkpoint = IF(
    @has_checkpoint=0,
    'ALTER TABLE system_module_jobs ADD COLUMN checkpoint_json JSON NULL AFTER payload_json',
    'SELECT 1'
);
PREPARE stmt_checkpoint FROM @sql_checkpoint;
EXECUTE stmt_checkpoint;
DEALLOCATE PREPARE stmt_checkpoint;

SET @has_consecutive_failures = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_module_jobs' AND COLUMN_NAME='consecutive_failures'
);
SET @sql_consecutive_failures = IF(
    @has_consecutive_failures=0,
    'ALTER TABLE system_module_jobs ADD COLUMN consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0 AFTER attempts',
    'SELECT 1'
);
PREPARE stmt_consecutive_failures FROM @sql_consecutive_failures;
EXECUTE stmt_consecutive_failures;
DEALLOCATE PREPARE stmt_consecutive_failures;

SET @has_last_success = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_module_jobs' AND COLUMN_NAME='last_success_at'
);
SET @sql_last_success = IF(
    @has_last_success=0,
    'ALTER TABLE system_module_jobs ADD COLUMN last_success_at DATETIME NULL AFTER finished_at',
    'SELECT 1'
);
PREPARE stmt_last_success FROM @sql_last_success;
EXECUTE stmt_last_success;
DEALLOCATE PREPARE stmt_last_success;

UPDATE system_module_jobs
SET status='retry',
    stage='lease_recovered',
    next_run_at=UTC_TIMESTAMP(),
    lock_owner=NULL,
    lock_expires_at=NULL,
    safe_error_message='La ejecución anterior se interrumpió; el trabajo se reanudará.',
    updated_at=UTC_TIMESTAMP()
WHERE status='running'
  AND lock_expires_at IS NOT NULL
  AND lock_expires_at<UTC_TIMESTAMP();

SET @live_module_jobs = (
    SELECT COUNT(*) FROM system_module_jobs
    WHERE status='running'
      AND lock_expires_at IS NOT NULL
      AND lock_expires_at>=UTC_TIMESTAMP()
);
SET @sql_assert_no_live_jobs = IF(
    @live_module_jobs=0,
    'SELECT 1',
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''Hay un trabajo modular activo. Espere a que termine o pause el worker y vuelva a intentar.'''
);
PREPARE stmt_assert_no_live_jobs FROM @sql_assert_no_live_jobs;
EXECUTE stmt_assert_no_live_jobs;
DEALLOCATE PREPARE stmt_assert_no_live_jobs;

SET @has_active_dedupe_key = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_module_jobs' AND COLUMN_NAME='active_dedupe_key'
);
SET @sql_active_dedupe_key = IF(
    @has_active_dedupe_key=0,
    'ALTER TABLE system_module_jobs ADD COLUMN active_dedupe_key CHAR(64) NULL AFTER dedupe_key',
    'SELECT 1'
);
PREPARE stmt_active_dedupe_key FROM @sql_active_dedupe_key;
EXECUTE stmt_active_dedupe_key;
DEALLOCATE PREPARE stmt_active_dedupe_key;

SET @active_dedupe_is_generated = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='system_module_jobs'
      AND COLUMN_NAME='active_dedupe_key'
      AND EXTRA LIKE '%GENERATED%'
);
SET @sql_normalize_active_dedupe = IF(
    @active_dedupe_is_generated>0,
    'ALTER TABLE system_module_jobs MODIFY COLUMN active_dedupe_key CHAR(64) NULL',
    'SELECT 1'
);
PREPARE stmt_normalize_active_dedupe FROM @sql_normalize_active_dedupe;
EXECUTE stmt_normalize_active_dedupe;
DEALLOCATE PREPARE stmt_normalize_active_dedupe;

SET @has_idx_module_dedupe = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_module_jobs' AND INDEX_NAME='idx_module_jobs_dedupe'
);
SET @sql_idx_module_dedupe = IF(
    @has_idx_module_dedupe=0,
    'ALTER TABLE system_module_jobs ADD KEY idx_module_jobs_dedupe (dedupe_key,status,id)',
    'SELECT 1'
);
PREPARE stmt_idx_module_dedupe FROM @sql_idx_module_dedupe;
EXECUTE stmt_idx_module_dedupe;
DEALLOCATE PREPARE stmt_idx_module_dedupe;

SET @has_idx_module_lease = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_module_jobs' AND INDEX_NAME='idx_module_jobs_lease'
);
SET @sql_idx_module_lease = IF(
    @has_idx_module_lease=0,
    'ALTER TABLE system_module_jobs ADD KEY idx_module_jobs_lease (status,lock_expires_at,next_run_at)',
    'SELECT 1'
);
PREPARE stmt_idx_module_lease FROM @sql_idx_module_lease;
EXECUTE stmt_idx_module_lease;
DEALLOCATE PREPARE stmt_idx_module_lease;

UPDATE system_module_jobs
SET dedupe_key=SHA2(
    CONCAT(
        module_id,'|',job_type,'|',COALESCE(meli_account_id,0),'|',
        COALESCE(NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.resource_id'))),''),'account')
    ),
    256
)
WHERE dedupe_key IS NULL;

UPDATE system_module_jobs duplicate_job
JOIN (
    SELECT dedupe_key,MIN(id) AS keep_id
    FROM system_module_jobs
    WHERE status IN ('pending','retry','paused')
      AND dedupe_key IS NOT NULL
    GROUP BY dedupe_key
    HAVING COUNT(*)>1
) duplicates ON duplicates.dedupe_key=duplicate_job.dedupe_key
SET duplicate_job.status='cancelled',
    duplicate_job.stage='consolidated',
    duplicate_job.safe_error_message=CONCAT('Consolidado con el trabajo canónico #',duplicates.keep_id,'.'),
    duplicate_job.finished_at=COALESCE(duplicate_job.finished_at,UTC_TIMESTAMP()),
    duplicate_job.active_dedupe_key=NULL,
    duplicate_job.updated_at=UTC_TIMESTAMP()
WHERE duplicate_job.id<>duplicates.keep_id
  AND duplicate_job.status IN ('pending','retry','paused');

UPDATE system_module_jobs
SET active_dedupe_key=CASE
    WHEN status IN ('pending','running','retry','paused') THEN dedupe_key
    ELSE NULL
END;

SET @has_uq_module_active_dedupe = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_module_jobs' AND INDEX_NAME='uq_module_jobs_active_dedupe'
);
SET @sql_uq_module_active_dedupe = IF(
    @has_uq_module_active_dedupe=0,
    'ALTER TABLE system_module_jobs ADD UNIQUE KEY uq_module_jobs_active_dedupe (active_dedupe_key)',
    'SELECT 1'
);
PREPARE stmt_uq_module_active_dedupe FROM @sql_uq_module_active_dedupe;
EXECUTE stmt_uq_module_active_dedupe;
DEALLOCATE PREPARE stmt_uq_module_active_dedupe;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('module.jobs.dedupe_enabled','1','modules',0),
('module.jobs.batch_limit','2','modules',0),
('module.jobs.max_consecutive_failures','3','modules',0),
('module.rollout.allowed_modules','meli-insights','modules',0),
('module.insights.batch_size','5','modules',0),
('module.insights.price_ttl_minutes','360','modules',0),
('module.insights.performance_ttl_minutes','1440','modules',0),
('module.insights.competition_ttl_minutes','360','modules',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.17.0','Meli Insights funcional, trabajos modulares deduplicados y sincronización reanudable');
