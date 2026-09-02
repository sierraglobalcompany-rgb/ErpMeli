-- ERP Meli 2.22.1 — contratos de presentación operativa y consolidación segura.

CREATE TABLE IF NOT EXISTS system_queue_health (
    queue_key VARCHAR(80) NOT NULL PRIMARY KEY,
    status ENUM('healthy','degraded','unavailable') NOT NULL DEFAULT 'healthy',
    last_checked_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_failure_at DATETIME NULL,
    safe_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE system_work_queue_projection
    ADD COLUMN IF NOT EXISTS heartbeat_at DATETIME NULL AFTER started_at_source,
    ADD COLUMN IF NOT EXISTS progress_kind ENUM('unknown','resources','units','pages')
        NOT NULL DEFAULT 'unknown' AFTER progress_total,
    ADD COLUMN IF NOT EXISTS incident_group_key CHAR(64) NULL AFTER diagnostic_id;

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES ('automation_center','117_operational_ux_contracts_2_22_1.sql','structural',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),enabled=1;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('automation.default_view','attention','automation',0),
('automation.recent_completed_limit','10','automation',0),
('automation.running_heartbeat_seconds','180','automation',0),
('automation.group_equivalent_errors','1','automation',0),
('manual_campaign.status_event_limit','10','manual_campaign',0),
('sales_control.help_hover_delay_ms','2000','sales_control',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.22.1','Automatización humana, salud separada y contratos operativos consolidados')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
