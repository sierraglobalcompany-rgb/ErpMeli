-- ERP Meli 2.23.0 — captura mensual verificable y evidencia de cobertura.

ALTER TABLE sync_sales_audit_runs
    ADD COLUMN IF NOT EXISTS capture_role ENUM('primary','verification')
        NOT NULL DEFAULT 'primary' AFTER mode,
    ADD COLUMN IF NOT EXISTS verification_of_run_id BIGINT UNSIGNED NULL AFTER capture_role,
    ADD COLUMN IF NOT EXISTS verification_not_before DATETIME NULL AFTER verification_of_run_id,
    ADD COLUMN IF NOT EXISTS coverage_validation_state ENUM('pending','valid','invalid')
        NOT NULL DEFAULT 'pending' AFTER remote_coverage,
    ADD COLUMN IF NOT EXISTS coverage_validation_json TEXT NULL AFTER coverage_validation_state;

ALTER TABLE sync_sales_audit_jobs
    ADD COLUMN IF NOT EXISTS capture_role ENUM('primary','verification')
        NOT NULL DEFAULT 'primary' AFTER status;

ALTER TABLE sync_sales_audit_run_orders
    MODIFY COLUMN classification ENUM(
        'pending','present','missing_remote','missing_normalized_date',
        'shifted_date','other_account','duplicate_accounts','extra_local',
        'outside_range','unknown'
    ) NOT NULL DEFAULT 'pending';

ALTER TABLE sales_control_captures
    ADD COLUMN IF NOT EXISTS capture_role ENUM('primary','verification')
        NOT NULL DEFAULT 'primary' AFTER capture_sequence,
    ADD COLUMN IF NOT EXISTS validation_state ENUM('pending','valid','invalid')
        NOT NULL DEFAULT 'pending' AFTER coverage_confidence,
    ADD COLUMN IF NOT EXISTS validation_json TEXT NULL AFTER validation_state;

CREATE TABLE IF NOT EXISTS sync_sales_capture_validations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sync_sales_audit_run_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    validation_state ENUM('valid','invalid') NOT NULL,
    page_count INT UNSIGNED NOT NULL DEFAULT 0,
    reported_total INT UNSIGNED NULL,
    unique_total INT UNSIGNED NOT NULL DEFAULT 0,
    first_offset INT UNSIGNED NULL,
    last_offset INT UNSIGNED NULL,
    reasons_json TEXT NULL,
    evidence_hash CHAR(64) NOT NULL,
    validated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_sales_capture_validation_run (sync_sales_audit_run_id),
    KEY idx_sales_capture_validation_scope
        (company_id,meli_account_id,validation_state,validated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES ('sales_control','119_verified_sales_capture_2_23_0.sql','structural',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),enabled=1;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('sales_control.verification_min_delay_minutes','15','sales_control',0),
('sales_control.strict_page_validation','1','sales_control',0),
('sales_control.remote_window_months','12','sales_control',0),
('sales_control.max_active_audits_per_account','1','sales_control',0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.23.0','Capturas mensuales verificables, cobertura honesta y conciliación por cuenta')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
