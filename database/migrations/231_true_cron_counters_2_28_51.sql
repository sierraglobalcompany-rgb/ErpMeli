-- ERP Meli 2.28.51 — contadores verdaderos de Cron.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.counters.separate_callback_http_resources','1',0,'cron'),
('cron.counters.waiting_not_started','1',0,'cron'),
('cron.counters.local_closed_separate','1',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.51',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.51','Separación de función reclamada, callback útil, HTTP, respuesta conocida y recurso finalizado')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
