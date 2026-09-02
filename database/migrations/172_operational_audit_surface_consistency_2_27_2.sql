-- ERP Meli 2.27.2 — auditoría operativa certificada y pantallas accionables.
-- No modifica datos comerciales, órdenes, cuentas, OAuth, pagos, packs, cierres ni evidencia fiscal.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('maintenance.blocked_screen_actions','1',0,'maintenance'),
    ('database_maintenance.protect_allowed_during_freeze','1',0,'maintenance'),
    ('backup.browser_dead_end_prevention','1',0,'backup'),
    ('operational_audit.module_matrix_required','1',0,'audit')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version,notes)
VALUES (
    '2.27.2',
    'Auditoría operativa: evita pantallas muertas de protección, permite resolver saneamiento desde el controlador y conserva copias por navegador sin Cron.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
