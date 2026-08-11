# ERP MELI 2.36.2 `actualizar.php` — causa raíz y hotfix 2.36.3

## Veredicto

`ROOT_CAUSE_CONFIRMED=YES`

La pantalla “La subida de archivos está incompleta” es un falso positivo reproducible con una release 2.36.2 completamente limpia. El runtime 2.36.2 se publicó bajo la política managed, pero `ReleaseIntegrityService` evaluaba el directorio instalado con la autoridad frozen/legacy. Sobre los mismos bytes Git exactos, la política equivocada produce exactamente:

- `manifest_installed_inventory_mismatch`
- `manifest_publication_policy_mismatch`
- `manifest_release_identity_mismatch`

La política managed reconoce esos mismos bytes sin errores. La copia FTP puede agravar el estado, pero no es necesaria para reproducir el mensaje mostrado.

## Segundo bloqueo

Al corregir sólo la selección de autoridad aparecía un bloqueo circular: schema 293 ya estaba completo, las migraciones pendientes eran cero, pero `app.version` y el marker seguían en 2.35.1. La integridad previa exigía que la base ya declarara la versión target antes de autorizar la acción que debía promover esa metadata. La interfaz terminaba ofreciendo únicamente volver a comprobar.

2.36.3 separa ese caso como una transición metadata-only explícita y fail-closed. No ejecuta migraciones, setup de cron ni invalidaciones ajenas. Su write-set permitido es exclusivamente:

- `app_settings` para `setting_key='app.version'`;
- la fila `app_versions` de 2.36.3;
- `storage/installed-release.json` mediante la autoridad de storage activa.

La transición usa PDO fresco no persistente, advisory lock, `READ COMMITTED`, `SELECT ... FOR UPDATE`, CAS, transacción, preimagen exacta del marker y rollback ante cada failpoint.

## Auditoría segura del backup

Se compararon sólo los 813 paths técnicos del paquete 2.36.2 y roots de código permitidos en `C:\codex\meli backup`. Nunca se abrió ni enumeró recursivamente `storage/raw` o `shared/storage/raw`; tampoco se leyeron `config.env`, secretos, OAuth, logs o payloads.

Clasificación del paquete observado:

- 13 archivos byte-exactos;
- 654 equivalentes tras normalizar LF/CRLF;
- 37 diferentes;
- 109 faltantes;
- 299 ejecutables técnicos adicionales, principalmente migraciones históricas necesarias para reconstruir el laboratorio pero ajenas al paquete managed 2.36.2.

Conclusión: el backup es una captura histórica/mixta útil como evidencia, no una release 2.36.2 limpia ni una fuente de base de datos. No debe volver a subirse como carpeta completa.

## Cambios 2.36.3

1. Selección explícita de política legacy/managed ligada a identidad exacta de release.
2. Perfiles instalados separados para 2.36.2 y 2.36.3; builds cruzados o desconocidos bloquean.
3. Política legacy `RuntimePublicationPolicy` conservada byte-exacta.
4. Finalización metadata-only para `2.35.1 + schema293 + pending0`.
5. Inventario updater 2.36.3 que recertifica únicamente los dos archivos intencionalmente cambiados y conserva la autoridad histórica de los otros 27.
6. Ningún endpoint, migración o llamada nueva a Mercado Libre.

## Diez pasadas

1. Autoridades Git 2.35.1/2.36.2 verificadas; 2.36.2 conserva 813 archivos y 812 componentes.
2. Backup comparado contra Git con exclusiones absolutas aplicadas.
3. Extras, faltantes, mezcla, CRLF, entrypoints/manifests y ejecutables históricos inventariados.
4. Los tres errores se reprodujeron en 2.36.2 limpia bajo frozen; managed dio cero errores.
5. MariaDB 11.8.8 local materializó migraciones 001–293 y el escenario exacto; login/CSRF/actualizador funcionaron bajo `/erp-meli`.
6. Matriz de cinco mezclas ejecutada; paquetes cruzados o contaminados bloquearon.
7. Casos futuro, downgrade, marker ausente/presente/tampered, lock ocupado, conexión expirada y autoridad de migración se probaron fail-closed.
8. Hotfix 2.36.3 implementado sin cambiar retroactivamente 2.36.2.
9. Promoción metadata-only y rollback exacto pasaron 21 checks; HTTP completo pasó 307 checks y conservó todas las tablas no autorizadas.
10. Artefactos construidos desde blobs Git; el cierre exige rebuilds independientes con `core.autocrlf=false/true` y auditoría final del freeze.

## Estado local antes/después

Antes: `VERSION=2.36.3`, `app.version=2.35.1`, marker 2.35.1, schema 293, pendientes 0, API/automatización detenidas.

Después: `VERSION=2.36.3`, `app.version=2.36.3`, marker 2.36.3, schema 293, pendientes 0. Todas las tablas fuera del write-set y todo storage fuera del marker permanecieron byte/lógicamente iguales en el snapshot de laboratorio.

Capturas: `lab/actualizar-before.png` y `lab/actualizar-after.png`.

## Artefactos de runtime

- `ERP_MELI_2.36.3_GIT_EXACT.zip`: `0e325f69ecf79e9fc5f5820d61eb2bf24c1f0536ddf12f39abd02a59aa6bfe70`
- `ERP_MELI_2.36.3_FTP_REPAIR_OVERLAY.zip`: `2e5663f9412d5d4bf24f339f51e5c56dbe813f909a44f9c22c727b265f58efb3`
- `ERP_MELI_2.36.3_UPDATE_PACKAGE.erpupd`: `58b8a4618bd51bb6e6ec834c94294b8b4bcf584382f3974c70cdc1f622acaca6`

Las identidades del commit/tree, inventario overlay y hashes de sidecars se encuentran en `ERP_MELI_2.36.3_ARTIFACT_MANIFEST.json` y `ERP_MELI_2.36.3_SHA256SUMS.txt`.

## Limitaciones

Esta certificación es exclusivamente local. No se consultó ni modificó Hostinger, producción, DNS, FTP, SSH o Mercado Libre. El backup no sustituye la verdad remota y las rutas raw quedaron deliberadamente sin inspección.

## Resumen compacto para ChatGPT

La release Git-exact 2.36.2 reproduce por sí sola el mensaje de subida incompleta porque `ReleaseIntegrityService` aplicaba la política frozen a un manifest managed. Corregida esa selección, aparecía un segundo bloqueo circular: schema293/pending0 con `app.version` y marker aún 2.35.1. 2.36.3 añade perfiles exactos y una finalización metadata-only transaccional limitada a app.version, app_versions[2.36.3] y marker, con lock/CAS/preimagen/rollback. MariaDB 11.8.8, HTTP bajo `/erp-meli`, mezclas, markers y failpoints pasaron localmente; raw no se tocó y producción no fue contactada. Próxima acción única: revisar informe y hashes antes de cualquier FTP.
