CREATE TABLE IF NOT EXISTS api_request_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NULL,
    request_id VARCHAR(80) NULL,
    method VARCHAR(12) NOT NULL,
    endpoint_path VARCHAR(255) NOT NULL,
    http_status INT NULL,
    duration_ms INT UNSIGNED NULL,
    retry_after_seconds INT UNSIGNED NULL,
    attempt INT UNSIGNED NOT NULL DEFAULT 1,
    was_blocked TINYINT(1) NOT NULL DEFAULT 0,
    safe_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_api_request_account_time (meli_account_id, created_at),
    KEY idx_api_request_endpoint_time (meli_account_id, endpoint_path, created_at),
    KEY idx_api_request_http_time (http_status, created_at),
    CONSTRAINT fk_api_request_logs_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_circuit_breakers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NULL,
    endpoint_path VARCHAR(255) NOT NULL,
    reason VARCHAR(120) NOT NULL,
    http_status INT NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    blocked_until DATETIME NOT NULL,
    closed_at DATETIME NULL,
    last_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_api_circuit_account_endpoint (meli_account_id, endpoint_path),
    KEY idx_api_circuit_lookup (meli_account_id, endpoint_path, status, blocked_until),
    CONSTRAINT fk_api_circuit_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE operational_alerts
    MODIFY alert_type ENUM('producto_sin_vinculo','sin_sku','sin_costo','margen_negativo','404_repetido','429','webhook_pendiente','token_vencido','sync_fallida','api_rate_limit','api_circuit_open','pregunta_pendiente','cron_sin_senal','sync_bloqueada_por_api') NOT NULL;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('api.guard.enabled', '1', 'api_guard', 0),
('api.guard.max_429_per_window', '3', 'api_guard', 0),
('api.guard.max_403_per_window', '1', 'api_guard', 0),
('api.guard.window_minutes', '10', 'api_guard', 0),
('api.guard.cooldown_minutes', '15', 'api_guard', 0),
('api.guard.max_retry_attempts', '3', 'api_guard', 0),
('api.guard.jitter_min_ms', '250', 'api_guard', 0),
('api.guard.jitter_max_ms', '1500', 'api_guard', 0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.4.0', 'Proteccion anti-bloqueos ML, preguntas, agenda cron, logs y mejoras de ventas');
