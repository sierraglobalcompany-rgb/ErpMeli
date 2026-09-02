-- ERP Meli 2.28.28 — lenguaje operativo, accesibilidad y adaptación móvil.
-- Solo registra defaults de interfaz. INSERT IGNORE conserva cualquier valor
-- elegido previamente por el operador. No modifica datos comerciales.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('ui.dynamic_document_titles_enabled','1',0,'ui'),
('ui.mobile_tables_as_cards_enabled','1',0,'ui'),
('ui.cron_http_transport_language_version','2',0,'ui'),
('ui.emergency_external_script_enabled','1',0,'ui');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.28',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('automation_center','208_human_frontend_accessibility_2_28_28.sql','metadata',1),
('emergency_control','208_human_frontend_accessibility_2_28_28.sql','metadata',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.28','Interfaz operativa humana, tablas móviles y freno de mano compatible con CSP')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
