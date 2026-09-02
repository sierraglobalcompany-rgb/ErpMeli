INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('automation.attention_tooltip_hover_delay_ms','2000','automation',0),
('automation.attention_tooltip_focus_delay_ms','300','automation',0),
('automation.attention_inline_reason_enabled','1','automation',0),
('automation.work_detail_enabled','1','automation',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.19.1','Automatización explicable, estados humanos y detalle seguro de trabajos');
