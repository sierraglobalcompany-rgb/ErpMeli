CREATE TABLE IF NOT EXISTS system_process_metrics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    process_type VARCHAR(100) NOT NULL,
    run_token CHAR(32) NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    job_id BIGINT UNSIGNED NULL,
    status ENUM('running','complete','empty','deferred','paused','error') NOT NULL DEFAULT 'running',
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    api_request_count INT UNSIGNED NOT NULL DEFAULT 0,
    db_reconnect_count INT UNSIGNED NOT NULL DEFAULT 0,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    next_run_at DATETIME NULL,
    pause_reason VARCHAR(120) NULL,
    safe_message VARCHAR(500) NULL,
    measured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_process_metrics_type_date (process_type, measured_at),
    KEY idx_process_metrics_account_date (meli_account_id, measured_at),
    KEY idx_process_metrics_status_next (status, next_run_at),
    CONSTRAINT fk_process_metrics_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_query_plan_audits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    query_id VARCHAR(100) NOT NULL,
    access_type VARCHAR(30) NULL,
    key_used VARCHAR(190) NULL,
    estimated_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
    needs_review TINYINT(1) NOT NULL DEFAULT 0,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_query_plan_audits_query_date (query_id, checked_at),
    KEY idx_query_plan_audits_review (needs_review, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('dashboard.cache_seconds', '20', 'performance', 0),
('scheduler.metrics_enabled', '1', 'cron', 0),
('scheduler.fairness_enabled', '1', 'cron', 0),
('routes.metadata_enabled', '1', 'system', 0),
('dates.storage_timezone', 'UTC', 'system', 0),
('dates.range_contract', 'half_open', 'system', 0),
('performance.explain_audit_enabled', '1', 'performance', 0)
ON DUPLICATE KEY UPDATE
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.11.0', 'Arquitectura gradual, rangos UTC, read models y observabilidad de procesos');
