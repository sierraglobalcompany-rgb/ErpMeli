-- B1.4: corte explícito entre motores. La instalación queda deshabilitada.
-- No importa backlog, no activa launchers y no consulta Mercado Libre.

CREATE TABLE IF NOT EXISTS queue_engine_control (
  control_key VARCHAR(32) NOT NULL,
  active_engine ENUM('disabled','v3','v4') NOT NULL DEFAULT 'disabled',
  generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
  changed_by VARCHAR(96) NOT NULL DEFAULT 'migration',
  changed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (control_key),
  KEY idx_queue_engine_active (active_engine,generation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO queue_engine_control
  (control_key,active_engine,generation,changed_by)
VALUES
  ('primary','disabled',0,'migration');

-- A+B usa esta generación para revivir de forma explícita un trabajo terminal
-- sin sustituir su identidad ni perder trazabilidad del intento anterior.
SET @queue_core_sql=IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=DATABASE() AND table_name='queue_core_jobs' AND column_name='revival_count')=0,
  'ALTER TABLE queue_core_jobs ADD COLUMN revival_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER max_attempts',
  'DO 1'
);
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

ALTER TABLE queue_core_events
  MODIFY event_type ENUM(
    'created','claimed','started','dispatch_reserved','physical_http_started',
    'response_known','completed','retry_wait','waiting_oauth','review','dead',
    'recovered','checkpoint','source_closed','revived'
  ) NOT NULL;
