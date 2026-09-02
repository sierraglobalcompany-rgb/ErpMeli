-- ERP Meli 2.25.11 — navegación recuperada y lecturas web acotadas.
-- Metadata corta. No modifica órdenes, campañas, pagos ni evidencia fiscal.

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('runtime.shell_requests_sequential','1','performance',0),
('runtime.async_sections_sequential','1','performance',0),
('runtime.performance_metrics_file_only','1','performance',0),
('runtime.schema_contract_batch_enabled','1','performance',0)
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.11','Recuperación de navegación, contratos de esquema por lote y telemetría web no bloqueante')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
