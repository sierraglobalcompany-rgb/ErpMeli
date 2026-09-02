-- ERP Meli 2.28.54 — campaña ejecutable y cierres locales visibles.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('manual_campaign.executable_projection_required','1',0,'manual_campaign'),
('manual_campaign.waiting_source_no_attempt','1',0,'manual_campaign'),
('manual_campaign.local_closed_separate_from_http','1',0,'manual_campaign');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.54',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.54','Campaña #6 separa ejecutables, waiting_source, HTTP confirmado y cierres locales')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
