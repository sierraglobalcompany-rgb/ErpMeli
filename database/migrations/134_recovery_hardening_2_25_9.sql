-- ERP Meli 2.25.9 — recuperación endurecida y parada completa de jobs heredados.
-- Metadata corta. No modifica datos comerciales, campañas ni evidencia fiscal.

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('runtime.recovery_reauthentication_seconds','900','update',0),
('runtime.recovery_origin_required','1','security',0),
('runtime.pending_update_gate_enabled','1','update',0),
('runtime.legacy_jobs_emergency_guard','1','security',0),
('runtime.migrator_skip_events_enabled','0','performance',0)
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.9','Recuperación endurecida, migrador optimizado y parada previa de jobs heredados')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
