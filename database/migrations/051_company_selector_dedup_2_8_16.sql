-- ERP Meli 2.8.16 — Corrección de empresas duplicadas en selectores.
-- El cambio funcional centraliza selectores operativos para excluir empresas
-- eliminadas lógicamente e inactivas. No fusiona ni borra empresas.

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.8.16', 'Corrige selectores de empresas para ocultar eliminadas/inactivas y agrega diagnóstico de duplicados activos por nombre.');
