CREATE TABLE IF NOT EXISTS ml_insights_item_capabilities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_item_id VARCHAR(80) NOT NULL,
    capability VARCHAR(120) NOT NULL,
    status VARCHAR(40) NOT NULL,
    last_http_status SMALLINT UNSIGNED NULL,
    safe_message VARCHAR(500) NULL,
    observed_at DATETIME NOT NULL,
    UNIQUE KEY uq_insights_item_capability (meli_account_id,external_item_id,capability),
    KEY idx_insights_item_capability_status (meli_account_id,capability,status,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE system_modules
SET discovered_version='1.1.1',updated_at=UTC_TIMESTAMP()
WHERE module_id='meli-insights';
