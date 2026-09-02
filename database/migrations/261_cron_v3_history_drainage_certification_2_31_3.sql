-- ERP Meli 2.31.3 - Historial resoluble y drenaje medido.
-- Metadata de certificación; no modifica órdenes, pagos, packs, envíos, OAuth ni evidencia fiscal.

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.31.3', 0, 'system'),
  ('cron_v3.history_legacy_grouping', '1', 0, 'cron_v3'),
  ('cron_v3.drainage_formula_required', '1', 0, 'cron_v3'),
  ('cron_v3.operational_release', '2.31.3', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.31.3', 'Historial de Cron separa legacy previo al corte, exige acciones exactas y publica fórmula de drenaje por snapshot.')
ON DUPLICATE KEY UPDATE installed_at=UTC_TIMESTAMP();
