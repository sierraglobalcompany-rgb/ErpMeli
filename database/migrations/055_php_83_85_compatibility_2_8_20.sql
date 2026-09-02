-- ERP Meli 2.8.20 — Compatibilidad PHP 8.3 a PHP 8.5.
-- Registra política de runtime sin cambiar datos operativos.

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
    ('app.min_php_version','8.3.0','runtime',0),
    ('app.max_tested_php_version','8.5.x','runtime',0),
    ('app.php_compatibility_range','8.3-8.5','runtime',0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.8.20', 'Compatibilidad oficial PHP 8.3 a 8.5, diagnóstico de runtime y limpieza de deprecaciones CSV.');
