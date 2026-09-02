-- ERP Meli 2.29.0 - estado financiero canonico por venta.
-- Aditiva, idempotente y sin modificar cierres mensuales existentes.

CREATE TABLE IF NOT EXISTS sale_financial_state (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    sale_key VARCHAR(90) NOT NULL,
    external_sale_id VARCHAR(40) NOT NULL,
    identity_type ENUM('pack','order') NOT NULL,
    input_version CHAR(64) NOT NULL,
    currency_id VARCHAR(10) NULL,
    commercial_status ENUM('complete','partial','missing','review') NOT NULL DEFAULT 'missing',
    logistics_status ENUM('complete','partial','missing','not_required','review') NOT NULL DEFAULT 'missing',
    provisional_status ENUM('complete','partial','missing','review') NOT NULL DEFAULT 'missing',
    official_status ENUM('missing','queued','capturing','partial','complete','review','error') NOT NULL DEFAULT 'missing',
    products_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    provisional_net_amount DECIMAL(18,2) NULL,
    official_net_amount DECIMAL(18,2) NULL,
    unknown_concepts_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    official_capture_id BIGINT UNSIGNED NULL,
    missing_flags_json JSON NULL,
    close_impact ENUM('open','closed_unchanged','closed_late_evidence') NOT NULL DEFAULT 'open',
    sales_control_close_id BIGINT UNSIGNED NULL,
    projected_at DATETIME NULL,
    official_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sale_financial_state_scope (company_id,meli_account_id,sale_key),
    KEY idx_sale_financial_state_gap (company_id,meli_account_id,official_status,provisional_status,sale_key),
    KEY idx_sale_financial_state_input (company_id,meli_account_id,sale_key,input_version),
    CONSTRAINT fk_sale_financial_state_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_financial_state_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_financial_state_capture FOREIGN KEY (official_capture_id) REFERENCES meli_billing_capture_runs(id) ON DELETE SET NULL,
    CONSTRAINT fk_sale_financial_state_close FOREIGN KEY (sales_control_close_id) REFERENCES sales_control_closes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_financial_evidence (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    sale_key VARCHAR(90) NOT NULL,
    input_version CHAR(64) NOT NULL,
    evidence_type ENUM('local_projection','billing_capture','late_close_discrepancy','diagnostic') NOT NULL,
    evidence_status VARCHAR(40) NOT NULL,
    source_id BIGINT UNSIGNED NULL,
    payload_hash CHAR(64) NOT NULL,
    provisional_net_amount DECIMAL(18,2) NULL,
    official_net_amount DECIMAL(18,2) NULL,
    evidence_json LONGTEXT NOT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sale_financial_evidence (company_id,meli_account_id,sale_key,input_version,evidence_type,payload_hash),
    KEY idx_sale_financial_evidence_sale (company_id,meli_account_id,sale_key,captured_at),
    CONSTRAINT fk_sale_financial_evidence_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_financial_evidence_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financial_gap_scan_state (
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    cursor_sale_key VARCHAR(90) NULL,
    last_read_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_enqueued_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_scanned_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id,meli_account_id),
    CONSTRAINT fk_financial_gap_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_financial_gap_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE meli_billing_capture_runs
    ADD COLUMN IF NOT EXISTS input_version CHAR(64) NULL AFTER external_sale_id;

SET @has_billing_capture_version_idx = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='meli_billing_capture_runs'
      AND index_name='idx_billing_capture_version'
);
SET @add_billing_capture_version_idx = IF(
    @has_billing_capture_version_idx=0,
    'ALTER TABLE meli_billing_capture_runs ADD KEY idx_billing_capture_version (company_id,meli_account_id,sale_key,input_version,response_class)',
    'SELECT 1'
);
PREPARE stmt_add_billing_capture_version_idx FROM @add_billing_capture_version_idx;
EXECUTE stmt_add_billing_capture_version_idx;
DEALLOCATE PREPARE stmt_add_billing_capture_version_idx;

ALTER TABLE sale_financial_reconciliation_jobs
    ADD COLUMN IF NOT EXISTS input_version CHAR(64) NULL AFTER external_sale_id;

UPDATE sale_financial_reconciliation_jobs
SET input_version=SHA2(CONCAT('legacy:',company_id,':',meli_account_id,':',sale_key),256)
WHERE input_version IS NULL OR input_version='';

ALTER TABLE sale_financial_reconciliation_jobs
    MODIFY COLUMN input_version CHAR(64) NOT NULL;

SET @has_old_sale_financial_job_uq = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='sale_financial_reconciliation_jobs'
      AND index_name='uq_sale_financial_job'
);
SET @drop_old_sale_financial_job_uq = IF(
    @has_old_sale_financial_job_uq>0,
    'ALTER TABLE sale_financial_reconciliation_jobs DROP INDEX uq_sale_financial_job',
    'SELECT 1'
);
PREPARE stmt_drop_old_sale_financial_job_uq FROM @drop_old_sale_financial_job_uq;
EXECUTE stmt_drop_old_sale_financial_job_uq;
DEALLOCATE PREPARE stmt_drop_old_sale_financial_job_uq;

