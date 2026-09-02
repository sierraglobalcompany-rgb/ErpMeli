INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
('app.version','2.29.3',0,'system'),
('cron_v3.assistant.enabled','1',0,'cron_v3'),
('cron_v3.assistant.requires_manual_hostinger','1',0,'cron_v3'),
('cron_v3.assistant.active_release','2.29.3',0,'cron_v3')
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
is_encrypted=VALUES(is_encrypted),
setting_group=VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.29.3','Asistente seguro para preparar Cron V3 shadow desde el actualizador.')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
