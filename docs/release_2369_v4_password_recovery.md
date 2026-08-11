# ERP MELI 2.36.9 — instalación del hotfix V4

Este paquete corrige únicamente la recuperación del armado V4 que quedó incompleto con `feature_generation_invalid`. No crea tareas Cron, no activa Queue Engine y no inicia automatización.

## Subida por FTP

1. Mantenga el freno de mano completo y no cree todavía el Cron V4 de Hostinger.
2. Suba el contenido de `ERP_MELI_2.36.9_FTP_REPAIR_OVERLAY.zip` dentro de la carpeta existente `erp-meli`.
3. No cree una carpeta adicional dentro de `erp-meli`.
4. No sobrescriba `config.env`, `storage/`, `shared/storage/`, archivos de pausa, credenciales, respaldos ni estado privado.
5. Abra `/erp-meli/actualizar.php` e instale la actualización metadata-only a 2.36.9.

## Recuperación controlada

Después de actualizar, abra la tarjeta de Readiness V4. La acción solicita únicamente la contraseña administrativa; la ausencia del scheduler se valida contra la autoridad técnica registrada. El primer uso de “Preparar y certificar V4” en el estado afectado sólo debe restaurar todas las autoridades a fail-closed y pedir una nueva acción. Verifique que continúan:

- Queue Engine desactivado.
- Cron V4 no creado.
- automatización detenida;
- API de Mercado Libre detenida;
- feature flags desactivados en generación 0;
- `CRON_V4_ENABLED=false`.

Deténgase en ese punto y revise el resultado antes de volver a iniciar una preparación. El hotfix no continúa automáticamente hacia readiness.
