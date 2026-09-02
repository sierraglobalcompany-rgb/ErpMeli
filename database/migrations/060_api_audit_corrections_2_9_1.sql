-- ERP Meli 2.9.1 — Correcciones API basadas en auditoría Mercado Libre.
-- Aditiva e idempotente. No modifica datos comerciales ni escribe en Mercado Libre.

CREATE TABLE IF NOT EXISTS meli_api_capabilities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    endpoint_path VARCHAR(255) NOT NULL,
    capability VARCHAR(120) NOT NULL,
    status VARCHAR(60) NOT NULL DEFAULT 'unknown',
    last_checked_at DATETIME NULL,
    last_error_message VARCHAR(500) NULL,
    cooldown_until DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_meli_api_capability (meli_account_id, endpoint_path, capability),
    KEY idx_meli_api_capabilities_status (status, cooldown_until),
    KEY idx_meli_api_capabilities_account (meli_account_id, endpoint_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
    ('questions.api_version','4','questions',0),
    ('questions.endpoint_confirmed','1','questions',0),
    ('claims.use_player_filters','1','claims',0),
    ('claims.fallback_without_player_filters','0','claims',0),
    ('items.search_mode','auto','items',0),
    ('items.scan_enabled','1','items',0),
    ('items.scan_threshold','1000','items',0),
    ('api.documentation_materialized','1','api',0),
    ('payments.expand_details_enabled','0','payments',0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.9.1', 'Correcciones operativas API Mercado Libre desde documentación materializada: preguntas v4, reclamos con filtros, endpoints bloqueados, capacidades y guardrails.');
