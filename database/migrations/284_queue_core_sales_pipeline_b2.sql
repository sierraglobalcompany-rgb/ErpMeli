-- B2: graph durable order -> pack/shipment -> financial projection.
-- It does not activate V4, import legacy queues, or call Mercado Libre.

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='meli_orders' AND column_name='queue_snapshot_version')=0,
  'ALTER TABLE meli_orders ADD COLUMN queue_snapshot_version CHAR(64) NULL AFTER synced_at',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='meli_orders' AND column_name='queue_snapshot_at')=0,
  'ALTER TABLE meli_orders ADD COLUMN queue_snapshot_at DATETIME(3) NULL AFTER queue_snapshot_version',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

ALTER TABLE queue_core_pending_capabilities
  MODIFY state ENUM('pending_b2','materialized','resolved','review') NOT NULL DEFAULT 'pending_b2';

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='queue_core_pending_capabilities' AND column_name='input_version')=0,
  'ALTER TABLE queue_core_pending_capabilities ADD COLUMN input_version VARCHAR(191) NULL AFTER state',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='queue_core_pending_capabilities' AND column_name='lifecycle_generation')=0,
  'ALTER TABLE queue_core_pending_capabilities ADD COLUMN lifecycle_generation BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER input_version',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='queue_core_pending_capabilities' AND column_name='required_dependencies')=0,
  'ALTER TABLE queue_core_pending_capabilities ADD COLUMN required_dependencies INT UNSIGNED NOT NULL DEFAULT 0 AFTER lifecycle_generation',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='queue_core_pending_capabilities' AND column_name='completed_dependencies')=0,
  'ALTER TABLE queue_core_pending_capabilities ADD COLUMN completed_dependencies INT UNSIGNED NOT NULL DEFAULT 0 AFTER required_dependencies',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='queue_core_pending_capabilities' AND column_name='last_error_class')=0,
  'ALTER TABLE queue_core_pending_capabilities ADD COLUMN last_error_class VARCHAR(100) NULL AFTER completed_dependencies',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='queue_core_pending_capabilities' AND column_name='resolved_at')=0,
  'ALTER TABLE queue_core_pending_capabilities ADD COLUMN resolved_at DATETIME(3) NULL AFTER last_error_class',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

CREATE TABLE IF NOT EXISTS queue_core_capability_dependencies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  capability_id BIGINT UNSIGNED NOT NULL,
  lifecycle_generation BIGINT UNSIGNED NOT NULL,
  company_id BIGINT UNSIGNED NOT NULL,
  meli_account_id BIGINT UNSIGNED NOT NULL,
  dependency_key VARCHAR(191) NOT NULL,
  queue_job_id BIGINT UNSIGNED NOT NULL,
  state ENUM('pending','completed','review') NOT NULL DEFAULT 'pending',
  error_class VARCHAR(100) NULL,
  completed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_queue_core_capability_dependency (capability_id,lifecycle_generation,dependency_key),
  UNIQUE KEY uq_queue_core_capability_job (capability_id,lifecycle_generation,queue_job_id),
  KEY idx_queue_core_dependency_scope (company_id,meli_account_id,state,id),
  KEY idx_queue_core_dependency_job (queue_job_id,state),
  CONSTRAINT fk_queue_core_dependency_capability
    FOREIGN KEY (capability_id) REFERENCES queue_core_pending_capabilities(id),
  CONSTRAINT fk_queue_core_dependency_job
    FOREIGN KEY (queue_job_id) REFERENCES queue_core_jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema=DATABASE() AND table_name='queue_core_pending_capabilities'
     AND index_name='idx_queue_core_capability_lifecycle')=0,
  'ALTER TABLE queue_core_pending_capabilities ADD INDEX idx_queue_core_capability_lifecycle (state,capability_key,id)',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;
