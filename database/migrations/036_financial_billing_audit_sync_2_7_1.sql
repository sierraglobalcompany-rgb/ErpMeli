-- ERP Meli / Gestion Pro 2.7.1
-- Correccion financiera, facturacion por fechas, auditoria y recalculo masivo.

ALTER TABLE date_report_runs
    ADD COLUMN buyer_shipping_paid DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER shipping_revenue,
    ADD COLUMN ml_shipping_charge DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER buyer_shipping_paid,
    ADD COLUMN shipping_net_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER ml_shipping_charge,
    ADD COLUMN net_without_shipping DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER refunds,
    ADD COLUMN net_after_shipping DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER net_without_shipping,
    ADD COLUMN reconciled_net_amount DECIMAL(18,2) NULL AFTER net_after_shipping,
    ADD COLUMN pending_shipping_charge_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER pending_financial_orders_count,
    ADD COLUMN requires_recalculation TINYINT(1) NOT NULL DEFAULT 0 AFTER pending_shipping_charge_count,
    ADD COLUMN recalculated_at DATETIME NULL AFTER requires_recalculation;

ALTER TABLE date_report_items
    ADD COLUMN buyer_shipping_paid DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER shipping_revenue,
    ADD COLUMN ml_shipping_charge DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER buyer_shipping_paid,
    ADD COLUMN shipping_net_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER ml_shipping_charge,
    ADD COLUMN net_without_shipping DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER refunds,
    ADD COLUMN net_after_shipping DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER net_without_shipping,
    ADD COLUMN reconciled_net_amount DECIMAL(18,2) NULL AFTER net_after_shipping,
    ADD COLUMN pending_shipping_charge_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER pending_financial_count,
    ADD COLUMN requires_recalculation TINYINT(1) NOT NULL DEFAULT 0 AFTER pending_shipping_charge_count;

CREATE TABLE IF NOT EXISTS date_report_item_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date_report_run_id BIGINT UNSIGNED NOT NULL,
    date_report_item_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_order_id VARCHAR(40) NOT NULL,
    order_date DATETIME NULL,
    account_name VARCHAR(255) NULL,
    product_title VARCHAR(255) NOT NULL,
    seller_sku VARCHAR(120) NULL,
    quantity DECIMAL(18,4) NOT NULL DEFAULT 0,
    product_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    sale_fee_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    discounts DECIMAL(18,2) NOT NULL DEFAULT 0,
    refunds DECIMAL(18,2) NOT NULL DEFAULT 0,
    buyer_shipping_paid DECIMAL(18,2) NOT NULL DEFAULT 0,
    ml_shipping_charge DECIMAL(18,2) NOT NULL DEFAULT 0,
    shipping_net_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    net_without_shipping DECIMAL(18,2) NOT NULL DEFAULT 0,
    net_after_shipping DECIMAL(18,2) NOT NULL DEFAULT 0,
    reconciled_net_amount DECIMAL(18,2) NULL,
    unit_cost DECIMAL(18,2) NULL,
    total_cost DECIMAL(18,2) NULL,
    profit_amount DECIMAL(18,2) NULL,
    cost_status VARCHAR(30) NOT NULL DEFAULT 'missing',
    financial_status VARCHAR(40) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_date_report_item_order (date_report_item_id, meli_order_id),
    KEY idx_date_report_item_orders_run (date_report_run_id, date_report_item_id),
    KEY idx_date_report_item_orders_order (meli_order_id),
    CONSTRAINT fk_date_report_item_orders_run FOREIGN KEY (date_report_run_id) REFERENCES date_report_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_date_report_item_orders_item FOREIGN KEY (date_report_item_id) REFERENCES date_report_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_date_report_item_orders_order FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_date_report_item_orders_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_financial_recalc_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NULL,
    date_from DATETIME NULL,
    date_to DATETIME NULL,
    mode VARCHAR(40) NOT NULL DEFAULT 'pending',
    source_type VARCHAR(60) NOT NULL DEFAULT 'manual',
    source_id BIGINT UNSIGNED NULL,
    status ENUM('pending','running','complete','error','cancelled') NOT NULL DEFAULT 'pending',
    total_items INT UNSIGNED NOT NULL DEFAULT 0,
    processed_items INT UNSIGNED NOT NULL DEFAULT 0,
    error_items INT UNSIGNED NOT NULL DEFAULT 0,
    safe_message VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_financial_recalc_status (status, created_at),
    KEY idx_financial_recalc_account (meli_account_id, date_from, date_to),
    CONSTRAINT fk_financial_recalc_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_financial_recalc_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_financial_recalc_job_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_financial_recalc_job_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    external_order_id VARCHAR(40) NOT NULL,
    status ENUM('pending','complete','error','skipped') NOT NULL DEFAULT 'pending',
    error_message VARCHAR(500) NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_financial_recalc_job_order (order_financial_recalc_job_id, meli_order_id),
    KEY idx_financial_recalc_items_status (order_financial_recalc_job_id, status),
    CONSTRAINT fk_financial_recalc_item_job FOREIGN KEY (order_financial_recalc_job_id) REFERENCES order_financial_recalc_jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_financial_recalc_item_order FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sync_sales_audit_remote_ids
    ADD COLUMN local_meli_account_id BIGINT UNSIGNED NULL AFTER found_local_order_id,
    ADD COLUMN local_date_created DATETIME NULL AFTER local_meli_account_id,
    ADD COLUMN local_date_created_local DATETIME NULL AFTER local_date_created,
    ADD COLUMN local_date_closed_local DATETIME NULL AFTER local_date_created_local,
    ADD COLUMN local_date_approved_local DATETIME NULL AFTER local_date_closed_local,
    ADD COLUMN local_status VARCHAR(80) NULL AFTER local_date_approved_local,
    ADD COLUMN diagnostic_message VARCHAR(500) NULL AFTER classification;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('billing.date.custom_columns_enabled', '1', 'billing', 0),
('billing.date.default_columns', 'resumida', 'billing', 0),
('orders.financial_recalc_batch_limit', '25', 'orders', 0),
('sync.assisted_stop_reason_enabled', '1', 'sync', 0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.7.1', 'Correccion financiera, facturacion por fechas, auditoria y recalculo masivo.');
