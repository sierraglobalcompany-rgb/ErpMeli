-- Cron V3 core engine. Disabled by default; ownership must be enabled per work type.

CREATE TABLE IF NOT EXISTS cron_v3_work (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    work_type VARCHAR(80) NOT NULL,
    work_family VARCHAR(80) NOT NULL,
    lane ENUM('local','remote') NOT NULL,
    dedupe_key CHAR(64) NOT NULL,
    input_version CHAR(64) NOT NULL,
    source_ref VARCHAR(190) NULL,
    payload_json JSON NOT NULL,
    status ENUM('ready','leased','completed','deferred','review','dead') NOT NULL DEFAULT 'ready',
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    available_at DATETIME(3) NOT NULL,
    owner_token CHAR(64) NULL,
    lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lease_until DATETIME(3) NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_error_code VARCHAR(100) NULL,
    completed_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cron_v3_work_dedupe
        (work_type,company_id,meli_account_id,dedupe_key,input_version),
    KEY idx_cron_v3_work_claim
        (lane,status,available_at,company_id,meli_account_id,work_family,priority,id),
    KEY idx_cron_v3_work_lease (status,lease_until,id),
    KEY idx_cron_v3_work_scope (company_id,meli_account_id,work_type,status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_v3_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    owner_token CHAR(64) NOT NULL,
    lease_generation BIGINT UNSIGNED NOT NULL,
    lane ENUM('local','remote') NOT NULL,
    outcome ENUM('started','completed','deferred','review','dead','lease_lost') NOT NULL,
    logical_http_calls TINYINT UNSIGNED NOT NULL DEFAULT 0,
    physical_http_calls TINYINT UNSIGNED NOT NULL DEFAULT 0,
    known_response_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    result_json JSON NULL,
    started_at DATETIME(3) NOT NULL,
    finished_at DATETIME(3) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cron_v3_attempt_fence (work_id,owner_token,lease_generation),
    KEY idx_cron_v3_attempt_scope (company_id,meli_account_id,started_at,id),
    KEY idx_cron_v3_attempt_outcome (outcome,started_at,id),
    CONSTRAINT fk_cron_v3_attempt_work FOREIGN KEY (work_id)
        REFERENCES cron_v3_work (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_v3_queue_ownership (
    queue_key VARCHAR(80) NOT NULL,
    lane ENUM('local','remote') NOT NULL,
    owner_engine ENUM('v2','v3','disabled') NOT NULL DEFAULT 'disabled',
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    changed_by VARCHAR(100) NULL,
    changed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (queue_key),
    KEY idx_cron_v3_ownership_active (owner_engine,enabled,lane,queue_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_v3_rate_buckets (
    scope_key CHAR(64) NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    work_type VARCHAR(80) NOT NULL,
    window_started_at DATETIME(3) NOT NULL,
    window_seconds SMALLINT UNSIGNED NOT NULL,
    limit_count SMALLINT UNSIGNED NOT NULL,
    used_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    blocked_until DATETIME(3) NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (scope_key),
    KEY idx_cron_v3_rate_scope (company_id,meli_account_id,work_type),
    KEY idx_cron_v3_rate_blocked (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_v3_circuit_states (
    scope_key CHAR(64) NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    work_type VARCHAR(80) NOT NULL,
    state ENUM('closed','open','half_open') NOT NULL DEFAULT 'closed',
    failure_count INT UNSIGNED NOT NULL DEFAULT 0,
    open_until DATETIME(3) NULL,
    probe_owner CHAR(64) NULL,
    generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (scope_key),
    KEY idx_cron_v3_circuit_scope (company_id,meli_account_id,work_type),
    KEY idx_cron_v3_circuit_open (state,open_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_v3_snapshots (
    snapshot_key VARCHAR(120) NOT NULL,
    snapshot_type ENUM('fairness','shadow','run','health') NOT NULL,
    lane ENUM('local','remote') NOT NULL,
    company_id BIGINT UNSIGNED NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    payload_json JSON NOT NULL,
    generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    observed_at DATETIME(3) NOT NULL,
    PRIMARY KEY (snapshot_key),
    KEY idx_cron_v3_snapshot_scope (snapshot_type,lane,company_id,meli_account_id,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cron_v3_queue_ownership (queue_key,lane,owner_engine,enabled,changed_by)
VALUES
('notification_spool','local','disabled',0,'migration_241'),
('notification_normalize','local','disabled',0,'migration_241'),
('notification_backfill','local','disabled',0,'migration_241'),
('recurring_schedule','local','disabled',0,'migration_241'),
('financial_local_projection','local','disabled',0,'migration_241'),
('financial_recalc','local','disabled',0,'migration_241'),
('financial_gap_scan','local','disabled',0,'migration_241'),
('order_date_repair','local','disabled',0,'migration_241'),
('operational_maintenance','local','disabled',0,'migration_241'),
('monthly_report_maintenance','local','disabled',0,'migration_241'),
('cron_v3_health_snapshot','local','v3',1,'migration_241'),
('oauth_refresh','remote','disabled',0,'migration_241'),
('orders_search_page','remote','disabled',0,'migration_241'),
('order_exact','remote','disabled',0,'migration_241'),
('pack_exact','remote','disabled',0,'migration_241'),
('shipment_exact','remote','disabled',0,'migration_241'),
('sales_audit_page','remote','disabled',0,'migration_241'),
('sales_repair_exact','remote','disabled',0,'migration_241'),
('questions_search_page','remote','disabled',0,'migration_241'),
('question_exact','remote','disabled',0,'migration_241'),
('claims_search_page','remote','disabled',0,'migration_241'),
('claim_exact','remote','disabled',0,'migration_241'),
('sale_billing_capture','remote','disabled',0,'migration_241'),
('sales_fiscal_exact','remote','disabled',0,'migration_241'),
('items_search_page','remote','disabled',0,'migration_241'),
('item_exact','remote','disabled',0,'migration_241'),
('catalog_description_exact','remote','disabled',0,'migration_241'),
('module_logistics_exact','remote','disabled',0,'migration_241')
ON DUPLICATE KEY UPDATE lane=VALUES(lane);

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron_v3.enabled','0',0,'cron_v3'),
('cron_v3.shadow_enabled','0',0,'cron_v3'),
('cron_v3.remote_rate_limit','10',0,'cron_v3'),
('cron_v3.remote_one_logical_call','1',0,'cron_v3');
