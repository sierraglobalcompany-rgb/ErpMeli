CREATE TABLE IF NOT EXISTS app_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(160) NOT NULL,
    setting_value MEDIUMTEXT NULL,
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    setting_group VARCHAR(80) NOT NULL DEFAULT 'general',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_app_settings_key (setting_key),
    KEY idx_app_settings_group (setting_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(40) NOT NULL,
    installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes VARCHAR(500) NULL,
    UNIQUE KEY uq_app_versions_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS update_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version_from VARCHAR(40) NULL,
    version_to VARCHAR(40) NULL,
    action VARCHAR(120) NOT NULL,
    status ENUM('pending','success','error','blocked') NOT NULL DEFAULT 'pending',
    message VARCHAR(800) NULL,
    executed_by BIGINT UNSIGNED NULL,
    executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_hash CHAR(64) NULL,
    user_agent_hash CHAR(64) NULL,
    KEY idx_update_logs_date (executed_at),
    KEY idx_update_logs_status (status),
    CONSTRAINT fk_update_logs_user FOREIGN KEY (executed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.0.1', 'Correctivo estabilidad, actualización, empresas, logs y UI');
