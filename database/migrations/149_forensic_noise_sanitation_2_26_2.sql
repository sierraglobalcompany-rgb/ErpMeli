ALTER TABLE database_maintenance_sessions
    ADD COLUMN IF NOT EXISTS steps_summary_json JSON NULL
        AFTER integrity_after_json,
    ADD COLUMN IF NOT EXISTS steps_compacted_count BIGINT UNSIGNED NOT NULL DEFAULT 0
        AFTER steps_summary_json,
    ADD COLUMN IF NOT EXISTS steps_compacted_at DATETIME(3) NULL
        AFTER steps_compacted_count;

CREATE TABLE IF NOT EXISTS meli_notification_backfill_unique_resources (
    run_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    canonical_topic VARCHAR(40) NOT NULL,
    resource_type VARCHAR(40) NOT NULL,
    remote_resource_id VARCHAR(120) NOT NULL,
    first_event_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (
        run_id,meli_account_id,canonical_topic,resource_type,remote_resource_id
    ),
    KEY idx_backfill_unique_event (run_id,first_event_id),
    CONSTRAINT fk_backfill_unique_run
        FOREIGN KEY (run_id) REFERENCES meli_notification_backfill_runs(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @idx_notification_legacy_empty_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'meli_notification_events'
      AND BINARY INDEX_NAME=BINARY 'idx_notification_legacy_empty'
);
SET @idx_notification_legacy_empty_sql = IF(
    @idx_notification_legacy_empty_exists=0,
    'ALTER TABLE meli_notification_events ADD INDEX idx_notification_legacy_empty (canonical_topic,status,id)',
    'SELECT 1'
);
PREPARE idx_notification_legacy_empty_stmt FROM @idx_notification_legacy_empty_sql;
EXECUTE idx_notification_legacy_empty_stmt;
DEALLOCATE PREPARE idx_notification_legacy_empty_stmt;

SET @idx_api_request_retention_created_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'api_request_logs'
      AND BINARY INDEX_NAME=BINARY 'idx_api_request_retention_created'
);
SET @idx_api_request_retention_created_sql = IF(
    @idx_api_request_retention_created_exists=0,
    'ALTER TABLE api_request_logs ADD INDEX idx_api_request_retention_created (created_at,id)',
    'SELECT 1'
);
PREPARE idx_api_request_retention_created_stmt FROM @idx_api_request_retention_created_sql;
EXECUTE idx_api_request_retention_created_stmt;
DEALLOCATE PREPARE idx_api_request_retention_created_stmt;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('database_maintenance.step_compaction_keep_tail','50',0,'maintenance'),
    ('notifications.legacy_normalization_batch_limit','500',0,'notifications')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

DELETE FROM app_settings
WHERE setting_key IN (
    'database_maintenance.step_compaction_auto_enabled',
    'notifications.legacy_normalization_auto_enabled'
);
