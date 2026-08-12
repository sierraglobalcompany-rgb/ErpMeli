# ERP MELI 2.36.11 — causa raíz y hotfix del clasificador V4

## Causa raíz

2.36.10 validaba correctamente los gates básicos y por eso producía `reason=ready`, pero su árbol de condiciones no clasificaba todas las postimágenes parciales legítimas. En particular, flags readiness armados en `G+1` junto con runtime fail-closed no coincidían con `ready_to_arm`, `ready_for_context` ni con la recuperación antigua. El resultado contradictorio era `state=blocked`, `reason=ready`; la contraseña nunca podía habilitar el botón porque la UI recibía `ok=false`.

## Corrección

2.36.11 introduce perfiles estructurados para motor, generaciones de flags y runtime, y un clasificador exhaustivo. Reconoce los estados estables y únicamente las postimágenes parciales producibles por el orden flags → API → config o por su rollback. Todo estado desconocido bloquea con un mismatch exacto; `blocked + ready` queda prohibido.

La recuperación y el nuevo armado permanecen separados por una petición y un GET nuevos. No se crean endpoints, migraciones o schedulers, no se activa Queue Engine y no se amplía el write-set.

## Seguridad y alcance

- Contraseña administrativa, sesión, CSRF, same-origin y advisory lock permanecen obligatorios.
- Cron V3/Shadow, ML writes, motor activo, schema/version drift, OAuth vencido, leases, runs, ejecuciones inciertas y autoridades extrañas bloquean.
- Construcción y QA: local solamente; Mercado Libre HTTP, Hostinger, producción y `storage/raw` no se usan.
