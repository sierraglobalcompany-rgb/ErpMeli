-- ERP Meli 2.19.4 — centro de procesamiento manual seguro.
-- La web administra sesiones; solo jobs/process_manual_queue.php ejecuta trabajo.

CREATE TABLE IF NOT EXISTS manual_processing_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_token CHAR(40) NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    mode ENUM('conservative','balanced','automatic','advanced') NOT NULL DEFAULT 'automatic',
    scope_key VARCHAR(50) NOT NULL,
    status ENUM('draft','active','paused','finishing','completed','abandoned','failed') NOT NULL DEFAULT 'draft',
    heartbeat_at DATETIME NULL,
    grace_until DATETIME NULL,
    started_at DATETIME NULL,
    paused_at DATETIME NULL,
    finished_at DATETIME NULL,
    total_jobs INT UNSIGNED NOT NULL DEFAULT 0,
    completed_jobs INT UNSIGNED NOT NULL DEFAULT 0,
    failed_jobs INT UNSIGNED NOT NULL DEFAULT 0,
    remote_calls INT UNSIGNED NOT NULL DEFAULT 0,
    transferred_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    safe_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_manual_session_token (session_token),
    KEY idx_manual_session_active (status,heartbeat_at,grace_until),
    KEY idx_manual_session_user (created_by_user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manual_processing_scopes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    manual_processing_session_id BIGINT UNSIGNED NOT NULL,
    queue_key VARCHAR(80) NOT NULL,
    account_scope_key VARCHAR(40) NOT NULL DEFAULT '*',
    meli_account_id BIGINT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_manual_scope (manual_processing_session_id,queue_key,account_scope_key),
    KEY idx_manual_scope_claim (queue_key,meli_account_id,active),
    CONSTRAINT fk_manual_scope_session FOREIGN KEY (manual_processing_session_id)
        REFERENCES manual_processing_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manual_processing_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    manual_processing_session_id BIGINT UNSIGNED NOT NULL,
    queue_key VARCHAR(80) NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    human_label VARCHAR(160) NOT NULL,
    content_summary VARCHAR(500) NULL,
    operation_key VARCHAR(80) NULL,
    load_class VARCHAR(24) NULL,
    status ENUM('pending','running','waiting','completed','partial','retry','failed','returned') NOT NULL DEFAULT 'pending',
    lease_owner VARCHAR(100) NULL,
    lease_generation INT UNSIGNED NOT NULL DEFAULT 0,
    lease_expires_at DATETIME NULL,
    progress_current INT UNSIGNED NOT NULL DEFAULT 0,
    progress_total INT UNSIGNED NOT NULL DEFAULT 0,
    result_summary VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_manual_frozen_work (manual_processing_session_id,queue_key,source_id),
    KEY idx_manual_item_claim (manual_processing_session_id,status,created_at),
    KEY idx_manual_item_lease (lease_expires_at,status),
    CONSTRAINT fk_manual_item_session FOREIGN KEY (manual_processing_session_id)
        REFERENCES manual_processing_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manual_processing_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    manual_processing_session_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    safe_message VARCHAR(500) NOT NULL,
    queue_key VARCHAR(80) NULL,
    source_id VARCHAR(100) NULL,
    diagnostic_id VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_manual_event_session (manual_processing_session_id,id),
    CONSTRAINT fk_manual_event_session FOREIGN KEY (manual_processing_session_id)
        REFERENCES manual_processing_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('manual_processing.enabled','1','manual_processing',0),
('manual_processing.heartbeat_seconds','10','manual_processing',0),
('manual_processing.browser_grace_minutes','15','manual_processing',0),
('manual_processing.worker_runtime_seconds','40','manual_processing',0),
('manual_processing.accept_work_until_seconds','30','manual_processing',0),
('manual_processing.urgent_budget_reserve_percent','25','manual_processing',0),
('manual_processing.max_remote_concurrency','1','manual_processing',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.19.4','Centro de procesamiento manual coordinado con cron')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
