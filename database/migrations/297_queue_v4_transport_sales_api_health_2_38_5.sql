-- Queue V4 Clean 2.38.5: physical transport authority, bounded sales audit
-- and local API-health maintenance. No Mercado Libre business writes.
-- Every ALTER is crash-reentrant: an interrupted migration may be retried.

-- -------------------------------------------------------------------------
-- Queue V4 physical-dispatch journal on the immutable attempt row.
-- -------------------------------------------------------------------------
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_jobs' AND COLUMN_NAME='lease_generation')=0,
 'ALTER TABLE queue_v4_clean_jobs ADD COLUMN lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER lease_owner','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_attempts' AND COLUMN_NAME='lease_generation')=0,
 'ALTER TABLE queue_v4_clean_attempts ADD COLUMN lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER lease_owner','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_attempts' AND COLUMN_NAME='dispatch_state')=0,
 'ALTER TABLE queue_v4_clean_attempts ADD COLUMN dispatch_state ENUM(''NOT_DISPATCHED'',''PHYSICAL_STARTED'',''RESPONSE_KNOWN'') NOT NULL DEFAULT ''NOT_DISPATCHED'' AFTER error_class','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_attempts' AND COLUMN_NAME='transport_method')=0,
 'ALTER TABLE queue_v4_clean_attempts ADD COLUMN transport_method VARCHAR(8) NULL AFTER dispatch_state','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_attempts' AND COLUMN_NAME='endpoint_key')=0,
 'ALTER TABLE queue_v4_clean_attempts ADD COLUMN endpoint_key VARCHAR(100) NULL AFTER transport_method','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_attempts' AND COLUMN_NAME='physical_http_calls')=0,
 'ALTER TABLE queue_v4_clean_attempts ADD COLUMN physical_http_calls TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER endpoint_key','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_attempts' AND COLUMN_NAME='physical_started_at')=0,
 'ALTER TABLE queue_v4_clean_attempts ADD COLUMN physical_started_at DATETIME(3) NULL AFTER physical_http_calls','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_attempts' AND COLUMN_NAME='http_status')=0,
 'ALTER TABLE queue_v4_clean_attempts ADD COLUMN http_status SMALLINT UNSIGNED NULL AFTER physical_started_at','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_attempts' AND COLUMN_NAME='response_known_at')=0,
 'ALTER TABLE queue_v4_clean_attempts ADD COLUMN response_known_at DATETIME(3) NULL AFTER http_status','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_attempts' AND COLUMN_NAME='source_closed_at')=0,
 'ALTER TABLE queue_v4_clean_attempts ADD COLUMN source_closed_at DATETIME(3) NULL AFTER response_known_at','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_attempts' AND INDEX_NAME='idx_qv4_attempt_dispatch')=0,
 'ALTER TABLE queue_v4_clean_attempts ADD KEY idx_qv4_attempt_dispatch (company_id,meli_account_id,dispatch_state,started_at)','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Composite keys make job -> attempt -> recovery tenant provenance indivisible.
