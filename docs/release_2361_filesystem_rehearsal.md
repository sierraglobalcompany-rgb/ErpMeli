# ERP MELI 2.36.1: rehearsal de filesystem y rollback externo

Este documento registra el contrato ejecutable de `tests/external_filesystem_cutover_rehearsal_2361.php`. Es un ensayo local desechable; no autoriza ni modifica producción y no invoca el updater.

## Autoridades utilizadas

- Runtime anterior: objetos Git del commit productivo `f91cd534d271b964db1ad9e682260475eca96820` (`2.35.1`).
- Runtime objetivo: ZIP de rehearsal construido directamente con cada `packageEntry` de `RuntimePublicationPolicy` y sus bytes obtenidos mediante `gitBlob` del `HEAD` ensayado. El artefacto de publicación final usa el builder canónico después de integrar el RC.
- Cuarentena: las seis rutas y SHA-256 de `resources/release/production-legacy-quarantine-2.36.1.json`.
- Arquitectura y orden operacional: `docs/release_2361_external_cutover.md`.

Los bytes reales de los ejecutables stale no se incorporan al repositorio. El fixture usa contenido PHP inerte en las seis rutas exactas y calcula su hash observado para ejercitar el contrato copy→verify→move y el bloqueo por mismatch. El test comprueba separadamente que la autoridad conserva las seis rutas y hashes de producción exactos. Un cutover real debe comparar los bytes observados con esos hashes autoritativos, nunca con los hashes del fixture.

## Filesystem production-like

El ensayo materializa el runtime 2.35.1 completo desde Git, una release de rollback, `shared/`, `releases/`, un staging privado fuera del webroot, cuarentena privada y el runtime objetivo completo. Agrega placeholders no secretos para configuración, storage, recovery OAuth, logs, cache, uploads, backups y los dos marcadores de parada.

También crea exactamente 350 archivos bajo `app/graphify-out`. Se prueba que permanecen byte-idénticos en el runtime clásico, que la autoridad los excluye del target y que no se copian a la release administrada.

## Secuencia probada

1. Crear runtime clásico y rollback desde objetos Git 2.35.1.
2. Construir el ZIP local de rehearsal desde objetos Git, abrirlo, comparar nombres y bytes con todos los blobs administrados y extraerlo fuera del webroot.
3. Verificar staging completo y ausencia de `.git`, secretos, estado mutable y `graphify-out`.
4. Renombrar el staging verificado a su directorio final inmutable bajo `releases/`.
5. Tomar snapshots por path, tipo, tamaño y SHA-256 del estado protegido y de los 350 caches.
6. Publicar maintenance y el guard 503; mantenerlos durante toda combinación transitoria de schema, versión y pointer.
7. Publicar el pointer mediante temp en el mismo directorio, escritura completa, `fflush`, `fsync(file)` cuando está disponible y rename atómico.
8. Ejecutar el preflight de los seis hashes antes de mover cualquiera. Después copiar, verificar y mover cada stale fuera del webroot, sin borrarlo.
9. Confirmar target completo, schema/version simulados coherentes, cero stale alcanzable y estado protegido intacto.
10. Restaurar `.htaccess` byte-exact y retirar maintenance sólo después del smoke simulado exitoso.

La sincronización del directorio padre después de cada rename sigue siendo un gate Linux obligatorio del procedimiento operacional. PHP sobre Windows no puede certificar de forma portable un descriptor de directorio; este ensayo no convierte esa limitación en un falso PASS.

## Matriz de fallos

El test cubre y exige recuperación determinista para:

- fallo antes de migrar;
- fallo a mitad de migraciones;
- fallo después de migraciones;
- fallo antes de cambiar `app.version`;
- fallo después de `app.version` y antes del pointer;
- crash después de escribir el temp y antes del rename del pointer;
- fallo después del pointer;
- fallo del smoke FPM;
- hash mismatch de stale antes de cualquier move;
- fallo después de tres movimientos de cuarentena;
- rollback completo después de mover los seis stale;
- rollback del pointer después de activación;
- rollback después del smoke fallido.

Las etapas DB de esta matriz expresan el contrato combinado; el rehearsal real de MariaDB, sus locks, migraciones 280–293, invariantes y compatibilidad N-1 pertenecen a la evidencia del agente de base de datos. En todos los fallos del filesystem el guard permanece activo hasta recuperar un pointer completo y una versión técnica conocida.

## Criterios de aceptación

- target staging y release final coinciden byte a byte con todos los blobs Git administrados;
- seis stale quedan fuera del webroot en éxito y pueden restaurarse por hash en rollback;
- un mismatch bloquea antes del primer move;
- un fallo parcial restaura los tres archivos movidos y conserva los otros tres;
- estado protegido modificado: cero;
- cache `graphify-out` modificado: cero;
- runtime mezclado observado al retirar el guard: cero;
- HTTP Mercado Libre real: cero;
- updater usado o modificado: no.
