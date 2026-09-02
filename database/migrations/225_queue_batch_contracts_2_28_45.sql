-- ERP Meli 2.28.45 — contratos de lotes reales por cola.

CREATE TABLE IF NOT EXISTS system_cron_queue_batch_contracts (
    queue_key VARCHAR(80) NOT NULL,
    batch_configured INT UNSIGNED NULL,
    batch_effective INT UNSIGNED NULL,
    http_expected DECIMAL(10,3) NULL,
    resources_expected_per_http DECIMAL(10,3) NULL,
    limit_reason VARCHAR(160) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (queue_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('cron.queue_batch_contracts_enabled','1',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.45',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts (component_key,required_migration,contract_kind,enabled)
VALUES ('automation_center','225_queue_batch_contracts_2_28_45.sql','metadata',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.45','Contratos visibles de lote por cola: HTTP, recursos y limitante efectiva')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
