-- ERP Meli 2.28.31 — perfiles de transporte HTTP y reloj único de Cron.
-- Agrega únicamente coordinación técnica. No modifica datos comerciales,
-- campañas, cuentas, OAuth, órdenes, pagos, cierres ni evidencia fiscal.

CREATE TABLE IF NOT EXISTS api_rhythm_penalties (
    scope_key VARCHAR(180) NOT NULL,
    reduced_limit_per_minute SMALLINT UNSIGNED NOT NULL,
    blocked_until DATETIME(3) NULL,
    reduced_until DATETIME(3) NOT NULL,
    reason VARCHAR(80) NOT NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (scope_key),
    KEY idx_api_rhythm_penalty_expiry (reduced_until,blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('api.rhythm.profile','fast',0,'api_rhythm'),
('api.rhythm.target_http_per_minute','30',0,'api_rhythm'),
('api.rhythm.minimum_interval_ms','1000',0,'api_rhythm'),
('api.rhythm.rolling_window_seconds','60',0,'api_rhythm'),
('api.rhythm.current_adaptive_limit','15',0,'api_rhythm'),
('api.rhythm.current_level_started_at','',0,'api_rhythm'),
('api.rhythm.last_ramp_evaluation_at','',0,'api_rhythm'),
('api.rhythm.ramp_evaluation_minutes','30',0,'api_rhythm'),
('api.rhythm.ramp_stable_after_429_minutes','60',0,'api_rhythm');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.31',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','211_configurable_adaptive_http_scheduler_2_28_31.sql','structural',1),
('api_rhythm','211_configurable_adaptive_http_scheduler_2_28_31.sql','structural',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.31','Perfiles HTTP 10/20/30/40, rampa adaptativa, ventana rodante y reloj único de Cron')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
