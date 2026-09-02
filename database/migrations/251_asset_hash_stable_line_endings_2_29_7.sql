-- ERP Meli 2.29.7 — hashes estables de assets en Hostinger/FTP
-- Solo metadata/versionado. No modifica datos comerciales, Cron, OAuth ni configuración.

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.29.7', 0, 'system'),
  ('update.asset_hash_line_endings_stable', '1', 0, 'update'),
  ('update.asset_hash_line_endings_contract', 'frontend_assets_lf_signed', 0, 'update')
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  is_encrypted = VALUES(is_encrypted),
  setting_group = VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.29.7', 'Firma assets frontend con finales LF para evitar falsos component_mismatch en Hostinger.')
ON DUPLICATE KEY UPDATE
  notes = VALUES(notes);
