ALTER TABLE meli_orders
    ADD COLUMN date_created_raw VARCHAR(80) NULL AFTER date_created,
    ADD COLUMN date_created_utc DATETIME NULL AFTER date_created_raw,
    ADD COLUMN date_created_local DATETIME NULL AFTER date_created_utc,
    ADD COLUMN date_created_local_date DATE NULL AFTER date_created_local,
    ADD COLUMN date_created_offset VARCHAR(10) NULL AFTER date_created_local_date,
    ADD COLUMN date_closed_raw VARCHAR(80) NULL AFTER date_closed,
    ADD COLUMN date_closed_utc DATETIME NULL AFTER date_closed_raw,
    ADD COLUMN date_closed_local DATETIME NULL AFTER date_closed_utc,
    ADD COLUMN date_closed_local_date DATE NULL AFTER date_closed_local,
    ADD COLUMN date_closed_offset VARCHAR(10) NULL AFTER date_closed_local_date,
    ADD COLUMN last_updated_raw VARCHAR(80) NULL AFTER date_closed_offset,
    ADD COLUMN last_updated_utc DATETIME NULL AFTER last_updated_raw,
    ADD COLUMN last_updated_local DATETIME NULL AFTER last_updated_utc,
    ADD COLUMN last_updated_local_date DATE NULL AFTER last_updated_local,
    ADD COLUMN last_updated_offset VARCHAR(10) NULL AFTER last_updated_local_date,
    ADD KEY idx_orders_created_local (meli_account_id, date_created_local_date),
    ADD KEY idx_orders_created_utc (meli_account_id, date_created_utc);

ALTER TABLE meli_payments
    ADD COLUMN date_approved_raw VARCHAR(80) NULL AFTER date_approved,
    ADD COLUMN date_approved_utc DATETIME NULL AFTER date_approved_raw,
    ADD COLUMN date_approved_local DATETIME NULL AFTER date_approved_utc,
    ADD COLUMN date_approved_local_date DATE NULL AFTER date_approved_local,
    ADD COLUMN date_approved_offset VARCHAR(10) NULL AFTER date_approved_local_date,
    ADD KEY idx_payments_approved_local (meli_account_id, date_approved_local_date),
    ADD KEY idx_payments_approved_utc (meli_account_id, date_approved_utc);

ALTER TABLE sync_batch_chunks
    ADD COLUMN local_from DATETIME NULL AFTER date_to,
    ADD COLUMN local_to DATETIME NULL AFTER local_from,
    ADD COLUMN utc_from DATETIME NULL AFTER local_to,
    ADD COLUMN utc_to DATETIME NULL AFTER utc_from,
    ADD COLUMN timezone VARCHAR(80) NULL AFTER utc_to,
    ADD COLUMN date_field_used VARCHAR(80) NOT NULL DEFAULT 'date_created' AFTER timezone,
    ADD KEY idx_sync_chunks_local_range (meli_account_id, local_from, local_to);

ALTER TABLE sync_sales_audits
    ADD COLUMN date_field_used VARCHAR(80) NOT NULL DEFAULT 'date_created' AFTER date_to,
    ADD COLUMN timezone_used VARCHAR(80) NOT NULL DEFAULT 'America/Bogota' AFTER date_field_used,
    ADD COLUMN local_from DATETIME NULL AFTER timezone_used,
    ADD COLUMN local_to DATETIME NULL AFTER local_from,
    ADD COLUMN utc_from DATETIME NULL AFTER local_to,
    ADD COLUMN utc_to DATETIME NULL AFTER utc_from,
    ADD COLUMN normalizer_version VARCHAR(30) NULL AFTER utc_to,
    ADD COLUMN last_quick_audit_at DATETIME NULL AFTER normalizer_version,
    ADD COLUMN last_exact_audit_at DATETIME NULL AFTER last_quick_audit_at,
    ADD COLUMN last_repair_at DATETIME NULL AFTER last_exact_audit_at,
    ADD COLUMN timezone_suspect TINYINT(1) NOT NULL DEFAULT 0 AFTER last_repair_at,
    ADD COLUMN recommendation VARCHAR(500) NULL AFTER timezone_suspect;

ALTER TABLE sync_sales_audit_days
    ADD COLUMN date_field_used VARCHAR(80) NOT NULL DEFAULT 'date_created' AFTER audit_date,
    ADD COLUMN timezone_used VARCHAR(80) NOT NULL DEFAULT 'America/Bogota' AFTER date_field_used,
    ADD COLUMN local_from DATETIME NULL AFTER timezone_used,
    ADD COLUMN local_to DATETIME NULL AFTER local_from,
    ADD COLUMN utc_from DATETIME NULL AFTER local_to,
    ADD COLUMN utc_to DATETIME NULL AFTER utc_from,
    ADD COLUMN exact_checked_at DATETIME NULL AFTER checked_at,
    ADD COLUMN missing_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER exact_checked_at,
    ADD COLUMN extra_local_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER missing_count,
    ADD COLUMN shifted_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER extra_local_count,
    ADD COLUMN timezone_suspect TINYINT(1) NOT NULL DEFAULT 0 AFTER shifted_count;

