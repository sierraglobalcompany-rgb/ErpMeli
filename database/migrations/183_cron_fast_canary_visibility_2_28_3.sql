-- ERP Meli 2.28.3 — Cron visible y canarios en vista rápida.
-- Metadata únicamente. No modifica datos comerciales, órdenes, pagos, cierres ni evidencia fiscal.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('commercial_path.version','2.28.3',0,'commercial_path'),
    ('operational_audit.last_certified_version','2.28.3',0,'audit'),
    ('operational_audit.cron_fast_canary_visibility','1',0,'audit')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version,notes)
VALUES (
    '2.28.3',
    'Cron visible: el Centro de Automatización muestra estado esencial y canarios antes de cargar diagnósticos pesados.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
