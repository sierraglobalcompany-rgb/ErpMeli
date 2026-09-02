-- ERP Meli 2.28.4 — Freno de mano humano y accionable.
-- Metadata únicamente. No modifica datos comerciales, órdenes, pagos, cierres ni evidencia fiscal.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('commercial_path.version','2.28.4',0,'commercial_path'),
    ('operational_audit.last_certified_version','2.28.4',0,'audit'),
    ('operational_audit.human_emergency_control','1',0,'audit')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version,notes)
VALUES (
    '2.28.4',
    'Freno de mano humano: interruptores accionables, confirmación simple y sin motivo ni contraseña repetidos dentro del panel.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
