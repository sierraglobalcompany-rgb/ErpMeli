-- Phase B1: autoridad única para Queue Core y fundación Cron V4.
-- No importa ni modifica trabajo legacy.

CREATE TABLE IF NOT EXISTS queue_core_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    work_type VARCHAR(80) NOT NULL,
    resource_type VARCHAR(80) NOT NULL,
    resource_id VARCHAR(191) NULL,
    lane ENUM('fresh_orders','recovery','normal','historical_backfill','local') NOT NULL,
    priority INT NOT NULL DEFAULT 0,
    idempotency_key VARCHAR(191) NOT NULL,
    input_version VARCHAR(191) NOT NULL,
    state ENUM('pending','claimed','running','retry_wait','completed','review','dead') NOT NULL DEFAULT 'pending',
    dispatch_state ENUM('NOT_DISPATCHED','DISPATCHED_RESULT_UNCERTAIN','DISPATCHED_RESULT_KNOWN') NOT NULL DEFAULT 'NOT_DISPATCHED',
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
    available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    next_attempt_at DATETIME(3) NULL,
    claimed_at DATETIME(3) NULL,
    lease_owner VARCHAR(96) NULL,
    lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lease_expires_at DATETIME(3) NULL,
    started_at DATETIME(3) NULL,
    completed_at DATETIME(3) NULL,
    last_error_class VARCHAR(100) NULL,
    last_http_status SMALLINT UNSIGNED NULL,
    source VARCHAR(80) NOT NULL,
    source_ref VARCHAR(191) NULL,
    payload_json JSON NOT NULL,
    provenance_json JSON NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_queue_core_idempotency (company_id,meli_account_id,work_type,idempotency_key,input_version),
    KEY idx_queue_core_claim (lane,state,available_at,priority,id),
    KEY idx_queue_core_account_fairness (lane,company_id,meli_account_id,state,available_at,id),
    KEY idx_queue_core_lease (state,lease_expires_at,id),
    KEY idx_queue_core_resource (company_id,meli_account_id,resource_type,resource_id,work_type),
    KEY idx_queue_core_source (source,source_ref),
    KEY idx_queue_core_progress (state,completed_at,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS queue_core_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    lease_owner VARCHAR(96) NOT NULL,
    lease_generation BIGINT UNSIGNED NOT NULL,
    launcher ENUM('cron_v4','manual','test') NOT NULL,
    outcome ENUM('started','completed','retry_wait','review','dead','lease_lost') NOT NULL DEFAULT 'started',
    dispatch_state ENUM('NOT_DISPATCHED','DISPATCHED_RESULT_UNCERTAIN','DISPATCHED_RESULT_KNOWN') NOT NULL DEFAULT 'NOT_DISPATCHED',
    physical_http_calls INT UNSIGNED NOT NULL DEFAULT 0,
    resources_discovered INT UNSIGNED NOT NULL DEFAULT 0,
    resources_persisted INT UNSIGNED NOT NULL DEFAULT 0,
    error_class VARCHAR(100) NULL,
    http_status SMALLINT UNSIGNED NULL,
    started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    finished_at DATETIME(3) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_queue_core_attempt_fence (job_id,lease_owner,lease_generation),
    KEY idx_queue_core_attempt_tenant (company_id,meli_account_id,started_at),
    KEY idx_queue_core_attempt_progress (outcome,finished_at),
    CONSTRAINT fk_queue_core_attempt_job FOREIGN KEY (job_id) REFERENCES queue_core_jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_core_dispatch_journal (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    attempt_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    lease_owner VARCHAR(96) NOT NULL,
    lease_generation BIGINT UNSIGNED NOT NULL,
    method VARCHAR(10) NOT NULL,
    endpoint_key VARCHAR(120) NOT NULL,
    state ENUM('reserved','in_flight','response_known','cancelled_before_remote') NOT NULL,
    http_status SMALLINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_queue_core_dispatch_attempt (attempt_id),
    KEY idx_queue_core_dispatch_fence (job_id,lease_owner,lease_generation,state),
    CONSTRAINT fk_queue_core_dispatch_job FOREIGN KEY (job_id) REFERENCES queue_core_jobs(id),
    CONSTRAINT fk_queue_core_dispatch_attempt FOREIGN KEY (attempt_id) REFERENCES queue_core_attempts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_core_producer_checkpoints (
    producer_key VARCHAR(100) NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    watermark_at DATETIME(3) NULL,
    window_from DATETIME(3) NULL,
    window_to DATETIME(3) NULL,
    cursor_value VARCHAR(191) NULL,
    next_due_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_discovered INT UNSIGNED NOT NULL DEFAULT 0,
    last_enqueued INT UNSIGNED NOT NULL DEFAULT 0,
    last_error_class VARCHAR(100) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (producer_key,company_id,meli_account_id),
    KEY idx_queue_core_checkpoint_due (producer_key,next_due_at,meli_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_core_scheduler_state (
    scheduler_key VARCHAR(60) NOT NULL,
    cycle_position INT UNSIGNED NOT NULL DEFAULT 0,
    generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (scheduler_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO queue_core_scheduler_state (scheduler_key,cycle_position,generation)
VALUES ('default',0,0);

CREATE TABLE IF NOT EXISTS queue_core_account_fairness (
    lane ENUM('fresh_orders','recovery','normal','historical_backfill','local') NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    last_claimed_at DATETIME(3) NULL,
    claim_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (lane,company_id,meli_account_id),
    KEY idx_queue_core_fairness (lane,last_claimed_at,claim_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_core_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    lane VARCHAR(40) NOT NULL,
    event_type ENUM('created','claimed','started','dispatch_started','response_known','completed','retry_wait','review','dead','recovered','checkpoint') NOT NULL,
    event_count INT UNSIGNED NOT NULL DEFAULT 1,
    resources_count INT UNSIGNED NOT NULL DEFAULT 0,
    occurred_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_queue_core_events_time (occurred_at,event_type),
    KEY idx_queue_core_events_scope (company_id,meli_account_id,lane,occurred_at),
    KEY idx_queue_core_events_job (job_id,occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
