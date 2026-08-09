-- B2.1: defaults de runtime V4. INSERT IGNORE conserva cualquier valor
-- elegido previamente por el operador y mantiene la migración idempotente.

INSERT IGNORE INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES
  ('queue_core.v4.cadence_seconds','60',0,'queue_core'),
  ('queue_core.v4.runtime_seconds','45',0,'queue_core'),
  ('queue_core.v4.safe_close_seconds','10',0,'queue_core'),
  ('queue_core.v4.max_remote_jobs','3',0,'queue_core'),
  ('queue_core.v4.safe_http_per_minute','3',0,'queue_core');
