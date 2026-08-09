-- Phase B1.1: FIFO estricto, fencing de transporte y arbitraje de launchers.
-- No importa trabajo legacy ni habilita Cron V4.

ALTER TABLE queue_core_jobs
    MODIFY state ENUM('pending','claimed','running','retry_wait','waiting_oauth','completed','review','dead') NOT NULL DEFAULT 'pending';

SET @queue_core_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='queue_core_jobs' AND column_name='wait_refresh_version')=0,'ALTER TABLE queue_core_jobs ADD COLUMN wait_refresh_version BIGINT UNSIGNED NULL AFTER next_attempt_at','DO 1');
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;
SET @queue_core_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='queue_core_jobs' AND column_name='lease_heartbeat_at')=0,'ALTER TABLE queue_core_jobs ADD COLUMN lease_heartbeat_at DATETIME(3) NULL AFTER lease_expires_at','DO 1');
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

ALTER TABLE queue_core_attempts
    MODIFY outcome ENUM('started','completed','retry_wait','waiting_oauth','review','dead','lease_lost') NOT NULL DEFAULT 'started';

SET @queue_core_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='queue_core_attempts' AND column_name='dispatch_reserved_at')=0,'ALTER TABLE queue_core_attempts ADD COLUMN dispatch_reserved_at DATETIME(3) NULL AFTER dispatch_state','DO 1');
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;
SET @queue_core_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='queue_core_attempts' AND column_name='physical_http_started_at')=0,'ALTER TABLE queue_core_attempts ADD COLUMN physical_http_started_at DATETIME(3) NULL AFTER physical_http_calls','DO 1');
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;
SET @queue_core_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='queue_core_attempts' AND column_name='response_known_at')=0,'ALTER TABLE queue_core_attempts ADD COLUMN response_known_at DATETIME(3) NULL AFTER http_status','DO 1');
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;
SET @queue_core_sql=IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='queue_core_attempts' AND column_name='source_closed_at')=0,'ALTER TABLE queue_core_attempts ADD COLUMN source_closed_at DATETIME(3) NULL AFTER response_known_at','DO 1');
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;

ALTER TABLE queue_core_events
    MODIFY event_type ENUM('created','claimed','started','dispatch_reserved','physical_http_started','response_known','completed','retry_wait','waiting_oauth','review','dead','recovered','checkpoint','source_closed') NOT NULL;

CREATE TABLE IF NOT EXISTS queue_core_execution_leases (
    lease_key VARCHAR(60) NOT NULL,
    launcher ENUM('cron_v4','manual','test') NULL,
    owner_token VARCHAR(96) NULL,
    generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    heartbeat_at DATETIME(3) NULL,
    expires_at DATETIME(3) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (lease_key),
    KEY idx_queue_core_execution_expiry (expires_at,launcher)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO queue_core_execution_leases (lease_key,generation)
VALUES ('global',0);

-- Retira físicamente las autoridades del scheduler ponderado de B1. La
-- columna priority se conserva solo por compatibilidad de envelopes; nunca
-- participa del selector ejecutable.
SET @queue_core_sql=IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='queue_core_jobs' AND index_name='idx_queue_core_claim')>0,'ALTER TABLE queue_core_jobs DROP INDEX idx_queue_core_claim','DO 1');
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;
SET @queue_core_sql=IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='queue_core_jobs' AND index_name='idx_queue_core_fifo')=0,'ALTER TABLE queue_core_jobs ADD INDEX idx_queue_core_fifo (state,available_at,next_attempt_at,id)','DO 1');
PREPARE queue_core_stmt FROM @queue_core_sql;
EXECUTE queue_core_stmt;
DEALLOCATE PREPARE queue_core_stmt;
DROP TABLE IF EXISTS queue_core_account_fairness;
