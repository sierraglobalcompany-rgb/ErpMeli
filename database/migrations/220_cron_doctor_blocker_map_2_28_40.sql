-- ERP Meli 2.28.40 — Cron Doctor y mapa de bloqueos.
-- Solo agrega metadata/contrato operativo; no modifica datos comerciales.

CREATE TABLE IF NOT EXISTS system_cron_doctor_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_token VARCHAR(100) NULL,
    requested_rpm DECIMAL(10,3) NULL,
    endpoint_allowed_rpm DECIMAL(10,3) NULL,
    observed_rpm DECIMAL(10,3) NULL,
    primary_blocker VARCHAR(120) NULL,
    blocker_payload_json JSON NULL,
    measured_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_cron_doctor_latest (measured_at,id),
    KEY idx_cron_doctor_run (run_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.doctor.enabled','1',0,'cron'),
('cron.doctor.snapshot_ttl_seconds','60',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.40',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts (component_key,required_migration,contract_kind,enabled)
VALUES ('process_sync_queue','220_cron_doctor_blocker_map_2_28_40.sql','metadata',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.40','Cron Doctor read-only para explicar ritmo solicitado, permitido, observado y bloqueo dominante')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
