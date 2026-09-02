CREATE TABLE IF NOT EXISTS meli_claims (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_claim_id BIGINT UNSIGNED NOT NULL,
    external_order_id BIGINT UNSIGNED NULL,
    meli_order_id BIGINT UNSIGNED NULL,
    type VARCHAR(80) NULL,
    stage VARCHAR(80) NULL,
    status VARCHAR(80) NULL,
    reason_id VARCHAR(120) NULL,
    reason_name VARCHAR(255) NULL,
    resource VARCHAR(255) NULL,
    opened_at DATETIME NULL,
    closed_at DATETIME NULL,
    raw_json JSON NULL,
    detail_json JSON NULL,
    synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meli_claim (meli_account_id, external_claim_id),
    KEY idx_claims_order (meli_account_id, external_order_id),
    KEY idx_claims_status (meli_account_id, status, opened_at),
    CONSTRAINT fk_claims_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_claims_order FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_claim_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_claim_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    event_status VARCHAR(120) NULL,
    event_at DATETIME NULL,
    raw_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_claim_event (meli_claim_id, event_type, event_status, event_at),
    CONSTRAINT fk_claim_events_claim FOREIGN KEY (meli_claim_id) REFERENCES meli_claims(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
