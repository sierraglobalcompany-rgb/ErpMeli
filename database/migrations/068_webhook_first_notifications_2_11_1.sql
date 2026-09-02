CREATE TABLE IF NOT EXISTS meli_notification_work_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NULL,
    account_scope_key VARCHAR(80) NOT NULL,
    meli_user_id BIGINT UNSIGNED NULL,
    canonical_topic VARCHAR(40) NOT NULL,
    resource_type VARCHAR(40) NOT NULL,
    remote_resource_id VARCHAR(120) NOT NULL,
    latest_event_id BIGINT UNSIGNED NULL,
    latest_event_sent_at DATETIME NULL,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('pending','running','retry','paused','complete','ignored','quarantined','error','cancelled') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_run_at DATETIME NOT NULL,
    locked_by VARCHAR(80) NULL,
    locked_at DATETIME NULL,
    lock_expires_at DATETIME NULL,
    rerun_requested TINYINT(1) NOT NULL DEFAULT 0,
    last_error_code VARCHAR(80) NULL,
    last_error_message VARCHAR(500) NULL,
    correlation_id CHAR(36) NOT NULL,
    last_result VARCHAR(40) NULL,
    first_received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_started_at DATETIME NULL,
    last_processed_at DATETIME NULL,
    completed_at DATETIME NULL,
    processing_ms INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notification_resource (account_scope_key, resource_type, remote_resource_id),
    KEY idx_notification_work_due (status, next_run_at, priority, id),
    KEY idx_notification_work_account_due (meli_account_id, status, next_run_at),
    KEY idx_notification_work_lease (lock_expires_at, locked_by),
    KEY idx_notification_work_correlation (correlation_id),
    KEY idx_notification_work_latest_event (latest_event_id),
    CONSTRAINT fk_notification_work_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_notification_work_event FOREIGN KEY (latest_event_id) REFERENCES meli_notification_events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_notification_backfill_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mode ENUM('analyze','enqueue','process') NOT NULL DEFAULT 'analyze',
    phase ENUM('recent','historical') NOT NULL DEFAULT 'recent',
    status ENUM('draft','analyzing','ready','running','paused','complete','error','cancelled') NOT NULL DEFAULT 'draft',
    checkpoint_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    source_total INT UNSIGNED NOT NULL DEFAULT 0,
    analyzed_count INT UNSIGNED NOT NULL DEFAULT 0,
    normalized_count INT UNSIGNED NOT NULL DEFAULT 0,
    unique_resource_count INT UNSIGNED NOT NULL DEFAULT 0,
    satisfied_local_count INT UNSIGNED NOT NULL DEFAULT 0,
    queued_count INT UNSIGNED NOT NULL DEFAULT 0,
    ignored_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_api_calls INT UNSIGNED NOT NULL DEFAULT 0,
    priority_since DATETIME NULL,
    next_run_at DATETIME NULL,
    locked_by VARCHAR(80) NULL,
    locked_at DATETIME NULL,
    lock_expires_at DATETIME NULL,
    last_error_message VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_notification_backfill_due (status, phase, next_run_at, id),
    KEY idx_notification_backfill_lock (lock_expires_at, locked_by),
    CONSTRAINT fk_notification_backfill_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE meli_notification_events
    ADD COLUMN IF NOT EXISTS source_type VARCHAR(40) NOT NULL DEFAULT 'webhook' AFTER notification_id,
    ADD COLUMN IF NOT EXISTS canonical_topic VARCHAR(40) NULL AFTER topic,
    ADD COLUMN IF NOT EXISTS resource_type VARCHAR(40) NULL AFTER canonical_topic,
    ADD COLUMN IF NOT EXISTS remote_resource_id VARCHAR(120) NULL AFTER resource_type,
    ADD COLUMN IF NOT EXISTS validation_status VARCHAR(40) NULL AFTER remote_resource_id,
    ADD COLUMN IF NOT EXISTS disposition VARCHAR(40) NULL AFTER validation_status,
    ADD COLUMN IF NOT EXISTS correlation_id CHAR(36) NULL AFTER disposition,
    ADD COLUMN IF NOT EXISTS work_item_id BIGINT UNSIGNED NULL AFTER correlation_id,
    ADD COLUMN IF NOT EXISTS acknowledged_at DATETIME NULL AFTER erp_received_at,
    ADD COLUMN IF NOT EXISTS receiver_duration_ms INT UNSIGNED NULL AFTER acknowledged_at,
    ADD COLUMN IF NOT EXISTS source_user_agent_hash CHAR(64) NULL AFTER user_agent;

