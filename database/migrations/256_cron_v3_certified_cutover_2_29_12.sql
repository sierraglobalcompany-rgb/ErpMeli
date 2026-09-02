-- ERP Meli 2.29.12 - Corte V3 de familias certificadas
-- Metadata y autorización segura de corte. No ejecuta handlers, no consulta
-- Mercado Libre, no modifica datos comerciales ni enciende escrituras remotas.

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES ('app.version', '2.29.12', 0, 'system')
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  is_encrypted = VALUES(is_encrypted),
  setting_group = VALUES(setting_group);

INSERT IGNORE INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('cron_v3.certified_cutover.enabled', '1', 0, 'cron_v3'),
  ('cron_v3.certified_cutover.release', '2.29.12', 0, 'cron_v3'),
  ('cron_v3.certified_cutover.local_types', 'financial_recalc', 0, 'cron_v3'),
  ('cron_v3.certified_cutover.remote_types', 'pack_exact,shipment_exact,sale_billing_capture', 0, 'cron_v3'),
  ('cron_v3.certified_cutover.minimum_canary_http', '25', 0, 'cron_v3'),
  ('cron_v3.certified_cutover.phase', 'armed', 0, 'cron_v3');

INSERT INTO app_versions (version, notes)
VALUES ('2.29.12', 'Cron V3 aplica automáticamente ownership de familias certificadas si el canario activo está sano; V3 completo permanece bloqueado.')
ON DUPLICATE KEY UPDATE
  notes = VALUES(notes);
