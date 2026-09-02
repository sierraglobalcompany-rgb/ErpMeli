-- ERP Meli 2.28.14
-- Alcance empresarial/cuenta, límites remotos duros y contratos API cerrados.
-- No modifica datos comerciales.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('sync.max_api_pages_per_run','5',0,'sync'),
    ('runtime.tenant_scope_enforced','1',0,'runtime'),
    ('runtime.unconfirmed_endpoints_fail_closed','1',0,'runtime'),
    ('runtime.oauth_guarded_transport','1',0,'runtime'),
    ('app.version','2.28.14',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=CASE
        WHEN setting_key='app.version' THEN VALUES(setting_value)
        ELSE setting_value
    END,
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);
