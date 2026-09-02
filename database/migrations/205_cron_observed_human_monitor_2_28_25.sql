-- ERP Meli 2.28.25 — política única de lotes y lectura humana verificable.
-- Solo amplía trazabilidad técnica. No modifica datos comerciales, campañas,
-- cuentas, tokens, OAuth, pagos, cierres ni evidencia fiscal.

ALTER TABLE system_work_queue_run_items
    ADD COLUMN IF NOT EXISTS batch_configured INT NULL AFTER batch_limit,
    ADD COLUMN IF NOT EXISTS batch_effective INT UNSIGNED NULL AFTER batch_configured,
    ADD COLUMN IF NOT EXISTS batch_executed INT UNSIGNED NOT NULL DEFAULT 0 AFTER batch_effective,
    ADD COLUMN IF NOT EXISTS batch_limit_reason VARCHAR(40) NULL AFTER batch_executed;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.batch_policy_authoritative','1',0,'cron'),
('cron.read_model_partial_protocol','1',0,'cron'),
('cron.poll_visible_seconds','10',0,'cron'),
('cron.poll_hidden_seconds','30',0,'cron'),
('app.version','2.28.25',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','205_cron_observed_human_monitor_2_28_25.sql','structural',1),
('automation_center','205_cron_observed_human_monitor_2_28_25.sql','structural',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.25','Política única de lotes, métricas parciales honestas y Centro Cron adaptable')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
