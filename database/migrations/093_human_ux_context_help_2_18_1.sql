INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('ui.context_help_enabled','1','ui',0),
('ui.context_help_hover_delay_ms','3000','ui',0),
('ui.context_help_focus_delay_ms','300','ui',0),
('ui.default_page_size','50','ui',0),
('ui.technical_details_collapsed','1','ui',0),
('ui.mobile_tables_as_cards','1','ui',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.18.1','Experiencia humana, paginación y ayuda contextual accesible');
