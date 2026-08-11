# ERP MELI 2.36.4 — informe del hotfix de configuración segura

## Causa raíz

La versión 2.36.3 ocultaba conjuntamente los paneles de preparación, Shadow y Canario cuando la verdad operativa histórica indicaba `operational_active`. El formulario autenticado “Preparar configuración segura” seguía existiendo, pero JavaScript lo hacía invisible.

El snapshot del asistente tampoco incorporaba `CRON_V4_ENABLED` a la autoridad segura, no informaba de forma uniforme los cuatro flags de retiro y consideraba preparados los flags V3 con sólo existir, incluso si su valor era `true`.

## Corrección

- El panel seguro permanece visible; Shadow y Canario se ocultan durante el retiro.
- El preflight resuelve `$_ENV`, `$_SERVER`, `getenv()` y finalmente `config.env`.
- Se exponen únicamente booleanos, fuentes y nombres de conflictos; nunca valores secretos.
- La preparación escribe V3, Shadow y V4 en `false`, conserva `ML_WRITE_ENABLED=false` como precondición y bloquea valores inválidos u overrides contradictorios.
- Se reutilizan el GET y POST administrativos existentes con autenticación, same-origin y CSRF.

## Límites

No hay migraciones, endpoints nuevos, creación de cron, activación de Queue Engine, llamadas a Mercado Libre ni DML comercial. La transición de versión sólo puede actualizar `app_settings['app.version']`, `app_versions['2.36.4']` y el marker firmado mediante el flujo metadata-only certificado.

