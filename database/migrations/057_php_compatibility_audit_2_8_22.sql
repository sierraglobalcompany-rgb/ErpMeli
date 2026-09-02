-- ERP Meli 2.8.22 — Auditoría profunda y compatibilidad PHP 8.3 a PHP 8.5.
-- No modifica datos operativos.

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
    ('app.min_php_version','8.3.0','runtime',0),
    ('app.max_tested_php_version','8.5.x','runtime',0),
    ('app.php_compatibility_range','8.3-8.5','runtime',0),
    ('app.php_compatibility_audit_version','2.8.22','runtime',0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.8.22', 'Auditoría de compatibilidad PHP 8.3–8.5, runtime web/cron, extensiones obligatorias y reconexión MySQL 4031.');
