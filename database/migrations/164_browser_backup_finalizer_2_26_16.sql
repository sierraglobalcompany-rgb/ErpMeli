-- ERP Meli 2.26.16 — finalizador robusto de copias por navegador.
-- Metadata solamente: no modifica datos comerciales ni activa Mercado Libre.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group) VALUES
('backup.browser_partial_releases_lease','1',0,'backup'),
('backup.browser_chunk_failure_is_recoverable','1',0,'backup')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version, notes, installed_at)
VALUES ('2.26.16','Finalizador robusto de copias por navegador, con fallos controlados y lease liberado por micro-paso.',UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
