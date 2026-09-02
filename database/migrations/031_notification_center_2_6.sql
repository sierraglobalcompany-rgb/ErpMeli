CREATE TABLE IF NOT EXISTS meli_notification_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    notification_id VARCHAR(120) NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    meli_user_id BIGINT UNSIGNED NULL,
    application_id VARCHAR(120) NULL,
    topic VARCHAR(80) NOT NULL,
    actions_json JSON NULL,
    resource VARCHAR(255) NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    sent_at DATETIME NULL,
    received_at DATETIME NULL,
    erp_received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source_ip VARCHAR(128) NULL,
    user_agent VARCHAR(255) NULL,
    payload_json JSON NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    status ENUM('received','queued','processing','processed','ignored','duplicate','failed','waiting_retry','unknown_topic','circuit_breaker_wait') NOT NULL DEFAULT 'received',
    priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
    process_after DATETIME NULL,
    processed_at DATETIME NULL,
    error_message VARCHAR(500) NULL,
    retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meli_notification_id (notification_id),
    UNIQUE KEY uq_meli_notification_hash (payload_hash),
    KEY idx_meli_notification_queue (status, process_after, priority, erp_received_at),
    KEY idx_meli_notification_account_topic (meli_account_id, topic, status),
    KEY idx_meli_notification_resource (meli_account_id, topic, resource),
    CONSTRAINT fk_meli_notification_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    notification_event_id BIGINT UNSIGNED NULL,
    type ENUM('order','payment','claim','question','item','shipment','invoice','api','sync','system') NOT NULL DEFAULT 'system',
    severity ENUM('info','warning','high','critical') NOT NULL DEFAULT 'info',
    title VARCHAR(190) NOT NULL,
    message VARCHAR(500) NULL,
    action_url VARCHAR(255) NULL,
    entity_type VARCHAR(80) NULL,
    entity_id BIGINT UNSIGNED NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    dismissed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_app_notifications_unread (is_read, severity, created_at),
    KEY idx_app_notifications_account (meli_account_id, created_at),
    CONSTRAINT fk_app_notifications_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    CONSTRAINT fk_app_notifications_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_app_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_app_notifications_event FOREIGN KEY (notification_event_id) REFERENCES meli_notification_events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    topic VARCHAR(80) NOT NULL,
    action VARCHAR(120) NULL,
    severity ENUM('info','warning','high','critical') NOT NULL DEFAULT 'info',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    create_app_notification TINYINT(1) NOT NULL DEFAULT 1,
    show_dashboard_badge TINYINT(1) NOT NULL DEFAULT 1,
    min_interval_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notification_rule_topic_action (topic, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS missed_feed_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id VARCHAR(120) NOT NULL,
    topic VARCHAR(80) NULL,
    status ENUM('running','success','error') NOT NULL DEFAULT 'running',
    offset_value INT UNSIGNED NOT NULL DEFAULT 0,
    limit_value INT UNSIGNED NOT NULL DEFAULT 20,
    fetched_count INT UNSIGNED NOT NULL DEFAULT 0,
    inserted_count INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_missed_feed_runs_created (created_at),
    CONSTRAINT fk_missed_feed_runs_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO notification_rules (topic, action, severity, enabled, create_app_notification, show_dashboard_badge, min_interval_minutes)
VALUES
('orders', NULL, 'high', 1, 1, 1, 0),
('orders_v2', NULL, 'high', 1, 1, 1, 0),
('post_purchase', NULL, 'high', 1, 1, 1, 0),
('questions', NULL, 'high', 1, 1, 1, 0),
('payments', NULL, 'info', 0, 1, 0, 0),
('items', NULL, 'info', 0, 1, 0, 0),
('shipments', NULL, 'info', 0, 1, 0, 0),
('invoices', NULL, 'info', 0, 1, 0, 0)
ON DUPLICATE KEY UPDATE severity=VALUES(severity);

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('notifications.enabled', '1', 'notifications', 0),
('notifications.safe_mode', '1', 'notifications', 0),
('notifications.max_events_per_run', '20', 'notifications', 0),
('notifications.max_resources_per_account_per_run', '10', 'notifications', 0),
('notifications.pause_between_requests_ms', '750', 'notifications', 0),
('notifications.max_retries', '3', 'notifications', 0),
('notifications.cooldown_429_minutes', '30', 'notifications', 0),
('notifications.cooldown_403_minutes', '60', 'notifications', 0),
('notifications.missed_feeds_enabled', '0', 'notifications', 0),
('notifications.show_bell', '1', 'notifications', 0),
('notifications.show_health', '1', 'notifications', 0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.6.0', 'Centro de Notificaciones y Avisos Mercado Libre');
