# ERP MELI 2.36.6 — informe del hotfix V4 readiness

## Resultado

2.36.6 incorpora un coordinador administrativo autenticado y reanudable para preparar y certificar readiness V4 sin crear el scheduler ni activar Queue Engine.

## Autoridades exigidas

- versión de archivos y `app.version` 2.36.6;
- schema 293 y retiro V3 ya certificado;
- scheduler V4 ausente, confirmado explícitamente por un administrador permanente;
- Queue Engine `disabled/idle/generation=0` al iniciar;
- API de lectura habilitable, automatización detenida y `ML_WRITE_ENABLED=false`;
- OAuth vigente para las tres cuentas;
- cero leases, runs activos y jobs inciertos;
- `historical_importer=false`;
- evidencia vigente de preflight, canary, convergencia, backup, capacidad y manifest para el mismo contexto.

## Write-set

La operación modifica exclusivamente configuración técnica, feature flags de Queue Core, estado de readiness, recibos/evidencias técnicas y checkpoints derivados de canaries de lectura. No crea tareas Hostinger, no activa Queue Engine, no ejecuta migraciones y no realiza escrituras remotas en Mercado Libre.

## Fallo y rollback

Ante cualquier fallo, el coordinador intenta volver a API detenida, Cron V4 deshabilitado, features deshabilitadas y engine `disabled/idle`. Las credenciales OAuth renovadas se conservan.

## Operación posterior

Después de revisar la certificación, la creación manual de exactamente un scheduler Hostinger y la activación CAS de Queue Engine pertenecen a un bloque posterior independiente.
