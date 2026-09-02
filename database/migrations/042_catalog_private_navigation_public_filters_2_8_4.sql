ALTER TABLE catalogs
    ADD COLUMN show_public_advanced_filters TINYINT(1) NOT NULL DEFAULT 0 AFTER show_updated_date;

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.8.4', 'Navegacion privada por enlace con sesion temporal y filtros avanzados publicos seguros.');
