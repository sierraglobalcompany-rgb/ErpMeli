-- ERP Meli 2.30.0 - Corte operativo Cron V3. Metadata/config segura; no toca datos comerciales.

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.30.0', 0, 'system'),
  ('cron_v3.operational_mode', '1', 0, 'cron_v3'),
  ('cron_v3.v2_runtime_disabled', '1', 0, 'cron_v3'),
  ('cron_v3.rollback_enabled', '0', 0, 'cron_v3'),
  ('cron_v3.operational_release', '2.30.0', 0, 'cron_v3'),
  ('cron_v3.operational_phase', 'pending_cli_cutover', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('cron_v3.operational.local_command', '/usr/bin/php {ERP_ROOT}/jobs/cron_v3_local.php --runtime=45 --max-items=50', 0, 'cron_v3'),
  ('cron_v3.operational.remote_command', '/usr/bin/php {ERP_ROOT}/jobs/cron_v3_remote.php --delay=10 --runtime=35 --max-http=12', 0, 'cron_v3'),
  ('cron_v3.operational.v2_removed_command', '/usr/bin/php {ERP_ROOT}/jobs/process_sync_queue.php', 0, 'cron_v3');

INSERT INTO app_versions (version, notes)
VALUES ('2.30.0', 'Cron V3 queda como runtime operativo. V2 no procesa colas cuando el modo operativo está activo.')
ON DUPLICATE KEY UPDATE installed_at=UTC_TIMESTAMP();
