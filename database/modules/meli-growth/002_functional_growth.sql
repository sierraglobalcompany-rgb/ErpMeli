CREATE TABLE IF NOT EXISTS ml_growth_capabilities (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 meli_account_id BIGINT UNSIGNED NOT NULL,
 capability VARCHAR(80) NOT NULL,
 status VARCHAR(40) NOT NULL DEFAULT 'pending',
 last_http_status SMALLINT UNSIGNED NULL,
 safe_message VARCHAR(500) NULL,
 checked_at DATETIME NOT NULL,
 cooldown_until DATETIME NULL,
 UNIQUE KEY uq_growth_capability (meli_account_id,capability),
 KEY idx_growth_capability_status (status,cooldown_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ml_growth_account_visits_daily (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 meli_account_id BIGINT UNSIGNED NOT NULL,
 observed_on DATE NOT NULL,
 visits INT UNSIGNED NOT NULL DEFAULT 0,
 source_from DATETIME NULL,
 source_to DATETIME NULL,
 observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_growth_account_visit_day (meli_account_id,observed_on),
 KEY idx_growth_account_visits_recent (meli_account_id,observed_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ml_growth_candidates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 meli_account_id BIGINT UNSIGNED NOT NULL,
 external_candidate_id VARCHAR(120) NOT NULL,
 external_item_id VARCHAR(40) NULL,
 external_promotion_id VARCHAR(120) NULL,
 promotion_type VARCHAR(80) NULL,
 status VARCHAR(60) NULL,
 snapshot_json JSON NULL,
 observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_growth_candidate (meli_account_id,external_candidate_id),
 KEY idx_growth_candidate_item (meli_account_id,external_item_id,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
