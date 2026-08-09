-- B2.1: evidencia de release/capacidad/backup. Todo queda fail-closed y sin activar motores.

CREATE TABLE IF NOT EXISTS queue_core_release_evidence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  engine_generation BIGINT UNSIGNED NOT NULL,
  evidence_type ENUM('backup','capacity','manifest') NOT NULL,
  company_id BIGINT UNSIGNED NULL,
  meli_account_id BIGINT UNSIGNED NULL,
  status ENUM('pass','fail') NOT NULL,
  readiness_context_hash CHAR(64) NOT NULL,
  evidence_hash CHAR(64) NOT NULL,
  metrics_json JSON NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_queue_core_release_latest
    (engine_generation,evidence_type,company_id,meli_account_id,id),
  KEY idx_queue_core_release_expiry (expires_at,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.36.0',0,'app')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group='app';

INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES
  ('queue_core.v4.cadence_seconds','60',0,'queue_core'),
  ('queue_core.v4.runtime_seconds','45',0,'queue_core'),
  ('queue_core.v4.safe_close_seconds','10',0,'queue_core'),
  ('queue_core.v4.max_remote_jobs','3',0,'queue_core'),
  ('queue_core.v4.safe_http_per_minute','3',0,'queue_core')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),is_encrypted=0,setting_group='queue_core';
