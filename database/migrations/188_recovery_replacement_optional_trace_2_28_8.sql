-- ERP Meli 2.28.8
-- Recuperación flexible de trazas opcionales para migraciones 185/186.
-- No modifica datos comerciales ni registra manualmente schema_migrations.

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('commercial_path.version', '2.28.8', 0, 'commercial_path'),
  ('operational_audit.last_certified_version', '2.28.8', 0, 'audit'),
  ('update.optional_source_failure_recovery', '1', 0, 'update'),
  ('update.replacement_policy_version', '2.28.8', 0, 'update')
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  is_encrypted = VALUES(is_encrypted),
  setting_group = VALUES(setting_group);
