# ERP MELI 2.36.1: cutover externo de runtime administrado

Estado: procedimiento excepcional, operado por SSH y fuera del updater. Este documento no autoriza producción. Su ejecución real exige un runbook generado desde el RC exacto, una ventana aprobada y evidencia fresca de todos los gates.

## Límites de autoridad

- El updater/installer de la aplicación no participa. No se invocan `SecureUpdateEngineService`, `UpdateReleaseService`, `ReleaseIntegrityService`, `actualizador.php` ni sus rutas.
- No se instala un segundo updater. Las operaciones descritas son pasos efímeros del operador, ejecutados desde fuera del webroot, con comandos revisados y evidencia por paso.
- El runtime candidato se prepara en un directorio inmutable `releases/<release_id>.staging-*`, se valida completamente y sólo entonces se renombra a `releases/<release_id>`.
- `config.env`, `.env`, `storage`, uploads, logs, caches, recovery escrows, backups, marcadores `PAUSE_*` y cualquier estado mutable quedan fuera de la release.
- La única frontera de activación del runtime es `shared/current-release.json`. Su publicación usa archivo temporal en el mismo directorio, escritura completa, `flush`, `fsync` del archivo, `rename` atómico y `fsync` del directorio padre en Linux. Si no puede certificarse cualquiera de esos pasos, el cutover se bloquea.

## Hallazgo sobre maintenance.json

`launcher/web.php` sí interpreta `shared/maintenance.json`, pero esa señal no cubre todo el webroot. La petición a `/` entra por el `index.php` estable, que llama directamente a `launcher/entrypoint.php`; el despachador define `ERP_RELEASE_BOOTSTRAPPED` y carga el `index.php` de la release. Esa ruta no pasa por el chequeo de mantenimiento de `launcher/web.php`. Otros entrypoints raíz también despachan directamente.

Por ello, `shared/maintenance.json` es defensa en profundidad, no la barrera primaria de este salto. La ventana crítica requiere un guard temporal del webroot controlado fuera de banda.

## Barrera web fuera de banda

Antes de migrar o cambiar `app.version`, el operador prepara fuera del webroot un `.htaccess` mínimo que responde 503 a toda petición salvo un único asset GET/HEAD sin estado usado para el smoke de FPM. El guard se valida en un clon Apache con el mismo `AllowOverride` y módulos que producción. Se publica en el webroot mediante temp + `fsync(file)` + rename + `fsync(parent)`. Se conserva fuera del webroot una copia byte-exact y su SHA-256 del `.htaccess` normal.

El guard debe:

- devolver 503 para `/`, entrypoints PHP, rutas públicas, callbacks y webhooks;
- no aceptar POST ni exponer un endpoint nuevo;
- permitir sólo el asset de smoke exacto, por GET/HEAD;
- conservar `Options -Indexes` y bloquear dotfiles;
- no contener secretos, nonce o bypass por cabecera/IP;
- permanecer activo hasta que el pointer, la versión técnica y los smokes locales sean coherentes.

Plantilla exacta para el rehearsal Apache (el asset puede cambiar sólo si el RC documenta su hash):

```apache
Options -Indexes
ErrorDocument 503 "ERP maintenance"
RewriteEngine On
RewriteRule (^|/)\. - [F,L]
RewriteCond %{REQUEST_METHOD} ^(?:GET|HEAD)$
RewriteRule ^assets/app\.css$ asset.php?path=app.css [END]
RewriteRule ^ - [R=503,L]
```

Al estar en contexto per-directory, el patrón es independiente del prefijo URL del ERP. Antes de aprobarlo se demuestra con Apache real: GET y HEAD del asset retornan su hash esperado; todos los demás métodos y paths retornan 503. El archivo se compone primero fuera del webroot. Para conservar atomicidad, se copia luego como un temp oculto no ejecutable en el mismo directorio que `.htaccess`, se sincroniza y se reemplaza con `rename`. La regla normal ya bloquea dotfiles mientras existe el temp. No se reutiliza un temp ni se acepta un filesystem diferente.

