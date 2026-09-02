-- ERP Meli 2.29.8 — recuperación segura de drift por finales de línea
-- Solo metadata/versionado. No modifica datos comerciales, Cron, OAuth ni configuración.

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.29.8', 0, 'system'),
  ('update.migration_line_ending_drift_recovery', '1', 0, 'update'),
  ('update.migration_drift_portable_hash_contract', 'lf_crlf_equivalence_only', 0, 'update')
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  is_encrypted = VALUES(is_encrypted),
  setting_group = VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.29.8', 'Actualizador adopta migraciones ya aplicadas cuando el drift corresponde exclusivamente a finales de línea LF/CRLF.')
ON DUPLICATE KEY UPDATE
  notes = VALUES(notes);
