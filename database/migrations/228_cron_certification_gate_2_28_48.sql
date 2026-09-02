-- ERP Meli 2.28.48 — compuerta de certificación Cron.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.certification.required_cycles','60',0,'cron'),
('cron.certification.requires_fake_transport','1',0,'cron'),
('cron.certification.requires_get_read_only','1',0,'cron'),
('cron.certification.requires_campaign_no_starvation','1',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.48',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','228_cron_certification_gate_2_28_48.sql','metadata',1),
('automation_center','228_cron_certification_gate_2_28_48.sql','metadata',1),
('api_health','228_cron_certification_gate_2_28_48.sql','metadata',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.48','Compuerta final para Cron reconstruido: perfiles, campaña, GET read-only y transporte falso en QA')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
