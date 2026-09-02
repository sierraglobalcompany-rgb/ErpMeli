-- Queue V4 Clean: motor greenfield. No importa ni consulta estado operacional legacy.

CREATE TABLE IF NOT EXISTS queue_v4_clean_control (
    control_key VARCHAR(32) NOT NULL,
    engine_state ENUM('STOPPED','CERTIFIED','ACTIVE') NOT NULL DEFAULT 'STOPPED',
    readiness_state ENUM('NOT_READY','READY_TO_TEST','TESTING','CERTIFIED','FAILED') NOT NULL DEFAULT 'NOT_READY',
    scheduler_enabled TINYINT(1) NOT NULL DEFAULT 0,
    readiness_passed_accounts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    readiness_error_class VARCHAR(100) NULL,
    certified_at DATETIME(3) NULL,
    activated_at DATETIME(3) NULL,
    stopped_at DATETIME(3) NULL,
    last_scheduler_at DATETIME(3) NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (control_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO queue_v4_clean_control (control_key) VALUES ('primary');

CREATE TABLE IF NOT EXISTS queue_v4_clean_readiness_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    state ENUM('TESTING','CERTIFIED','FAILED') NOT NULL,
    expected_accounts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    passed_accounts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    failure_class VARCHAR(100) NULL,
    started_by BIGINT UNSIGNED NOT NULL,
    started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    finished_at DATETIME(3) NULL,
    PRIMARY KEY (id),
    KEY idx_qv4_readiness_state (state,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_v4_clean_readiness_accounts (
    readiness_run_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    outcome ENUM('PASS','FAIL') NOT NULL,
    failure_class VARCHAR(100) NULL,
    checked_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (readiness_run_id,company_id,meli_account_id),
    KEY idx_qv4_readiness_tenant (company_id,meli_account_id,checked_at),
    CONSTRAINT fk_qv4_readiness_run FOREIGN KEY (readiness_run_id)
        REFERENCES queue_v4_clean_readiness_runs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_v4_clean_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    job_type ENUM('fresh_orders_discovery','order_exact') NOT NULL,
    resource_id VARCHAR(191) NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    state ENUM('ready','running','waiting','review','dead','completed') NOT NULL DEFAULT 'ready',
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    lease_owner VARCHAR(96) NULL,
    lease_expires_at DATETIME(3) NULL,
    payload_json JSON NOT NULL,
    last_error_class VARCHAR(100) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    completed_at DATETIME(3) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qv4_job_identity (company_id,meli_account_id,job_type,idempotency_key),
    KEY idx_qv4_fifo (state,available_at,id),
    KEY idx_qv4_tenant (company_id,meli_account_id,state,id),
    KEY idx_qv4_lease (state,lease_expires_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_v4_clean_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    lease_owner VARCHAR(96) NOT NULL,
    outcome ENUM('running','completed','waiting','review','dead','lease_expired') NOT NULL DEFAULT 'running',
    error_class VARCHAR(100) NULL,
    started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    finished_at DATETIME(3) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qv4_attempt (job_id,lease_owner),
    KEY idx_qv4_attempt_tenant (company_id,meli_account_id,started_at),
    KEY idx_qv4_attempt_run (run_id,company_id,meli_account_id),
    CONSTRAINT fk_qv4_attempt_job FOREIGN KEY (job_id) REFERENCES queue_v4_clean_jobs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_v4_clean_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    launcher ENUM('scheduler','manual','test') NOT NULL,
    worker_ref VARCHAR(96) NOT NULL,
    status ENUM('running','completed','failed','stopped') NOT NULL DEFAULT 'running',
    jobs_claimed INT UNSIGNED NOT NULL DEFAULT 0,
    jobs_completed INT UNSIGNED NOT NULL DEFAULT 0,
    jobs_deferred INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    finished_at DATETIME(3) NULL,
    PRIMARY KEY (id),
    KEY idx_qv4_runs_status (status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_v4_clean_leases (
    lease_key VARCHAR(64) NOT NULL,
    owner_ref VARCHAR(96) NULL,
    acquired_at DATETIME(3) NULL,
    heartbeat_at DATETIME(3) NULL,
    expires_at DATETIME(3) NULL,
    PRIMARY KEY (lease_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO queue_v4_clean_leases (lease_key) VALUES ('scheduler');

CREATE TABLE IF NOT EXISTS queue_v4_clean_checkpoints (
    producer_key VARCHAR(64) NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    watermark_at DATETIME(3) NULL,
    next_due_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    last_job_id BIGINT UNSIGNED NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (producer_key,company_id,meli_account_id),
    KEY idx_qv4_checkpoint_due (producer_key,next_due_at,meli_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los launchers heredados quedan sin autoridad técnica. Los valores de
-- proceso/config.env siguen siendo verificados fail-closed por readiness.
INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES
  ('cron_v3.enabled','0',0,'cron_v3'),
  ('cron_v3.shadow_enabled','0',0,'cron_v3'),
  ('queue_core.v4.enabled','0',0,'queue_core')
ON DUPLICATE KEY UPDATE setting_value='0',is_encrypted=0;
