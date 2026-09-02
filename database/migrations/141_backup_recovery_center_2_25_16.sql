CREATE TABLE IF NOT EXISTS system_backup_archives (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    purpose ENUM('manual','pre_update') NOT NULL DEFAULT 'manual',
    status ENUM('prepared','queued','creating','verifying','ready','failed','deleted') NOT NULL DEFAULT 'prepared',
    erp_version VARCHAR(32) NOT NULL,
    format_version SMALLINT UNSIGNED NOT NULL DEFAULT 2,
    key_id VARCHAR(80) NULL,
    storage_name VARCHAR(190) NULL,
    size_bytes BIGINT UNSIGNED NULL,
    table_count INT UNSIGNED NULL,
    row_count BIGINT UNSIGNED NULL,
    checksum_sha256 CHAR(64) NULL,
    manifest_sha256 CHAR(64) NULL,
    safe_error_code VARCHAR(80) NULL,
    safe_error_message VARCHAR(500) NULL,
    requested_at DATETIME(3) NOT NULL DEFAULT UTC_TIMESTAMP(3),
    started_at DATETIME(3) NULL,
    heartbeat_at DATETIME(3) NULL,
    verified_at DATETIME(3) NULL,
    completed_at DATETIME(3) NULL,
    expires_at DATETIME(3) NULL,
    deleted_at DATETIME(3) NULL,
    UNIQUE KEY uq_backup_archives_public (public_id),
    KEY idx_backup_archives_queue (status, requested_at),
    KEY idx_backup_archives_retention (purpose, expires_at, deleted_at),
    CONSTRAINT fk_backup_archives_user FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_backup_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    backup_id BIGINT UNSIGNED NOT NULL,
    job_type ENUM('create','verify') NOT NULL,
    status ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
    lease_owner VARCHAR(96) NULL,
    lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lease_expires_at DATETIME(3) NULL,
    checkpoint_json JSON NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    safe_error_code VARCHAR(80) NULL,
    safe_error_message VARCHAR(500) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT UTC_TIMESTAMP(3),
    started_at DATETIME(3) NULL,
    heartbeat_at DATETIME(3) NULL,
    completed_at DATETIME(3) NULL,
    UNIQUE KEY uq_backup_jobs_active (backup_id, job_type),
    KEY idx_backup_jobs_claim (status, lease_expires_at, created_at),
    CONSTRAINT fk_backup_jobs_archive FOREIGN KEY (backup_id) REFERENCES system_backup_archives(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_backup_table_checks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    backup_id BIGINT UNSIGNED NOT NULL,
    table_name VARCHAR(190) NOT NULL,
    row_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    structure_sha256 CHAR(64) NOT NULL,
    data_sha256 CHAR(64) NOT NULL,
    verified_at DATETIME(3) NULL,
    UNIQUE KEY uq_backup_table_check (backup_id, table_name),
    CONSTRAINT fk_backup_table_checks_archive FOREIGN KEY (backup_id) REFERENCES system_backup_archives(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_backup_download_grants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    backup_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME(3) NOT NULL,
    consumed_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT UTC_TIMESTAMP(3),
    UNIQUE KEY uq_backup_download_token (token_hash),
    KEY idx_backup_download_expiry (expires_at, consumed_at),
    CONSTRAINT fk_backup_download_archive FOREIGN KEY (backup_id) REFERENCES system_backup_archives(id) ON DELETE CASCADE,
    CONSTRAINT fk_backup_download_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_backup_audit_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    backup_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(80) NOT NULL,
    outcome ENUM('success','denied','failed') NOT NULL,
    metadata_json JSON NULL,
    created_at DATETIME(3) NOT NULL DEFAULT UTC_TIMESTAMP(3),
    KEY idx_backup_audit_archive (backup_id, created_at),
    KEY idx_backup_audit_user (user_id, created_at),
    CONSTRAINT fk_backup_audit_archive FOREIGN KEY (backup_id) REFERENCES system_backup_archives(id) ON DELETE SET NULL,
    CONSTRAINT fk_backup_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('backup.minimum_free_space_factor','2.5',0,'backup'),
('backup.update_retention_days','30',0,'backup'),
('backup.download_grant_seconds','300',0,'backup')
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
is_encrypted=VALUES(is_encrypted),
setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.16','Centro de copias cifradas, verificables y ejecutadas por CLI.')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
