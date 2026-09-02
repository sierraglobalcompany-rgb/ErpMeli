-- ERP Meli 2.28.26 — deuda dirigida, scope global explícito y backlog comparable.
-- Solo modifica telemetría operativa. No toca órdenes, ventas, pagos, campañas,
-- cuentas, OAuth, cierres ni evidencia fiscal.

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='cron_task_state'
       AND column_name='directed_missed_cycles')=0,
    'ALTER TABLE cron_task_state ADD COLUMN directed_missed_cycles SMALLINT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='cron_task_state'
       AND column_name='last_directed_miss_at')=0,
    'ALTER TABLE cron_task_state ADD COLUMN last_directed_miss_at DATETIME(3) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Mantenimiento es trabajo administrativo, no backlog comercial. Los registros
-- históricos se excluyen para que dos ciclos posteriores puedan compararse.
DELETE FROM system_cron_backlog_snapshots
WHERE queue_key IN ('operational_maintenance','monthly_report_maintenance');

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.directed_debt_enabled','1',0,'cron'),
('cron.backlog_excludes_maintenance','1',0,'cron'),
('cron.operational_scope','explicit_application',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.26',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','206_cron_directed_debt_scope_truth_2_28_26.sql','structural',1),
('automation_center','206_cron_directed_debt_scope_truth_2_28_26.sql','structural',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.26','Deuda dirigida verificable, scope Cron explícito y backlog comparable')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
