# Auditoría ERP Meli 2.29.3 — Asistente Cron V3

## Resultado

La versión 2.29.3 no activa Cron V3 real, ownership ni HTTP remoto operativo. Su objetivo es reducir pasos manuales: el actualizador prepara `config.env` seguro y el panel guía la prueba Shadow.

## Cambios revisados

- `config.env` se actualiza de forma acotada con una lista blanca de claves Cron V3.
- `CRON_V3_ENABLED` permanece en `false`.
- `CRON_V3_SHADOW_ENABLED` solo puede activarse tras Doctor local/remoto aprobado.
- `ML_WRITE_ENABLED=true` bloquea la preparación; no se cambia silenciosamente.
- El usuario solo debe crear las tareas Cron Shadow en Hostinger.
- `jobs/cron_v3_setup_check.php` es read-only y no construye transporte Mercado Libre.

## Puertas pendientes antes de activar V3 real

- Doctor real en Hostinger.
- 60 ciclos Shadow reales.
- Canario operativo posterior, todavía con ownership apagado.
- Auditoría independiente antes de cortar una familia de colas.
