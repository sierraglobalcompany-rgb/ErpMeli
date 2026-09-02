-- ERP Meli 2.28.50 — preflight de clon local y replay con transporte falso.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.qa_replay.cycles','60',0,'cron'),
('cron.qa_replay.fake_transport_required','1',0,'cron'),
('cron.qa_replay.block_on_pause_markers','1',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.50',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.50','Preflight QA para replay Cron con dump limpio y transporte Mercado Libre falso')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
