-- ERP Meli 2.9.4 — Migrador compatible con PDO nativo y trazabilidad segura.
-- Aditiva e idempotente. No modifica datos comerciales ni credenciales.

CREATE TABLE IF NOT EXISTS system_update_migration_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    diagnostic_id VARCHAR(64) NOT NULL,
    migration_key VARCHAR(180) NULL,
    stage VARCHAR(80) NOT NULL,
    status VARCHAR(30) NOT NULL,
    duration_ms INT UNSIGNED NULL,
    checksum_sha256 CHAR(64) NULL,
    file_version VARCHAR(40) NULL,
    installed_version VARCHAR(40) NULL,
    php_version VARCHAR(40) NULL,
    php_sapi VARCHAR(40) NULL,
    pdo_driver VARCHAR(40) NULL,
    sql_state VARCHAR(20) NULL,
    driver_code VARCHAR(30) NULL,
    exception_class VARCHAR(190) NULL,
    safe_message VARCHAR(700) NULL,
    context_json MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_migration_events_diagnostic (diagnostic_id, created_at),
    KEY idx_migration_events_migration (migration_key, created_at),
    KEY idx_migration_events_stage (stage, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
    ('update.migration_debug_enabled','1','update',0),
    ('update.migration_debug_retention_days','30','update',0),
    ('update.migration_debug_max_events','2000','update',0),
    ('update.migration_debug_file_fallback','1','update',0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.9.4', 'Migrador sin upsert parametrizado complejo, diagnóstico por etapas y trazabilidad sanitizada exportable.');
