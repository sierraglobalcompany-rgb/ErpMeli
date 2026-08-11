# ERP MELI 2.36.7 — feature flag order hotfix

## Causa raíz

`QueueCoreFeatureFlagService::snapshot()` canonicaliza el mapa mediante
`ksort(SORT_STRING)`. El consumidor `V4ReadinessBootstrapService` comparaba ese
mapa con constantes mediante `===`, que en PHP también compara el orden de
inserción. Las precondiciones quedaban en `ready`, pero el estado no avanzaba a
`ready_to_arm`.

## Corrección

Se añadió una comparación privada que copia y ordena ambos mapas antes de usar
igualdad estricta. La validación continúa exigiendo el conjunto exacto: faltantes,
extras, valores y tipos incorrectos siguen bloqueados. El mismo helper protege el
snapshot inicial, el reconocimiento del entorno armado y el rollback.

No se modificaron `QueueCoreFeatureFlagService`, CAS, generations, canarios,
convergencia, capacidad, OAuth, FIFO ni Queue Engine. No hay migración.

## Seguridad operacional

- Scheduler Hostinger: no creado.
- Queue Engine: no activado.
- Escrituras remotas de negocio: ninguna.
- `storage/raw`: no accedido.
- Producción: no contactada durante construcción y certificación.

Los hashes finales son autoridad de los sidecars generados por
`bin/build_release_2367_artifacts.php`.
