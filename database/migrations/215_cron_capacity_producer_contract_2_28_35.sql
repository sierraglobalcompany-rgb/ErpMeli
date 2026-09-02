-- ERP Meli 2.28.35 — capacidad Cron, productores y contrato exacto de campaña.
-- Solo agrega telemetría y fencing técnico. No modifica información comercial,
-- campañas existentes, cuentas, OAuth, órdenes, pagos ni cierres.

CREATE TABLE IF NOT EXISTS system_cron_capacity_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_token VARCHAR(100) NOT NULL,
    profile VARCHAR(32) NOT NULL,
    requested_rpm DECIMAL(8,2) NOT NULL,
    permitted_rpm DECIMAL(8,2) NOT NULL,
    minimum_interval_ms INT UNSIGNED NOT NULL,
    runtime_seconds SMALLINT UNSIGNED NOT NULL,
    accept_seconds SMALLINT UNSIGNED NOT NULL,
    safe_close_seconds SMALLINT UNSIGNED NOT NULL,
    transport_slots SMALLINT UNSIGNED NOT NULL,
    max_claims SMALLINT UNSIGNED NOT NULL,
    limit_reason VARCHAR(120) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cron_capacity_run (run_token),
    KEY idx_cron_capacity_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_cron_producer_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_token VARCHAR(100) NOT NULL,
    queue_key VARCHAR(80) NOT NULL,
    producer_key VARCHAR(80) NOT NULL DEFAULT 'callback',
    newly_discovered INT UNSIGNED NULL,
    deduplicated INT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cron_producer_run_queue (run_token,queue_key,producer_key),
    KEY idx_cron_producer_queue_created (queue_key,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE manual_campaigns
    ADD COLUMN IF NOT EXISTS last_scheduler_planned_at DATETIME(3) NULL AFTER next_launcher_at,
    ADD COLUMN IF NOT EXISTS last_scheduler_claimed_at DATETIME(3) NULL AFTER last_scheduler_selected_at,
    ADD COLUMN IF NOT EXISTS blocking_item_id BIGINT UNSIGNED NULL AFTER last_scheduler_claimed_at;

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.capacity_plan_enabled','1',0,'cron'),
('cron.bootstrap_headroom_seconds','10',0,'cron'),
('manual_campaign.candidate_scan_limit','100',0,'manual_processing');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.35',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('automation_center','215_cron_capacity_producer_contract_2_28_35.sql','structural',1),
('manual_processing','215_cron_capacity_producer_contract_2_28_35.sql','structural',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.35','Capacidad Cron persistida, round-robin real, métricas de productores y campaña cercada')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
