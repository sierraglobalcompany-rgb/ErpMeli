-- ERP Meli 2.22.0 — evidencia de cobertura y control anual verificable.

ALTER TABLE sales_control_months
    ADD COLUMN IF NOT EXISTS coverage_state ENUM('unknown','provisional','complete','partial','unavailable')
        NOT NULL DEFAULT 'unknown' AFTER status,
    ADD COLUMN IF NOT EXISTS coverage_from DATE NULL AFTER coverage_state,
    ADD COLUMN IF NOT EXISTS coverage_to DATE NULL AFTER coverage_from,
    ADD COLUMN IF NOT EXISTS source_search_total INT UNSIGNED NULL AFTER coverage_to,
    ADD COLUMN IF NOT EXISTS source_webhook_total INT UNSIGNED NULL AFTER source_search_total,
    ADD COLUMN IF NOT EXISTS source_exact_total INT UNSIGNED NULL AFTER source_webhook_total,
    ADD COLUMN IF NOT EXISTS source_local_only_total INT UNSIGNED NULL AFTER source_exact_total,
    ADD COLUMN IF NOT EXISTS cancellation_coverage ENUM('unknown','limited','supplemented')
        NOT NULL DEFAULT 'unknown' AFTER source_local_only_total,
    ADD COLUMN IF NOT EXISTS coverage_message VARCHAR(500) NULL AFTER cancellation_coverage;

ALTER TABLE sales_control_captures
    ADD COLUMN IF NOT EXISTS requested_from_utc DATETIME NULL AFTER capture_sequence,
    ADD COLUMN IF NOT EXISTS requested_to_utc DATETIME NULL AFTER requested_from_utc,
    ADD COLUMN IF NOT EXISTS http_status SMALLINT UNSIGNED NULL AFTER requested_to_utc,
    ADD COLUMN IF NOT EXISTS content_missing VARCHAR(500) NULL AFTER http_status,
    ADD COLUMN IF NOT EXISTS source_breakdown_json TEXT NULL AFTER content_missing,
    ADD COLUMN IF NOT EXISTS coverage_confidence ENUM('unknown','provisional','complete','partial')
        NOT NULL DEFAULT 'unknown' AFTER source_breakdown_json;

CREATE TABLE IF NOT EXISTS sales_control_month_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sales_control_month_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_order_id VARCHAR(80) NOT NULL,
    source_kind ENUM('search','webhook','exact_id','local_only') NOT NULL,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    current_capture_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_sales_month_order_source
        (sales_control_month_id,meli_account_id,external_order_id,source_kind),
    KEY idx_sales_month_source_scope
        (company_id,meli_account_id,sales_control_month_id,source_kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES ('sales_control','116_sales_evidence_control_2_22_0.sql','structural',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),enabled=1;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('sales_control.current_month_provisional','1','sales_control',0),
('sales_control.historical_double_capture','1','sales_control',0),
('sales_control.cancelled_search_limitation_visible','1','sales_control',0),
('sales_control.month_orchestration_parallel_per_account','1','sales_control',0),
('sales_control.default_page_size','50','sales_control',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.22.0','Cobertura verificable, fuentes de ventas y control anual honesto')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
