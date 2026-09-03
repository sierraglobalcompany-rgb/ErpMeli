INSERT IGNORE INTO app_settings(setting_key, setting_value, is_encrypted, setting_group) VALUES
('automation.max_api_calls_per_cycle', '1', 0, 'automation'),
('api.rhythm.shared_429_backoff_seconds', '1800', 0, 'api_rhythm'),
('alerts.email.enabled', '0', 0, 'alerts'),
('alerts.email.to', '', 0, 'alerts'),
('alerts.email.cooldown_minutes', '60', 0, 'alerts'),
('alerts.email.notify_429', '1', 0, 'alerts'),
('alerts.email.notify_auth', '1', 0, 'alerts');

CREATE TABLE IF NOT EXISTS api_critical_email_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fingerprint CHAR(64) NOT NULL,
    incident_key VARCHAR(190) NOT NULL,
    scope_key VARCHAR(190) NOT NULL,
    first_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    last_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    repetition_count INT UNSIGNED NOT NULL DEFAULT 1,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    last_attempt_at DATETIME(3) NULL,
    last_sent_at DATETIME(3) NULL,
    next_attempt_at DATETIME(3) NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error_code VARCHAR(120) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_api_critical_email_fingerprint (fingerprint),
    KEY idx_api_critical_email_next_attempt (status, next_attempt_at),
    KEY idx_api_critical_email_incident (incident_key),
    KEY idx_api_critical_email_scope (scope_key)
);
