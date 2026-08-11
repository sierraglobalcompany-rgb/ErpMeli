# ERP MELI 2.36.5 — informe del control administrativo de retiro V3

## Resultado

La transición manual certificada fue convertida en una acción explícita del dominio Cron. No se integró en el updater metadata-only y no se añadió una migración.

## Cambios

- Servicio dedicado `CronV3RetirementForV4Service`.
- Preflight read-only visible desde el JSON existente de Cron.
- Segundo formulario en la pantalla Cron con frase y reautenticación.
- Reutilización del POST existente; no hay endpoints nuevos.
- Receipt durable sin secretos.
- Autoridad managed 2.36.5 y artefactos deterministas.

## Contrato de datos

Las únicas tablas mutables son `cron_v3_queue_ownership` y `app_settings`. No hay `DELETE`. Los contadores de `cron_v3_work` y `cron_v3_attempts`, Queue Engine y el sentinel comercial permanecen sin cambios. Cron V4 queda apagado.

## Pruebas

La matriz local compara el SQL aprobado en una DB A con el servicio en una DB B sobre MariaDB 11.8.8. También cubre flags inseguros, override de proceso, versión, schema, engine, ownership extranjero, lock ocupado, idempotencia y rollback en todos los failpoints.

## Alcance externo

Producción, Hostinger, SSH, FTP, Mercado Libre y `storage/raw` no fueron contactados durante implementación o certificación.

## Resumen para ChatGPT

ERP MELI 2.36.5 reemplaza el uso normal de phpMyAdmin para retirar V3 por una acción administrativa segura en `/settings/cron`. Exige preflight exacto, contraseña, CSRF y frase explícita; sólo deshabilita ownership V3 y nueve settings técnicas dentro de una transacción cercada. No borra históricos, no activa V4 y conserva el SQL anterior exclusivamente como oracle/recovery.
