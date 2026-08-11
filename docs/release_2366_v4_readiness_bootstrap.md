# ERP MELI 2.36.6 — instalación del hotfix V4 readiness

Esta release corrige únicamente la preparación y certificación acotada de readiness V4. No crea el Cron de Hostinger y no activa Queue Engine.

## Subida manual

1. Cargue el contenido de `ERP_MELI_2.36.6_FTP_REPAIR_OVERLAY.zip` dentro de la carpeta real de la instalación, por ejemplo `public_html/erp-meli/`.
2. No cree una carpeta adicional con el nombre del ZIP.
3. No sobrescriba `config.env`, `.env`, `shared/config.env`, `storage/`, `shared/storage/`, `PAUSE_MELI_API`, `PAUSE_ERP_AUTOMATION` ni `shared/current-release.json`.
4. Abra `actualizar.php`, complete la promoción metadata-only y vuelva a iniciar sesión.
5. En Configuración > Cron use la acción administrativa existente “Preparar y certificar V4”. Cada pulsación ejecuta como máximo una etapa acotada y muestra el siguiente paso.

La acción exige administrador permanente, contraseña, CSRF, mismo origen, la frase exacta y confirmación explícita de que no existe un scheduler V4. La certificación termina con Queue Engine deshabilitado y el scheduler ausente.

## Reversión

La acción de reversión V4 devuelve configuración, API y feature flags al estado fail-closed. No revierte ni invalida las credenciales OAuth ya renovadas.
