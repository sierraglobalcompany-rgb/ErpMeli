-- ERP Meli 2.28.34 — autoridad coherente de versión instalada.
-- Solo reconcilia metadata del runtime. No modifica información comercial,
-- campañas, colas, OAuth, cuentas ni configuración de ritmo.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.34',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('update.installed_version_authority','app_settings',0,'update')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes,installed_at)
VALUES ('2.28.34','Autoridad de versión instalada coherente y protección frente a cargas parciales',CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE
    notes=VALUES(notes),
    installed_at=CURRENT_TIMESTAMP;
