-- ERP Meli 2.28.24 — Salud API e incidentes con alcance verificable.
-- Solo amplía telemetría técnica. No modifica órdenes, pagos, OAuth cifrado,
-- campañas, cierres ni evidencia fiscal.

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='api_request_logs' AND column_name='company_id')=0,
    'ALTER TABLE api_request_logs ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER meli_account_id','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='api_request_logs' AND column_name='scope_kind')=0,
    'ALTER TABLE api_request_logs ADD COLUMN scope_kind VARCHAR(20) NOT NULL DEFAULT ''account'' AFTER company_id','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='api_request_logs' AND column_name='diagnostic_id')=0,
    'ALTER TABLE api_request_logs ADD COLUMN diagnostic_id VARCHAR(80) NULL AFTER safe_message','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- No se hace backfill masivo durante actualizar.php. Las escrituras nuevas
-- guardan el alcance completo y las lecturas legacy derivan la empresa con un
-- JOIN acotado. Un backfill físico, si se desea, debe ser CLI y por lotes.

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='api_request_logs' AND index_name='idx_api_request_scope_time')=0,
    'ALTER TABLE api_request_logs ADD INDEX idx_api_request_scope_time (scope_kind,company_id,created_at)','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='api_request_logs' AND index_name='idx_api_request_account_incident_time')=0,
    'ALTER TABLE api_request_logs ADD INDEX idx_api_request_account_incident_time (meli_account_id,incident_key,created_at)','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='api_incident_acknowledgements' AND column_name='scope_key')=0,
    'ALTER TABLE api_incident_acknowledgements ADD COLUMN scope_key VARCHAR(80) NOT NULL DEFAULT ''application'' AFTER incident_key','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='api_incident_acknowledgements' AND column_name='scope_kind')=0,
    'ALTER TABLE api_incident_acknowledgements ADD COLUMN scope_kind VARCHAR(20) NOT NULL DEFAULT ''application'' AFTER scope_key','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='api_incident_acknowledgements' AND column_name='company_id')=0,
    'ALTER TABLE api_incident_acknowledgements ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER scope_kind','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='api_incident_acknowledgements' AND column_name='meli_account_id')=0,
    'ALTER TABLE api_incident_acknowledgements ADD COLUMN meli_account_id BIGINT UNSIGNED NULL AFTER company_id','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='api_incident_acknowledgements' AND column_name='acknowledged_through_at')=0,
    'ALTER TABLE api_incident_acknowledgements ADD COLUMN acknowledged_through_at DATETIME NULL AFTER acknowledged_at','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE api_incident_acknowledgements
SET scope_key=COALESCE(NULLIF(scope_key,''),'application'),
    scope_kind=CASE
        WHEN meli_account_id IS NOT NULL THEN 'account'
        WHEN company_id IS NOT NULL THEN 'company'
        ELSE 'application'
    END,
    acknowledged_through_at=COALESCE(acknowledged_through_at,acknowledged_at);

SET @has_old_ack_unique := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='api_incident_acknowledgements'
      AND index_name='uq_api_incident_ack_key'
);
SET @drop_old_ack_unique := IF(
    @has_old_ack_unique>0,
    'ALTER TABLE api_incident_acknowledgements DROP INDEX uq_api_incident_ack_key',
    'SELECT 1'
);
PREPARE stmt FROM @drop_old_ack_unique;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_scoped_ack_unique := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='api_incident_acknowledgements'
      AND index_name='uq_api_incident_ack_scope'
);
SET @add_scoped_ack_unique := IF(
    @has_scoped_ack_unique=0,
    'ALTER TABLE api_incident_acknowledgements ADD UNIQUE KEY uq_api_incident_ack_scope (incident_key,scope_key)',
    'SELECT 1'
);
PREPARE stmt FROM @add_scoped_ack_unique;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='api_incident_acknowledgements' AND index_name='idx_api_incident_ack_scope_time')=0,
    'ALTER TABLE api_incident_acknowledgements ADD INDEX idx_api_incident_ack_scope_time (scope_kind,company_id,meli_account_id,acknowledged_at)','SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS api_incident_acknowledgement_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    incident_key CHAR(64) NOT NULL,
    scope_key VARCHAR(80) NOT NULL,
    scope_kind VARCHAR(20) NOT NULL,
    company_id BIGINT UNSIGNED NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    acknowledged_by BIGINT UNSIGNED NULL,
    acknowledged_through_at DATETIME NOT NULL,
    note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_api_incident_ack_event_scope (incident_key,scope_key,created_at),
    KEY idx_api_incident_ack_event_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('api.health.scoped_incidents','1',0,'api_health'),
('api.health.remote_evidence_required','1',0,'api_health'),
('api.health.incident_page_size','50',0,'api_health'),
('app.version','2.28.24',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('api_health','204_api_health_incident_truth_2_28_24.sql','structural',1),
('api_guard','204_api_health_incident_truth_2_28_24.sql','structural',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.24','Salud API scoped, incidentes reabribles y errores sanitizados')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
