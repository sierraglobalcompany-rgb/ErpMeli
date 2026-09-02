SET @has_outcome_class = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_request_logs' AND COLUMN_NAME='outcome_class'
);
SET @sql_outcome_class = IF(
    @has_outcome_class=0,
    'ALTER TABLE api_request_logs ADD COLUMN outcome_class VARCHAR(32) NULL AFTER is_app_blocked_signal',
    'SELECT 1'
);
PREPARE stmt_outcome_class FROM @sql_outcome_class;
EXECUTE stmt_outcome_class;
DEALLOCATE PREPARE stmt_outcome_class;

SET @has_reached_remote = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_request_logs' AND COLUMN_NAME='reached_remote'
);
SET @sql_reached_remote = IF(
    @has_reached_remote=0,
    'ALTER TABLE api_request_logs ADD COLUMN reached_remote TINYINT(1) NOT NULL DEFAULT 0 AFTER outcome_class',
    'SELECT 1'
);
PREPARE stmt_reached_remote FROM @sql_reached_remote;
EXECUTE stmt_reached_remote;
DEALLOCATE PREPARE stmt_reached_remote;

SET @has_actionable = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_request_logs' AND COLUMN_NAME='actionable'
);
SET @sql_actionable = IF(
    @has_actionable=0,
    'ALTER TABLE api_request_logs ADD COLUMN actionable TINYINT(1) NOT NULL DEFAULT 0 AFTER reached_remote',
    'SELECT 1'
);
PREPARE stmt_actionable FROM @sql_actionable;
EXECUTE stmt_actionable;
DEALLOCATE PREPARE stmt_actionable;

SET @has_risk_signal = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_request_logs' AND COLUMN_NAME='risk_signal'
);
SET @sql_risk_signal = IF(
    @has_risk_signal=0,
    'ALTER TABLE api_request_logs ADD COLUMN risk_signal TINYINT(1) NOT NULL DEFAULT 0 AFTER actionable',
    'SELECT 1'
);
PREPARE stmt_risk_signal FROM @sql_risk_signal;
EXECUTE stmt_risk_signal;
DEALLOCATE PREPARE stmt_risk_signal;

SET @has_incident_key = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_request_logs' AND COLUMN_NAME='incident_key'
);
SET @sql_incident_key = IF(
    @has_incident_key=0,
    'ALTER TABLE api_request_logs ADD COLUMN incident_key CHAR(64) NULL AFTER risk_signal',
    'SELECT 1'
);
PREPARE stmt_incident_key FROM @sql_incident_key;
EXECUTE stmt_incident_key;
DEALLOCATE PREPARE stmt_incident_key;

UPDATE api_request_logs
SET outcome_class = CASE
        WHEN http_status>=200 AND http_status<400 THEN 'success'
        WHEN http_status=404 AND endpoint_path LIKE '%/description' THEN 'expected_absence'
        WHEN is_app_blocked_signal=1
             OR LOWER(COALESCE(error_code,'')) IN ('unauthorized_scopes','excessive_api_call')
             OR LOWER(COALESCE(safe_message,'')) LIKE '%unauthorized_scopes%'
             OR LOWER(COALESCE(safe_message,'')) LIKE '%excessive_api_call%' THEN 'blocked_signal'
        WHEN http_status>=400 THEN 'remote_error'
        WHEN error_type IN ('api_budget_exhausted','api_circuit_open')
             AND LOWER(COALESCE(safe_message,'')) NOT LIKE '%already an active transaction%' THEN 'policy_delay'
        ELSE 'local_failure'
    END,
    reached_remote = IF(http_status IS NULL,0,1)
WHERE outcome_class IS NULL OR outcome_class='';

UPDATE api_request_logs
SET outcome_class='local_failure',
    reached_remote=0,
    actionable=1,
    risk_signal=0,
    error_type='api_budget_infrastructure'
WHERE LOWER(COALESCE(safe_message,'')) LIKE '%already an active transaction%';

UPDATE api_request_logs
SET actionable = CASE
        WHEN outcome_class IN ('success','expected_absence') THEN 0
        ELSE 1
    END,
    risk_signal = CASE
        WHEN outcome_class='blocked_signal' THEN 1
        WHEN outcome_class='remote_error' AND http_status IN (401,403,429) THEN 1
        ELSE 0
    END;

UPDATE api_request_logs
SET incident_key = SHA2(
    CONCAT_WS(
        '|',
        UPPER(method),
        endpoint_path,
        outcome_class,
        LOWER(COALESCE(error_type,'')),
        LOWER(COALESCE(error_code,'')),
        LOWER(LEFT(COALESCE(safe_message,''),240))
    ),
    256
)
WHERE outcome_class<>'success' AND incident_key IS NULL;

SET @has_idx_outcome = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_request_logs' AND INDEX_NAME='idx_api_request_outcome_time'
);
SET @sql_idx_outcome = IF(
    @has_idx_outcome=0,
    'ALTER TABLE api_request_logs ADD KEY idx_api_request_outcome_time (outcome_class,created_at)',
    'SELECT 1'
);
PREPARE stmt_idx_outcome FROM @sql_idx_outcome;
EXECUTE stmt_idx_outcome;
DEALLOCATE PREPARE stmt_idx_outcome;

SET @has_idx_incident = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_request_logs' AND INDEX_NAME='idx_api_request_incident_time'
);
SET @sql_idx_incident = IF(
    @has_idx_incident=0,
    'ALTER TABLE api_request_logs ADD KEY idx_api_request_incident_time (incident_key,created_at)',
    'SELECT 1'
);
PREPARE stmt_idx_incident FROM @sql_idx_incident;
EXECUTE stmt_idx_incident;
DEALLOCATE PREPARE stmt_idx_incident;

SET @has_idx_account_outcome = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_request_logs' AND INDEX_NAME='idx_api_request_account_outcome'
);
SET @sql_idx_account_outcome = IF(
    @has_idx_account_outcome=0,
    'ALTER TABLE api_request_logs ADD KEY idx_api_request_account_outcome (meli_account_id,outcome_class,created_at)',
    'SELECT 1'
);
PREPARE stmt_idx_account_outcome FROM @sql_idx_account_outcome;
EXECUTE stmt_idx_account_outcome;
DEALLOCATE PREPARE stmt_idx_account_outcome;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('api.health.active_window_minutes','15','api_guard',0),
('api.health.incidents_enabled','1','api_guard',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.16.1','Salud API explicable, incidentes navegables y clasificación de riesgo real');
