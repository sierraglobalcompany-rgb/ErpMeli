CREATE TABLE IF NOT EXISTS ml_insights_item_performance (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(40) NOT NULL,
 level VARCHAR(40) NULL,score DECIMAL(8,4) NULL,completed_json JSON NULL,pending_json JSON NULL,recommendations_json JSON NULL,
 snapshot_json JSON NULL,observed_at DATETIME NOT NULL,UNIQUE KEY uq_insights_performance(meli_account_id,external_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_insights_moderations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(40) NOT NULL,
 moderation_id VARCHAR(120) NULL,status VARCHAR(40) NULL,reason VARCHAR(500) NULL,affected_fields_json JSON NULL,snapshot_json JSON NULL,
 observed_at DATETIME NOT NULL,UNIQUE KEY uq_insights_moderation(meli_account_id,external_item_id,moderation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
