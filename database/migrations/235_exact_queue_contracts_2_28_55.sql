-- ERP Meli 2.28.55 — contratos exactos por cola.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.queue_contracts.required','1',0,'cron'),
('cron.queue_contracts.process_due_ambiguous_visible','1',0,'cron'),
('cron.queue_contracts.unit_language_version','3',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.55',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.55','Contratos por cola: HTTP esperado, recursos por HTTP y razón del límite')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