ALTER TABLE app_notifications
    ADD COLUMN IF NOT EXISTS dedupe_key VARCHAR(190) NULL AFTER notification_event_id,
    ADD COLUMN IF NOT EXISTS action_required TINYINT(1) NOT NULL DEFAULT 0 AFTER severity,
    ADD COLUMN IF NOT EXISTS occurrence_count INT UNSIGNED NOT NULL DEFAULT 1 AFTER action_required,
    ADD COLUMN IF NOT EXISTS last_occurred_at DATETIME NULL AFTER occurrence_count;

UPDATE notification_rules
SET enabled=1,
    severity=IF(topic IN ('claims','claims_actions'),'high','info'),
    create_app_notification=IF(topic IN ('claims','claims_actions'),1,0),
    show_dashboard_badge=IF(topic IN ('claims','claims_actions'),1,0)
WHERE action IS NULL
  AND topic IN ('shipments','claims','claims_actions','items','stock_locations','stock-location','stock-locations','messages','payments');

INSERT INTO notification_rules (topic,action,severity,enabled,create_app_notification,show_dashboard_badge,min_interval_minutes)
SELECT src.topic,NULL,src.severity,1,src.human_notice,src.human_notice,0
FROM (
    SELECT 'shipments' topic,'info' severity,0 human_notice
    UNION ALL SELECT 'claims','high',1
    UNION ALL SELECT 'claims_actions','high',1
    UNION ALL SELECT 'items','info',0
    UNION ALL SELECT 'stock_locations','info',0
    UNION ALL SELECT 'stock-location','info',0
    UNION ALL SELECT 'stock-locations','info',0
    UNION ALL SELECT 'messages','info',0
    UNION ALL SELECT 'payments','info',0
) src
WHERE NOT EXISTS (
    SELECT 1 FROM notification_rules existing
    WHERE existing.topic=src.topic AND existing.action IS NULL
);

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('notifications.webhook_first_enabled', '1', 'notifications', 0),
('notifications.receiver_max_payload_bytes', '262144', 'notifications', 0),
('notifications.receiver_spool_enabled', '1', 'notifications', 0),
('notifications.worker_batch_limit', '15', 'notifications', 0),
('notifications.max_resources_per_account_per_run', '5', 'notifications', 0),
('notifications.worker_time_budget_seconds', '40', 'notifications', 0),
('notifications.debounce_seconds', '5', 'notifications', 0),
('notifications.target_sla_seconds', '120', 'notifications', 0),
('notifications.heartbeat_stale_seconds', '180', 'notifications', 0),
('notifications.dedicated_cron_interval_minutes', '1', 'notifications', 0),
('notifications.api_budget_reserve_percent', '25', 'notifications', 0),
('notifications.reconcile_healthy_minutes', '60', 'notifications', 0),
('notifications.reconcile_degraded_minutes', '15', 'notifications', 0),
('notifications.reconcile_overlap_hours', '2', 'notifications', 0),
('notifications.missed_feeds_interval_minutes', '360', 'notifications', 0),
('notifications.backfill_priority_days', '7', 'notifications', 0),
('notifications.backfill_batch_limit', '500', 'notifications', 0),
('notifications.event_retention_days', '180', 'notifications', 0),
('notifications.ignored_event_retention_days', '30', 'notifications', 0)
ON DUPLICATE KEY UPDATE
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.11.1', 'Notificaciones humanas y sincronización automática Webhook-First');
