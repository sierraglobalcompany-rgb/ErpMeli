-- ERP Meli 2.29.11 - Canario V3 por evidencia activa
-- Solo metadata/versionado. No modifica datos comerciales, ownership, colas ni configuración remota.

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.29.11', 0, 'system'),
  ('cron_v3.canary.evidence_truth_ui', '1', 0, 'cron_v3'),
  ('cron_v3.canary.evidence_release', '2.29.11', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  is_encrypted = VALUES(is_encrypted),
  setting_group = VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.29.11', 'Canario V3 se muestra por evidencia activa de ownership, ciclos e HTTP reales; no exige Shadow vivo si el canario ya corre.')
ON DUPLICATE KEY UPDATE
  notes = VALUES(notes);
