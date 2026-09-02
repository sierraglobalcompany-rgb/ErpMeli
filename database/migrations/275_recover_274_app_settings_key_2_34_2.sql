-- ERP Meli 2.34.2
-- Recupera de forma segura la migración 274 cuando producción alcanzó
-- sql_execution con la columna legacy incorrecta app_settings.key.
-- Solo metadata/versionado; no toca datos comerciales ni Mercado Libre.

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('app.version','2.34.2','system',0),
('api.health.rate_limit_signal_ui','1','api_health',0),
('release.clean_runtime_allowlist','2.34.2','update',0),
('release.recovered_migration_274_app_settings_key','1','update',0)
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  setting_group = VALUES(setting_group),
  is_encrypted = VALUES(is_encrypted);

INSERT INTO app_versions (`version`, `notes`)
VALUES ('2.34.2', 'Recuperación del fallo 274 por columna app_settings.key; mantiene Salud API 429 accionable y paquete limpio.')
ON DUPLICATE KEY UPDATE
  `notes` = VALUES(`notes`),
  installed_at = UTC_TIMESTAMP();
