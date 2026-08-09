-- B2.1: recibos autoritativos y modo de preparacion cercado.
-- No activa V4, no crea trabajo comercial y no consulta Mercado Libre.

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='queue_engine_control' AND column_name='readiness_mode')=0,
  "ALTER TABLE queue_engine_control ADD COLUMN readiness_mode ENUM('idle','preparing') NOT NULL DEFAULT 'idle' AFTER active_engine",
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='queue_engine_control' AND column_name='readiness_context_hash')=0,
  'ALTER TABLE queue_engine_control ADD COLUMN readiness_context_hash CHAR(64) NULL AFTER readiness_mode',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='queue_core_readiness_receipts' AND column_name='context_hash')=0,
  'ALTER TABLE queue_core_readiness_receipts ADD COLUMN context_hash CHAR(64) NULL AFTER status',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema=DATABASE() AND table_name='queue_core_readiness_receipts' AND index_name='idx_queue_core_receipt_latest')=0,
  'ALTER TABLE queue_core_readiness_receipts ADD INDEX idx_queue_core_receipt_latest (engine_generation,receipt_type,company_id,meli_account_id,id)',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

UPDATE queue_engine_control
SET readiness_mode='idle',readiness_context_hash=NULL
WHERE control_key='primary' AND active_engine<>'disabled';