Las primitivas durables se ejecutan con una herramienta estándar disponible al operador que pueda certificar `open/write/flush/fsync/rename/fsync(parent)` (por ejemplo, Python 3 `os.open`, `os.write`, `os.fsync` y `os.replace`). Su código y hash quedan en la evidencia del runbook, se ejecuta fuera del webroot y se retira al cerrar la ventana. La ausencia de Python 3 o la imposibilidad de abrir/sincronizar el directorio es un gate de bloqueo; `mv`, `file_put_contents` o `fsync(temp)+rename` por sí solos no cuentan como publish durable.

La restauración del `.htaccess` normal también es un reemplazo durable y atómico. Un hash distinto al aprobado bloquea. `shared/maintenance.json` se crea antes del guard y se retira únicamente después de restaurar el `.htaccess` normal y verificar el estado final.

## Layout y estado compartido

La raíz estable conserva `.htaccess`, los entrypoints raíz y `launcher/`. Antes del ensayo se certifica que esos archivos son los hashes conocidos del runtime 2.35.1 y compatibles con el formato del pointer 2.36.1; no se reemplazan durante el switch.

El candidato vive en `releases/<release_id>`. El estado persistente vive en `shared/` y fuera del webroot privado según `AppPaths`:

- `shared/config.env`, modo privado, copiado sin mostrar contenido;
- `shared/storage`, preservando nombres, bytes y permisos necesarios;
- `PAUSE_MELI_API` y `PAUSE_ERP_AUTOMATION` en la raíz estable;
- recovery OAuth/emergency y backups en su ubicación privada certificada;
- `shared/current-release.json` como pointer;
- quarantine y backups fuera del webroot.

En una instalación clásica que aún usa `storage/`, se hace una primera copia hacia `shared/storage` con el sistema detenido. Después de publicar el guard 503 se hace una copia delta y una comparación completa. No se activa ningún pointer hasta que `shared/config.env` y `shared/storage` sean legibles, privados y exactos. El origen no se mueve ni se borra.

Se prepara además una release de rollback desde el backup focal aprobado del runtime 2.35.1, usando un inventario explícito y sin estado protegido. Permanece inaccesible bajo `releases/<rollback_id>` y se verifica antes de la ventana. Así el rollback conserva `shared/` como autoridad y evita volver de forma silenciosa al storage clásico.

El pointer publicado tiene únicamente metadata no secreta y el formato ya aceptado por los launchers:

```json
{
  "release_id": "erp-meli-2.36.1-<git-short-sha>",
  "version": "2.36.1",
  "path": "releases/erp-meli-2.36.1-<git-short-sha>",
  "previous_release_id": "rollback-2.35.1-<evidence-id>",
  "previous_version": "2.35.1",
  "activated_at": "<UTC RFC3339>"
}
```

`release_id`, basename de `path`, `VERSION` y la identidad Git del manifest deben coincidir. El path es relativo, no contiene `..`, drive ni enlace que escape de `releases/`. La release final y todos sus ancestros se resuelven con `realpath` antes del publish.

## Gates previos

Todos deben pasar inmediatamente antes de cualquier mutación del clon/rehearsal o, en el futuro, de producción:

1. HEAD/tag/ZIP/manifest y hashes de migraciones 280-293 son los aprobados.
2. Runtime target y rollback están completos, inmutables y fuera del activo.
3. Inventario de updater permanece byte-idéntico al base.
4. Backup focal de archivos y backup MariaDB fresco, restaurables y con SHA-256.
5. API BLOCKED; Automation STOPPED; `ML_WRITE_ENABLED=false`; Cron V3 ausente o demostrado fail-closed con cero HTTP; ninguna lease/cola/proceso activo.
6. No hay refresh OAuth, canary, recovery escrow, migration ni cutover concurrente.
7. Marcadores de seguridad existen en la raíz estable y sus hashes/inodos se registran sin contenido sensible.
8. `shared/`, `releases/`, quarantine y temporales tienen permisos y espacio suficiente; todos los renames críticos son dentro del mismo filesystem.
9. El guard 503 fue probado y su activación/restauración son atómicas y durables.
10. Existe un único lock externo exclusivo de cutover fuera del webroot, adquirido con `flock` no bloqueante. Un lock ocupado bloquea.
11. La transición técnica exacta de `app_settings.setting_key='app.version'` y su rollback provienen del contrato certificado del repositorio; no se improvisa SQL.
12. La compatibilidad N-1 detenida sobre schema 293 fue certificada. En caso contrario, el rollback exige restaurar el backup DB completo.

