-- ERP Meli 2.28.1 — Fase 1: cobertura temporal correcta.
-- Aditiva. No modifica órdenes, cierres históricos ni evidencia comercial.

ALTER TABLE sync_sales_audit_runs
    ADD COLUMN IF NOT EXISTS requested_from_utc DATETIME NULL AFTER utc_to,
    ADD COLUMN IF NOT EXISTS requested_to_utc DATETIME NULL AFTER requested_from_utc,
    ADD COLUMN IF NOT EXISTS historical_window_starts_at DATETIME NULL AFTER requested_to_utc,
    ADD COLUMN IF NOT EXISTS effective_coverage_from_utc DATETIME NULL AFTER historical_window_starts_at,
    ADD COLUMN IF NOT EXISTS effective_coverage_to_utc DATETIME NULL AFTER effective_coverage_from_utc,
    ADD COLUMN IF NOT EXISTS temporal_coverage_state
        ENUM('pending','full','partial','outside','invalid') NOT NULL DEFAULT 'pending'
        AFTER effective_coverage_to_utc,
    ADD COLUMN IF NOT EXISTS temporal_coverage_reason VARCHAR(500) NULL AFTER temporal_coverage_state,
    ADD COLUMN IF NOT EXISTS coverage_contract_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER temporal_coverage_reason;

UPDATE sync_sales_audit_runs
SET requested_from_utc=COALESCE(requested_from_utc,utc_from),
    requested_to_utc=COALESCE(requested_to_utc,utc_to)
WHERE requested_from_utc IS NULL OR requested_to_utc IS NULL;

ALTER TABLE sales_control_captures
    ADD COLUMN IF NOT EXISTS effective_coverage_from_utc DATETIME NULL AFTER requested_to_utc,
    ADD COLUMN IF NOT EXISTS effective_coverage_to_utc DATETIME NULL AFTER effective_coverage_from_utc,
    ADD COLUMN IF NOT EXISTS temporal_coverage_state
        ENUM('pending','full','partial','outside','invalid') NOT NULL DEFAULT 'pending'
        AFTER effective_coverage_to_utc,
    ADD COLUMN IF NOT EXISTS temporal_coverage_reason VARCHAR(500) NULL AFTER temporal_coverage_state,
    ADD COLUMN IF NOT EXISTS coverage_contract_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER temporal_coverage_reason;

ALTER TABLE sales_control_closes
    ADD COLUMN IF NOT EXISTS coverage_contract_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER snapshot_hash,
    ADD COLUMN IF NOT EXISTS temporal_coverage_state
        ENUM('pending','full','partial','outside','invalid') NOT NULL DEFAULT 'pending'
        AFTER coverage_contract_version;

SET @idx_temporal_runs = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE BINARY table_schema=BINARY DATABASE()
      AND BINARY table_name=BINARY 'sync_sales_audit_runs'
      AND BINARY index_name=BINARY 'idx_sales_audit_temporal_coverage'
);
SET @sql_temporal_runs = IF(
    @idx_temporal_runs=0,
    'ALTER TABLE sync_sales_audit_runs ADD KEY idx_sales_audit_temporal_coverage (company_id,meli_account_id,period_year,period_month,temporal_coverage_state,id)',
    'SELECT 1'
);
PREPARE stmt_temporal_runs FROM @sql_temporal_runs;
EXECUTE stmt_temporal_runs;
DEALLOCATE PREPARE stmt_temporal_runs;

DELETE FROM system_component_schema_contracts
WHERE component_key='sales_control'
  AND required_migration<>'181_sales_temporal_coverage_contract_2_28_1.sql';

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES ('sales_control','181_sales_temporal_coverage_contract_2_28_1.sql','structural',1)
ON DUPLICATE KEY UPDATE
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('commercial_path.version','2.28.1',0,'commercial_path'),
    ('commercial_path.coverage_temporal_enabled','1',0,'commercial_path'),
    ('commercial_path.next_phase','order_change_sweep',0,'commercial_path'),
    ('sales_control.coverage_contract_version','2',0,'sales_control'),
    ('sales_control.block_close_without_temporal_full','1',0,'sales_control'),
    ('sales_control.temporal_coverage_ui_enabled','1',0,'sales_control')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version,notes)
VALUES (
    '2.28.1',
    'Fase 1 comercial: cobertura temporal full/partial/outside/invalid y cierres nuevos bloqueados sin cobertura full.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
