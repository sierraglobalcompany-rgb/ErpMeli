-- ERP Meli 2.28.41 — paneles Cron read-only rápidos.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.shell.progressive_enabled','1',0,'cron'),
('cron.read_models.require_read_only_get','1',0,'cron'),
('cron.read_models.section_timeout_ms','8000',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.41',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts (component_key,required_migration,contract_kind,enabled)
VALUES
('automation_center','221_cron_readonly_fast_shell_2_28_41.sql','metadata',1),
('api_health','221_cron_readonly_fast_shell_2_28_41.sql','metadata',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.41','Shell Cron progresivo y contrato read-only para GET operativos')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
