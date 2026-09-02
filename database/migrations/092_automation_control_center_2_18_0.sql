CREATE TABLE IF NOT EXISTS system_work_queue_projection (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue_key VARCHAR(80) NOT NULL,
    source_table VARCHAR(100) NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    account_name VARCHAR(190) NULL,
    category VARCHAR(60) NOT NULL,
    human_label VARCHAR(190) NOT NULL,
    content_summary VARCHAR(500) NOT NULL,
    source_status VARCHAR(60) NOT NULL,
    display_status VARCHAR(60) NOT NULL,
    priority_tier TINYINT UNSIGNED NOT NULL,
    priority_score BIGINT NOT NULL DEFAULT 0,
    is_api_task TINYINT(1) NOT NULL DEFAULT 0,
    item_count INT UNSIGNED NOT NULL DEFAULT 0,
    progress_current INT UNSIGNED NOT NULL DEFAULT 0,
    progress_total INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_api_calls INT UNSIGNED NULL,
    estimated_seconds INT UNSIGNED NULL,
    created_at_source DATETIME NULL,
    next_eligible_at DATETIME NULL,
    started_at_source DATETIME NULL,
    finished_at_source DATETIME NULL,
    last_result VARCHAR(60) NULL,
    wait_reason VARCHAR(190) NULL,
    safe_error_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    source_updated_at DATETIME NULL,
    projected_at DATETIME NOT NULL,
    UNIQUE KEY uq_work_projection_source (queue_key,source_table,source_id),
    KEY idx_work_projection_order (display_status,priority_tier,created_at_source,id),
    KEY idx_work_projection_account (meli_account_id,display_status,priority_tier),
    KEY idx_work_projection_eligible (next_eligible_at,display_status),
    KEY idx_work_projection_source_updated (source_updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_work_queue_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_token VARCHAR(100) NOT NULL,
    origin VARCHAR(40) NOT NULL DEFAULT 'cron',
    status ENUM('running','completed','partial','failed','empty','skipped') NOT NULL DEFAULT 'running',
    selected_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    completed_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    deferred_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    failed_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    api_calls_used INT UNSIGNED NOT NULL DEFAULT 0,
    duration_ms INT UNSIGNED NULL,
    safe_summary VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_work_queue_run_token (run_token),
    KEY idx_work_queue_runs_recent (started_at,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_work_queue_run_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    work_queue_run_id BIGINT UNSIGNED NOT NULL,
    projection_id BIGINT UNSIGNED NULL,
    queue_key VARCHAR(80) NOT NULL,
    source_table VARCHAR(100) NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    result ENUM('selected','started','deferred','completed','partial','failed','retried','skipped') NOT NULL DEFAULT 'selected',
    position_no SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    estimated_api_calls INT UNSIGNED NULL,
    estimated_seconds INT UNSIGNED NULL,
    actual_api_calls INT UNSIGNED NULL,
    duration_ms INT UNSIGNED NULL,
    safe_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_work_run_items_run (work_queue_run_id,position_no),
    KEY idx_work_run_items_source (queue_key,source_id,result),
    CONSTRAINT fk_work_run_items_run FOREIGN KEY (work_queue_run_id) REFERENCES system_work_queue_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_work_run_items_projection FOREIGN KEY (projection_id) REFERENCES system_work_queue_projection(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('automation.projection_refresh_seconds','30','automation',0),
('automation.queue_page_size','50','automation',0),
('automation.history_page_size','50','automation',0),
('automation.max_next_tasks','3','automation',0),
('automation.max_next_api_tasks','1','automation',0),
('automation.eta_min_samples','3','automation',0),
('automation.oldest_first_within_tier','1','automation',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.18.0','Centro de Automatización y proyección unificada de colas');
