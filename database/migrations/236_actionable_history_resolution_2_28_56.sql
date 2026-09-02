-- ERP Meli 2.28.56 — historial e intervención resoluble.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.history.red_rows_require_action_link','1',0,'cron'),
('cron.history.automatic_waits_not_attention','1',0,'cron'),
('cron.web_actions.exact_only','1',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.56',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.56','Cada error rojo debe tener recurso, diagnóstico seguro y acción exacta; esperas automáticas no son atención')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
