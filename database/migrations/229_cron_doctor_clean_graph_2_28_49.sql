-- ERP Meli 2.28.49 — Cron Doctor y mapa limpio de bloqueos.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.doctor.enabled','1',0,'cron'),
('cron.doctor.read_only_required','1',0,'cron'),
('cron.doctor.graph_scope','process_sync_queue,CronExecutionPlanner,CronTaskStateService,CronWorkCoordinator,ApiRhythmPolicyService,MeliApiClient,cron_read_models',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.49',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts (component_key,required_migration,contract_kind,enabled)
VALUES ('process_sync_queue','229_cron_doctor_clean_graph_2_28_49.sql','metadata',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.49','Cron Doctor read-only y mapa de bloqueos para explicar solicitado/permitido/observado')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
