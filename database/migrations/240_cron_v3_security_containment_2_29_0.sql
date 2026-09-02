-- ERP Meli 2.29.0 - contencion P0 previa a Cron V3.
-- No modifica datos comerciales ni realiza operaciones remotas.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('api.budget.fail_closed','1',0,'api'),
('notifications.receiver_max_payload_bytes','262144',0,'notifications'),
('notifications.quarantine_terminal_once','1',0,'notifications'),
('cron.retention.requires_runtime_guards','1',0,'retention')
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
is_encrypted=VALUES(is_encrypted),
setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','240_cron_v3_security_containment_2_29_0.sql','structural',1),
('webhook_receiver','240_cron_v3_security_containment_2_29_0.sql','structural',1),
('api_budget','240_cron_v3_security_containment_2_29_0.sql','structural',1),
('oauth','240_cron_v3_security_containment_2_29_0.sql','structural',1)
ON DUPLICATE KEY UPDATE
required_migration=VALUES(required_migration),
contract_kind=VALUES(contract_kind),
enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.29.0','Contencion P0: OAuth multitenant, presupuesto atomico fail-closed, webhook terminal y retencion cercada')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
