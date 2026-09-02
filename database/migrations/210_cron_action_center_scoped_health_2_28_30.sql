-- ERP Meli 2.28.30 — historial resoluble, trabajo exacto y Salud API scoped.
-- Agrega únicamente trazabilidad técnica. No modifica datos comerciales,
-- campañas, cuentas, credenciales, OAuth, pagos, cierres ni evidencia fiscal.

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='system_work_queue_run_items'
       AND column_name='company_id')=0,
    'ALTER TABLE system_work_queue_run_items ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER source_id',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='system_work_queue_run_items'
       AND column_name='meli_account_id')=0,
    'ALTER TABLE system_work_queue_run_items ADD COLUMN meli_account_id BIGINT UNSIGNED NULL AFTER company_id',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='system_work_queue_run_items'
       AND column_name='work_source_id')=0,
    'ALTER TABLE system_work_queue_run_items ADD COLUMN work_source_id VARCHAR(100) NULL AFTER meli_account_id',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='system_work_queue_run_items'
       AND column_name='reached_remote')=0,
    'ALTER TABLE system_work_queue_run_items ADD COLUMN reached_remote TINYINT(1) NULL AFTER blocked_remote_calls',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='system_work_queue_run_items'
       AND column_name='failure_class')=0,
    'ALTER TABLE system_work_queue_run_items ADD COLUMN failure_class VARCHAR(60) NULL AFTER reached_remote',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='system_work_queue_run_items'
       AND index_name='idx_work_run_items_scope')=0,
    'ALTER TABLE system_work_queue_run_items ADD INDEX idx_work_run_items_scope (company_id,meli_account_id,created_at)',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.run_detail_enabled','1',0,'cron'),
('cron.exact_remediation_protocol_version','2',0,'cron'),
('cron.attention_waits_excluded','1',0,'cron'),
('api.health.progressive_protocol_version','3',0,'api_health'),
('api.health.automation_scope_required','1',0,'api_health');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.30',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('automation_center','210_cron_action_center_scoped_health_2_28_30.sql','structural',1),
('api_health','210_cron_action_center_scoped_health_2_28_30.sql','structural',1),
('api_guard','210_cron_action_center_scoped_health_2_28_30.sql','structural',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.30','Historial Cron resoluble, remediación exacta y Salud API aislada por empresa y cuenta')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
