-- B2: observabilidad acotada y autoridades de preflight/canario.
-- Todo queda deshabilitado. No reclama trabajo ni activa motores.

CREATE TABLE IF NOT EXISTS queue_core_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  engine_generation BIGINT UNSIGNED NOT NULL,
  launcher VARCHAR(40) NOT NULL,
  worker_ref CHAR(64) NOT NULL,
  status ENUM('running','completed','stopped','failed','lease_lost') NOT NULL DEFAULT 'running',
  close_reason VARCHAR(100) NULL,
  phase VARCHAR(60) NULL,
  jobs_claimed INT UNSIGNED NOT NULL DEFAULT 0,
  physical_http_calls INT UNSIGNED NOT NULL DEFAULT 0,
  known_responses INT UNSIGNED NOT NULL DEFAULT 0,
  resources_persisted INT UNSIGNED NOT NULL DEFAULT 0,
  started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  finished_at DATETIME(3) NULL,
  last_heartbeat_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_queue_core_runs_time (started_at,status),
  KEY idx_queue_core_runs_generation (engine_generation,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE queue_core_attempts
  MODIFY launcher ENUM('cron_v4','canary_v4','manual','test') NOT NULL;

ALTER TABLE queue_core_execution_leases
  MODIFY launcher ENUM('cron_v4','canary_v4','manual','test') NULL;

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='queue_core_attempts' AND column_name='run_id')=0,
  'ALTER TABLE queue_core_attempts ADD COLUMN run_id BIGINT UNSIGNED NULL AFTER id',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema=DATABASE() AND table_name='queue_core_attempts' AND index_name='idx_queue_core_attempt_run')=0,
  'ALTER TABLE queue_core_attempts ADD INDEX idx_queue_core_attempt_run (run_id,started_at,id)',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

CREATE TABLE IF NOT EXISTS queue_core_readiness_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  engine_generation BIGINT UNSIGNED NOT NULL,
  receipt_type ENUM('preflight','canary','convergence') NOT NULL,
  company_id BIGINT UNSIGNED NULL,
  meli_account_id BIGINT UNSIGNED NULL,
  status ENUM('pass','fail') NOT NULL,
  evidence_hash CHAR(64) NOT NULL,
  metrics_json JSON NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_queue_core_receipt_gate (engine_generation,receipt_type,status,expires_at,id),
  KEY idx_queue_core_receipt_scope (company_id,meli_account_id,receipt_type,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_core_health_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  engine_generation BIGINT UNSIGNED NOT NULL,
  health_state ENUM('GREEN','DEGRADED','RED') NOT NULL,
  account_count INT UNSIGNED NOT NULL DEFAULT 0,
  eligible_depth INT UNSIGNED NOT NULL DEFAULT 0,
  waiting_oauth INT UNSIGNED NOT NULL DEFAULT 0,
  waiting_dependency INT UNSIGNED NOT NULL DEFAULT 0,
  review_depth INT UNSIGNED NOT NULL DEFAULT 0,
  dead_depth INT UNSIGNED NOT NULL DEFAULT 0,
  oldest_eligible_seconds INT UNSIGNED NULL,
  freshness_lag_seconds INT UNSIGNED NULL,
  reasons_json JSON NOT NULL,
  generated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_queue_core_health_latest (engine_generation,generated_at),
  KEY idx_queue_core_health_state (health_state,generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_core_feature_flags (
  feature_key VARCHAR(80) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  hard_cap INT UNSIGNED NULL,
  generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (feature_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO queue_core_feature_flags(feature_key,enabled,hard_cap) VALUES
  ('fresh_producer',0,NULL),
  ('webhook_producer',0,NULL),
  ('pack_shipment_followups',0,NULL),
  ('remote_financial',0,NULL),
  ('historical_importer',0,50);

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema=DATABASE() AND table_name='queue_core_attempts'
     AND index_name='idx_queue_core_attempt_started')=0,
  'ALTER TABLE queue_core_attempts ADD INDEX idx_queue_core_attempt_started (started_at,outcome,job_id)',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema=DATABASE() AND table_name='queue_core_jobs'
     AND index_name='idx_queue_core_health_depth')=0,
  'ALTER TABLE queue_core_jobs ADD INDEX idx_queue_core_health_depth (queue_domain,state,lane,company_id,meli_account_id,created_at,id)',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;
