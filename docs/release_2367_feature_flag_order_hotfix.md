# ERP MELI 2.36.7 — instalación manual del hotfix

Este hotfix corrige exclusivamente la comparación del conjunto de feature flags
usado por readiness V4. No contiene migraciones y no crea Cron Hostinger ni
activa Queue Engine.

## Instalación FTP

1. Mantenga el freno de mano, la automatización detenida y Cron V4 ausente.
2. Extraiga `ERP_MELI_2.36.7_FTP_REPAIR_OVERLAY.zip` localmente.
3. Suba su contenido dentro de la carpeta existente `erp-meli`, conservando la
   estructura relativa y permitiendo reemplazar únicamente los archivos del ZIP.
4. No sobrescriba `config.env`, `.env`, `shared/config.env`, `storage/`,
   `shared/storage/`, `PAUSE_MELI_API`, `PAUSE_ERP_AUTOMATION` ni
   `shared/current-release.json`.
5. Abra `actualizar.php`, complete la promoción metadata-only a 2.36.7 y vuelva
   a `/settings/cron`.
6. Antes de pulsar readiness, confirme que la tarjeta muestra `ready_to_arm`.

No suba `ERP_MELI_2.36.7_GIT_EXACT.zip` sobre una instalación con estado
persistente. El paquete `.erpupd` es para el actualizador administrativo.
