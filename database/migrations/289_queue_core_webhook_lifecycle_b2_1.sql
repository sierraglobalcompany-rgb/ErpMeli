-- B2.1: lifecycle durable del spool webhook y rollback verificable.
-- No activa V4, no importa backlog y no realiza transporte remoto.

CREATE TABLE IF NOT EXISTS queue_core_webhook_spool_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  spool_key CHAR(64) NOT NULL,
  payload_sha256 CHAR(64) NOT NULL,
  payload_json JSON NOT NULL,
  lifecycle ENUM('received','materializing','materialized','resolved','archived')
    NOT NULL DEFAULT 'received',
  company_id BIGINT UNSIGNED NULL,
  meli_account_id BIGINT UNSIGNED NULL,
  trigger_id BIGINT UNSIGNED NULL,
  observation_generation BIGINT UNSIGNED NULL,
  materialization_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error_class VARCHAR(100) NULL,
  received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  materializing_at DATETIME(3) NULL,
  materialized_at DATETIME(3) NULL,
  resolved_at DATETIME(3) NULL,
  archived_at DATETIME(3) NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
    ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_queue_core_webhook_spool_key (spool_key),
  KEY idx_queue_core_webhook_spool_lifecycle (lifecycle,updated_at,id),
  KEY idx_queue_core_webhook_spool_trigger
    (trigger_id,company_id,meli_account_id,observation_generation,lifecycle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
