-- ERP Meli 2.34.0 - Snapshot único para Cron, ritmo, historial y salud.

SET @idx_cron_v3_operational_created = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema=DATABASE()
    AND table_name='cron_v3_operational_snapshots'
    AND index_name='idx_cron_v3_operational_created'
);

SET @sql_cron_v3_operational_created = IF(
  @idx_cron_v3_operational_created=0,
  "ALTER TABLE cron_v3_operational_snapshots ADD KEY idx_cron_v3_operational_created (created_at,id)",
  "SELECT 1"
);
PREPARE stmt_cron_v3_operational_created FROM @sql_cron_v3_operational_created;
EXECUTE stmt_cron_v3_operational_created;
DEALLOCATE PREPARE stmt_cron_v3_operational_created;

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('cron_v3.operational_truth_snapshot', '1', 0, 'cron_v3'),
  ('cron_v3.human_fifo_copy', '1', 0, 'cron_v3'),
  ('api_health.uses_cron_v3_operational_snapshot', '1', 0, 'api_health')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);
