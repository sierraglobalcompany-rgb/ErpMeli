-- ERP Meli / Gestion Pro 2.7.3
-- Recalculo financiero inteligente con billing automatico segun faltantes.

ALTER TABLE order_financial_recalc_jobs
    ADD COLUMN current_phase VARCHAR(40) NOT NULL DEFAULT 'local_recalc' AFTER status,
    ADD COLUMN local_recalc_items INT UNSIGNED NOT NULL DEFAULT 0 AFTER error_items,
    ADD COLUMN billing_needed_items INT UNSIGNED NOT NULL DEFAULT 0 AFTER local_recalc_items,
    ADD COLUMN billing_imported_items INT UNSIGNED NOT NULL DEFAULT 0 AFTER billing_needed_items,
    ADD COLUMN matched_items INT UNSIGNED NOT NULL DEFAULT 0 AFTER billing_imported_items,
    ADD COLUMN partial_items INT UNSIGNED NOT NULL DEFAULT 0 AFTER matched_items,
    ADD COLUMN last_pause_reason VARCHAR(80) NULL AFTER last_error_message,
    ADD COLUMN settings_snapshot_json JSON NULL AFTER last_pause_reason,
    ADD KEY idx_financial_recalc_phase (current_phase, status);

ALTER TABLE order_financial_recalc_job_items
    ADD COLUMN phase VARCHAR(40) NOT NULL DEFAULT 'local_recalc' AFTER status,
    ADD COLUMN missing_flags VARCHAR(255) NULL AFTER phase,
    ADD COLUMN requires_billing TINYINT(1) NOT NULL DEFAULT 0 AFTER missing_flags,
    ADD COLUMN billing_imported_at DATETIME NULL AFTER requires_billing,
    ADD COLUMN billing_status VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER billing_imported_at,
    ADD COLUMN financial_status VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER billing_status,
    ADD COLUMN diagnostic_message VARCHAR(500) NULL AFTER financial_status,
    ADD KEY idx_financial_recalc_item_phase (order_financial_recalc_job_id, phase, status),
    ADD KEY idx_financial_recalc_requires_billing (requires_billing, billing_status);

ALTER TABLE meli_order_financials
    ADD COLUMN tax_withholding_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER withholdings_amount,
    ADD COLUMN tax_retention_source_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER tax_withholding_amount,
    ADD COLUMN tax_reteiva_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER tax_retention_source_amount,
    ADD COLUMN tax_other_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER tax_reteiva_amount,
    ADD COLUMN local_estimated_net_amount DECIMAL(18,2) NULL AFTER ml_net_amount,
    ADD COLUMN billing_reconciled_net_amount DECIMAL(18,2) NULL AFTER local_estimated_net_amount,
    ADD COLUMN reconciliation_difference DECIMAL(18,2) NULL AFTER billing_reconciled_net_amount,
    ADD COLUMN financial_source VARCHAR(50) NOT NULL DEFAULT 'local' AFTER source,
    ADD COLUMN billing_import_status VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER financial_source,
    ADD COLUMN billing_imported_at DATETIME NULL AFTER billing_import_status,
    ADD COLUMN raw_billing_summary_json JSON NULL AFTER raw_summary_json,
    ADD KEY idx_financial_billing_import (billing_import_status, updated_at);

ALTER TABLE meli_order_billing_details
    ADD COLUMN classification VARCHAR(80) NULL AFTER detail_subtype,
    ADD COLUMN detail_hash VARCHAR(64) NULL AFTER classification,
    ADD COLUMN date_created DATETIME NULL AFTER occurred_at,
    ADD COLUMN date_approved DATETIME NULL AFTER date_created,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD UNIQUE KEY uq_billing_detail_hash (meli_account_id, meli_order_id, detail_hash),
    ADD KEY idx_billing_classification (classification, source_status);

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('financial_recalc.enabled', '1', 'financial_recalc', 0),
('financial_recalc.orders_per_run', '25', 'financial_recalc', 0),
('financial_recalc.batch_limit', '25', 'financial_recalc', 0),
('financial_recalc.max_orders_per_job', '500', 'financial_recalc', 0),
('financial_recalc.billing_order_ids_per_request', '60', 'financial_recalc', 0),
('financial_recalc.time_budget_seconds', '30', 'financial_recalc', 0),
('financial_recalc.pause_between_requests_ms', '800', 'financial_recalc', 0),
('financial_recalc.use_billing_order_details', '1', 'financial_recalc', 0),
('financial_recalc.auto_billing_for_missing', '1', 'financial_recalc', 0),
('financial_recalc.safe_mode', '1', 'financial_recalc', 0),
('financial_recalc.stop_on_429', '1', 'financial_recalc', 0),
('financial_recalc.stop_on_403', '1', 'financial_recalc', 0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.7.3', 'Recalculo financiero inteligente con billing automatico segun faltantes.');
