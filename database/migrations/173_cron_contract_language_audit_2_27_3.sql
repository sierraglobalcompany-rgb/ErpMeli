-- ERP Meli 2.27.3 — contratos de Cron y lenguaje operativo.
-- Solo metadata. No modifica datos comerciales, cuentas, OAuth, órdenes, pagos,
-- packs, envíos, cierres ni evidencia fiscal.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('cron.single_launcher_contract','process_sync_queue.php',0,'cron'),
    ('cron.legacy_web_routes_contract','retired_or_cli_only',0,'cron'),
    ('cron.runtime_language_presenter','1',0,'cron'),
    ('cron.forbid_secondary_workers_copy','1',0,'cron'),
    ('backup.browser_forbidden_launcher_words','Esperando lanzador|worker|cron|en cola',0,'backup'),
    ('sanitation.browser_forbidden_launcher_words','Esperando lanzador|worker|cron|en cola',0,'maintenance')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version,notes)
VALUES (
    '2.27.3',
    'Auditoría ejecutable: contrato de lanzador único, rutas web heredadas retiradas o CLI-only y lenguaje sin segundo worker.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
