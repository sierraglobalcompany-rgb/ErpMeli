CREATE TABLE IF NOT EXISTS system_cold_archives (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    dataset_key ENUM(
        'notification_events',
        'api_request_logs',
        'cron_health_checks',
        'financial_job_items'
    ) NOT NULL,
    period_month CHAR(7) NOT NULL,
    status ENUM('creating','verifying','ready','failed','deleted') NOT NULL DEFAULT 'creating',
    storage_name VARCHAR(190) NULL,
    key_id VARCHAR(120) NULL,
    row_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    content_sha256 CHAR(64) NULL,
    archive_sha256 CHAR(64) NULL,
    first_row_at DATETIME NULL,
    last_row_at DATETIME NULL,
    verified_at DATETIME(3) NULL,
    rollup_verified_at DATETIME(3) NULL,
    downloaded_verified_at DATETIME(3) NULL,
    delete_after DATETIME(3) NULL,
    safe_error_code VARCHAR(80) NULL,
    safe_error_message VARCHAR(500) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_cold_archive_public (public_id),
    UNIQUE KEY uq_cold_archive_dataset_month (dataset_key, period_month),
    KEY idx_cold_archive_retention (status, delete_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_request_daily_rollups (
    rollup_date DATE NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    operation_key VARCHAR(80) NOT NULL DEFAULT '',
    outcome_class VARCHAR(32) NOT NULL DEFAULT '',
    http_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    reached_remote TINYINT(1) NOT NULL DEFAULT 0,
    requests BIGINT UNSIGNED NOT NULL DEFAULT 0,
    duration_total_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
    wire_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    decoded_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (rollup_date,meli_account_id,operation_key,outcome_class,http_status,reached_remote),
    KEY idx_api_rollup_retention (rollup_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_event_daily_rollups (
    rollup_date DATE NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    canonical_topic VARCHAR(40) NOT NULL DEFAULT '',
    status VARCHAR(40) NOT NULL,
    events BIGINT UNSIGNED NOT NULL DEFAULT 0,
    unique_resources BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (rollup_date,meli_account_id,canonical_topic,status),
    KEY idx_notification_rollup_retention (rollup_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_resource_monthly_rollups (
    rollup_month CHAR(7) NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    canonical_topic VARCHAR(40) NOT NULL DEFAULT '',
    remote_resource_id VARCHAR(120) NOT NULL DEFAULT '',
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    occurrence_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_terminal_status VARCHAR(40) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (rollup_month,meli_account_id,canonical_topic,remote_resource_id),
    KEY idx_notification_resource_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_daily_rollups (
    rollup_date DATE NOT NULL,
    job_name VARCHAR(120) NOT NULL,
    execution_source VARCHAR(40) NOT NULL,
    result_state VARCHAR(30) NOT NULL DEFAULT '',
    runs BIGINT UNSIGNED NOT NULL DEFAULT 0,
    duration_total_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
    processed_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
    errors_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
    remote_runs BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (rollup_date,job_name,execution_source,result_state),
    KEY idx_cron_rollup_retention (rollup_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remote_payload_objects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payload_sha256 CHAR(64) NOT NULL,
    storage_name VARCHAR(255) NOT NULL,
    compression ENUM('gzip') NOT NULL DEFAULT 'gzip',
    original_bytes BIGINT UNSIGNED NOT NULL,
    stored_bytes BIGINT UNSIGNED NOT NULL,
    verified_at DATETIME(3) NOT NULL,
    reference_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_remote_payload_hash (payload_sha256),
    UNIQUE KEY uq_remote_payload_storage (storage_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remote_payload_references (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payload_object_id BIGINT UNSIGNED NOT NULL,
    entity_table ENUM('meli_orders','meli_shipments','meli_payments','meli_packs','meli_order_items') NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    linked_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_remote_payload_entity (entity_table, entity_id),
    KEY idx_remote_payload_account (meli_account_id, entity_table),
    CONSTRAINT fk_remote_payload_reference_object
        FOREIGN KEY (payload_object_id) REFERENCES remote_payload_objects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_maintenance_task_state (
    task_key VARCHAR(120) PRIMARY KEY,
    last_started_at DATETIME(3) NULL,
    last_completed_at DATETIME(3) NULL,
    next_run_at DATETIME(3) NULL,
    last_result VARCHAR(40) NULL,
    last_affected_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
    safe_error_message VARCHAR(500) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('retention.success_days','15',0,'maintenance'),
    ('retention.incident_days','90',0,'maintenance'),
    ('retention.summary_days','365',0,'maintenance'),
    ('retention.cold_archive_server_days','90',0,'maintenance'),
    ('retention.batch_size','500',0,'maintenance'),
    ('retention.daily_hour_utc','7',0,'maintenance'),
    ('raw_payload.database_days','15',0,'maintenance'),
    ('raw_payload.batch_size','100',0,'maintenance')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.19','Archivo frío cifrado, retención 15/90 días y payload remoto único verificado.')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
