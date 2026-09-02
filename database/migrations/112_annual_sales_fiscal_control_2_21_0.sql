-- ERP Meli 2.21.0 — Control anual de ventas y preparación fiscal.
-- Los cierres y evidencias son inmutables; las reaperturas crean revisiones.

CREATE TABLE IF NOT EXISTS sales_control_years (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    control_year SMALLINT UNSIGNED NOT NULL,
    status ENUM('needs_review','checking','ready','closed','partial','unavailable') NOT NULL DEFAULT 'needs_review',
    coverage_from DATE NULL,
    coverage_to DATE NULL,
    coverage_confidence ENUM('unknown','complete','partial','unavailable') NOT NULL DEFAULT 'unknown',
    last_checked_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_control_year (company_id,meli_account_id,control_year),
    KEY idx_sales_control_year_status (company_id,status,control_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_control_months (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sales_control_year_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    period_year SMALLINT UNSIGNED NOT NULL,
    period_month TINYINT UNSIGNED NOT NULL,
    status ENUM('unchecked','checking','changing','missing_sales','date_attention','sales_verified','fiscal_incomplete','ready_to_close','closed','reopened','failed') NOT NULL DEFAULT 'unchecked',
    current_audit_run_id BIGINT UNSIGNED NULL,
    verification_audit_run_id BIGINT UNSIGNED NULL,
    current_close_id BIGINT UNSIGNED NULL,
    remote_total INT UNSIGNED NULL,
    local_total INT UNSIGNED NULL,
    missing_total INT UNSIGNED NULL,
    temporal_issue_total INT UNSIGNED NULL,
    fiscal_ready_total INT UNSIGNED NULL,
    fiscal_missing_total INT UNSIGNED NULL,
    credit_note_review_total INT UNSIGNED NULL,
    reconciliation_total INT UNSIGNED NULL,
    last_checked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_control_month (company_id,meli_account_id,period_year,period_month),
    KEY idx_sales_control_month_year (sales_control_year_id,period_month),
    KEY idx_sales_control_month_status (company_id,meli_account_id,status,period_year,period_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_control_captures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sales_control_month_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    sync_sales_audit_run_id BIGINT UNSIGNED NOT NULL,
    capture_sequence TINYINT UNSIGNED NOT NULL,
    snapshot_hash CHAR(64) NOT NULL,
    remote_total INT UNSIGNED NOT NULL,
    coverage_status VARCHAR(30) NOT NULL,
    http_status SMALLINT UNSIGNED NULL,
    content_missing_json JSON NULL,
    timezone_used VARCHAR(64) NOT NULL,
    normalizer_version VARCHAR(32) NOT NULL,
    captured_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_control_capture_run (sales_control_month_id,sync_sales_audit_run_id),
    KEY idx_sales_control_capture_compare (sales_control_month_id,capture_sequence,snapshot_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_control_fiscal_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sales_control_month_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    external_order_id VARCHAR(64) NOT NULL,
    commercial_status VARCHAR(80) NULL,
    fiscal_status ENUM('ready','invoiced','not_invoiceable','credit_note_review','reconciliation','fiscal_data_required','human_review','excluded') NOT NULL DEFAULT 'human_review',
    source_kind ENUM('search','webhook','exact_id','local_only') NOT NULL DEFAULT 'search',
    reason_code VARCHAR(60) NULL,
    justification VARCHAR(500) NULL,
    fiscal_snapshot_id BIGINT UNSIGNED NULL,
    classified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_control_fiscal_order (sales_control_month_id,meli_account_id,meli_order_id),
    KEY idx_sales_control_fiscal_status (company_id,meli_account_id,fiscal_status,id),
    KEY idx_sales_control_fiscal_external (company_id,meli_account_id,external_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_control_fiscal_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    external_order_id VARCHAR(64) NOT NULL,
    billing_info_id VARCHAR(100) NOT NULL,
    site_id VARCHAR(20) NOT NULL,
    person_type ENUM('natural','legal','unknown') NOT NULL DEFAULT 'unknown',
    document_type VARCHAR(30) NULL,
    completeness_status ENUM('complete','partial','missing','unavailable') NOT NULL DEFAULT 'missing',
    encrypted_payload LONGTEXT NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    source_updated_at DATETIME NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_fiscal_snapshot (company_id,meli_account_id,meli_order_id,payload_hash),
    KEY idx_sales_fiscal_snapshot_current (company_id,meli_account_id,meli_order_id,expires_at,captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_control_fiscal_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sales_control_month_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','running','waiting_budget','retry','paused','complete','partial','error') NOT NULL DEFAULT 'pending',
    total_items INT UNSIGNED NOT NULL DEFAULT 0,
    processed_items INT UNSIGNED NOT NULL DEFAULT 0,
    success_items INT UNSIGNED NOT NULL DEFAULT 0,
    missing_items INT UNSIGNED NOT NULL DEFAULT 0,
    error_items INT UNSIGNED NOT NULL DEFAULT 0,
    next_run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lock_owner VARCHAR(100) NULL,
    lock_expires_at DATETIME NULL,
    lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    heartbeat_at DATETIME NULL,
    safe_error_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sales_fiscal_job_due (status,next_run_at,lock_expires_at,id),
    KEY idx_sales_fiscal_job_scope (company_id,meli_account_id,status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_control_fiscal_job_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sales_control_fiscal_job_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    external_order_id VARCHAR(64) NOT NULL,
    billing_info_id VARCHAR(100) NULL,
    site_id VARCHAR(20) NULL,
    status ENUM('pending','running','complete','already_current','missing','retry','error','skipped') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_run_at DATETIME NULL,
    safe_error_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    processed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_fiscal_job_order (sales_control_fiscal_job_id,meli_order_id),
    KEY idx_sales_fiscal_item_due (sales_control_fiscal_job_id,status,next_run_at,id),
    KEY idx_sales_fiscal_item_scope (company_id,meli_account_id,meli_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_control_closes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sales_control_month_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    status ENUM('closed','superseded') NOT NULL DEFAULT 'closed',
    audit_run_id BIGINT UNSIGNED NOT NULL,
    verification_run_id BIGINT UNSIGNED NOT NULL,
    snapshot_hash CHAR(64) NOT NULL,
    evidence_json LONGTEXT NOT NULL,
    evidence_hash CHAR(64) NOT NULL,
    closed_by BIGINT UNSIGNED NOT NULL,
    closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    superseded_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_control_close_revision (sales_control_month_id,revision_number),
    KEY idx_sales_control_close_current (company_id,meli_account_id,status,closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_control_reopenings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sales_control_month_id BIGINT UNSIGNED NOT NULL,
    previous_close_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    reopened_by BIGINT UNSIGNED NOT NULL,
    reopened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    replacement_close_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_sales_control_reopening_month (sales_control_month_id,reopened_at),
    KEY idx_sales_control_reopening_scope (company_id,meli_account_id,reopened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_control_access_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(50) NOT NULL,
    resource_type VARCHAR(40) NOT NULL,
    resource_id BIGINT UNSIGNED NOT NULL,
    safe_context_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sales_control_access_scope (company_id,meli_account_id,created_at),
    KEY idx_sales_control_access_user (user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('sales_control.enabled','1','sales_control',0),
('sales_control.current_year_only_default','1','sales_control',0),
('sales_control.require_double_capture_for_close','1','sales_control',0),
('sales_control.fiscal_snapshot_ttl_days','30','sales_control',0),
('sales_control.page_size','50','sales_control',0),
('sales_control.api_retention_months','12','sales_control',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.21.0','Control anual de ventas, evidencia mensual y preparación fiscal cifrada');
