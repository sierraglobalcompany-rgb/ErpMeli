-- ERP Meli 2.26.15 — reconciliación de copias interactivas incompletas.
-- Metadata solamente: no modifica datos comerciales ni activa Mercado Libre.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group) VALUES
('backup.browser_reconcile_missing_final_state','1',0,'backup'),
('backup.browser_failed_without_verified_file','1',0,'backup')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version, notes, installed_at)
VALUES ('2.26.15','Reconciliación de copias interactivas incompletas sin cron ni transporte remoto.',UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
