-- ERP Meli 2.28.16 — vistas humanas de Cron y Salud API.
-- Metadata y defaults de lectura; no modifica datos comerciales.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.human_overview_enabled','1',0,'cron'),
('cron.rhythm_preview_enabled','1',0,'cron'),
('api_health.separate_erp_waits','1',0,'api_health'),
('cron.read_snapshot_seconds','10',0,'cron'),
('app.version','2.28.16',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=CASE WHEN setting_key='app.version' THEN VALUES(setting_value) ELSE setting_value END,
    setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO system_component_schema_contracts (component_key,required_migration,contract_kind,enabled)
VALUES
('automation_center','196_cron_health_human_control_2_28_16.sql','structural',1),
('api_health','196_cron_health_human_control_2_28_16.sql','structural',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.16','Cron humano, Salud API confiable y ritmo configurable')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
