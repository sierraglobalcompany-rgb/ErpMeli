INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('ui.products_title_tooltip_enabled','1','ui',0),
('ui.products_title_tooltip_hover_delay_ms','3000','ui',0),
('ui.products_title_tooltip_focus_delay_ms','300','ui',0),
('cron.release_integrity_required_version','2.11.10','cron',0),
('cron.release_integrity_required_migration','077_product_title_tooltip_2_11_10.sql','cron',0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.11.10','Agrega tooltip seguro, retardado y configurable para títulos recortados de Productos ML.');
