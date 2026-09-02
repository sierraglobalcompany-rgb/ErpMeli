-- ERP Meli 2.9.3 — Correctivo de migrador para bases con collations mixtas.
-- La corrección principal está en app/Services/Migrator.php: evita comparar strings
-- parametrizados dentro de SQL para no disparar errores 1267 en instalaciones antiguas.

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.9.3', 'Correctivo del migrador para evitar Illegal mix of collations al activar el motor seguro desde instalaciones 2.8.x.');
