CREATE TABLE IF NOT EXISTS system_modules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id VARCHAR(80) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    discovered_version VARCHAR(40) NOT NULL,
    installed_version VARCHAR(40) NULL,
    migration_version INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('discovered','installed','disabled','enabled','migration_required','degraded','incompatible','failed') NOT NULL DEFAULT 'discovered',
    enabled_at DATETIME NULL,
    disabled_at DATETIME NULL,
    last_health_at DATETIME NULL,
    last_error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_system_modules_id (module_id),
    KEY idx_system_modules_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_module_migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id VARCHAR(80) NOT NULL,
    migration_key VARCHAR(190) NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    state ENUM('pending','running','applied','adopted','failed','drifted') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    safe_error_message VARCHAR(500) NULL,
    UNIQUE KEY uq_module_migration (module_id,migration_key),
    KEY idx_module_migrations_state (module_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_module_health (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id VARCHAR(80) NOT NULL,
    status VARCHAR(40) NOT NULL,
    details_json JSON NULL,
    checked_at DATETIME NOT NULL,
    KEY idx_module_health_recent (module_id,checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_module_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id VARCHAR(80) NOT NULL,
    source_event_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    topic VARCHAR(100) NOT NULL,
    resource_type VARCHAR(80) NULL,
    remote_resource_id VARCHAR(120) NULL,
    status ENUM('pending','processed','ignored','error') NOT NULL DEFAULT 'pending',
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_module_source_event (module_id,source_event_id),
    KEY idx_module_events_due (module_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_module_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id VARCHAR(80) NOT NULL,
    job_type VARCHAR(80) NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    status ENUM('pending','running','retry','paused','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    priority INT NOT NULL DEFAULT 100,
    payload_json JSON NULL,
    result_json JSON NULL,
    progress_current INT UNSIGNED NOT NULL DEFAULT 0,
    progress_total INT UNSIGNED NOT NULL DEFAULT 0,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_run_at DATETIME NOT NULL,
    lock_owner VARCHAR(80) NULL,
    lock_expires_at DATETIME NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    safe_error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_module_jobs_due (status,next_run_at,priority),
    KEY idx_module_jobs_module (module_id,status,next_run_at),
    KEY idx_module_jobs_account (meli_account_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO system_modules (module_id,display_name,discovered_version,status) VALUES
('meli-insights','Rendimiento Mercado Libre','1.0.0','discovered');

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted) VALUES
('module.meli_insights.enabled','0','modules',0),
('modules.api_reserved_percent','10','modules',0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version,notes) VALUES ('2.12.0','Runtime modular aislado y módulo Meli Insights');
