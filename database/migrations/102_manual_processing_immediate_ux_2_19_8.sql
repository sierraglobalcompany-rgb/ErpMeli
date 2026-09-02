-- ERP Meli 2.19.8 — Procesar ahora visible e inicio conservador inmediato.
-- Aditiva e idempotente. No modifica datos comerciales ni realiza llamadas remotas.

CREATE TABLE IF NOT EXISTS manual_processing_engine_health (
    component VARCHAR(80) NOT NULL PRIMARY KEY,
    worker_heartbeat_at DATETIME NULL,
    last_worker_result VARCHAR(40) NULL,
    last_worker_message VARCHAR(500) NULL,
    release_version VARCHAR(30) NULL,
    release_build_id VARCHAR(100) NULL,
    last_check_at DATETIME NULL,
    last_check_result VARCHAR(40) NULL,
    last_check_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_manual_engine_heartbeat (worker_heartbeat_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO manual_processing_engine_health (component,last_worker_result,last_worker_message)
VALUES ('process_manual_queue','waiting','Esperando la primera ejecución CLI del motor manual.')
ON DUPLICATE KEY UPDATE component=VALUES(component);

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('manual_processing.default_scope','recommended','manual_processing',0),
('manual_processing.conservative_immediate_enabled','1','manual_processing',0),
('manual_processing.worker_stale_seconds','180','manual_processing',0),
('manual_processing.engine_interval_minutes','1','manual_processing',0),
('manual_processing.descriptions_require_confirmation','1','manual_processing',0),
('manual_processing.abandoned_return_minutes','15','manual_processing',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.19.8','Procesar ahora visible, motor manual verificable e inicio conservador inmediato')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
