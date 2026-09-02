-- ERP Meli 2.28.7 — recuperación del actualizador y diagnóstico real de Cron.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('commercial_path.version','2.28.7',0,'commercial_path'),
    ('operational_audit.last_certified_version','2.28.7',0,'audit'),
    ('recovery.updater_migration_failure_detail','1',0,'update'),
    ('cron.integrity_error_detail','1',0,'cron')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);
