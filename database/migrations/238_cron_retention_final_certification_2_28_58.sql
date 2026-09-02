-- ERP Meli 2.28.58 — retención técnica y certificación final de reconstrucción Cron.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.retention.cli_only','1',0,'retention'),
('cron.certification.minimum_version','2.28.58',0,'cron'),
('cron.certification.requires_doctor','1',0,'cron'),
('cron.certification.requires_true_counters','1',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.58',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','238_cron_retention_final_certification_2_28_58.sql','metadata',1),
('automation_center','238_cron_retention_final_certification_2_28_58.sql','metadata',1),
('api_health','238_cron_retention_final_certification_2_28_58.sql','metadata',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.58','Certificación acumulativa: Doctor, contadores verdaderos, planner verificable, snapshots y retención CLI')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
