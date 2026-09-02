INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('ui.products_title_tooltip_hover_delay_ms','2000','ui',0),
('cron.release_integrity_required_version','2.11.12','cron',0),
('cron.release_integrity_required_migration','079_product_title_tooltip_delay_2_11_12.sql','cron',0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.11.12','Reduce a dos segundos la espera del tooltip de títulos recortados en Productos ML.');
