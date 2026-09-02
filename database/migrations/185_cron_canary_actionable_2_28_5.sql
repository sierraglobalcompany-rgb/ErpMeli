-- ERP Meli 2.28.5 — Cron canarios accionables y diagnóstico rápido.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('commercial_path.version','2.28.5',0,'commercial_path'),
    ('operational_audit.last_certified_version','2.28.5',0,'audit'),
    ('cron.canary_actionable_shell','1',0,'cron'),
    ('cron.detail_heavy_checks_deferred','1',0,'cron')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);
