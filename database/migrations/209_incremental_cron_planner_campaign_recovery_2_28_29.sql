-- ERP Meli 2.28.29 — planificador incremental y recuperación dirigida.
-- Solo registra contratos y defaults operativos. No modifica órdenes, pagos,
-- campañas, cuentas, OAuth, cierres ni evidencia fiscal.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.incremental_planner_enabled','1',0,'cron'),
('cron.measure_after_execution','1',0,'cron'),
('cron.directed_before_global_probe','1',0,'cron'),
('cron.persist_not_started_reason','1',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.29',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','209_incremental_cron_planner_campaign_recovery_2_28_29.sql','structural',1),
('automation_center','209_incremental_cron_planner_campaign_recovery_2_28_29.sql','structural',1),
('manual_campaigns','209_incremental_cron_planner_campaign_recovery_2_28_29.sql','structural',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.29','Planificador Cron incremental, campaña dirigida prioritaria y medición fuera del camino crítico')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
