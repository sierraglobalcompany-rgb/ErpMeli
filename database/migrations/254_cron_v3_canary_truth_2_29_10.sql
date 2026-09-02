-- ERP Meli 2.29.10 — Canario V3 sin contradicciones
-- Solo metadata/versionado. No modifica datos comerciales, ownership, Cron V3 ni configuración Hostinger.

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.29.10', 0, 'system'),
  ('cron_v3.canary.truth_ui', '1', 0, 'cron_v3'),
  ('cron_v3.canary.shadow_superseded_by_active_evidence', '1', 0, 'cron_v3'),
  ('cron_v3.canary.active_release', '2.29.10', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  is_encrypted = VALUES(is_encrypted),
  setting_group = VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.29.10', 'Cron V3 muestra canario activo con evidencia real sin volver a bloquear por shadow histórico.')
ON DUPLICATE KEY UPDATE
  notes = VALUES(notes);
