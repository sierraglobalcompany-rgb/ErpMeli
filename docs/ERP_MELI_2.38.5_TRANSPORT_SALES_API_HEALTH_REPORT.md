# ERP MELI 2.38.5 — transporte, auditoría de ventas y Salud API

## Alcance

Esta release integra en el único scheduler `queue_v4_clean.php` tres autoridades que en
2.38.4 estaban separadas: frontera física de transporte, consumo paginado de auditorías
de ventas y mantenimiento local del modelo de lectura de Salud API.

La construcción y las pruebas se realizaron exclusivamente en MariaDB local desechable.
No se consultó Hostinger, no se usó SSH productivo, no se ejecutó FTP, no se accedió a
`storage/raw` y no se efectuaron escrituras remotas Mercado Libre.

## Causas raíz cerradas

1. `sync_sales_audit_jobs` no tenía consumidor en Queue V4 Clean. Los trece períodos
   creados por “Importar todo lo disponible” permanecían preparados, sin páginas HTTP.
2. Una excepción de transporte no distinguía con autoridad durable si cURL aún no había
   comenzado, si el GET había empezado sin respuesta o si ya existía un HTTP conocido.
3. Salud API mezclaba estado legacy V2/V3 con Queue V4 y podía ejecutar agrupamientos
   costosos sobre logs durante una petición administrativa.
4. El materializador y su retención no pertenecían al único scheduler vigente.

## Contrato 2.38.5

- Migración 297, sin migración 298.
- Journal físico por intento Queue V4: `NOT_DISPATCHED`, `PHYSICAL_STARTED` y
  `RESPONSE_KNOWN`, siempre con empresa, cuenta, job, intento y lease.
- Fallo antes de cURL: aplazamiento no penalizado y cero HTTP físico.
- GET iniciado sin respuesta: aplazamiento seguro no penalizado; se recupera como máximo
  un GET histórico elegible por ciclo.
- 429 conocido: conserva `Retry-After`/`nextSafeAt`, no consume intento funcional.
- Presupuesto por ciclo: máximo 10 trabajos reclamados y 10 transportes físicos sumando
  OAuth, auditoría de ventas y órdenes. OAuth conserva máximo un POST; ventas, máximo una
  página GET por ciclo.
- Auditoría de ventas cercada por `company_id` + `meli_account_id`, con lease compartido
  por Cron y “Procesar ahora”.
- Salud API usa Queue V4 para engine, readiness, heartbeat, OAuth, colas y revisiones.
- La web sólo lee el modelo materializado. Si está atrasado devuelve `partial`; nunca
  ejecuta el agrupamiento crudo de logs como fallback.
- Materialización y retención son etapas locales, acotadas y sin HTTP del scheduler.
- `ML_WRITE_ENABLED=false` sigue bloqueando toda mutación remota.

## Pruebas focales

- Migración y transición metadata 2.38.4 → 2.38.5, schema 297 y pendientes 0.
- Fallo antes de cURL, incertidumbre después de frontera física, 429 con `Retry-After` y
  respuesta 200 conocida.
- Presupuesto físico global exacto de 10.
- Recuperación de múltiples GET inciertos, uno por ciclo.
- FK negativa cross-tenant para recovery.
- Lease vencido antes/después de transporte sin penalización.
- Trece meses de auditoría convergen en trece ciclos de una página.
- Snapshot Queue V4 tenant-scoped por debajo de un segundo en laboratorio.
- Regresiones OAuth real-path, control-plane y estabilidad de rate limit.

## Operación posterior

El Cron productivo permanece ausente durante la construcción. Después de instalar y
verificar la release se debe crear exactamente un Cron cada minuto:

```bash
/opt/alt/php83/usr/bin/php /home/u390570745/domains/bodegadigitalmedellin.com/public_html/erp-meli/jobs/queue_v4_clean.php --runtime=45 --max-jobs=10
```

Observar tres ejecuciones antes de aumentar capacidad. Detener si aparece
`QUEUE_V4_CLEAN_FAILED`, más de un POST OAuth por ciclo, `REMOTE_UNCERTAIN`,
`RECONNECT_REQUIRED` o `FAILED`.

