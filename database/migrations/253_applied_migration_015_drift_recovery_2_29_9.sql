-- ERP Meli 2.29.9 — recuperación certificada de drift histórico en migración 015
-- Solo metadata/versionado. No modifica datos comerciales, Cron, OAuth ni configuración.

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.29.9', 0, 'system'),
  ('update.applied_migration_015_drift_recovery', '1', 0, 'update'),
  ('update.applied_migration_015_contract', 'schema_migrations_manifest_schema_no_sql', 0, 'update')
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  is_encrypted = VALUES(is_encrypted),
  setting_group = VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.29.9', 'Actualizador reconcilia drift histórico de la migración 015 ya aplicada mediante contrato de esquema certificado.')
ON DUPLICATE KEY UPDATE
  notes = VALUES(notes);
