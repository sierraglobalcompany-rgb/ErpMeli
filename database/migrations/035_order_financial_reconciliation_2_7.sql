-- ERP Meli / Gestion Pro 2.7.0
-- Ordenes enriquecidas, conciliacion financiera local y facturacion por fechas mas segura.

CREATE TABLE IF NOT EXISTS meli_order_financials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    external_order_id VARCHAR(40) NOT NULL,
    currency_id VARCHAR(10) NULL,
    product_sold_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    buyer_shipping_paid DECIMAL(18,2) NOT NULL DEFAULT 0,
    buyer_paid_total DECIMAL(18,2) NOT NULL DEFAULT 0,
    sale_fee_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    sale_fee_rate DECIMAL(8,4) NULL,
    ml_shipping_charge DECIMAL(18,2) NOT NULL DEFAULT 0,
    shipping_net_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    other_charges_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    bonifications_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    taxes_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    withholdings_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    adjustments_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    ml_net_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    internal_cost_amount DECIMAL(18,2) NULL,
    packaging_cost_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    other_internal_costs_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    erp_profit_amount DECIMAL(18,2) NULL,
    erp_margin_percent DECIMAL(8,4) NULL,
    erp_calculated_net DECIMAL(18,2) NOT NULL DEFAULT 0,
    difference_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    missing_cost_items_count INT UNSIGNED NOT NULL DEFAULT 0,
    missing_link_items_count INT UNSIGNED NOT NULL DEFAULT 0,
    billing_status ENUM('pending','imported','calculated','matched','difference','error','manual_review') NOT NULL DEFAULT 'pending',
    reconciliation_status ENUM('pending','queued','calculated','matched','difference','error','manual_review') NOT NULL DEFAULT 'pending',
    source ENUM('orders_summary','local_recalculation','billing_job','manual_review') NOT NULL DEFAULT 'orders_summary',
    safe_message VARCHAR(500) NULL,
    last_billing_sync_at DATETIME NULL,
    raw_summary_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_financial_order (meli_order_id),
    KEY idx_financial_account_status (meli_account_id, reconciliation_status, updated_at),
    KEY idx_financial_external (meli_account_id, external_order_id),
    CONSTRAINT fk_financial_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_financial_order FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_order_billing_details (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    meli_order_financial_id BIGINT UNSIGNED NULL,
    external_order_id VARCHAR(40) NOT NULL,
    detail_id VARCHAR(120) NULL,
    external_payment_id VARCHAR(80) NULL,
    external_document_id VARCHAR(120) NULL,
    detail_type VARCHAR(80) NOT NULL DEFAULT 'local_summary',
    detail_subtype VARCHAR(120) NULL,
    description VARCHAR(500) NULL,
    amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    currency_id VARCHAR(10) NULL,
    occurred_at DATETIME NULL,
    source_endpoint VARCHAR(255) NOT NULL DEFAULT 'local',
    source_status ENUM('local','confirmed','investigating','error') NOT NULL DEFAULT 'local',
    raw_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_billing_detail (meli_account_id, meli_order_id, detail_type, detail_subtype, detail_id),
    KEY idx_billing_order (meli_order_id),
    KEY idx_billing_type (detail_type, source_status),
    CONSTRAINT fk_billing_detail_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_detail_order FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_detail_financial FOREIGN KEY (meli_order_financial_id) REFERENCES meli_order_financials(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE date_report_runs
    ADD COLUMN missing_cost_items_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER margin_percent,
    ADD COLUMN pending_financial_orders_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER missing_cost_items_count;

ALTER TABLE date_report_items
    ADD COLUMN cost_status ENUM('ok','missing','partial') NOT NULL DEFAULT 'ok' AFTER suggested_purchase_value,
    ADD COLUMN missing_cost_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER cost_status,
    ADD COLUMN pending_financial_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER missing_cost_count;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('orders.financial_reconciliation_enabled', '1', 'orders', 0),
('orders.financial_batch_limit', '25', 'orders', 0),
('orders.financial_unconfirmed_billing_calls', '0', 'orders', 0),
('billing.date.column_preset', 'resumida', 'billing', 0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.7.0', 'Ordenes enriquecidas, conciliacion financiera local, facturacion por fechas corregida y vinculacion mejorada.');
