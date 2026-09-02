CREATE TABLE IF NOT EXISTS api_manual_pauses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope VARCHAR(20) NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    pause_mode VARCHAR(20) NOT NULL DEFAULT 'timed',
    paused_until DATETIME NULL,
    reason VARCHAR(500) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resumed_by BIGINT UNSIGNED NULL,
    resumed_at DATETIME NULL,
    resume_reason VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_api_manual_pause_active (status,scope,paused_until),
    KEY idx_api_manual_pause_account (meli_account_id,status,paused_until),
    KEY idx_api_manual_pause_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_incident_acknowledgements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    incident_key CHAR(64) NOT NULL,
    acknowledged_by BIGINT UNSIGNED NULL,
    acknowledged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(500) NULL,
    muted_until DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_incident_ack_key (incident_key),
    KEY idx_api_incident_ack_time (acknowledged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE api_request_logs
SET outcome_class='expected_absence',
    actionable=0,
    risk_signal=0,
    reached_remote=1
WHERE http_status=404
  AND endpoint_path LIKE '%/description';

UPDATE api_request_logs
SET actionable=0,
    risk_signal=0
WHERE endpoint_path LIKE '%/payments/%'
  AND created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY;

SET @has_idx_risk_time = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_request_logs' AND INDEX_NAME='idx_api_request_risk_time'
);
SET @sql_idx_risk_time = IF(
    @has_idx_risk_time=0,
    'ALTER TABLE api_request_logs ADD KEY idx_api_request_risk_time (risk_signal,created_at)',
    'SELECT 1'
);
PREPARE stmt_idx_risk_time FROM @sql_idx_risk_time;
EXECUTE stmt_idx_risk_time;
DEALLOCATE PREPARE stmt_idx_risk_time;

SET @has_idx_remote_time = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_request_logs' AND INDEX_NAME='idx_api_request_remote_time'
);
SET @sql_idx_remote_time = IF(
    @has_idx_remote_time=0,
    'ALTER TABLE api_request_logs ADD KEY idx_api_request_remote_time (reached_remote,created_at)',
    'SELECT 1'
);
PREPARE stmt_idx_remote_time FROM @sql_idx_remote_time;
EXECUTE stmt_idx_remote_time;
DEALLOCATE PREPARE stmt_idx_remote_time;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('api.manual_pause.enabled','1','api_guard',0),
('api.health.global_banner_enabled','1','api_guard',0),
('api.health.banner_refresh_seconds','45','api_guard',0),
('api.logs.default_per_page','50','api_guard',0),
('api.logs.education_enabled','1','api_guard',0),
('api.logs.retention_days','90','api_guard',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.16.2','Control de emergencia API, logs explicables, documentación reparada y aislamiento modular');
