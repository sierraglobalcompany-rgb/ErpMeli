# ERP MELI 2.36.4 — hotfix de configuración segura

## Instalación por FTP en subcarpeta

1. Verifique el SHA-256 del archivo `ERP_MELI_2.36.4_FTP_REPAIR_OVERLAY.zip` contra `ERP_MELI_2.36.4_ARTIFACT_MANIFEST.json`.
2. Extraiga el overlay localmente y suba **su contenido** dentro de la instalación existente, por ejemplo `public_html/erp-meli/`. No cree una carpeta adicional dentro de `erp-meli`.
3. No sobrescriba `config.env`, `.env`, `shared/config.env`, `storage/`, `shared/storage/`, `PAUSE_MELI_API`, `PAUSE_ERP_AUTOMATION` ni `shared/current-release.json`.
4. Abra `/erp-meli/actualizar.php` y complete únicamente la transición metadata-only 2.36.3 → 2.36.4. No se ejecutan migraciones.
5. Inicie sesión y abra `/erp-meli/settings/cron`. El panel “Preparar configuración segura” debe permanecer visible.

## Acción posterior autorizada

Pulse una sola vez “Preparar configuración segura”. La operación exige y muestra:

- `CRON_V3_ENABLED=false`;
- `CRON_V3_SHADOW_ENABLED=false`;
- `CRON_V4_ENABLED=false`;
- `ML_WRITE_ENABLED=false`;
- cero overrides de proceso contradictorios.

No cree Cron V4, no active Queue Engine y no ejecute el SQL de retiro hasta revisar el resultado del preflight.
