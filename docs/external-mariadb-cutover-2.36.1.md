# MariaDB 279 → 293 y transición técnica 2.36.1

Este contrato pertenece exclusivamente al cutover externo administrado de ERP
Meli 2.36.1. No llama ni modifica el updater/installer del ERP y no constituye
un segundo actualizador permanente.

## Autoridades confirmadas

- `app_settings.setting_key='app.version'` es la autoridad primaria de versión
  instalada. `AppVersionService::installedVersion()` sólo consulta
  `app_versions` como fallback para instalaciones antiguas sin esa clave.
- `app_versions` es historial. Debe registrar 2.36.1 en el mismo `COMMIT` que
  promueve `app.version`, pero no sustituye a `app.version`.
- `storage/installed-release.json` es una autoridad externa firmada con
  `APP_KEY`. El login administrativo exige que su versión coincida con
  `VERSION`; el cuerpo anterior del marcador debe respaldarse por bytes y hash
  para poder hacer rollback.
- Las migraciones 291 y 292 escriben el estado intermedio `app.version=2.36.0`.
  La migración 293 no cambia versión. Ese intermedio sólo puede existir dentro
  de mantenimiento, con el runtime anterior aún activo y todos los launchers
  detenidos.
- El cambio 2.36.0 → 2.36.1 no necesita esquema ni datos nuevos: no se crea
  migración 294. La promoción a 2.36.1 es metadata técnica externa posterior a
  la certificación de las 14 migraciones.

## Precondiciones fail-closed

1. Backup reciente, restaurable y verificado de MariaDB.
2. Backup exacto de `app.version`, de cualquier fila histórica 2.36.1 y del
   marcador firmado anterior.
3. Mantenimiento activo; API y Automation detenidas; Cron V3/V4 sin ejecución.
4. Runtime activo todavía en 2.35.1.
5. `app.version=2.35.1`, 279 filas en `schema_migrations`, máximo numérico 279 y
   ninguna migración 280+.
6. Los SHA-256 de 280–293 coinciden con
   `tests/external_cutover_mariadb_2361.php`.
7. La conexión del operador adquiere
   `GET_LOCK('erp_meli_external_cutover_2_36_1',0)`. Si falla, no continúa.

## Secuencia certificada

1. Mantener el lock externo durante migración, promoción y switch.
2. Ejecutar directamente `Migrator`/`bin/migrate.php` contra MariaDB. No usar
   controllers, RecoveryKernel, SecureUpdateEngineService ni servicios del
   updater.
3. Verificar que las 14 filas exactas 280–293 existen una sola vez, que no hay
   migración posterior a 293 y que todos los invariantes comerciales coinciden.
4. Verificar el estado intermedio esperado `app.version=2.36.0`.
5. Dentro de una transacción SQL:
   - bloquear la fila `app.version` con `SELECT ... FOR UPDATE`;
   - volver a verificar que la conexión posee el lock externo;
   - volver a verificar las 14 migraciones;
   - actualizar únicamente `app_settings.app.version` a 2.36.1;
   - insertar/actualizar la fila histórica 2.36.1 en `app_versions`;
   - hacer `COMMIT`.
6. Publicar el marcador firmado 2.36.1/293 en shared storage sin imprimir
   `APP_KEY`. El publish operacional debe usar temporal, flush, `fsync`, rename
   atómico y sincronización del directorio padre; el `write()` PHP existente
   conserva el formato/HMAC, pero por sí solo no certifica fsync de directorio.
7. Verificar localmente las tres autoridades y sólo entonces permitir el
   switch atómico del runtime.
8. Ejecutar smoke local sin transporte Mercado Libre. Un fallo mantiene
   mantenimiento y activa rollback externo.
9. Liberar el lock únicamente después del smoke o del rollback completo.

## Rollback determinista

| Fallo inyectado | Recuperación |
| --- | --- |
| Antes de 280 | No hay cambio; runtime y metadata siguen en 2.35.1/279. |
| En límite medio 286 | No activar runtime. El rehearsal confirmó reanudación 287→293 bajo la autoridad del migrador. Un fallo SQL no certificado exige evaluar su diagnóstico; si hay mutación comercial o DDL incierto, restaurar el backup. |
| Después de 293, antes de versión | Conservar esquema aditivo 293, restaurar `app.version=2.35.1` y mantener el marcador anterior. |
| Después del COMMIT, antes del marcador | Transacción compensatoria a 2.35.1, restauración exacta de la fila histórica y marcador anterior intacto. |
| Después del marcador, antes del pointer | Restaurar metadata SQL y bytes exactos del marcador, sin cambiar el pointer. |
| Después del pointer o smoke fallido | Bajo mantenimiento: restaurar primero el pointer 2.35.1, invalidar opcache, restaurar metadata/marcador y repetir smoke local. |
| Corrupción de esquema/datos o incompatibilidad N-1 | Restaurar el backup MariaDB completo; no intentar rollback parcial. |

El esquema 293 puede conservarse durante rollback sólo si la suite N-1 en modo
detenido certifica compatibilidad. Este rehearsal prueba que 280–293 no cambian
filas ni checksums de tablas comerciales; la certificación del runtime N-1
pertenece al gate combinado de cutover.

## Evidencia local 2026-08-09

- Fuente: backup probado restaurado por streaming en un `datadir` MariaDB
  desechable y aislado; ninguna conexión a producción.
- Inicio: 269 tablas, 279 migraciones, máximo 279, versión 2.35.1.
- Motor de ensayo: MariaDB 12.3.2.
- Migraciones: 280–286, parada inyectada, reanudación 287–293.
- Tablas comerciales protegidas por `COUNT(*)` + `CHECKSUM TABLE`: 262.
- Fallos ejercitados: antes de migración, límite medio, antes de versión,
  después de versión/marcador y rollback de switch.
- Final: schema 293, `app.version=2.36.1`, marcador 2.36.1/293 válido,
  segunda ejecución idempotente, pérdida comercial 0.
- HTTP Mercado Libre: 0.

Comando focal (sólo contra clon desechable localhost y puerto no 3306):

```text
ERP_2361_REHEARSAL_ACK=DISPOSABLE_CLONE_SCHEMA_279
ERP_2361_REHEARSAL_DSN=mysql:host=127.0.0.1;port=<puerto-local>;dbname=<clon>;charset=utf8mb4
php tests/external_cutover_mariadb_2361.php
```
