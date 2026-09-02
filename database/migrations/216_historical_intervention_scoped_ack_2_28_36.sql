-- ERP Meli 2.28.36 — reconciliación histórica e intervención exacta.
-- Solo agrega trazabilidad técnica. No cambia órdenes, ítems, pagos, envíos,
-- campañas, cuentas, OAuth, cierres ni evidencia fiscal.

CREATE TABLE IF NOT EXISTS system_work_historical_reconciliations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    queue_key VARCHAR(80) NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    observed_source_status VARCHAR(40) NOT NULL,
    observed_generation INT UNSIGNED NOT NULL DEFAULT 0,
    observed_source_updated_at DATETIME(3) NULL,
    normalized_error_code VARCHAR(80) NOT NULL,
    failure_class VARCHAR(80) NOT NULL,
    reached_remote TINYINT(1) NULL,
    diagnostic_id VARCHAR(80) NOT NULL,
    safe_message VARCHAR(500) NOT NULL,
    reconciliation_state VARCHAR(50) NOT NULL,
    reconciled_by BIGINT UNSIGNED NOT NULL,
    reconciled_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_work_historical_resource (company_id,meli_account_id,queue_key,source_id),
    KEY idx_work_historical_scope (company_id,meli_account_id,reconciled_at),
    KEY idx_work_historical_state (reconciliation_state,reconciled_at),
    KEY idx_work_historical_diagnostic (diagnostic_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.historical_reconciliation_enabled','1',0,'cron'),
('cron.group_mutations_enabled','0',0,'cron'),
('api.health.scope_acknowledgement_required','1',0,'api_health'),
('api.health.incident_pagination_enabled','1',0,'api_health');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.36',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('automation_center','216_historical_intervention_scoped_ack_2_28_36.sql','structural',1),
('api_health','216_historical_intervention_scoped_ack_2_28_36.sql','structural',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.36','Reconciliación histórica exacta, centro de intervención y reconocimiento de incidentes por alcance')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