## Máquina de estados combinada

Cada estado persiste evidencia no secreta fuera del webroot. Una reanudación primero observa filesystem, pointer, `schema_migrations` y `app.version`; nunca confía sólo en el último log.

| Estado | Runtime visible bajo guard | Schema | app.version | Acción permitida |
|---|---|---:|---|---|
| `S0_VERIFIED_OLD` | 2.35.1 clásico | 279 | 2.35.1 | stage/backup solamente |
| `S1_TARGET_STAGED` | 2.35.1 clásico | 279 | 2.35.1 | preparar shared/rollback |
| `S2_WEB_GUARDED` | 503 | 279 | 2.35.1 | cerrar delta de shared |
| `S3_ROLLBACK_POINTER` | 503, pointer 2.35.1 | 279 | 2.35.1 | migrar bajo lock |
| `S4_MIGRATING` | 503, pointer 2.35.1 | 280..293 | 2.35.1 | continuar o diagnosticar |
| `S5_SCHEMA_293` | 503, pointer 2.35.1 | 293 | 2.35.1 | validar invariantes |
| `S6_VERSION_2361` | 503, pointer 2.35.1 | 293 | 2.36.1 | switch o rollback metadata |
| `S7_TARGET_POINTER` | 503, pointer 2.36.1 | 293 | 2.36.1 | smokes locales/quarantine |
| `S8_LOCAL_SMOKE_PASS` | 503 salvo asset | 293 | 2.36.1 | restaurar guard normal |
| `S9_EXPOSED_STOPPED` | 2.36.1 | 293 | 2.36.1 | terminar; no activar jobs/API |

Nunca se cambia `app.version` antes de verificar schema 293. Nunca se retira el guard si pointer/version/schema no forman una terna aprobada.

## Orden exacto del rehearsal

1. Adquirir el lock externo y capturar evidencia de gates.
2. Verificar backups, inventarios, hashes y espacio; no volver a generar artefactos desde el working tree.
3. Extraer el ZIP Git-exact fuera del activo; comparar nombres y bytes con la autoridad; renombrar staging a release final inmutable.
4. Preparar/verificar la release de rollback 2.35.1 desde el backup e inventario aprobado.
5. Preparar `shared/config.env` y la primera copia de `shared/storage` sin modificar sus fuentes.
6. Crear `shared/maintenance.json` durable como defensa adicional.
7. Publicar el guard 503 atómicamente y comprobar por HTTP que `/`, PHP, webhook y callback devuelven 503 y sólo el asset de smoke permanece legible.
8. Detenerse si aparece un proceso/lease. Cerrar y verificar la copia delta de `shared/storage`.
9. Publicar un pointer al rollback runtime 2.35.1 y verificarlo desde los entrypoints estables mientras el guard sigue activo.
10. Adquirir el advisory lock MariaDB; aplicar exactamente 280→293 con el runner certificado y hashes aprobados. Validar `schema_migrations`, estructura y conteos/invariantes de negocio.
11. Ejecutar exactamente una transición técnica `app.version: 2.35.1 → 2.36.1`, usando el mecanismo certificado por la auditoría de DB.
12. Publicar durablemente el pointer 2.36.1. Leerlo de nuevo, validar `release_id == basename(path)`, path relativo dentro de `releases/`, versión y hashes.
13. Ejecutar lint/bootstrap/DB health por CLI desde la release exacta. Ejecutar por FPM sólo el asset GET/HEAD permitido y comparar bytes/headers esperados. La ruta inmutable de release evita reutilizar bytecode del runtime viejo; cualquier cambio en archivos estables requiere una recarga explícita y certificada del pool, no un `opcache_reset()` CLI engañoso.
14. Para cada stale executable, exigir hash exacto, copiar+verificar fuera del webroot y sólo entonces mover atómicamente a quarantine. Hash mismatch bloquea. Nada se borra.
15. Verificar nuevamente API/Automation/ML_WRITE/Cron, recovery, protected state y ausencia de HTTP Mercado Libre.
16. Restaurar de forma durable el `.htaccess` normal byte-exact. Ejecutar un único smoke HTTP local no mutante; no llamar Mercado Libre.
17. Retirar `shared/maintenance.json`, liberar locks y conservar evidencia. API y Automation permanecen STOPPED.

