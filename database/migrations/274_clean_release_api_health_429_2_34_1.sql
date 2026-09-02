-- ERP Meli 2.34.1
-- Entrega limpia y Salud API: los HTTP 429 son señales de rate limit
-- aunque el incidente ya se haya recuperado. Solo metadata/versionado.

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('app.version','2.34.1','system',0),
('api.health.rate_limit_signal_ui','1','api_health',0),
('release.clean_runtime_allowlist','2.34.1','update',0)
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  setting_group = VALUES(setting_group),
  is_encrypted = VALUES(is_encrypted);

INSERT INTO app_versions (`version`, `notes`)
VALUES ('2.34.1', 'Entrega limpia con Salud API accionable: HTTP 429 se muestra como rate limit de alta severidad y el paquete excluye archivos no runtime.')
ON DUPLICATE KEY UPDATE
  `notes` = VALUES(`notes`),
  installed_at = UTC_TIMESTAMP();
