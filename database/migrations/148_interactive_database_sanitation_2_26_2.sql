CREATE TABLE IF NOT EXISTS database_maintenance_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    backup_id BIGINT UNSIGNED NULL,
    status ENUM(
        'analyzed','running','pausing','paused','completed','finished','failed'
    ) NOT NULL DEFAULT 'analyzed',
    phase VARCHAR(40) NOT NULL DEFAULT 'analysis',
    dataset_key VARCHAR(80) NULL,
    dataset_position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
    control_token_hash CHAR(64) NULL,
    control_expires_at DATETIME(3) NULL,
    plan_json JSON NOT NULL,
    counters_json JSON NOT NULL,
    integrity_before_json JSON NOT NULL,
    integrity_after_json JSON NULL,
    integrity_sha256 CHAR(64) NULL,
    safe_message VARCHAR(500) NULL,
    last_step_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    started_at DATETIME(3) NULL,
    paused_at DATETIME(3) NULL,
    completed_at DATETIME(3) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_database_maintenance_public (public_id),
    KEY idx_database_maintenance_active (status, control_expires_at, id),
    KEY idx_database_maintenance_user (requested_by, id),
    CONSTRAINT fk_database_maintenance_user
        FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_database_maintenance_backup
        FOREIGN KEY (backup_id) REFERENCES system_backup_archives(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS database_maintenance_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    maintenance_session_id BIGINT UNSIGNED NOT NULL,
    sequence_no BIGINT UNSIGNED NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    generation BIGINT UNSIGNED NOT NULL,
    phase VARCHAR(40) NOT NULL,
    dataset_key VARCHAR(80) NULL,
    status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    rows_reviewed INT UNSIGNED NOT NULL DEFAULT 0,
    rows_archived INT UNSIGNED NOT NULL DEFAULT 0,
    rows_summarized INT UNSIGNED NOT NULL DEFAULT 0,
    rows_deleted INT UNSIGNED NOT NULL DEFAULT 0,
    payloads_externalized INT UNSIGNED NOT NULL DEFAULT 0,
    bytes_released BIGINT UNSIGNED NOT NULL DEFAULT 0,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    safe_message VARCHAR(500) NULL,
    result_json JSON NULL,
    started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    completed_at DATETIME(3) NULL,
    UNIQUE KEY uq_database_maintenance_step (
        maintenance_session_id, idempotency_key
    ),
    UNIQUE KEY uq_database_maintenance_sequence (
        maintenance_session_id, sequence_no
    ),
    KEY idx_database_maintenance_step_status (
        maintenance_session_id, status, sequence_no
    ),
    CONSTRAINT fk_database_maintenance_step_session
        FOREIGN KEY (maintenance_session_id)
        REFERENCES database_maintenance_sessions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE system_cold_archives
    ADD COLUMN IF NOT EXISTS build_cursor_id BIGINT UNSIGNED NOT NULL DEFAULT 0
        AFTER row_count,
    ADD COLUMN IF NOT EXISTS build_plain_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0
        AFTER build_cursor_id,
    ADD COLUMN IF NOT EXISTS build_storage_name VARCHAR(190) NULL
        AFTER build_plain_bytes,
    ADD COLUMN IF NOT EXISTS build_started_at DATETIME(3) NULL
        AFTER build_storage_name,
    ADD COLUMN IF NOT EXISTS build_heartbeat_at DATETIME(3) NULL
        AFTER build_started_at,
    ADD COLUMN IF NOT EXISTS rollup_cursor_date DATE NULL
        AFTER rollup_verified_at;

SET @maintenance_retention_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'meli_notification_events'
      AND BINARY INDEX_NAME=BINARY 'idx_notification_retention_date'
);
SET @maintenance_retention_index_sql = IF(
    @maintenance_retention_index_exists=0,
    'ALTER TABLE meli_notification_events ADD INDEX idx_notification_retention_date (erp_received_at,status,id)',
    'SELECT 1'
);
PREPARE maintenance_retention_index_stmt FROM @maintenance_retention_index_sql;
EXECUTE maintenance_retention_index_stmt;
DEALLOCATE PREPARE maintenance_retention_index_stmt;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('database_maintenance.batch_size','500',0,'maintenance'),
    ('database_maintenance.step_deadline_ms','2000',0,'maintenance'),
    ('database_maintenance.control_ttl_seconds','45',0,'maintenance'),
    ('database_maintenance.require_verified_backup','1',0,'maintenance')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES (
    '2.26.2',
    'Saneamiento local interactivo, idempotente, respaldado y con recuperación física separada.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
