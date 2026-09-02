-- ERP Meli 2.28.2 — Pulido operativo certificado.
-- Metadata únicamente. No modifica datos comerciales, órdenes, pagos, cierres ni evidencia fiscal.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('commercial_path.version','2.28.2',0,'commercial_path'),
    ('operational_audit.last_certified_version','2.28.2',0,'audit'),
    ('operational_audit.backup_status_json_lightweight','1',0,'audit'),
    ('operational_audit.financial_recalc_summary_uses_job_counters','1',0,'audit'),
    ('operational_audit.legacy_cron_language_removed','1',0,'audit')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version,notes)
VALUES (
    '2.28.2',
    'Pulido operativo: monitor de copias liviano, resumen financiero sin escaneo masivo y lenguaje consistente con lanzador único.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
