CREATE TABLE IF NOT EXISTS module_health_checks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_name VARCHAR(120) NOT NULL,
    status ENUM('ok','warning','error') NOT NULL DEFAULT 'ok',
    message VARCHAR(500) NULL,
    context_json JSON NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_module_health_module_date (module_name, checked_at),
    KEY idx_module_health_status_date (status, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NULL,
    external_payment_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(80) NULL,
    status_detail VARCHAR(160) NULL,
    payment_method_id VARCHAR(80) NULL,
    payment_type VARCHAR(80) NULL,
    transaction_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    shipping_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
    coupon_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    marketplace_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
    date_approved DATETIME NULL,
    raw_json JSON NULL,
    raw_path VARCHAR(500) NULL,
    synced_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meli_payment (meli_account_id, external_payment_id),
    KEY idx_payments_approved (meli_account_id, date_approved),
    KEY idx_payments_status (status),
    CONSTRAINT fk_payments_account_2_4_2 FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_order_2_4_2 FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE meli_payments
    ADD KEY idx_payments_lookup_2_4_2 (meli_account_id, external_payment_id, status),
    ADD KEY idx_payments_synced_2_4_2 (meli_account_id, synced_at);

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('payments.expand_details_enabled', '0', 'payments', 0),
('payments.source', 'orders_summary', 'payments', 0),
('module_audit.enabled', '1', 'diagnostics', 0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.4.2', 'Auditoría general y Pagos estable desde resumen de órdenes');
