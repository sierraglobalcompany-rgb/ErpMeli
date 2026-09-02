-- ERP Meli 2.20.7 — auditoría de ventas forense.
-- Aditiva e idempotente. No modifica órdenes ni datos comerciales.

ALTER TABLE sync_sales_audit_runs
    ADD COLUMN IF NOT EXISTS company_id BIGINT UNSIGNED NULL AFTER meli_account_id,
    ADD COLUMN IF NOT EXISTS snapshot_hash CHAR(64) NULL AFTER checked_total,
    ADD COLUMN IF NOT EXISTS coverage_http_status SMALLINT UNSIGNED NULL AFTER snapshot_hash,
    ADD COLUMN IF NOT EXISTS content_missing_json JSON NULL AFTER coverage_http_status,
    ADD COLUMN IF NOT EXISTS capture_started_at DATETIME NULL AFTER content_missing_json,
    ADD COLUMN IF NOT EXISTS capture_finished_at DATETIME NULL AFTER capture_started_at;

UPDATE sync_sales_audit_runs r
JOIN meli_accounts a ON a.id=r.meli_account_id
SET r.company_id=a.company_id
WHERE r.company_id IS NULL;

ALTER TABLE sync_sales_audit_jobs
    ADD COLUMN IF NOT EXISTS company_id BIGINT UNSIGNED NULL AFTER meli_account_id,
    ADD COLUMN IF NOT EXISTS lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER lock_expires_at,
    ADD COLUMN IF NOT EXISTS heartbeat_at DATETIME NULL AFTER lease_generation,
    ADD COLUMN IF NOT EXISTS last_page_hash CHAR(64) NULL AFTER heartbeat_at,
    ADD COLUMN IF NOT EXISTS last_http_status SMALLINT UNSIGNED NULL AFTER last_page_hash,
    ADD COLUMN IF NOT EXISTS last_error_class VARCHAR(40) NULL AFTER diagnostic_id,
    ADD COLUMN IF NOT EXISTS last_error_retryable TINYINT(1) NOT NULL DEFAULT 0 AFTER last_error_class;

UPDATE sync_sales_audit_jobs j
JOIN meli_accounts a ON a.id=j.meli_account_id
SET j.company_id=a.company_id
WHERE j.company_id IS NULL;

ALTER TABLE sync_sales_repair_jobs
    ADD COLUMN IF NOT EXISTS company_id BIGINT UNSIGNED NULL AFTER meli_account_id,
    ADD COLUMN IF NOT EXISTS lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER lock_expires_at,
    ADD COLUMN IF NOT EXISTS last_error_class VARCHAR(40) NULL AFTER diagnostic_id,
    ADD COLUMN IF NOT EXISTS last_error_retryable TINYINT(1) NOT NULL DEFAULT 0 AFTER last_error_class;

UPDATE sync_sales_repair_jobs j
JOIN meli_accounts a ON a.id=j.meli_account_id
SET j.company_id=a.company_id
WHERE j.company_id IS NULL;

CREATE TABLE IF NOT EXISTS sync_sales_audit_run_pages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sync_sales_audit_run_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    page_offset INT UNSIGNED NOT NULL,
    page_limit SMALLINT UNSIGNED NOT NULL,
    result_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    remote_reported_total INT UNSIGNED NOT NULL DEFAULT 0,
    http_status SMALLINT UNSIGNED NULL,
    content_missing_json JSON NULL,
    ids_hash CHAR(64) NOT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_audit_page (sync_sales_audit_run_id,page_offset),
    KEY idx_sales_audit_page_scope (company_id,meli_account_id,sync_sales_audit_run_id),
    KEY idx_sales_audit_page_http (sync_sales_audit_run_id,http_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_sales_audit_evidence_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sync_sales_audit_run_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    safe_summary VARCHAR(500) NOT NULL,
    diagnostic_id VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sales_evidence_run (sync_sales_audit_run_id,id),
    KEY idx_sales_evidence_scope (company_id,meli_account_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @idx_run_scope = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='sync_sales_audit_runs'
      AND index_name='idx_sales_audit_runs_company_period'
);
SET @sql_run_scope = IF(
    @idx_run_scope=0,
    'ALTER TABLE sync_sales_audit_runs ADD KEY idx_sales_audit_runs_company_period (company_id,meli_account_id,period_year,period_month,id)',
    'SELECT 1'
);
PREPARE stmt_run_scope FROM @sql_run_scope;
EXECUTE stmt_run_scope;
DEALLOCATE PREPARE stmt_run_scope;

SET @idx_job_fence = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='sync_sales_audit_jobs'
      AND index_name='idx_sales_audit_job_fence'
);
SET @sql_job_fence = IF(
    @idx_job_fence=0,
    'ALTER TABLE sync_sales_audit_jobs ADD KEY idx_sales_audit_job_fence (id,locked_by,lease_generation,lock_expires_at)',
    'SELECT 1'
);
PREPARE stmt_job_fence FROM @sql_job_fence;
EXECUTE stmt_job_fence;
DEALLOCATE PREPARE stmt_job_fence;

SET @idx_repair_fence = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='sync_sales_repair_jobs'
      AND index_name='idx_sales_repair_job_fence'
);
SET @sql_repair_fence = IF(
    @idx_repair_fence=0,
    'ALTER TABLE sync_sales_repair_jobs ADD KEY idx_sales_repair_job_fence (id,lock_owner,lease_generation,lock_expires_at)',
    'SELECT 1'
);
PREPARE stmt_repair_fence FROM @sql_repair_fence;
EXECUTE stmt_repair_fence;
DEALLOCATE PREPARE stmt_repair_fence;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('sales_audit.forensic_mode_enabled','1','sales_audit',0),
('sales_audit.require_stable_capture_for_close','1','sales_audit',0),
('sales_audit.legacy_read_only','1','sales_audit',0),
('sales_audit.page_split_threshold','1000','sales_audit',0),
('sales_audit.lease_seconds','60','sales_audit',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.20.7','Auditoría de ventas forense, aislamiento por empresa y cobertura verificable');
