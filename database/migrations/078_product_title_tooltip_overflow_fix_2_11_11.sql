INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('cron.release_integrity_required_version','2.11.11','cron',0),
('cron.release_integrity_required_migration','078_product_title_tooltip_overflow_fix_2_11_11.sql','cron',0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.11.11','Corrige la detección de recorte del tooltip midiendo el texto visible de Productos ML.');
