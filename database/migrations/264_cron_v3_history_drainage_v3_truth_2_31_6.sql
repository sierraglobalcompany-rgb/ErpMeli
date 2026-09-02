-- ERP Meli 2.31.6 - Historial V3 resoluble y drenaje medible.
-- Metadata de certificación; no modifica campañas, ventas, órdenes, pagos, packs, envíos, OAuth ni evidencia fiscal.

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.31.6', 0, 'system'),
  ('cron_v3.history_requires_actionable_error', '1', 0, 'cron_v3'),
  ('cron_v3.legacy_needs_diagnosis_grouped', '1', 0, 'cron_v3'),
  ('cron_v3.drainage_snapshot_truth', '1', 0, 'cron_v3'),
  ('cron_v3.operational_release', '2.31.6', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.31.6', 'Historial agrupa legacy sin diagnóstico, exige error accionable y mantiene drenaje medido por snapshots V3.')
ON DUPLICATE KEY UPDATE installed_at=UTC_TIMESTAMP();
