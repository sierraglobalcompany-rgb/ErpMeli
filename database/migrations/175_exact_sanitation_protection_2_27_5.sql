-- ERP Meli 2.27.5 — saneamiento exacto por sesión.
-- Solo metadata/flags de protección. No elimina ni altera datos comerciales.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('database_maintenance.exact_session_protection','1',0,'maintenance'),
    ('database_maintenance.external_backup_without_internal_required','1',0,'maintenance'),
    ('database_maintenance.waived_allows_logical_only','1',0,'maintenance'),
    ('database_maintenance.canary_required_before_delete','1',0,'maintenance'),
    ('database_maintenance.blocked_screen_must_offer_actions','1',0,'maintenance')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version,notes)
VALUES (
    '2.27.5',
    'Saneamiento exacto: protección fijada por sesión, respaldo externo o renuncia sin usar copias globales.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
