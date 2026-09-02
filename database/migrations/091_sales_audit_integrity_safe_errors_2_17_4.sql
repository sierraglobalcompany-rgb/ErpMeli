CREATE TABLE IF NOT EXISTS sync_sales_audit_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  meli_account_id BIGINT UNSIGNED NOT NULL,
  period_year SMALLINT UNSIGNED NOT NULL,
  period_month TINYINT UNSIGNED NOT NULL,
  mode ENUM('quick','exact') NOT NULL DEFAULT 'exact',
  status ENUM('pending','running','complete','partial','paused','error','cancelled') NOT NULL DEFAULT 'pending',
  remote_coverage ENUM('pending','complete','truncated','paused','failed') NOT NULL DEFAULT 'pending',
  local_presence ENUM('pending','complete','missing','extra','mixed') NOT NULL DEFAULT 'pending',
  temporal_quality ENUM('pending','correct','missing_normalized','shifted','other_account','mixed') NOT NULL DEFAULT 'pending',
  reconciliation_status ENUM('pending','ready','partial','blocked') NOT NULL DEFAULT 'pending',
  timezone_used VARCHAR(64) NOT NULL,
  normalizer_version VARCHAR(32) NOT NULL,
  local_from DATETIME NOT NULL,
  local_to DATETIME NOT NULL,
  utc_from DATETIME NOT NULL,
  utc_to DATETIME NOT NULL,
  remote_reported_total INT UNSIGNED NOT NULL DEFAULT 0,
  remote_unique_total INT UNSIGNED NOT NULL DEFAULT 0,
  local_period_total INT UNSIGNED NOT NULL DEFAULT 0,
  present_total INT UNSIGNED NOT NULL DEFAULT 0,
  missing_total INT UNSIGNED NOT NULL DEFAULT 0,
  extra_total INT UNSIGNED NOT NULL DEFAULT 0,
  shifted_total INT UNSIGNED NOT NULL DEFAULT 0,
  missing_normalized_total INT UNSIGNED NOT NULL DEFAULT 0,
  other_account_total INT UNSIGNED NOT NULL DEFAULT 0,
  checked_total INT UNSIGNED NOT NULL DEFAULT 0,
  safe_error_message VARCHAR(500) NULL,
  diagnostic_id VARCHAR(80) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sales_audit_runs_period (meli_account_id,period_year,period_month,created_at),
  KEY idx_sales_audit_runs_status (status,created_at),
  KEY idx_sales_audit_runs_diagnostic (diagnostic_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_sales_audit_run_days (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sync_sales_audit_run_id BIGINT UNSIGNED NOT NULL,
  audit_date DATE NOT NULL,
  remote_unique_total INT UNSIGNED NOT NULL DEFAULT 0,
  local_total INT UNSIGNED NOT NULL DEFAULT 0,
  present_total INT UNSIGNED NOT NULL DEFAULT 0,
  missing_total INT UNSIGNED NOT NULL DEFAULT 0,
  extra_total INT UNSIGNED NOT NULL DEFAULT 0,
  shifted_total INT UNSIGNED NOT NULL DEFAULT 0,
  missing_normalized_total INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('pending','complete','attention','blocked') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_audit_run_day (sync_sales_audit_run_id,audit_date),
  KEY idx_sales_audit_run_days_status (sync_sales_audit_run_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_sales_audit_run_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sync_sales_audit_run_id BIGINT UNSIGNED NOT NULL,
  meli_account_id BIGINT UNSIGNED NOT NULL,
  external_order_id VARCHAR(64) NOT NULL,
  audit_date DATE NULL,
  remote_date_created DATETIME NULL,
  remote_status VARCHAR(64) NULL,
  found_local_order_id BIGINT UNSIGNED NULL,
  local_meli_account_id BIGINT UNSIGNED NULL,
  local_date_created DATETIME NULL,
  local_date_created_local DATETIME NULL,
  classification ENUM(
    'pending','present','missing_remote','missing_normalized_date',
    'shifted_date','other_account','extra_local','outside_range','unknown'
  ) NOT NULL DEFAULT 'pending',
  safe_explanation VARCHAR(500) NULL,
  checked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_audit_run_order (sync_sales_audit_run_id,meli_account_id,external_order_id),
  KEY idx_sales_audit_run_orders_class (sync_sales_audit_run_id,classification,id),
  KEY idx_sales_audit_run_orders_day (sync_sales_audit_run_id,audit_date,id),
  KEY idx_sales_audit_run_orders_external (meli_account_id,external_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_sales_audit_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sync_sales_audit_run_id BIGINT UNSIGNED NOT NULL,
  meli_account_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','running','waiting_budget','paused','complete','error','cancelled') NOT NULL DEFAULT 'pending',
  next_offset INT UNSIGNED NOT NULL DEFAULT 0,
  remote_reported_total INT UNSIGNED NOT NULL DEFAULT 0,
  page_limit SMALLINT UNSIGNED NOT NULL DEFAULT 50,
  processed_pages INT UNSIGNED NOT NULL DEFAULT 0,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
  next_run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_by VARCHAR(100) NULL,
  lock_expires_at DATETIME NULL,
  safe_error_message VARCHAR(500) NULL,
  diagnostic_id VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_audit_job_run (sync_sales_audit_run_id),
  KEY idx_sales_audit_jobs_due (status,next_run_at,lock_expires_at,id),
  KEY idx_sales_audit_jobs_account (meli_account_id,status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('sales_audit.exact_page_limit','50','sales_audit',0),
('sales_audit.exact_pages_per_cycle','2','sales_audit',0),
('sales_audit.details_page_size','50','sales_audit',0),
('sales_audit.long_range_queue_days','7','sales_audit',0),
('errors.safe_presentation_enabled','1','system',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.17.4','Auditorías de ventas coherentes y presentación segura de errores');
