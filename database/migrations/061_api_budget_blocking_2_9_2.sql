-- ERP Meli 2.9.2 — Presupuesto API preventivo y anti-bloqueo Mercado Libre.
-- Aditiva e idempotente. No modifica datos comerciales ni escribe en Mercado Libre.

CREATE TABLE IF NOT EXISTS api_budget_windows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope VARCHAR(40) NOT NULL,
    scope_key VARCHAR(255) NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    endpoint_path VARCHAR(255) NULL,
    job_type VARCHAR(80) NULL,
    window_started_at DATETIME NOT NULL,
    window_seconds INT UNSIGNED NOT NULL DEFAULT 900,
    request_limit INT UNSIGNED NOT NULL DEFAULT 0,
    request_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_400_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_401_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_403_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_429_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_5xx_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_request_at DATETIME NULL,
    cooldown_until DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_budget_window (scope_key, window_started_at, window_seconds),
    KEY idx_api_budget_scope_window (scope, window_started_at),
    KEY idx_api_budget_account_window (meli_account_id, window_started_at),
    KEY idx_api_budget_endpoint_window (endpoint_path, window_started_at),
    KEY idx_api_budget_job_window (job_type, window_started_at),
    KEY idx_api_budget_cooldown (cooldown_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_workload_estimates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    module VARCHAR(80) NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    estimated_calls INT UNSIGNED NOT NULL DEFAULT 0,
    allowed_calls INT UNSIGNED NOT NULL DEFAULT 0,
    result VARCHAR(40) NOT NULL DEFAULT 'allowed',
    safe_reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_api_workload_module_created (module, created_at),
    KEY idx_api_workload_result_created (result, created_at),
    KEY idx_api_workload_account_created (meli_account_id, created_at),
    KEY idx_api_workload_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
    ('api.budget.enabled','1','api',0),
    ('api.budget.window_seconds','900','api',0),
    ('api.budget.global_requests_per_15m','300','api',0),
    ('api.budget.account_requests_per_15m','120','api',0),
    ('api.budget.endpoint_requests_per_15m','50','api',0),
    ('api.budget.job_type_requests_per_15m','80','api',0),
    ('api.budget.web_request_api_limit','10','api',0),
    ('api.guard.max_400_per_window','5','api',0),
    ('api.guard.max_unknown_400_per_window','3','api',0),
    ('api.guard.unauthorized_scopes_global_pause_minutes','1440','api',0),
    ('api.cron.priority_budget_enabled','1','api',0),
    ('items.sync_descriptions_inline_enabled','0','items',0),
    ('sales_audit.exact_queue_required_threshold_days','7','sales_audit',0),
    ('claims.detail_capability_cooldown_minutes','1440','claims',0),
    ('payments.expand_details_enabled','0','payments',0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.9.2', 'Presupuesto API preventivo, circuitos por 400 repetidos, pausa global por unauthorized_scopes y priorización de cron anti-bloqueo Mercado Libre.');
