ALTER TABLE api_request_logs
    ADD COLUMN error_type VARCHAR(80) NULL AFTER safe_message,
    ADD COLUMN error_code VARCHAR(120) NULL AFTER error_type,
    ADD COLUMN is_retryable TINYINT(1) NOT NULL DEFAULT 0 AFTER error_code,
    ADD COLUMN is_app_blocked_signal TINYINT(1) NOT NULL DEFAULT 0 AFTER is_retryable,
    ADD KEY idx_api_request_error_type_time (error_type, created_at),
    ADD KEY idx_api_request_blocked_signal (is_app_blocked_signal, created_at);

ALTER TABLE api_circuit_breakers
    ADD COLUMN error_type VARCHAR(80) NULL AFTER http_status,
    ADD COLUMN failure_count INT UNSIGNED NOT NULL DEFAULT 1 AFTER error_type,
    ADD COLUMN window_started_at DATETIME NULL AFTER failure_count,
    ADD COLUMN next_retry_at DATETIME NULL AFTER blocked_until,
    ADD COLUMN scope ENUM('endpoint','account','app','module') NOT NULL DEFAULT 'endpoint' AFTER next_retry_at,
    ADD COLUMN manually_closed_by BIGINT UNSIGNED NULL AFTER closed_at,
    ADD COLUMN manually_closed_at DATETIME NULL AFTER manually_closed_by,
    ADD KEY idx_api_circuit_scope_status (scope, status, blocked_until),
    ADD KEY idx_api_circuit_error_type (error_type, status, blocked_until),
    ADD CONSTRAINT fk_api_circuit_manual_user FOREIGN KEY (manually_closed_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS api_guard_admin_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    api_circuit_breaker_id BIGINT UNSIGNED NULL,
    reason VARCHAR(500) NULL,
    payload_json JSON NULL,
    ip_hash VARCHAR(128) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_api_guard_actions_user_time (user_id, created_at),
    KEY idx_api_guard_actions_account_time (meli_account_id, created_at),
    KEY idx_api_guard_actions_action_time (action, created_at),
    CONSTRAINT fk_api_guard_actions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_api_guard_actions_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_api_guard_actions_circuit FOREIGN KEY (api_circuit_breaker_id) REFERENCES api_circuit_breakers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('api.guard.max_401_per_window', '2', 'api_guard', 0),
('api.guard.max_5xx_per_window', '3', 'api_guard', 0),
('api.guard.app_blocked_cooldown_minutes', '1440', 'api_guard', 0),
('api.guard.pause_global_on_app_blocked', '1', 'api_guard', 0),
('api.guard.export_sanitized_enabled', '1', 'api_guard', 0),
('api.logs.request_retention_days', '60', 'api_guard', 0),
('api.logs.error_retention_days', '180', 'api_guard', 0),
('api.logs.raw_retention_days', '365', 'api_guard', 0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.5.1', 'Proteccion anti-bloqueo Mercado Libre, clasificador de errores, salud API y acciones admin auditadas');
