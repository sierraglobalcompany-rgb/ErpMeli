-- ERP Meli 2.31.4 - Coherencia visual V3 y Salud API.
-- Metadata de UI/read-model; no modifica datos comerciales, OAuth, colas ni evidencia fiscal.

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.31.4', 0, 'system'),
  ('cron_v3.visual_truth_source', 'operational_snapshot', 0, 'cron_v3'),
  ('cron_v3.hide_shadow_canary_when_operational', '1', 0, 'cron_v3'),
  ('api_health.v3_operational_snapshot_enabled', '1', 0, 'api_health')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.31.4', 'Cron usa snapshot operativo V3 como primera verdad visual y Salud API carga fallback humano con asset vigente.')
ON DUPLICATE KEY UPDATE installed_at=UTC_TIMESTAMP();
