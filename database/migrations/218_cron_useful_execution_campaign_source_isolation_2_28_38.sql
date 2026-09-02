-- ERP Meli 2.28.38 — ejecución útil, fuentes pausadas aisladas y alcance inmediato.
-- No activa módulos, no modifica datos comerciales y no consulta Mercado Libre.

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='manual_campaign_items'
       AND index_name='idx_campaign_waiting_source')=0,
    'ALTER TABLE manual_campaign_items ADD INDEX idx_campaign_waiting_source (manual_campaign_id,source_state,next_eligible_at,id)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('manual_campaign.source_wait_seconds','60',0,'manual_campaign'),
('manual_campaign.source_preflight_enabled','1',0,'manual_campaign'),
('cron.capacity_claim_headroom','8',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.38',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=0,setting_group='system';

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('automation_center','218_cron_useful_execution_campaign_source_isolation_2_28_38.sql','structural',1),
('api_health','218_cron_useful_execution_campaign_source_isolation_2_28_38.sql','metadata',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=1;

INSERT INTO app_versions (version,notes)
VALUES ('2.28.38','Cron útil: fuentes pausadas aisladas antes del claim y alcance API sin caché obsoleto')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=UTC_TIMESTAMP();
