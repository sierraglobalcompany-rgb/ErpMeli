-- ERP Meli 2.24.0 — conciliación financiera oficial por venta agrupada.

CREATE TABLE IF NOT EXISTS meli_pack_order_expectations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    meli_pack_id BIGINT UNSIGNED NOT NULL,
    external_order_id VARCHAR(40) NOT NULL,
    meli_order_id BIGINT UNSIGNED NULL,
    status ENUM('expected','linked','missing','unavailable','review') NOT NULL DEFAULT 'expected',
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pack_expected_order (meli_pack_id,external_order_id),
    KEY idx_pack_expected_scope (company_id,meli_account_id,status),
    CONSTRAINT fk_pack_expected_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_pack_expected_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_pack_expected_pack FOREIGN KEY (meli_pack_id) REFERENCES meli_packs(id) ON DELETE CASCADE,
    CONSTRAINT fk_pack_expected_order FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_billing_capture_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    sale_key VARCHAR(90) NOT NULL,
    external_sale_id VARCHAR(40) NOT NULL,
    source_mode ENUM('daily_capture','exact_repair') NOT NULL DEFAULT 'daily_capture',
    http_status SMALLINT UNSIGNED NULL,
    response_class ENUM('pending','complete','partial','processing','unavailable','error') NOT NULL DEFAULT 'pending',
    requested_order_ids_json JSON NOT NULL,
    response_hash CHAR(64) NULL,
    missing_fields_json JSON NULL,
    safe_message VARCHAR(500) NULL,
    captured_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_billing_capture_sale (meli_account_id,sale_key,created_at),
    KEY idx_billing_capture_status (response_class,created_at),
    CONSTRAINT fk_billing_capture_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_capture_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_sale_financials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    sale_key VARCHAR(90) NOT NULL,
    external_sale_id VARCHAR(40) NOT NULL,
    identity_type ENUM('pack','order') NOT NULL,
    currency_id VARCHAR(10) NULL,
    products_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    sale_fee_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    shipping_charge_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    taxes_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    discounts_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    credits_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    adjustments_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    net_amount DECIMAL(18,2) NULL,
    local_estimate_amount DECIMAL(18,2) NULL,
    legacy_difference_amount DECIMAL(18,2) NULL,
    source ENUM('local_estimate','billing_official') NOT NULL DEFAULT 'local_estimate',
    capture_run_id BIGINT UNSIGNED NULL,
    reconciliation_status ENUM('not_started','queued','capturing','partial','reconciled','review','error') NOT NULL DEFAULT 'not_started',
    methodology_version VARCHAR(30) NOT NULL DEFAULT 'pack-v1',
    safe_message VARCHAR(500) NULL,
    reconciled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sale_financial (meli_account_id,sale_key),
    KEY idx_sale_financial_scope (company_id,meli_account_id,reconciliation_status),
    CONSTRAINT fk_sale_financial_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_financial_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_financial_capture FOREIGN KEY (capture_run_id) REFERENCES meli_billing_capture_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_sale_financial_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_sale_financial_id BIGINT UNSIGNED NOT NULL,
    meli_billing_capture_run_id BIGINT UNSIGNED NULL,
    external_order_id VARCHAR(40) NULL,
    detail_id VARCHAR(120) NULL,
    line_group ENUM('product','sale_fee','shipping','tax','discount','credit','adjustment','other') NOT NULL,
    line_type VARCHAR(100) NULL,
    line_subtype VARCHAR(140) NULL,
    description VARCHAR(500) NULL,
    amount DECIMAL(18,2) NOT NULL,
    direction ENUM('debit','credit','neutral') NOT NULL DEFAULT 'debit',
    is_shared TINYINT(1) NOT NULL DEFAULT 0,
    source_status ENUM('official','partial','processing','unknown') NOT NULL DEFAULT 'official',
    line_hash CHAR(64) NOT NULL,
    occurred_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sale_financial_line (meli_sale_financial_id,line_hash),
    KEY idx_sale_financial_line_group (meli_sale_financial_id,line_group),
    CONSTRAINT fk_sale_line_financial FOREIGN KEY (meli_sale_financial_id) REFERENCES meli_sale_financials(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_line_capture FOREIGN KEY (meli_billing_capture_run_id) REFERENCES meli_billing_capture_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_sale_financial_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_sale_financial_id BIGINT UNSIGNED NOT NULL,
    meli_order_item_id BIGINT UNSIGNED NOT NULL,
    gross_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    weight_basis ENUM('gross','units') NOT NULL DEFAULT 'gross',
    sale_fee_allocated DECIMAL(18,2) NOT NULL DEFAULT 0,
    shipping_allocated DECIMAL(18,2) NOT NULL DEFAULT 0,
    tax_allocated DECIMAL(18,2) NOT NULL DEFAULT 0,
    discount_allocated DECIMAL(18,2) NOT NULL DEFAULT 0,
    credit_allocated DECIMAL(18,2) NOT NULL DEFAULT 0,
    other_allocated DECIMAL(18,2) NOT NULL DEFAULT 0,
    net_allocated DECIMAL(18,2) NOT NULL DEFAULT 0,
    allocation_version VARCHAR(30) NOT NULL DEFAULT 'largest-remainder-v1',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sale_allocation_item (meli_sale_financial_id,meli_order_item_id),
    CONSTRAINT fk_sale_allocation_financial FOREIGN KEY (meli_sale_financial_id) REFERENCES meli_sale_financials(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_allocation_item FOREIGN KEY (meli_order_item_id) REFERENCES meli_order_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_financial_reconciliation_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    sale_key VARCHAR(90) NOT NULL,
    external_sale_id VARCHAR(40) NOT NULL,
    status ENUM('pending','running','retry','complete','partial','review','error','paused','cancelled') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lock_owner CHAR(32) NULL,
    lease_generation INT UNSIGNED NOT NULL DEFAULT 0,
    lease_expires_at DATETIME NULL,
    heartbeat_at DATETIME NULL,
    safe_message VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sale_financial_job (meli_account_id,sale_key),
    KEY idx_sale_financial_job_due (status,next_run_at,id),
    CONSTRAINT fk_sale_financial_job_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_financial_job_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_financial_job_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_sale_financial_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_sale_financial_id BIGINT UNSIGNED NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    methodology_version VARCHAR(30) NOT NULL,
    totals_json JSON NOT NULL,
    lines_summary_json JSON NULL,
    allocations_summary_json JSON NULL,
    source VARCHAR(40) NOT NULL,
    safe_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sale_financial_revision (meli_sale_financial_id,revision_no),
    CONSTRAINT fk_sale_financial_history FOREIGN KEY (meli_sale_financial_id) REFERENCES meli_sale_financials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migra expectativas ya verificadas sin volver a consultar Mercado Libre.
INSERT INTO meli_pack_order_expectations
    (company_id,meli_account_id,meli_pack_id,external_order_id,meli_order_id,status,verified_at)
SELECT a.company_id,p.meli_account_id,p.id,
       expected.external_order_id,
       o.id,
       IF(o.id IS NULL,'missing','linked'),
       p.verified_at
FROM meli_packs p
JOIN meli_accounts a ON a.id=p.meli_account_id
CROSS JOIN JSON_TABLE(
    COALESCE(p.expected_orders_json,JSON_ARRAY()),
    '$[*]' COLUMNS (
        external_order_id VARCHAR(40) PATH '$'
    )
) AS expected
LEFT JOIN meli_orders o
  ON o.meli_account_id=p.meli_account_id
 AND CAST(o.external_order_id AS CHAR)=expected.external_order_id
WHERE expected.external_order_id IS NOT NULL
ON DUPLICATE KEY UPDATE meli_order_id=VALUES(meli_order_id),status=VALUES(status),verified_at=VALUES(verified_at);

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('sales_financial.daily_capture_enabled','1','sales_financial',0),
('sales_financial.exact_repair_enabled','1','sales_financial',0),
('sales_financial.billing_cache_hours','24','sales_financial',0),
('sales_financial.allocation_method','largest-remainder-v1','sales_financial',0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('sales_financial','122_sale_financial_reconciliation_2_24_0.sql','structural',1),
('process_sync_queue','122_sale_financial_reconciliation_2_24_0.sql','structural',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),enabled=1;

INSERT INTO app_versions (version,notes)
VALUES ('2.24.0','Conciliación financiera oficial por venta agrupada y distribución proporcional')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
