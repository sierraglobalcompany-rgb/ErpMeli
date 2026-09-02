-- ERP Meli 2.29.6 — actualizador con transición segura de marcador previo
-- Solo metadata/versionado. No modifica datos comerciales, Cron, OAuth ni configuración de ritmo.

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.29.6', 0, 'system'),
  ('update.safe_transition_previous_marker', '1', 0, 'update'),
  ('update.safe_transition_contract', 'files_manifest_minimum_migration_then_marker', 0, 'update'),
  ('update.safe_transition_date', UTC_TIMESTAMP(), 0, 'update')
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  is_encrypted = VALUES(is_encrypted),
  setting_group = VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.29.6', 'Actualizador permite transición segura con marcador firmado anterior y migración pendiente.')
ON DUPLICATE KEY UPDATE
  notes = VALUES(notes);
