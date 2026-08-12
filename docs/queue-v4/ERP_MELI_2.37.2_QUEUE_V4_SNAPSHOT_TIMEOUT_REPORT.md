# ERP MELI 2.37.2 — Queue V4 snapshot timeout

## Incidente confirmado

En 2.37.1, el GET autenticado `settings/cron/queue-v4.json` superó el límite de nginx y respondió 504 HTML. La contraseña no intervino: el snapshot nunca llegó al navegador y todas las acciones permanecieron deshabilitadas. El frontend intentó interpretar el HTML como JSON y mostró `Unexpected token '<'`.

## Causa y corrección

`QueueV4CleanDatabaseContract` hacía cuatro lecturas de `information_schema` por cada una de doce tablas, con filtros `BINARY`. 2.37.2 obtiene la misma autoridad exacta mediante cuatro consultas agrupadas —tablas, columnas, índices y claves foráneas— filtradas por `TABLE_SCHEMA=?` y `TABLE_NAME IN (...)`. La comparación de nombres, orden, tipos y metadatos continúa en PHP y sigue bloqueando cualquier deriva.

La UI agrega timeout explícito, valida estado HTTP y `Content-Type` antes de parsear JSON, distingue 504/HTML/JSON inválido y elimina cualquier snapshot previo al fallar. Una contraseña sólo habilita la acción permitida cuando existe un snapshot válido.

## Límites de la release

- Sin migración 295; el esquema permanece en 294.
- Sin caché ni receipt de topología reutilizable.
- Sin scheduler, activación, readiness automática ni llamadas Mercado Libre durante build.
- Sin acceso a `storage/raw` y sin estado productivo dentro de artefactos.
- Actualización metadata-only 2.37.1 → 2.37.2.

## Evidencia local requerida

- MariaDB 11.8.8 y 294 migraciones canónicas.
- Máximo cuatro consultas de metadata, incluso con schemas/tablas señuelo.
- Derivas de columna, collation, índice, FK y tabla bloqueadas.
- Endpoint bajo `/erp-meli` con HTTP 200 JSON y menos de cinco segundos.
- UI fail-closed ante 504, HTML y JSON inválido.
- Readiness 3/3 sólo GET `/users/me`, cero jobs y cero DML comercial.
- Rebuild Git `core.autocrlf=false/true` byte-exacto.

## Resumen para auditoría externa

```text
PASSWORD_ROOT_CAUSE=NO
QUEUE_V4_STATUS_GET=504_GATEWAY_TIMEOUT
SERVER_LAYER=NGINX
RESPONSE_TYPE=HTML_NOT_JSON
BUTTON_DISABLED_REASON=SNAPSHOT_NEVER_LOADED
PRIMARY_CODE_CAUSE=PER_TABLE_INFORMATION_SCHEMA_SCANS
REMEDIATION=4_BATCHED_METADATA_QUERIES+SAFE_JSON_TIMEOUT_UI
MIGRATION_295=NO
PRODUCTION_MUTATIONS=0
```

## Resultado del laboratorio final

- Contrato/full flow MariaDB 11.8.8: PASS, 64 checks.
- Consultas agrupadas de metadata: 4, incluso con 8 schemas y 32 tablas señuelo.
- Derivas bloqueadas: columna/nullability, collation, índice, FK y tabla ausente.
- HTTP `/erp-meli/settings/cron/queue-v4.json`: 200 JSON, `READY_TO_TEST`, 76.433 ms.
- Readiness: `CERTIFIED`, 3/3 GET `/users/me`, cero jobs y cero DML comercial.
- UI: PASS 10 checks; 504, HTML y JSON inválido permanecen fail-closed.
