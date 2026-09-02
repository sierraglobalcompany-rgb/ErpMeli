ALTER TABLE meli_orders
    ADD COLUMN IF NOT EXISTS enrichment_status ENUM('basic','enrichment_pending','complete','partial','error') NOT NULL DEFAULT 'basic' AFTER status,
    ADD COLUMN IF NOT EXISTS enrichment_error_message VARCHAR(500) NULL AFTER enrichment_status,
    ADD COLUMN IF NOT EXISTS enriched_at DATETIME NULL AFTER enrichment_error_message;

CREATE TABLE IF NOT EXISTS order_resource_enrichment_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    resource_type ENUM('pack','shipment') NOT NULL,
    external_resource_id VARCHAR(80) NOT NULL,
    status ENUM('pending','retry','running','complete','error','paused','cancelled') NOT NULL DEFAULT 'pending',
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_run_at DATETIME NOT NULL,
    lock_token CHAR(32) NULL,
    locked_at DATETIME NULL,
    last_started_at DATETIME NULL,
    last_processed_at DATETIME NULL,
    completed_at DATETIME NULL,
    last_error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_order_enrichment_resource (meli_account_id, resource_type, external_resource_id),
    KEY idx_order_enrichment_due (status, next_run_at, priority),
    KEY idx_order_enrichment_lock (locked_at, lock_token),
    KEY idx_order_enrichment_order (meli_order_id, status),
    CONSTRAINT fk_order_enrichment_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_enrichment_order FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_resource_enrichment_job_orders (
    order_resource_enrichment_job_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (order_resource_enrichment_job_id, meli_order_id),
    KEY idx_order_enrichment_map_order (meli_order_id),
    CONSTRAINT fk_order_enrichment_map_job FOREIGN KEY (order_resource_enrichment_job_id) REFERENCES order_resource_enrichment_jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_enrichment_map_order FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_cron_run_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cron_health_check_id BIGINT UNSIGNED NULL,
    run_token CHAR(32) NOT NULL,
    step_name VARCHAR(100) NOT NULL,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    status ENUM('running','complete','empty','deferred','error') NOT NULL DEFAULT 'running',
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    stop_reason VARCHAR(120) NULL,
    safe_message VARCHAR(500) NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cron_steps_run (run_token, priority, id),
    KEY idx_cron_steps_status (status, started_at),
    KEY idx_cron_steps_health (cron_health_check_id),
    CONSTRAINT fk_cron_steps_health FOREIGN KEY (cron_health_check_id) REFERENCES cron_health_checks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_item_sync_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    search_mode ENUM('offset','scan') NOT NULL DEFAULT 'offset',
    phase ENUM('discovering','details','complete','partial','error','cancelled') NOT NULL DEFAULT 'discovering',
    cursor_value TEXT NULL,
    offset_value INT UNSIGNED NOT NULL DEFAULT 0,
    discovered_count INT UNSIGNED NOT NULL DEFAULT 0,
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_run_at DATETIME NOT NULL,
    lock_token CHAR(32) NULL,
    locked_at DATETIME NULL,
    last_error_message VARCHAR(500) NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_item_sync_jobs_due (phase, next_run_at),
    KEY idx_item_sync_jobs_account (meli_account_id, phase, created_at),
    CONSTRAINT fk_item_sync_jobs_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_item_sync_job_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_item_sync_job_id BIGINT UNSIGNED NOT NULL,
    external_item_id VARCHAR(40) NOT NULL,
    status ENUM('pending','running','complete','error','skipped') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error_message VARCHAR(500) NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_item_sync_job_external (meli_item_sync_job_id, external_item_id),
    KEY idx_item_sync_job_items_due (meli_item_sync_job_id, status, id),
    CONSTRAINT fk_item_sync_job_items_job FOREIGN KEY (meli_item_sync_job_id) REFERENCES meli_item_sync_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sync_batches
    MODIFY COLUMN status ENUM('draft','queued','running','partial','complete','empty','error','cancelled') NOT NULL DEFAULT 'draft';

ALTER TABLE sync_batch_chunks
    MODIFY COLUMN status ENUM('pending','queued','running','partial','complete','empty','error','cancelled') NOT NULL DEFAULT 'pending';

ALTER TABLE meli_notification_events
    ADD COLUMN IF NOT EXISTS compacted_at DATETIME NULL AFTER processed_at;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('cron.run_time_budget_seconds', '45', 'cron', 0),
('cron.minimum_step_budget_ms', '750', 'cron', 0),
('orders.enrichment_batch_limit', '10', 'orders', 0),
('orders.enrichment_max_attempts', '3', 'orders', 0),
('orders.enrichment_retry_minutes', '5', 'orders', 0),
('items.sync_detail_batch_limit', '25', 'items', 0),
('items.sync_discovery_page_limit', '100', 'items', 0),
('items.sync_job_max_attempts', '3', 'items', 0),
('notifications.backlog_batch_limit', '500', 'notifications', 0),
('notifications.event_retention_days', '180', 'notifications', 0),
('notifications.unknown_topic_retention_days', '30', 'notifications', 0),
('raw.retention_days', '365', 'system', 0)
ON DUPLICATE KEY UPDATE
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.10.1', 'Estabilización de procesos, enriquecimiento deduplicado y cron con presupuesto');
