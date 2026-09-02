-- ERP Meli 2.33.0 - Cron V3 FIFO por llegada y parking lot.
-- Metadata/estructura técnica. No toca órdenes, pagos, packs, envíos, OAuth,
-- cierres ni evidencia fiscal.

SET @cron_v3_work_status_column = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema=DATABASE()
    AND table_name='cron_v3_work'
    AND column_name='status'
);

SET @sql_cron_v3_work_status = IF(
  @cron_v3_work_status_column=1,
  "ALTER TABLE cron_v3_work MODIFY COLUMN status ENUM('ready','leased','completed','deferred','waiting_capability','waiting_identity','waiting_rate','waiting_budget','waiting_api','review','dead') NOT NULL DEFAULT 'ready'",
  "SELECT 1"
);
PREPARE stmt_cron_v3_work_status FROM @sql_cron_v3_work_status;
EXECUTE stmt_cron_v3_work_status;
DEALLOCATE PREPARE stmt_cron_v3_work_status;

SET @idx_cron_v3_work_fifo = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema=DATABASE()
    AND table_name='cron_v3_work'
    AND index_name='idx_cron_v3_work_fifo_claim'
);

SET @sql_cron_v3_work_fifo = IF(
  @idx_cron_v3_work_fifo=0,
  "ALTER TABLE cron_v3_work ADD KEY idx_cron_v3_work_fifo_claim (lane,status,available_at,id)",
  "SELECT 1"
);
PREPARE stmt_cron_v3_work_fifo FROM @sql_cron_v3_work_fifo;
EXECUTE stmt_cron_v3_work_fifo;
DEALLOCATE PREPARE stmt_cron_v3_work_fifo;

CREATE TABLE IF NOT EXISTS cron_v3_parked_work_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  work_id BIGINT UNSIGNED NULL,
  company_id BIGINT UNSIGNED NOT NULL,
  meli_account_id BIGINT UNSIGNED NOT NULL,
  work_type VARCHAR(80) NOT NULL,
  source_ref VARCHAR(190) NULL,
  parking_state VARCHAR(40) NOT NULL,
  reason_code VARCHAR(100) NOT NULL,
  safe_message VARCHAR(255) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_cron_v3_parked_scope (company_id,meli_account_id,parking_state,work_type,id),
  KEY idx_cron_v3_parked_work (work_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.33.0', 0, 'system'),
  ('cron_v3.arrival_queue_mode', 'fifo', 0, 'cron_v3'),
  ('cron_v3.parking_lot_enabled', '1', 0, 'cron_v3'),
  ('cron_v3.operational_release', '2.33.0', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);

INSERT INTO cron_v3_queue_ownership
  (queue_key,lane,owner_engine,enabled,changed_by,changed_at)
VALUES
  ('notification_identity_repair','local','disabled',0,'migration_266',UTC_TIMESTAMP(3))
ON DUPLICATE KEY UPDATE
  lane=VALUES(lane),
  changed_by=VALUES(changed_by),
  changed_at=VALUES(changed_at);

INSERT INTO cron_v3_capability_matrix
  (queue_key,family,lane,capability_state,work_types_json,reason,updated_at)
VALUES
  ('notification_identity_repair','notifications','local','legacy_readonly_backlog',
   JSON_ARRAY('notification_identity_repair'),
   'Registros de notificación parqueados por falta de identidad segura; se diagnostican localmente y no bloquean FIFO.',
   UTC_TIMESTAMP(3))
ON DUPLICATE KEY UPDATE
  family=VALUES(family),
  lane=VALUES(lane),
  capability_state=VALUES(capability_state),
  work_types_json=VALUES(work_types_json),
  reason=VALUES(reason),
  updated_at=VALUES(updated_at);

INSERT INTO app_versions (version, notes)
VALUES ('2.33.0', 'Cron V3 reclama por llegada FIFO y parquea trabajos no ejecutables sin bloquear la fila.')
ON DUPLICATE KEY UPDATE installed_at=UTC_TIMESTAMP();
