-- ERP Meli 2.26.17 — normalización de solicitudes de respaldo y reintento seguro de lease.
-- Metadata solamente: no modifica datos comerciales ni activa Mercado Libre.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group) VALUES
('backup.browser_lost_lease_is_deferred','1',0,'backup'),
('backup.browser_retry_from_last_approved_checkpoint','1',0,'backup'),
('backup.accept_legacy_forced_request_id','1',0,'backup'),
('backup.browser_request_normalizer_version','2.26.17',0,'backup'),
('backup.request_normalizer_ignores_deleted_active_rows','1',0,'backup')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version, notes, installed_at)
VALUES ('2.26.17','Normaliza solicitudes de respaldo y convierte pérdida de lease del navegador en reintento desde el último checkpoint aprobado.',UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
