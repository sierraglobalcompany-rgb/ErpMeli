-- ERP Meli 2.19.2 — auditoría guiada, reparación exacta y salud explicable.
-- Aditiva e idempotente. No modifica órdenes, pagos ni datos comerciales.

ALTER TABLE sync_sales_repair_jobs
    ADD COLUMN IF NOT EXISTS sync_sales_audit_run_id BIGINT UNSIGNED NULL AFTER sync_sales_audit_id,
    ADD COLUMN IF NOT EXISTS source_kind VARCHAR(20) NOT NULL DEFAULT 'legacy' AFTER sync_sales_audit_run_id,
    ADD COLUMN IF NOT EXISTS next_run_at DATETIME NULL AFTER status,
    ADD COLUMN IF NOT EXISTS consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0 AFTER processed_items,
    ADD COLUMN IF NOT EXISTS success_items INT UNSIGNED NOT NULL DEFAULT 0 AFTER consecutive_failures,
    ADD COLUMN IF NOT EXISTS unavailable_items INT UNSIGNED NOT NULL DEFAULT 0 AFTER success_items,
    ADD COLUMN IF NOT EXISTS error_items INT UNSIGNED NOT NULL DEFAULT 0 AFTER unavailable_items,
    ADD COLUMN IF NOT EXISTS lock_owner VARCHAR(100) NULL AFTER error_items,
    ADD COLUMN IF NOT EXISTS lock_expires_at DATETIME NULL AFTER lock_owner,
    ADD COLUMN IF NOT EXISTS heartbeat_at DATETIME NULL AFTER lock_expires_at,
    ADD COLUMN IF NOT EXISTS safe_error_message VARCHAR(500) NULL AFTER error_message,
    ADD COLUMN IF NOT EXISTS diagnostic_id VARCHAR(80) NULL AFTER safe_error_message,
    ADD COLUMN IF NOT EXISTS verification_audit_job_id BIGINT UNSIGNED NULL AFTER diagnostic_id;

ALTER TABLE sync_sales_repair_jobs
    MODIFY COLUMN status ENUM(
        'pending','running','waiting_budget','retry','paused',
        'complete','partial','error','cancelled'
    ) NOT NULL DEFAULT 'pending';

UPDATE sync_sales_repair_jobs
SET next_run_at=COALESCE(next_run_at,created_at)
WHERE next_run_at IS NULL;

SET @has_exact_repair_key = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='sync_sales_repair_jobs'
      AND index_name='uq_sales_repair_exact_run'
);
SET @sql_exact_repair_key = IF(
    @has_exact_repair_key=0,
    'ALTER TABLE sync_sales_repair_jobs ADD UNIQUE KEY uq_sales_repair_exact_run (sync_sales_audit_run_id)',
    'SELECT 1'
);
PREPARE stmt_exact_repair_key FROM @sql_exact_repair_key;
EXECUTE stmt_exact_repair_key;
DEALLOCATE PREPARE stmt_exact_repair_key;

SET @has_exact_repair_due = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='sync_sales_repair_jobs'
      AND index_name='idx_sales_repair_exact_due'
);
SET @sql_exact_repair_due = IF(
    @has_exact_repair_due=0,
    'ALTER TABLE sync_sales_repair_jobs ADD KEY idx_sales_repair_exact_due (source_kind,status,next_run_at,lock_expires_at,id)',
    'SELECT 1'
);
PREPARE stmt_exact_repair_due FROM @sql_exact_repair_due;
EXECUTE stmt_exact_repair_due;
DEALLOCATE PREPARE stmt_exact_repair_due;

ALTER TABLE sync_sales_repair_job_items
    ADD COLUMN IF NOT EXISTS sync_sales_audit_run_order_id BIGINT UNSIGNED NULL AFTER audit_day_id,
    ADD COLUMN IF NOT EXISTS attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER action,
    ADD COLUMN IF NOT EXISTS next_run_at DATETIME NULL AFTER attempts,
    ADD COLUMN IF NOT EXISTS safe_error_message VARCHAR(500) NULL AFTER error_message,
    ADD COLUMN IF NOT EXISTS diagnostic_id VARCHAR(80) NULL AFTER safe_error_message;

ALTER TABLE sync_sales_repair_job_items
    MODIFY COLUMN status ENUM(
        'pending','running','waiting_budget','retry','complete',
        'already_present','unavailable','error','skipped'
    ) NOT NULL DEFAULT 'pending';

UPDATE sync_sales_repair_job_items i
JOIN sync_sales_repair_jobs j ON j.id=i.sync_sales_repair_job_id
SET i.next_run_at=COALESCE(i.next_run_at,j.created_at)
WHERE i.next_run_at IS NULL;

SET @has_exact_repair_items_due = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='sync_sales_repair_job_items'
      AND index_name='idx_sales_repair_items_due'
);
SET @sql_exact_repair_items_due = IF(
    @has_exact_repair_items_due=0,
    'ALTER TABLE sync_sales_repair_job_items ADD KEY idx_sales_repair_items_due (sync_sales_repair_job_id,status,next_run_at,id)',
    'SELECT 1'
);
PREPARE stmt_exact_repair_items_due FROM @sql_exact_repair_items_due;
EXECUTE stmt_exact_repair_items_due;
DEALLOCATE PREPARE stmt_exact_repair_items_due;

ALTER TABLE api_request_logs
    ADD COLUMN IF NOT EXISTS execution_source VARCHAR(40) NULL AFTER incident_key,
    ADD COLUMN IF NOT EXISTS job_type VARCHAR(80) NULL AFTER execution_source,
    ADD COLUMN IF NOT EXISTS source_queue_key VARCHAR(80) NULL AFTER job_type,
    ADD COLUMN IF NOT EXISTS source_work_id VARCHAR(100) NULL AFTER source_queue_key;

CREATE TABLE IF NOT EXISTS system_work_queue_adapter_health (
    queue_key VARCHAR(80) NOT NULL,
    status ENUM('healthy','unavailable','error') NOT NULL DEFAULT 'healthy',
    last_checked_at DATETIME NOT NULL,
    last_success_at DATETIME NULL,
    last_failure_at DATETIME NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    safe_error_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    PRIMARY KEY (queue_key),
    KEY idx_work_adapter_health_status (status,last_checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('sales_audit.exact_repair_enabled','1','sales_audit',0),
('sales_audit.repair_batch_limit','5','sales_audit',0),
('sales_audit.repair_max_attempts','3','sales_audit',0),
('sales_audit.repair_lease_seconds','45','sales_audit',0),
('sales_audit.repair_preview_enabled','1','sales_audit',0),
('api.health.separate_erp_status_enabled','1','api',0),
('automation.adapter_health_enabled','1','automation',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.19.2','Auditoría guiada, reparación exacta, salud sin falsas alarmas y colas verificables');
