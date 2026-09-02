-- ERP Meli 2.28.44 — campaña dirigida con candidatos ejecutables.

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='manual_campaign_events'
       AND index_name='idx_campaign_event_dedupe')=0,
    'ALTER TABLE manual_campaign_events ADD INDEX idx_campaign_event_dedupe (manual_campaign_id,event_type,operation_key,meli_account_id,created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('manual_campaign.executable_projection_enabled','1',0,'manual_campaign'),
('manual_campaign.waiting_source_event_dedupe_seconds','900',0,'manual_campaign');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.44',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts (component_key,required_migration,contract_kind,enabled)
VALUES ('manual_campaign','224_campaign_executable_projection_2_28_44.sql','structural',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.44','Campaña dirigida salta fuentes no ejecutables sin consumir ventana ni duplicar eventos')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
