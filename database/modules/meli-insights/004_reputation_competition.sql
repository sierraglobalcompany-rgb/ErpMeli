CREATE TABLE IF NOT EXISTS ml_insights_reputation_snapshots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,level_id VARCHAR(40) NULL,power_seller_status VARCHAR(40) NULL,
 transactions_json JSON NULL,metrics_json JSON NULL,observed_on DATE NOT NULL,snapshot_json JSON NULL,UNIQUE KEY uq_insights_reputation(meli_account_id,observed_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_insights_catalog_competition (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(40) NOT NULL,
 status VARCHAR(60) NULL,current_price DECIMAL(18,2) NULL,suggested_price DECIMAL(18,2) NULL,currency_id VARCHAR(12) NULL,reason VARCHAR(500) NULL,
 snapshot_json JSON NULL,observed_at DATETIME NOT NULL,UNIQUE KEY uq_insights_competition(meli_account_id,external_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
