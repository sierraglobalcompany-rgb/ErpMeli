-- ERP Meli 2.27.6 — matriz de certificación operativa.
-- Solo metadata para validación local. No modifica datos comerciales.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('operational_certification.version','2.27.6',0,'audit'),
    ('operational_certification.cron_states','ready|waiting_automation|waiting_api|waiting_budget|waiting_lock|waiting_schedule|action_required|empty|failed',0,'audit'),
    ('operational_certification.require_http_matrix','1',0,'audit'),
    ('operational_certification.require_zero_remote_transport','1',0,'audit'),
    ('operational_certification.require_browser_backup','1',0,'audit'),
    ('operational_certification.require_browser_sanitation','1',0,'audit')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version,notes)
VALUES (
    '2.27.6',
    'Certificación integral local: Cron centralizado, copias sin Cron, saneamiento por sesión y matriz HTTP/rendimiento.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
