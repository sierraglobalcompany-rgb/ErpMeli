INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
('app.version','2.29.5',0,'system'),
('cron_v3.canary.ui_state_authority','1',0,'cron_v3'),
('cron_v3.canary.active_release','2.29.5',0,'cron_v3')
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
is_encrypted=VALUES(is_encrypted),
setting_group=VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.29.5','Botones accionables del canario Cron V3 sincronizados con el estado real.')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
