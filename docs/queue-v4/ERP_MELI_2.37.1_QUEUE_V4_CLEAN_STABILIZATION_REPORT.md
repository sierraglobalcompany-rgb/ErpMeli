# ERP MELI 2.37.1 — estabilización Queue V4 Clean

## Causa raíz

La release 2.37.0 consultaba `schema_migrations.migration`, pero la autoridad histórica real creada por las migraciones 001–294 usa las columnas `version` y `applied_at`. El E2E anterior ocultó la incompatibilidad al inventar tablas core simplificadas en vez de ejecutar el migrador real.

La auditoría integral también encontró dos bloqueos de operación: la activación dejaba `scheduler_enabled=0`, por lo que el scheduler nunca podía trabajar, y la interfaz no permitía reactivar un motor certificado después de `STOPPED`.

## Correcciones

- Readiness consulta `schema_migrations.version`, exige exactamente la migración 294, cero migraciones pendientes, versión de archivo, `app.version` y marker coincidentes.
- El contrato físico de MariaDB 11.8.8 valida motor, versión, columnas, tipos, nulabilidad, defaults, índices y foreign keys de las tablas core y Queue V4 Clean.
- `Migrator::pendingCount()` quedó estrictamente read-only.
- Activar Queue V4 Clean publica atómicamente `ACTIVE` y `scheduler_enabled=1`; detener publica `STOPPED` y `scheduler_enabled=0`.
- La UI permite reactivar únicamente desde `CERTIFIED` o `STOPPED` con certificación vigente.
- El fixture histórico 2.37.0 delega al laboratorio canónico y ya no puede crear tablas core manuales.

## Autoridades y alcance

- Esquema canónico: `docs/queue-v4/QUEUE_V4_CANONICAL_DB_CONTRACT.json`.
- Copia empaquetada usada por runtime: `resources/release/queue-v4-canonical-db-contract-2.37.1.json`.
- Inventario SQL: `docs/queue-v4/QUEUE_V4_SQL_INVENTORY.json`.
- Migraciones: 001–294 aplicadas; migración 295 ausente.
- Legacy Queue Core/V2/V3 se conserva inerte y fuera de la autoridad operacional Queue V4 Clean.

## Evidencia local

- MariaDB 11.8.8, migraciones reales 001–294: PASS.
- Flujo integral: readiness 3/3, activación, productor acotado, FIFO, retry/waiting/review/dead, leases, stop y reactivación: PASS.
- HTTP real bajo `/erp-meli`, login, CSRF, GET `READY_TO_TEST` y POST `CERTIFIED`: PASS.
- Consultas Mercado Libre del laboratorio: sólo tres GET `/users/me` contra servidor falso localhost.
- Mutaciones comerciales remotas: 0.
- Acceso a `storage/raw`: 0.

Los hashes y conteos finales se publican en `ERP_MELI_2.37.1_ARTIFACT_MANIFEST.json` y `ERP_MELI_2.37.1_SHA256SUMS.txt` para evitar duplicar autoridades dentro del código fuente.
