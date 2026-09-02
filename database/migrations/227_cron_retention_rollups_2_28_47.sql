-- ERP Meli 2.28.47 — retención técnica segura por CLI.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('retention.cron_rollups_enabled','1',0,'retention'),
('retention.cron_max_rows_per_cycle','500',0,'retention'),
('retention.never_delete_commercial_evidence','1',0,'retention');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.47',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts (component_key,required_migration,contract_kind,enabled)
VALUES ('database_maintenance','227_cron_retention_rollups_2_28_47.sql','metadata',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.47','Retención técnica CLI con rollups, checksum y exclusión de evidencia comercial')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
