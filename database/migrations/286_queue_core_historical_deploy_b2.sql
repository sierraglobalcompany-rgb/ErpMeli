-- B2: importacion historica certificada, apagada por defecto.
-- No importa backlog, no cambia ownership y no habilita Cron V4.

CREATE TABLE IF NOT EXISTS queue_core_historical_checkpoints (
  source_key VARCHAR(80) NOT NULL,
  company_id BIGINT UNSIGNED NOT NULL,
  meli_account_id BIGINT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  state ENUM('disabled','ready','running','complete','blocked') NOT NULL DEFAULT 'disabled',
  high_water_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cursor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_scanned INT UNSIGNED NOT NULL DEFAULT 0,
  last_created INT UNSIGNED NOT NULL DEFAULT 0,
  last_duplicates INT UNSIGNED NOT NULL DEFAULT 0,
  last_reviewed INT UNSIGNED NOT NULL DEFAULT 0,
  last_error_class VARCHAR(100) NULL,
  enabled_by VARCHAR(96) NULL,
  enabled_at DATETIME(3) NULL,
  completed_at DATETIME(3) NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (source_key,company_id,meli_account_id),
  KEY idx_qc_historical_enabled (enabled,state,source_key,company_id,meli_account_id),
  KEY idx_qc_historical_progress (source_key,state,cursor_id,high_water_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS queue_core_historical_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_key VARCHAR(80) NOT NULL,
  company_id BIGINT UNSIGNED NOT NULL,
  meli_account_id BIGINT UNSIGNED NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  source_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
  source_state VARCHAR(40) NOT NULL,
  source_version VARCHAR(191) NOT NULL,
  input_version VARCHAR(191) NOT NULL,
  queue_job_id BIGINT UNSIGNED NOT NULL,
  admission_result ENUM('created','duplicate') NOT NULL,
  closure_state ENUM('open','closed','rollback_restored','review') NOT NULL DEFAULT 'open',
  source_snapshot_json JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  closed_at DATETIME(3) NULL,
  restored_at DATETIME(3) NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_qc_historical_receipt (source_key,company_id,meli_account_id,source_id,input_version),
  KEY idx_qc_historical_receipt_job (queue_job_id,closure_state,id),
  KEY idx_qc_historical_receipt_scope (company_id,meli_account_id,source_key,closure_state,id),
  CONSTRAINT fk_qc_historical_receipt_job FOREIGN KEY (queue_job_id) REFERENCES queue_core_jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_core_historical_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_key VARCHAR(80) NOT NULL,
  company_id BIGINT UNSIGNED NOT NULL,
  meli_account_id BIGINT UNSIGNED NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  source_version VARCHAR(191) NOT NULL,
  reason_code VARCHAR(100) NOT NULL,
  source_state VARCHAR(40) NULL,
  evidence_sha256 CHAR(64) NOT NULL,
  state ENUM('open','resolved') NOT NULL DEFAULT 'open',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  resolved_at DATETIME(3) NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_qc_historical_review (source_key,company_id,meli_account_id,source_id,source_version,reason_code),
  KEY idx_qc_historical_review_scope (company_id,meli_account_id,state,source_key,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
