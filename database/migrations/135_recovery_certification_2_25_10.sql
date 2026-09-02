-- ERP Meli 2.25.10 — recuperación certificada sin bucles y spool atómico.
-- Metadata corta. No modifica órdenes, campañas, pagos ni evidencia fiscal.

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('runtime.recovery_stop_on_migration_error','1','update',0),
('runtime.recovery_release_session_on_diagnostics','1','performance',0),
('notifications.spool_atomic_replay','1','notifications',0),
('runtime.update_json_conflict_enabled','1','update',0)
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.10','Recuperación certificada sin reintentos automáticos fallidos y spool concurrente seguro')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
