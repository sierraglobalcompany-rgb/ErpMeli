-- ERP Meli 2.28.57 — panel rápido y snapshots O(1).

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.panels.shell_target_ms','500',0,'cron'),
('cron.panels.snapshot_o1_required','1',0,'cron'),
('cron.panels.shared_generation_required','1',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.57',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.57','Cron, campaña y Salud API leen snapshot compartido y shell progresivo')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
