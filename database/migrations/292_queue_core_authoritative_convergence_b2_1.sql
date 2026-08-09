-- B2.1: captura autoritativa acotada para convergencia exacta por identidad.
-- No activa motores ni modifica datos comerciales.

CREATE TABLE IF NOT EXISTS queue_core_readiness_captures (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  engine_generation BIGINT UNSIGNED NOT NULL,
  readiness_context_hash CHAR(64) NOT NULL,
  company_id BIGINT UNSIGNED NOT NULL,
  meli_account_id BIGINT UNSIGNED NOT NULL,
  discovery_job_id BIGINT UNSIGNED NOT NULL,
  window_from DATETIME(3) NOT NULL,
  window_to DATETIME(3) NOT NULL,
  page_offset INT UNSIGNED NOT NULL,
  response_count INT UNSIGNED NOT NULL,
  capture_hash CHAR(64) NOT NULL,
  complete TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_queue_core_readiness_capture_job
    (engine_generation,company_id,meli_account_id,discovery_job_id),
  KEY idx_queue_core_readiness_capture_window
    (engine_generation,company_id,meli_account_id,window_from,window_to,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_core_readiness_capture_items (
  capture_id BIGINT UNSIGNED NOT NULL,
  resource_id VARCHAR(191) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (capture_id,resource_id),
  KEY idx_queue_core_readiness_capture_resource (resource_id,capture_id),
  CONSTRAINT fk_queue_core_readiness_capture_item
    FOREIGN KEY (capture_id) REFERENCES queue_core_readiness_captures(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.36.0',0,'app')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),is_encrypted=0,setting_group='app';
