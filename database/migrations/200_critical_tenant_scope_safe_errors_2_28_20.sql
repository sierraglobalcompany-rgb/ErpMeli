-- ERP Meli 2.28.20 — cierre de aislamiento multiempresa.
-- Solo registra contratos de seguridad. No modifica órdenes, ventas, pagos,
-- campañas, cuentas, OAuth, cierres ni evidencia fiscal.

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='meli_item_descriptions')=1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='meli_item_descriptions' AND column_name='diagnostic_id')=0,
    'ALTER TABLE meli_item_descriptions ADD COLUMN diagnostic_id VARCHAR(80) NULL AFTER safe_error_message',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='catalog_description_job_items')=1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='catalog_description_job_items' AND column_name='diagnostic_id')=0,
    'ALTER TABLE catalog_description_job_items ADD COLUMN diagnostic_id VARCHAR(80) NULL AFTER safe_error_message',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='catalog_description_jobs')=1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='catalog_description_jobs' AND column_name='last_error_diagnostic_id')=0,
    'ALTER TABLE catalog_description_jobs ADD COLUMN last_error_diagnostic_id VARCHAR(80) NULL AFTER last_error_message',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='catalogs')=1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='catalogs' AND column_name='last_description_sync_diagnostic_id')=0,
    'ALTER TABLE catalogs ADD COLUMN last_description_sync_diagnostic_id VARCHAR(80) NULL AFTER last_description_sync_message',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='catalogs')=1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='catalogs' AND column_name='last_refresh_diagnostic_id')=0,
    'ALTER TABLE catalogs ADD COLUMN last_refresh_diagnostic_id VARCHAR(80) NULL AFTER last_refresh_message',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('security.business_reads_fail_closed','1',0,'security'),
('security.direct_job_ids_require_account_scope','1',0,'security'),
('security.safe_database_errors_only','1',0,'security'),
('app.version','2.28.20',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('payment_read_model','200_critical_tenant_scope_safe_errors_2_28_20.sql','metadata',1),
('product_linking','200_critical_tenant_scope_safe_errors_2_28_20.sql','metadata',1),
('date_billing','200_critical_tenant_scope_safe_errors_2_28_20.sql','metadata',1),
('catalog_descriptions','200_critical_tenant_scope_safe_errors_2_28_20.sql','metadata',1),
('claims','200_critical_tenant_scope_safe_errors_2_28_20.sql','metadata',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.20','Cierre de alcance multiempresa en pagos, vínculos, reportes, catálogos y reclamos')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
