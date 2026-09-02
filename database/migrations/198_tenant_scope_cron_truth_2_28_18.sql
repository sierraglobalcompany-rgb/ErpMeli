-- ERP Meli 2.28.18 — aislamiento multiempresa y verdad operativa de Cron.
-- Solo registra contratos técnicos. No modifica órdenes, ventas, pagos,
-- campañas, cuentas, OAuth, cierres ni evidencia fiscal.

CREATE TABLE IF NOT EXISTS user_meli_account_access (
    user_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    access_role VARCHAR(30) NOT NULL DEFAULT 'member',
    granted_by BIGINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (user_id,meli_account_id),
    KEY idx_user_meli_account_access_account (meli_account_id,user_id),
    CONSTRAINT fk_user_meli_account_access_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_meli_account_access_account
        FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_meli_account_access_granter
        FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('security.authorized_business_scope_required','1',0,'security'),
('security.web_bulk_queue_mutations_enabled','0',0,'security'),
('cron.read_protocol_version','2',0,'cron'),
('cron.selector_preview_matches_runtime','1',0,'cron'),
('cron.waiting_outcomes_are_failures','0',0,'cron'),
('api.health.fail_closed_on_read_error','1',0,'api_health'),
('api.health.maximum_overview_queries','12',0,'api_health'),
('app.version','2.28.18',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','198_tenant_scope_cron_truth_2_28_18.sql','structural',1),
('automation_center','198_tenant_scope_cron_truth_2_28_18.sql','structural',1),
('api_health','198_tenant_scope_cron_truth_2_28_18.sql','structural',1),
('sync_center','198_tenant_scope_cron_truth_2_28_18.sql','structural',1),
('financial_recalc','198_tenant_scope_cron_truth_2_28_18.sql','structural',1),
('notifications','198_tenant_scope_cron_truth_2_28_18.sql','structural',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.18','Aislamiento multiempresa, mutaciones exactas, Cron verificable y Salud API confiable')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
