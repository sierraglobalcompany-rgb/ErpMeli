-- ERP Meli 2.31.1 - Cron V3 como verdad visual principal.
-- Metadata y contratos de lectura; no toca datos comerciales ni activa escrituras remotas.

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.31.1', 0, 'system'),
  ('cron_v3.operational_snapshot_primary', '1', 0, 'cron_v3'),
  ('cron_v3.legacy_overview_collapsed', '1', 0, 'cron_v3'),
  ('cron_v3.shadow_canary_first_level_hidden_when_operational', '1', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.31.1', 'Cron y Salud API leen primero el snapshot operativo V3; Shadow/Canario quedan como evidencia histórica.')
ON DUPLICATE KEY UPDATE installed_at=UTC_TIMESTAMP();
