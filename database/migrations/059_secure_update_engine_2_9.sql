-- ERP Meli 2.9.0 — Motor de actualizaciones seguro, reanudable y compatible.
-- Solo agrega metadatos operativos. No modifica datos de ventas ni credenciales.

CREATE TABLE IF NOT EXISTS system_update_releases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    release_id VARCHAR(120) NOT NULL,
    product_id VARCHAR(100) NOT NULL DEFAULT 'erp-meli',
    version VARCHAR(40) NOT NULL,
    sequence_no BIGINT UNSIGNED NOT NULL DEFAULT 0,
    channel VARCHAR(30) NOT NULL DEFAULT 'stable',
    source_type VARCHAR(30) NOT NULL,
    source_reference VARCHAR(700) NULL,
    manifest_json LONGTEXT NULL,
    manifest_sha256 CHAR(64) NULL,
    signature_status VARCHAR(30) NOT NULL DEFAULT 'not_checked',
    status VARCHAR(30) NOT NULL DEFAULT 'discovered',
    is_withdrawn TINYINT(1) NOT NULL DEFAULT 0,
    installed_at DATETIME NULL,
    activated_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_system_update_release_id (release_id),
    KEY idx_system_update_release_version (version, channel),
    KEY idx_system_update_release_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_update_paths (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id VARCHAR(100) NOT NULL DEFAULT 'erp-meli',
    version_from VARCHAR(40) NOT NULL,
    version_to VARCHAR(40) NOT NULL,
    bridge_release_id VARCHAR(120) NULL,
    priority_no SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_system_update_path (product_id, version_from, version_to, bridge_release_id),
    KEY idx_system_update_path_from (version_from, is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_update_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_uuid CHAR(36) NOT NULL,
    release_id BIGINT UNSIGNED NULL,
    version_from VARCHAR(40) NULL,
    version_to VARCHAR(40) NULL,
    mode VARCHAR(30) NOT NULL DEFAULT 'atomic',
    state VARCHAR(40) NOT NULL DEFAULT 'discovered',
    progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    backup_requested TINYINT(1) NOT NULL DEFAULT 1,
    backup_waived TINYINT(1) NOT NULL DEFAULT 0,
    backup_waiver_text VARCHAR(80) NULL,
    schema_classification VARCHAR(40) NULL,
    current_step VARCHAR(120) NULL,
    next_action VARCHAR(120) NULL,
    source_path VARCHAR(700) NULL,
    staging_path VARCHAR(700) NULL,
    previous_release_id VARCHAR(120) NULL,
    lock_owner VARCHAR(120) NULL,
    heartbeat_at DATETIME NULL,
    safe_error_code VARCHAR(80) NULL,
    safe_error_message VARCHAR(700) NULL,
    started_by BIGINT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_system_update_run_uuid (run_uuid),
    KEY idx_system_update_runs_state (state, created_at),
    KEY idx_system_update_runs_release (release_id, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_update_run_steps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NOT NULL,
    step_key VARCHAR(120) NOT NULL,
    sequence_no SMALLINT UNSIGNED NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    checkpoint_json LONGTEXT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    safe_message VARCHAR(700) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_system_update_run_step (run_id, step_key),
    KEY idx_system_update_run_steps_due (run_id, status, sequence_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_update_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration_key VARCHAR(180) NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    release_version VARCHAR(40) NULL,
    state VARCHAR(40) NOT NULL DEFAULT 'pending',
    checkpoint_json LONGTEXT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    safe_error_message VARCHAR(700) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_system_update_migration_key (migration_key),
    KEY idx_system_update_migrations_state (state, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_update_schema_fingerprints (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NULL,
    app_version VARCHAR(40) NULL,
    fingerprint_sha256 CHAR(64) NOT NULL,
    schema_json LONGTEXT NOT NULL,
    classification VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_system_update_fingerprint_run (run_id, created_at),
    KEY idx_system_update_fingerprint_hash (fingerprint_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_update_schema_differences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NULL,
    object_type VARCHAR(30) NOT NULL,
    object_name VARCHAR(190) NOT NULL,
    difference_type VARCHAR(40) NOT NULL,
    expected_value MEDIUMTEXT NULL,
    actual_value MEDIUMTEXT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'warning',
    resolution_status VARCHAR(30) NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_system_update_difference_run (run_id, resolution_status),
    KEY idx_system_update_difference_object (object_type, object_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_update_backups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NULL,
    backup_type VARCHAR(30) NOT NULL,
    adapter VARCHAR(40) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    storage_path VARCHAR(700) NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    checksum_sha256 CHAR(64) NULL,
    verified_at DATETIME NULL,
    expires_at DATETIME NULL,
    safe_error_message VARCHAR(700) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_system_update_backups_run (run_id, status),
    KEY idx_system_update_backups_expiry (expires_at, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_update_locks (
    lock_name VARCHAR(120) NOT NULL,
    owner_token VARCHAR(120) NOT NULL,
    run_id BIGINT UNSIGNED NULL,
    heartbeat_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (lock_name),
    KEY idx_system_update_locks_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_update_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NULL,
    level VARCHAR(20) NOT NULL DEFAULT 'info',
    event_code VARCHAR(80) NOT NULL,
    message VARCHAR(700) NOT NULL,
    context_json MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_system_update_events_run (run_id, created_at),
    KEY idx_system_update_events_level (level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_update_trusted_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    key_id VARCHAR(100) NOT NULL,
    algorithm VARCHAR(30) NOT NULL DEFAULT 'openssl-sha256',
    public_key_pem TEXT NOT NULL,
    channels_json VARCHAR(300) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    valid_from DATETIME NULL,
    valid_until DATETIME NULL,
    revoked_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_system_update_trusted_key (key_id),
    KEY idx_system_update_trusted_keys_status (status, valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
    ('update.remote_url','','update',0),
    ('update.channel','stable','update',0),
    ('update.telemetry_enabled','0','update',0),
    ('update.installation_id','','update',0),
    ('update.backup_default','1','update',0),
    ('update.retention_releases','3','update',0),
    ('update.retention_backups_days','30','update',0),
    ('update.allow_classic_overwrite','1','update',0),
    ('update.max_package_mb','512','update',0),
    ('update.managed_releases_enabled','1','update',0)
ON DUPLICATE KEY UPDATE
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.9.0', 'Motor de actualizaciones firmado, reanudable, con releases atómicos, backups y compatibilidad heredada.');
