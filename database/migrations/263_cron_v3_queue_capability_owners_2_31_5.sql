-- ERP Meli 2.31.5 - Colas con dueño operativo explícito.
-- Metadata de matriz V3; no activa V2, no ejecuta handlers y no toca datos comerciales.

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.31.5', 0, 'system'),
  ('cron_v3.capability_matrix_required', '1', 0, 'cron_v3'),
  ('cron_v3.no_unowned_queue_allowed', '1', 0, 'cron_v3'),
  ('cron_v3.v2_sleep_when_operational', '1', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.31.5', 'Toda función queda declarada como V3 activo, V3 local, esperando capacidad, no soportada o histórico de solo diagnóstico.')
ON DUPLICATE KEY UPDATE installed_at=UTC_TIMESTAMP();
