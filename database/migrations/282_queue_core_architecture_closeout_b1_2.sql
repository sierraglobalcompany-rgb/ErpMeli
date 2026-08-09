-- Phase B1.2: dominios FIFO explícitos y obligaciones locales sin fanout.
-- No importa backlog legacy, no habilita Cron V4 y no toca datos comerciales.

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='queue_core_jobs' AND column_name='queue_domain')=0,
  'ALTER TABLE queue_core_jobs ADD COLUMN queue_domain ENUM(''operational'',''manual'') NULL AFTER lane',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

UPDATE queue_core_jobs
SET queue_domain=CASE WHEN work_type='manual_exact' THEN 'manual' ELSE 'operational' END
WHERE queue_domain IS NULL;

ALTER TABLE queue_core_jobs
  MODIFY queue_domain ENUM('operational','manual') NOT NULL DEFAULT 'operational';

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema=DATABASE() AND table_name='queue_core_jobs' AND index_name='idx_queue_core_fifo_domain')=0,
  'ALTER TABLE queue_core_jobs ADD INDEX idx_queue_core_fifo_domain (queue_domain,state,available_at,next_attempt_at,id)',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

CREATE TABLE IF NOT EXISTS queue_core_pending_capabilities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  meli_account_id BIGINT UNSIGNED NOT NULL,
  resource_type VARCHAR(80) NOT NULL,
  resource_id VARCHAR(191) NOT NULL,
  capability_key VARCHAR(80) NOT NULL,
  state ENUM('pending_b2','resolved','review') NOT NULL DEFAULT 'pending_b2',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_queue_core_pending_capability
    (company_id,meli_account_id,resource_type,resource_id,capability_key),
  KEY idx_queue_core_pending_scope (company_id,meli_account_id,state,capability_key,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
