CREATE TABLE IF NOT EXISTS ml_growth_promotions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_promotion_id VARCHAR(120) NOT NULL,
 name VARCHAR(255) NULL,promotion_type VARCHAR(80) NULL,status VARCHAR(60) NULL,start_at DATETIME NULL,end_at DATETIME NULL,
 snapshot_json JSON NULL,observed_at DATETIME NOT NULL,UNIQUE KEY uq_growth_promotion(meli_account_id,external_promotion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_growth_promotion_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_promotion_id VARCHAR(120) NOT NULL,
 external_item_id VARCHAR(40) NOT NULL,status VARCHAR(60) NULL,offer_json JSON NULL,observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_growth_promotion_item(meli_account_id,external_promotion_id,external_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_growth_offers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(40) NOT NULL,
 offer_type VARCHAR(80) NULL,status VARCHAR(60) NULL,discount_percent DECIMAL(8,4) NULL,snapshot_json JSON NULL,observed_at DATETIME NOT NULL,
 KEY idx_growth_offer_item(meli_account_id,external_item_id,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_growth_item_visits_daily (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(40) NOT NULL,
 observed_on DATE NOT NULL,visits INT UNSIGNED NOT NULL DEFAULT 0,orders_count INT UNSIGNED NOT NULL DEFAULT 0,units_sold INT UNSIGNED NOT NULL DEFAULT 0,
 conversion_rate DECIMAL(10,6) NULL,UNIQUE KEY uq_growth_visit_day(meli_account_id,external_item_id,observed_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_growth_conversion_daily (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,observed_on DATE NOT NULL,
 visits INT UNSIGNED NOT NULL DEFAULT 0,orders_count INT UNSIGNED NOT NULL DEFAULT 0,conversion_rate DECIMAL(10,6) NULL,
 source_status VARCHAR(40) NOT NULL DEFAULT 'derived',UNIQUE KEY uq_growth_conversion_day(meli_account_id,observed_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_growth_trends (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,site_id VARCHAR(12) NOT NULL,category_id VARCHAR(80) NOT NULL,keyword VARCHAR(255) NOT NULL,
 rank_position INT UNSIGNED NULL,snapshot_json JSON NULL,observed_at DATETIME NOT NULL,KEY idx_growth_trend(site_id,category_id,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_growth_highlights (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,site_id VARCHAR(12) NOT NULL,category_id VARCHAR(80) NOT NULL,external_item_id VARCHAR(40) NULL,
 external_product_id VARCHAR(80) NULL,rank_position INT UNSIGNED NULL,snapshot_json JSON NULL,observed_at DATETIME NOT NULL,
 KEY idx_growth_highlight(site_id,category_id,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_growth_sync_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,job_type VARCHAR(80) NOT NULL,status VARCHAR(40) NOT NULL,
 checkpoint_json JSON NULL,next_run_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,KEY idx_growth_jobs(status,next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