CREATE TABLE IF NOT EXISTS timezone_diagnostics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(80) NOT NULL DEFAULT 'manual',
    php_timezone VARCHAR(80) NULL,
    php_now DATETIME NULL,
    php_utc DATETIME NULL,
    mysql_now DATETIME NULL,
    mysql_utc DATETIME NULL,
    mysql_timezone VARCHAR(80) NULL,
    erp_timezone VARCHAR(80) NULL,
    erp_now DATETIME NULL,
    diff_seconds INT NULL,
    sample_raw VARCHAR(120) NULL,
    sample_utc DATETIME NULL,
    sample_local DATETIME NULL,
    warnings_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_timezone_diagnostics_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_datetime_repair_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    period_year SMALLINT UNSIGNED NOT NULL,
    period_month TINYINT UNSIGNED NOT NULL,
    status ENUM('pending','running','complete','error','cancelled') NOT NULL DEFAULT 'pending',
    total_orders INT UNSIGNED NOT NULL DEFAULT 0,
    processed_orders INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_order_datetime_repair_status (status, created_at),
    KEY idx_order_datetime_repair_account (meli_account_id, period_year, period_month),
    CONSTRAINT fk_order_datetime_repair_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_datetime_repair_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_datetime_repair_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_datetime_repair_job_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    old_snapshot_json JSON NULL,
    new_snapshot_json JSON NULL,
    status ENUM('pending','fixed','error') NOT NULL DEFAULT 'pending',
    error_message VARCHAR(500) NULL,
    processed_at DATETIME NULL,
    UNIQUE KEY uq_order_datetime_repair_item (order_datetime_repair_job_id, meli_order_id),
    CONSTRAINT fk_order_datetime_repair_item_job FOREIGN KEY (order_datetime_repair_job_id) REFERENCES order_datetime_repair_jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_datetime_repair_item_order FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_sales_repair_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sync_sales_audit_id BIGINT UNSIGNED NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    period_year SMALLINT UNSIGNED NOT NULL,
    period_month TINYINT UNSIGNED NOT NULL,
    status ENUM('pending','running','complete','error','cancelled') NOT NULL DEFAULT 'pending',
    total_items INT UNSIGNED NOT NULL DEFAULT 0,
    processed_items INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sync_sales_repair_status (status, created_at),
    KEY idx_sync_sales_repair_account (meli_account_id, period_year, period_month),
    CONSTRAINT fk_sync_sales_repair_audit FOREIGN KEY (sync_sales_audit_id) REFERENCES sync_sales_audits(id) ON DELETE SET NULL,
    CONSTRAINT fk_sync_sales_repair_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_sync_sales_repair_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_sales_repair_job_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sync_sales_repair_job_id BIGINT UNSIGNED NOT NULL,
    external_order_id VARCHAR(80) NOT NULL,
    audit_day_id BIGINT UNSIGNED NULL,
    action ENUM('fetch_missing','refresh_existing') NOT NULL DEFAULT 'fetch_missing',
    status ENUM('pending','complete','error') NOT NULL DEFAULT 'pending',
    error_message VARCHAR(500) NULL,
    processed_at DATETIME NULL,
    UNIQUE KEY uq_sync_sales_repair_item (sync_sales_repair_job_id, external_order_id),
    KEY idx_sync_sales_repair_items_status (status),
    CONSTRAINT fk_sync_sales_repair_item_job FOREIGN KEY (sync_sales_repair_job_id) REFERENCES sync_sales_repair_jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_sync_sales_repair_item_day FOREIGN KEY (audit_day_id) REFERENCES sync_sales_audit_days(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_data_purge_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    sync_batch_id BIGINT UNSIGNED NULL,
    purge_type ENUM('plan_only','plan_and_local_data') NOT NULL DEFAULT 'plan_only',
    status ENUM('requested','blocked','completed','cancelled') NOT NULL DEFAULT 'requested',
    reason VARCHAR(500) NULL,
    blocked_reason VARCHAR(500) NULL,
    requested_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    KEY idx_sync_data_purge_status (status, created_at),
    CONSTRAINT fk_sync_data_purge_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_sync_data_purge_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE monthly_reports
    ADD COLUMN coverage_status VARCHAR(30) NOT NULL DEFAULT 'unknown',
    ADD COLUMN audit_status VARCHAR(30) NOT NULL DEFAULT 'missing',
    ADD COLUMN audit_id BIGINT UNSIGNED NULL,
    ADD COLUMN coverage_snapshot_json JSON NULL,
    ADD COLUMN audit_snapshot_json JSON NULL,
    ADD COLUMN is_incomplete_draft TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN is_admin_override TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN override_by BIGINT UNSIGNED NULL,
    ADD COLUMN override_reason VARCHAR(500) NULL,
    ADD COLUMN override_at DATETIME NULL;

ALTER TABLE date_report_runs
    ADD COLUMN coverage_status VARCHAR(30) NOT NULL DEFAULT 'unknown',
    ADD COLUMN audit_status VARCHAR(30) NOT NULL DEFAULT 'missing',
    ADD COLUMN audit_id BIGINT UNSIGNED NULL,
    ADD COLUMN repair_job_id BIGINT UNSIGNED NULL,
    ADD COLUMN coverage_snapshot_json JSON NULL,
    ADD COLUMN audit_snapshot_json JSON NULL,
    ADD COLUMN is_incomplete_draft TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN is_admin_override TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN override_by BIGINT UNSIGNED NULL,
    ADD COLUMN override_reason VARCHAR(500) NULL,
    ADD COLUMN override_at DATETIME NULL;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('app.timezone', 'America/Bogota', 'general', 0),
('app.storage_timezone', 'UTC', 'general', 0),
('sync.audit_date_field', 'date_created', 'sync', 0),
('billing.date_field', 'payment_date_approved', 'billing', 0),
('sync.default_chunk_mode', 'daily', 'sync', 0),
('sync.repair_max_items_per_run', '50', 'sync', 0),
('billing.require_complete_audit', '1', 'billing', 0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.5.0', 'Integridad de ventas, auditoria confiable y facturacion segura');
