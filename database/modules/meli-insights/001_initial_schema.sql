CREATE TABLE IF NOT EXISTS ml_insights_account_capabilities (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,capability VARCHAR(120) NOT NULL,
 status VARCHAR(40) NOT NULL,last_http_status SMALLINT UNSIGNED NULL,safe_message VARCHAR(500) NULL,cooldown_until DATETIME NULL,
 last_checked_at DATETIME NOT NULL,UNIQUE KEY uq_insights_capability (meli_account_id,capability),KEY idx_insights_capability_status(status,cooldown_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_insights_sync_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,job_type VARCHAR(80) NOT NULL,status VARCHAR(40) NOT NULL,
 checkpoint_json JSON NULL,result_json JSON NULL,next_run_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
 KEY idx_insights_jobs(status,next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
