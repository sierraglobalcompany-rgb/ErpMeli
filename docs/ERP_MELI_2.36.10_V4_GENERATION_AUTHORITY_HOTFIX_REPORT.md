# ERP MELI 2.36.10 — autoridad monotónica V4

## Causa raíz

2.36.9 exigía Queue Engine y feature flags en generación cero. Sin embargo, la transición `idle → preparing` y su rollback `preparing → idle` incrementan legítimamente la generación. El sistema quedaba fail-closed, pero el panel lo clasificaba como `feature_generation_invalid` y deshabilitaba el botón antes de validar la contraseña.

## Corrección

- Baseline fail-closed: motor `idle` generación `G` y flags de readiness apagados ligados a `G`.
- Armado: los tres flags habilitables se reservan en `G+1`.
- Preparación: Queue Engine avanza a esa misma `G+1`.
- Rollback: conserva la monotonicidad y vuelve a un baseline coherente, sin reiniciar generaciones.
- El snapshot publica perfil, esperado, observado y mismatches técnicos sin secretos.

No se añadieron endpoints, migraciones ni DML comercial. El scheduler permanece ausente, Queue Engine disabled, ML writes apagado y la acción V4 conserva contraseña, sesión administrativa, CSRF, same-origin y advisory lock.

## Límites operativos

La construcción y QA son locales. No se accedió a Hostinger, Mercado Libre ni `storage/raw`. Tras instalar, el único paso inicial autorizado es verificar `ready_to_arm`; no se crea Cron V4 ni se activa Queue Engine.
