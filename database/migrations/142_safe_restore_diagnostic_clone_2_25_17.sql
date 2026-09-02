CREATE TABLE IF NOT EXISTS system_restore_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    backup_id BIGINT UNSIGNED NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    mode ENUM('production_recovery','diagnostic_clone') NOT NULL,
    status ENUM('prepared','validated','queued','restoring','verifying','ready_to_switch','completed','switched','rolled_back','failed','cancelled') NOT NULL DEFAULT 'prepared',
    target_secret_name VARCHAR(190) NOT NULL,
    target_database_hash CHAR(64) NOT NULL,
    lease_owner VARCHAR(96) NULL,
    lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lease_expires_at DATETIME(3) NULL,
    checkpoint_json JSON NULL,
    tables_total INT UNSIGNED NULL,
    tables_completed INT UNSIGNED NOT NULL DEFAULT 0,
    rows_restored BIGINT UNSIGNED NOT NULL DEFAULT 0,
    safe_error_code VARCHAR(80) NULL,
    safe_error_message VARCHAR(500) NULL,
    requested_at DATETIME(3) NOT NULL DEFAULT UTC_TIMESTAMP(3),
    started_at DATETIME(3) NULL,
    heartbeat_at DATETIME(3) NULL,
    verified_at DATETIME(3) NULL,
    completed_at DATETIME(3) NULL,
    UNIQUE KEY uq_restore_plans_public (public_id),
    KEY idx_restore_plans_queue (status, requested_at),
    CONSTRAINT fk_restore_plans_backup FOREIGN KEY (backup_id) REFERENCES system_backup_archives(id),
    CONSTRAINT fk_restore_plans_user FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_restore_checks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restore_id BIGINT UNSIGNED NOT NULL,
    check_key VARCHAR(100) NOT NULL,
    status ENUM('pending','passed','failed','warning') NOT NULL,
    expected_value VARCHAR(255) NULL,
    actual_value VARCHAR(255) NULL,
    checked_at DATETIME(3) NOT NULL DEFAULT UTC_TIMESTAMP(3),
    UNIQUE KEY uq_restore_check (restore_id, check_key),
    CONSTRAINT fk_restore_checks_plan FOREIGN KEY (restore_id) REFERENCES system_restore_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_restore_switches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restore_id BIGINT UNSIGNED NOT NULL,
    switched_by BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    previous_config_name VARCHAR(190) NOT NULL,
    next_config_sha256 CHAR(64) NOT NULL,
    status ENUM('prepared','switched','rolled_back','failed') NOT NULL DEFAULT 'prepared',
    switched_at DATETIME(3) NULL,
    rolled_back_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT UTC_TIMESTAMP(3),
    UNIQUE KEY uq_restore_switch_plan (restore_id),
    CONSTRAINT fk_restore_switch_plan FOREIGN KEY (restore_id) REFERENCES system_restore_plans(id),
    CONSTRAINT fk_restore_switch_user FOREIGN KEY (switched_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('restore.batch_statements','500',0,'backup'),
('restore.require_empty_database','1',0,'backup'),
('restore.keep_previous_config_days','30',0,'backup')
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
is_encrypted=VALUES(is_encrypted),
setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.17','Restauración segura en base nueva y clon diagnóstico sin credenciales remotas.')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