SET @has_v3_sale_financial_job_uq = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='sale_financial_reconciliation_jobs'
      AND index_name='uq_sale_financial_job_v3'
);
SET @add_v3_sale_financial_job_uq = IF(
    @has_v3_sale_financial_job_uq=0,
    'ALTER TABLE sale_financial_reconciliation_jobs ADD UNIQUE KEY uq_sale_financial_job_v3 (company_id,meli_account_id,sale_key,input_version)',
    'SELECT 1'
);
PREPARE stmt_add_v3_sale_financial_job_uq FROM @add_v3_sale_financial_job_uq;
EXECUTE stmt_add_v3_sale_financial_job_uq;
DEALLOCATE PREPARE stmt_add_v3_sale_financial_job_uq;

ALTER TABLE order_financial_recalc_jobs
    ADD COLUMN IF NOT EXISTS company_id BIGINT UNSIGNED NULL AFTER id;

SET @has_financial_recalc_v3_scope_idx = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='order_financial_recalc_jobs'
      AND index_name='idx_financial_recalc_v3_scope'
);
SET @add_financial_recalc_v3_scope_idx = IF(
    @has_financial_recalc_v3_scope_idx=0,
    'ALTER TABLE order_financial_recalc_jobs ADD KEY idx_financial_recalc_v3_scope (company_id,meli_account_id,status,id)',
    'SELECT 1'
);
PREPARE stmt_add_financial_recalc_v3_scope_idx FROM @add_financial_recalc_v3_scope_idx;
EXECUTE stmt_add_financial_recalc_v3_scope_idx;
DEALLOCATE PREPARE stmt_add_financial_recalc_v3_scope_idx;

UPDATE order_financial_recalc_jobs j
JOIN meli_accounts a ON a.id=j.meli_account_id
SET j.company_id=a.company_id
WHERE j.company_id IS NULL AND j.meli_account_id IS NOT NULL;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('sales_financial.daily_capture_enabled','0','sales_financial',0),
('financial_recalc.auto_billing_for_missing','0','financial_recalc',0),
('financial_recalc.use_billing_order_details','0','financial_recalc',0),
('sales_financial.v3_enabled','1','sales_financial',0),
('sales_financial.gap_scan_read_limit','50','sales_financial',0),
('sales_financial.gap_scan_enqueue_limit','20','sales_financial',0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),setting_group=VALUES(setting_group),is_encrypted=0;

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES ('sales_financial_v3','242_financial_state_v3_2_29_0.sql','structural',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=1;

INSERT INTO app_versions (version,notes)
VALUES ('2.29.0','Estado financiero canonico por venta, evidencia inmutable y Billing unico por version de entrada')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
