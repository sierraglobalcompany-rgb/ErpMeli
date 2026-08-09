-- B2: ownership nativo de webhooks para Queue Core.
-- La migracion no importa backlog legacy, no activa V4 y no hace transporte.

CREATE TABLE IF NOT EXISTS queue_core_webhook_triggers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  meli_account_id BIGINT UNSIGNED NOT NULL,
  resource_type ENUM('order','pack','shipment') NOT NULL,
  resource_id VARCHAR(191) NOT NULL,
  state ENUM('pending','inflight','idle','quarantined') NOT NULL DEFAULT 'pending',
  desired_watermark BIGINT UNSIGNED NOT NULL DEFAULT 1,
  scheduled_watermark BIGINT UNSIGNED NOT NULL DEFAULT 0,
  completed_watermark BIGINT UNSIGNED NOT NULL DEFAULT 0,
  occurrence_count BIGINT UNSIGNED NOT NULL DEFAULT 1,
  inflight_job_id BIGINT UNSIGNED NULL,
  last_error_class VARCHAR(100) NULL,
  first_observed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_observed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  scheduled_at DATETIME(3) NULL,
  completed_at DATETIME(3) NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_queue_core_webhook_resource
    (company_id,meli_account_id,resource_type,resource_id),
  KEY idx_queue_core_webhook_pending
    (state,last_observed_at,resource_type,id),
  KEY idx_queue_core_webhook_inflight
    (state,inflight_job_id,company_id,meli_account_id),
  CONSTRAINT fk_queue_core_webhook_job
    FOREIGN KEY (inflight_job_id) REFERENCES queue_core_jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
