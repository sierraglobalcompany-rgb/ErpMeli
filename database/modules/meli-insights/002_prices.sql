CREATE TABLE IF NOT EXISTS ml_insights_item_prices (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(40) NOT NULL,
 standard_amount DECIMAL(18,2) NULL,effective_amount DECIMAL(18,2) NULL,currency_id VARCHAR(12) NULL,context VARCHAR(80) NULL,
 valid_from DATETIME NULL,valid_to DATETIME NULL,status VARCHAR(40) NOT NULL,snapshot_json JSON NULL,observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_insights_price (meli_account_id,external_item_id,context),KEY idx_insights_price_observed(observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_insights_price_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(40) NOT NULL,
 amount DECIMAL(18,2) NOT NULL,currency_id VARCHAR(12) NULL,price_type VARCHAR(60) NULL,observed_at DATETIME NOT NULL,
 KEY idx_insights_price_history(meli_account_id,external_item_id,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
