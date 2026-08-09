# ERP Meli 2.36.2 — contrato de rehearsal y cutover externo

Este documento no autoriza producción. Define el rehearsal que debe completarse
antes de publicar o desplegar 2.36.2. No se invoca el updater, no se ejecuta Cron
V4, OAuth remoto, Automation ni HTTP real a Mercado Libre.

## Autoridades iniciales

- Base de código: `75997f864b917c829d19f14e21cd7303d2685776`.
- Runtime seguro instalado: HF1.2 clásico, versión `2.35.1`.
- Base de datos: `schema=293`, `app.version=2.35.1` y las migraciones 280–293
  presentes exactamente una vez.
- `MIGRATIONS_TO_APPLY_EXPECTED=0`; no existe migración 294 y el cutover no
  llama al migrador.
- `PAUSE_MELI_API` y `PAUSE_ERP_AUTOMATION` deben existir y conservar su hash.
  `ML_WRITE_ENABLED=false`, engine disabled/idle y Cron V4 disabled son gates.
- El scheduler externo permanece `UNKNOWN`; por tanto no se autoriza activación.

Los hashes de migraciones y del runtime se verifican con
`RAW_GIT_OR_PACKAGE_BYTES`: `git cat-file`/`git archive` sobre el commit o bytes
leídos directamente del ZIP certificado. Un checkout normalizado por CRLF/LF no
es autoridad de identidad.

## State machine obligatorio

1. `S0_STOPPED_2351_SCHEMA293`: adquirir lock externo, verificar stops físicos,
   ausencia de workers/leases y respaldos restaurables. Capturar snapshots de
   estado protegido y de las seis rutas stale.
2. `S1_TARGET_VERIFIED`: extraer el ZIP privado, bloquear paths inseguros,
   symlinks, colisiones de mayúsculas, faltantes, orphans y blobs distintos a
   Git. No se invoca el updater.
3. `S2_WEB_GUARDED`: publicar guard 503 con write/flush/fsync(file)/rename y
   fsync(parent). Revalidar stops y que el pointer aún resuelve 2.35.1 completo.
4. `S3_VERSION_2362`: abrir una fresh verified connection inmediatamente antes
   de la transacción; bloquear `app.version`, revalidar `schema=293` y promover
   técnicamente `2.35.1 → 2.36.2`. No hay versión intermedia 2.36.1.
5. `S4_TARGET_POINTER`: publicar atómicamente el pointer a la release Git-exact,
   verificar asset y ejecutar la matriz web real completa. La matriz debe incluir
   `/`, login, actualizar, stop, mantenimiento, recuperar, cron-status y asset;
   fatal=0, redeclaration=0 y dispatch depth máximo=1.
6. `S5_QUARANTINED`: preflight global de los seis hashes; por cada archivo hacer
   copy fuera del webroot, verificar, mover atómicamente y fsync de ambos padres.
   No borrar nada.
7. `S6_EXPOSED_STOPPED`: restaurar routing normal, repetir matriz web/FPM y dejar
   API y Automation detenidos. El resultado exige `MIXED_ACTIVE_RUNTIME=0`.

El marker instalado se publica duramente antes del pointer. Cada gate vuelve a
comprobar que ninguna llamada HTTP de Mercado Libre haya comenzado.

## Rollback después del pointer

Ante fatal, redeclaración, recursión, FPM/opcache incorrecto, error de auth o
fallo de quarantine:

1. republicar de inmediato el guard 503;
2. publicar el pointer completo a HF1.2/2.35.1;
3. abrir una fresh verified connection nueva, revalidar lock y `schema=293`, y
   restaurar `app.version` a 2.35.1 en su propia transacción;
4. restaurar el marker anterior;
5. restaurar las seis rutas desde las copias privadas y verificar cada hash;
6. verificar que shared storage, config y stops coinciden con el snapshot;
7. retirar el guard y ejecutar toda la matriz web del runtime rollback.

La conexión que promovió la versión no se conserva esperando smokes de
filesystem/FPM. El rehearsal debe expirar deliberadamente esa conexión y probar
que el rollback con una conexión nueva funciona:
`EXPIRED_DB_CONNECTION_ROLLBACK=PASS`.

## Matriz de fallos

| Punto | Estado recuperable exigido |
| --- | --- |
| Antes de versión | 2.35.1, schema293, pointer viejo, seis rutas presentes |
| Después de versión, antes del pointer | versión restaurada a 2.35.1 con conexión nueva |
| Después del pointer | pointer viejo + app.version 2.35.1 + matriz rollback completa |
| En quarantine parcial | journal inverso, seis hashes exactos, ninguna eliminación |
| Pointer parcial/malformado | documento publicado anterior intacto; fail closed |
| Conexión DB vencida | conexión nueva transaccional; nunca reutilizar la sesión vencida |

En cualquier rollback el schema aditivo permanece 293. No se ejecuta downgrade
de migraciones.

## Autoridad del paquete que debe regenerarse

El hotfix no debe editar el updater ni ninguna de sus 29 autoridades congeladas.
Desde el head final revisado deben regenerarse únicamente estas identidades del
managed runtime siguiendo el flujo ya certificado en 2.36.1:

- `VERSION` a 2.36.2;
- constantes de identidad de `ManagedRuntimePublicationPolicy` (versión, build
  id y fecha reproducible) y su referencia al registro 2.36.2;
- `resources/release/managed-runtime-dependencies-2.36.2.json`, actualizando
  solamente rutas/literales de release y el inventario derivado;
- `resources/release/production-legacy-quarantine-2.36.2.json`, conservando las
  mismas seis rutas y hashes productivos, pero con destinos privados 2.36.2;
- `resources/runtime-manifest.json`, al final, desde blobs Git del head revisado.

El manifest es el último archivo generado porque atestigua todos los demás. El
conteo del ZIP y de componentes se deriva del árbol, nunca se fija a 811/810. El
ZIP `ERP_MELI_2.36.2_GIT_EXACT.zip` se construye sólo desde objetos del tag y se
vuelve a leer entrada por entrada: required missing=0, orphan=0, blob mismatch=0,
unmanaged executable=0, ambiguous=0, unsafe path=0 y unsafe symlink=0.

No se regeneran ni modifican migraciones, updater/installer, inventario locked,
estado compartido, secretos o backups. Los tests y esta documentación son
`TEST_ONLY`/`DOCUMENTATION` y no entran al paquete administrado.

## Comandos de QA focal

```text
php tests/external_cutover_state_machine_2362.php
php tests/external_cutover_mariadb_2362.php
php tests/<managed-entrypoint-matrix-2362>.php
php tests/updater_immutability_2361.php
php -l tests/external_cutover_state_machine_2362.php
php -l tests/external_cutover_mariadb_2362.php
git diff --check 75997f864b917c829d19f14e21cd7303d2685776..HEAD
```

La prueba MariaDB es opt-in y sólo admite un clon local desechable en puerto no
estándar. La matriz real de entrypoints es un gate separado y obligatorio; el
fixture de state machine no pretende sustituir esa certificación.
