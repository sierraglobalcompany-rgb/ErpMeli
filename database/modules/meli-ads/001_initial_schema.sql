CREATE TABLE IF NOT EXISTS ml_ads_advertisers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_advertiser_id VARCHAR(120) NOT NULL,
 name VARCHAR(255) NULL,status VARCHAR(60) NULL,snapshot_json JSON NULL,observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_ads_advertiser(meli_account_id,external_advertiser_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_ads_campaigns (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_advertiser_id VARCHAR(120) NOT NULL,
 external_campaign_id VARCHAR(120) NOT NULL,name VARCHAR(255) NULL,status VARCHAR(60) NULL,budget DECIMAL(18,2) NULL,snapshot_json JSON NULL,observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_ads_campaign(meli_account_id,external_campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_ads_ad_groups (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_campaign_id VARCHAR(120) NOT NULL,
 external_ad_group_id VARCHAR(120) NOT NULL,name VARCHAR(255) NULL,status VARCHAR(60) NULL,snapshot_json JSON NULL,observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_ads_group(meli_account_id,external_ad_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_ads_ads (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_ad_id VARCHAR(120) NOT NULL,
 external_item_id VARCHAR(40) NULL,external_campaign_id VARCHAR(120) NULL,status VARCHAR(60) NULL,snapshot_json JSON NULL,observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_ads_ad(meli_account_id,external_ad_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_ads_metrics_daily (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,entity_type VARCHAR(40) NOT NULL,external_entity_id VARCHAR(120) NOT NULL,
 observed_on DATE NOT NULL,impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,cost DECIMAL(18,4) NOT NULL DEFAULT 0,
 direct_sales DECIMAL(18,2) NOT NULL DEFAULT 0,indirect_sales DECIMAL(18,2) NOT NULL DEFAULT 0,organic_sales DECIMAL(18,2) NOT NULL DEFAULT 0,
 metrics_json JSON NULL,UNIQUE KEY uq_ads_metrics(entity_type,external_entity_id,observed_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_ads_sync_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,job_type VARCHAR(80) NOT NULL,status VARCHAR(40) NOT NULL,
 checkpoint_json JSON NULL,next_run_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,KEY idx_ads_jobs(status,next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