## Fallos y recuperación determinista

| Punto inyectado | Estado seguro inmediato | Recuperación |
|---|---|---|
| antes de migración | guard activo, pointer rollback, versión 2.35.1 | abortar; restaurar guard normal sólo tras comprobar viejo runtime |
| durante migraciones | guard activo, pointer rollback, versión 2.35.1 | no exponer; completar idempotentemente sólo si el runner lo certifica, o restaurar DB |
| después de migraciones | guard activo, pointer rollback, schema 293, versión 2.35.1 | validar N-1; continuar o restaurar DB |
| antes de `app.version` | igual a `S5_SCHEMA_293` | continuar sólo con invariantes PASS |
| después de `app.version` y antes del pointer | guard activo, pointer rollback, versión 2.36.1 | revertir metadata a 2.35.1 o completar switch; nunca exponer la combinación |
| fallo al publicar pointer | guard activo; por atomicidad el pointer es viejo o nuevo completo | leer autoridad real; alinear versión y pointer a rollback o target |
| después de activar target | guard activo, target completo | smoke; si falla, pointer rollback y metadata 2.35.1 |
| smoke FPM/opcache falla | guard activo | pointer rollback, metadata 2.35.1, verificar old smoke; DB293 queda sólo con N-1 PASS |
| fallo tras exponer | activar guard primero | esperar 503, luego pointer rollback + metadata; restaurar stale desde quarantine si el rollback lo necesita |

Un fallo de rollback, hash, fsync, DB restore o compatibilidad mantiene el guard 503, API/Automation STOPPED y requiere intervención. No hay estado que autorice continuar automáticamente.

## Rollback externo

El rollback no usa el updater. Bajo guard 503 y locks:

1. verificar que el rollback runtime y sus hashes siguen intactos;
2. publicar atómicamente el pointer de rollback;
3. restaurar `app.version=2.35.1` con el mecanismo técnico certificado;
4. mantener schema 293 sólo si la prueba N-1 detenida pasó; si no, restaurar el backup MariaDB completo;
5. restaurar desde quarantine cualquier archivo exacto requerido por el runtime anterior, sin sobrescribir hash desconocido;
6. confirmar que config, storage, escrows, uploads, backups y marcadores no cambiaron;
7. hacer smokes locales y sólo después restaurar el `.htaccess` normal.

La release 2.36.1 fallida nunca se edita: queda inmutable para forense. No se borra evidencia ni se limpia automáticamente.

## Opcache/FPM

Cada release usa un path físico único e inmutable, por lo que las claves de opcache del target no se confunden con las del runtime anterior. El procedimiento no modifica los launchers ni entrypoints estables. `opcache_reset()` ejecutado por CLI no prueba ni limpia el pool FPM y no cuenta como evidencia. La certificación mínima combina:

- resolución y hash del pointer desde CLI;
- carga de `bootstrap.php` y health DB local desde el path target;
- GET y HEAD FPM del asset exacto permitido por el guard, con hash de body;
- revisión de error log sin persistir response bodies ni secretos.

Si algún archivo estable fuera diferente al hash certificado, este procedimiento se bloquea; no lo reemplaza ni intenta recargar FPM de manera ad hoc.

## Resultado esperado del rehearsal

`schema=293`, `app.version=2.36.1`, pointer exacto a 2.36.1, runtime Git-exact, protected state byte-identical, stale exacto en quarantine, cero ventana de runtime mezclado, cero HTTP Mercado Libre. El rollback debe terminar con pointer 2.35.1, `app.version=2.35.1`, protected state intacto y schema 293 únicamente cuando N-1 esté certificado.
