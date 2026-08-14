# ERP MELI 2.38.6 — Private Filesystem Authority / OAuth Durable Escrow

## Alcance

2.38.6 corrige exclusivamente la autoridad de ruta del escrow OAuth durable. No cambia el esquema 297, la política OAuth, FIFO, Sales Audit, el presupuesto HTTP ni Queue V4. La construcción y las pruebas son locales; producción y Hostinger permanecen intactos.

## Causa raíz

2.38.5 aceptaba `DOCUMENT_ROOT` como autoridad servida sin clasificarlo. En CLI, un valor vacío puede convertirse mediante `realpath('')` en el directorio de trabajo; un valor sobre-amplio como HOME también puede convertir falsamente toda la cuenta en árbol público. La rama de fallo confirmada es la comparación con `DOCUMENT_ROOT`; su valor productivo exacto no está probado.

## Corrección

- `App\Core\PrivatePathAuthority` centraliza rutas absolutas, límites `public_html`/`htdocs`/`httpdocs`/`wwwroot`, estados sanitizados de `DOCUMENT_ROOT`, validación previa a `mkdir`, symlinks y la identidad `device/inode/type` del padre.
- `QueueOAuthDurableRecoveryStore` delega en esa autoridad para probe, stage, load y clear; exige archivos regulares, no sustituye escrows pendientes y conserva rename/fsync y modos `0700/0600`.
- El self-check publica fuente e ID SHA-256 de la raíz privada sin mostrar HOME, DOCUMENT_ROOT ni la ruta privada.
- La prueba OAuth real demuestra 2xx conocido, escrow durable, fallo antes del CAS y recuperación posterior sin un segundo POST.

## Runbook posterior (documentación; no ejecutar durante build)

1. Instalar 2.38.6 con el Cron físico ausente.
2. Verificar versión 2.38.6, schema 297, pendientes 0 y `ML_WRITE_ENABLED=false`.
3. Ejecutar manualmente `jobs/queue_v4_runtime_self_check.php` y guardar `PRIVATE_ROOT_SOURCE`, `PRIVATE_ROOT_ID` y el veredicto.
4. Crear una única tarea temporal en Hostinger que ejecute ese mismo self-check una vez de forma natural.
5. Exigir igualdad entre los ID manual y scheduler. Si difieren, detenerse.
6. Sólo con paridad y veredicto PASS, convertir esa misma tarea —sin crear otra— a `queue_v4_clean.php --runtime=45 --max-jobs=5`.

No se registra ningún token, secreto ni ruta privada en el reporte o en el self-check.
