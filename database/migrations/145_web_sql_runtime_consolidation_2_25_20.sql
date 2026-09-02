ALTER TABLE meli_orders
    ADD COLUMN IF NOT EXISTS sale_identity BIGINT UNSIGNED
        AS (COALESCE(external_pack_id,external_order_id)) STORED;

CREATE INDEX IF NOT EXISTS idx_orders_sale_identity
    ON meli_orders (meli_account_id,sale_identity,date_created_local);

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('runtime.shell_snapshot_enabled','1',0,'performance'),
    ('runtime.legacy_browser_workers_enabled','0',0,'performance'),
    ('runtime.schema_snapshot_ttl_seconds','60',0,'performance'),
    ('runtime.list_default_page_size','50',0,'performance')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.20','Consultas web paginadas, shell unificado y procesadores web heredados retirados.')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
