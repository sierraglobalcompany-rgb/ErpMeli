CREATE TABLE IF NOT EXISTS system_retired_web_routes (
    route_key VARCHAR(190) NOT NULL,
    retired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    replacement_path VARCHAR(255) NULL,
    safe_message VARCHAR(500) NOT NULL,
    PRIMARY KEY (route_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_retired_web_routes (route_key,replacement_path,safe_message)
VALUES
('sync.assisted_step','/settings/manual-processing','El procesamiento asistido desde el navegador fue retirado.'),
('financial.assisted_step','/settings/manual-processing?scope=financial','El recálculo se ejecuta mediante trabajos CLI.'),
('notifications.assisted_step','/settings/manual-processing?scope=notifications','Las notificaciones se ejecutan mediante trabajos CLI.'),
('notifications.process','/settings/manual-processing?scope=notifications','El navegador ya no procesa notificaciones.'),
('catalog_descriptions.step','/settings/manual-processing?scope=descriptions','Las descripciones se ejecutan mediante trabajos CLI.')
ON DUPLICATE KEY UPDATE
replacement_path=VALUES(replacement_path),
safe_message=VALUES(safe_message);

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('performance.progressive_max_concurrency','2',0,'system'),
('performance.section_timeout_ms','8000',0,'system'),
('performance.list_default_page_size','50',0,'system'),
('runtime.web_transport_disabled','1',0,'system')
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
is_encrypted=VALUES(is_encrypted),
setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.13','Rendimiento web y contratos de ejecución exclusivamente CLI.')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
