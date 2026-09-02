-- ERP Meli 2.34.0 - Importación FIFO reentrante y sin dejar trabajo atrás.
-- No ejecuta colas ni consulta Mercado Libre.

SET @idx_cron_v3_work_source_ref = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema=DATABASE()
    AND table_name='cron_v3_work'
    AND index_name='idx_cron_v3_work_source_ref'
);

SET @sql_cron_v3_work_source_ref = IF(
  @idx_cron_v3_work_source_ref=0,
  "ALTER TABLE cron_v3_work ADD KEY idx_cron_v3_work_source_ref (source_ref)",
  "SELECT 1"
);
PREPARE stmt_cron_v3_work_source_ref FROM @sql_cron_v3_work_source_ref;
EXECUTE stmt_cron_v3_work_source_ref;
DEALLOCATE PREPARE stmt_cron_v3_work_source_ref;

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('cron_v3.legacy_import_mode', 'reentrant_fifo', 0, 'cron_v3'),
  ('cron_v3.legacy_import_cursor_only', '0', 0, 'cron_v3'),
  ('cron_v3.parking_does_not_block_fifo', '1', 0, 'cron_v3'),
  ('cron_v3.fifo_ready_claim_order', 'arrival_seq', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);