SET @q := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_jobs' AND INDEX_NAME='uq_qv4_job_tenant_id')=0,
 'ALTER TABLE queue_v4_clean_jobs ADD UNIQUE KEY uq_qv4_job_tenant_id (id,company_id,meli_account_id)','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_attempts' AND INDEX_NAME='uq_qv4_attempt_tenant_id')=0,
 'ALTER TABLE queue_v4_clean_attempts ADD UNIQUE KEY uq_qv4_attempt_tenant_id (id,job_id,company_id,meli_account_id)','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS queue_v4_clean_recovery_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    attempt_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    recovery_class VARCHAR(100) NOT NULL,
    observed_dispatch_state ENUM('NOT_DISPATCHED','PHYSICAL_STARTED','RESPONSE_KNOWN') NOT NULL,
    recovered_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_qv4_recovery_attempt (job_id,attempt_id),
    KEY idx_qv4_recovery_tenant (company_id,meli_account_id,recovered_at),
    CONSTRAINT fk_qv4_recovery_job_tenant FOREIGN KEY (job_id,company_id,meli_account_id)
        REFERENCES queue_v4_clean_jobs(id,company_id,meli_account_id),
    CONSTRAINT fk_qv4_recovery_attempt_tenant FOREIGN KEY (attempt_id,job_id,company_id,meli_account_id)
        REFERENCES queue_v4_clean_attempts(id,job_id,company_id,meli_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One immutable row per physical transport boundary across OAuth, orders and
-- sales audit. API Health reads this journal instead of inferring calls from
-- mutable job/operation counters.
CREATE TABLE IF NOT EXISTS queue_v4_clean_transport_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    source_kind ENUM('queue','oauth','sales_audit') NOT NULL,
    work_id BIGINT UNSIGNED NOT NULL,
    attempt_id BIGINT UNSIGNED NULL,
    lease_generation BIGINT UNSIGNED NOT NULL,
    request_id VARCHAR(64) NOT NULL,
    method VARCHAR(10) NOT NULL,
    endpoint_key VARCHAR(100) NOT NULL,
    dispatch_state ENUM('PHYSICAL_STARTED','RESPONSE_KNOWN') NOT NULL DEFAULT 'PHYSICAL_STARTED',
    physical_started_at DATETIME(3) NOT NULL,
    response_known_at DATETIME(3) NULL,
    http_status SMALLINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_queue_v4_transport_request (source_kind,request_id),
    KEY idx_queue_v4_transport_tenant_time (company_id,meli_account_id,physical_started_at,id),
    KEY idx_queue_v4_transport_work (source_kind,work_id,lease_generation,id),
    CONSTRAINT fk_queue_v4_transport_tenant FOREIGN KEY (company_id,meli_account_id)
        REFERENCES meli_accounts(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- Sales audit becomes a first-class tenant-scoped Queue V4 stage.
-- -------------------------------------------------------------------------
UPDATE sync_sales_audit_runs r
INNER JOIN meli_accounts a ON a.id=r.meli_account_id
SET r.company_id=a.company_id
WHERE r.company_id IS NULL;

UPDATE sync_sales_audit_jobs j
INNER JOIN sync_sales_audit_runs r
  ON r.id=j.sync_sales_audit_run_id AND r.meli_account_id=j.meli_account_id
SET j.company_id=r.company_id
WHERE j.company_id IS NULL;

SET @q := IF((SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sync_sales_audit_runs' AND COLUMN_NAME='company_id')='YES',
 'ALTER TABLE sync_sales_audit_runs MODIFY company_id BIGINT UNSIGNED NOT NULL','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sync_sales_audit_jobs' AND COLUMN_NAME='company_id')='YES',
 'ALTER TABLE sync_sales_audit_jobs MODIFY company_id BIGINT UNSIGNED NOT NULL','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sync_sales_audit_runs' AND INDEX_NAME='uq_sales_audit_run_tenant_id')=0,
 'ALTER TABLE sync_sales_audit_runs ADD UNIQUE KEY uq_sales_audit_run_tenant_id (id,company_id,meli_account_id)','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sync_sales_audit_jobs' AND INDEX_NAME='idx_sales_audit_jobs_tenant_due')=0,
 'ALTER TABLE sync_sales_audit_jobs ADD KEY idx_sales_audit_jobs_tenant_due (company_id,meli_account_id,status,next_run_at,id)','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sync_sales_audit_jobs' AND COLUMN_NAME='remote_dispatch_state')=0,
 'ALTER TABLE sync_sales_audit_jobs ADD COLUMN remote_dispatch_state ENUM(''NOT_DISPATCHED'',''PHYSICAL_STARTED'',''RESPONSE_KNOWN'') NOT NULL DEFAULT ''NOT_DISPATCHED'' AFTER last_http_status','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sync_sales_audit_jobs' AND COLUMN_NAME='remote_dispatched_at')=0,
 'ALTER TABLE sync_sales_audit_jobs ADD COLUMN remote_dispatched_at DATETIME(3) NULL AFTER remote_dispatch_state','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sync_sales_audit_jobs' AND COLUMN_NAME='response_known_at')=0,
 'ALTER TABLE sync_sales_audit_jobs ADD COLUMN response_known_at DATETIME(3) NULL AFTER remote_dispatched_at','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sync_sales_audit_jobs' AND COLUMN_NAME='last_request_id')=0,
 'ALTER TABLE sync_sales_audit_jobs ADD COLUMN last_request_id VARCHAR(64) NULL AFTER response_known_at','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := IF((SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='sync_sales_audit_runs' AND CONSTRAINT_NAME='fk_sales_audit_run_tenant')=0,
 'ALTER TABLE sync_sales_audit_runs ADD CONSTRAINT fk_sales_audit_run_tenant FOREIGN KEY (company_id,meli_account_id) REFERENCES meli_accounts(company_id,id)','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q := IF((SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='sync_sales_audit_jobs' AND CONSTRAINT_NAME='fk_sales_audit_job_run_tenant')=0,
 'ALTER TABLE sync_sales_audit_jobs ADD CONSTRAINT fk_sales_audit_job_run_tenant FOREIGN KEY (sync_sales_audit_run_id,company_id,meli_account_id) REFERENCES sync_sales_audit_runs(id,company_id,meli_account_id)','DO 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- -------------------------------------------------------------------------
-- Browser-safe API Health read model. The web never aggregates raw logs.
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_incident_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    incident_key CHAR(64) NOT NULL,
    scope_key VARCHAR(80) NOT NULL,
    scope_kind ENUM('application','company','account') NOT NULL,
    company_id BIGINT UNSIGNED NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    outcome_class VARCHAR(40) NOT NULL,
    method VARCHAR(10) NOT NULL,
    endpoint_path VARCHAR(255) NOT NULL,
    http_status SMALLINT UNSIGNED NULL,
    error_type VARCHAR(80) NULL,
    error_code VARCHAR(120) NULL,
    safe_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    reached_remote TINYINT(1) NOT NULL DEFAULT 0,
    actionable TINYINT(1) NOT NULL DEFAULT 0,
    risk_signal TINYINT(1) NOT NULL DEFAULT 0,
    first_seen_at DATETIME(3) NOT NULL,
    last_seen_at DATETIME(3) NOT NULL,
    repetitions BIGINT UNSIGNED NOT NULL DEFAULT 1,
    last_log_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_incident_group_scope (incident_key,scope_key,outcome_class,method),
    KEY idx_api_incident_group_page (last_seen_at,id),
    KEY idx_api_incident_group_account (meli_account_id,last_seen_at,id),
    KEY idx_api_incident_group_company (company_id,last_seen_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_incident_materializer_state (
    singleton_id TINYINT UNSIGNED NOT NULL,
    last_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    heartbeat_at DATETIME(3) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (singleton_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO api_incident_materializer_state(singleton_id,last_log_id) VALUES (1,0);
