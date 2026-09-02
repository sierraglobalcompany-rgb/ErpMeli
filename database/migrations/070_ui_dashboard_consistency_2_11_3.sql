-- ERP Meli 2.11.3 — Coherencia del Panel general, accesibilidad y paginación visual.
-- Aditiva, idempotente y sin cambios en datos comerciales o cálculos históricos.

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('dashboard.api_error_summary_limit','5','interface',0),
('dashboard.mobile_cards_enabled','1','interface',0),
('products.internal_page_size','50','interface',0),
('products.links_page_size','50','interface',0),
('ui.minimum_touch_target_px','44','interface',0),
('ui.technical_tables_sticky_header','1','interface',0)
ON DUPLICATE KEY UPDATE
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.11.3','Coherencia del Panel general, etiquetas humanas, accesibilidad y tablas responsivas.');
