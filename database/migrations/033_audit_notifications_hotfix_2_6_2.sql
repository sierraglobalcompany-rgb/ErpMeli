-- ERP Meli / Gestion Pro 2.6.2
-- Hotfix visual y operativo para auditoria de ventas y notificaciones webhook.

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.6.2', 'Corrige auditoria con ordenes en otro dia local, botones superpuestos y procesamiento manual de notificaciones webhook pendientes.');
