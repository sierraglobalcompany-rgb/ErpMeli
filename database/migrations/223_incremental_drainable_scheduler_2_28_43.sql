-- ERP Meli 2.28.43 — scheduler incremental y drenable.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.scheduler.drainable_deadline_enabled','1',0,'cron'),
('cron.scheduler.selected_after_claim_only','1',0,'cron'),
('cron.scheduler.started_requires_useful_work','1',0,'cron'),
('cron.directed_min_start_seconds','4',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.43',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts (component_key,required_migration,contract_kind,enabled)
VALUES ('process_sync_queue','223_incremental_drainable_scheduler_2_28_43.sql','structural',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.43','Scheduler incremental: una cola que no cabe no cierra todo el ciclo y started exige trabajo útil')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
