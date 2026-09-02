-- ERP Meli 2.34.3
-- El freno de mano ofrece activación directa de lecturas para Cron V3
-- sin exigir canario clásico. Solo metadata/versionado.

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('app.version','2.34.3','system',0),
('emergency_handbrake.v3_direct_api_start','1','safety',0),
('emergency_handbrake.canary_and_direct_options','1','safety',0)
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  setting_group = VALUES(setting_group),
  is_encrypted = VALUES(is_encrypted);

INSERT INTO app_versions (`version`, `notes`)
VALUES ('2.34.3', 'Freno de mano compatible con Cron V3: permite activar lecturas sin canario manteniendo ML_WRITE_ENABLED=false y protecciones de ritmo.')
ON DUPLICATE KEY UPDATE
  `notes` = VALUES(`notes`),
  installed_at = UTC_TIMESTAMP();
