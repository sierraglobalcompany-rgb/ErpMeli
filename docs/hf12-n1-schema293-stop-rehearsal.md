# HF1.2 N-1 detenido contra schema293

Este rehearsal certifica exclusivamente el rollback de runtime 2.36.1 → HF1.2
en modo completamente detenido. No certifica activar V3, V4, API o Automation
con el runtime N-1.

## Autoridades del ensayo

- Runtime N-1: commit exacto
  `f91cd534d271b964db1ad9e682260475eca96820`, VERSION 2.35.1.
- Materialización: `git -c core.autocrlf=false archive` y validación de los 960
  blobs mediante su object id Git. En Windows, omitir
  `-c core.autocrlf=false` transforma finales de línea durante `git archive` y
  deja de ser blob-exact.
- MariaDB: clon local desechable del backup probado, migrado de 279 a 293 con
  las 14 migraciones aprobadas.
- Metadata de rollback: `app.version=2.35.1`, schema293 conservado.
- Frenos físicos: `PAUSE_MELI_API` y `PAUSE_ERP_AUTOMATION` presentes.
- Flags: `ML_WRITE_ENABLED=false`, V3 active/shadow false, V4 false y Queue Core
  false.
- Producción y transporte Mercado Libre: no utilizados.

## Smokes ejecutados

| Entrada HF1.2 real | Resultado esperado y observado |
| --- | --- |
| `jobs/process_sync_queue.php` | Exit 0, `manual_automation_stop`, `remote=false`, `database=false`. |
| `jobs/cron_v3_local.php` | Exit 0, `status=disabled`, `http_calls=0`. |
| `jobs/cron_v3_remote.php` | Exit 0, `status=disabled`, `http_calls=0`. |
| V3 local `--doctor --json` | Exit 2, `state=blocked`, `read_only=true`, `http_calls=0`, MariaDB soportada y tablas requeridas presentes. |
| V3 remote `--doctor --json` | Exit 2 con el mismo contrato fail-closed/read-only. |

Los doctors permanecen bloqueados porque el árbol N-1 no contiene todas las
fuentes históricas que su diagnóstico espera y porque la autoridad de entorno
mantiene V3 deshabilitado. Esto es un resultado seguro: pudieron inspeccionar
schema293 sin excepción SQL, no reclamaron trabajo y no intentaron transporte.

## Invariantes

- Tablas inspeccionadas antes y después: 292.
- Comparación por tabla: `COUNT(*)` y `CHECKSUM TABLE` idénticos.
- Delta MariaDB: 0.
- Claims nuevos: 0.
- HTTP Mercado Libre: 0.
- Migraciones presentes: 293 totales, 280–293 exactamente 14, ninguna >293.
- `app.version` final del rollback N-1: 2.35.1.

Conclusión: HF1.2 es compatible con schema293 únicamente en el modo detenido
certificado. Un rollback por fallo de pointer/smoke puede conservar el esquema
aditivo 293 y restaurar metadata/marcador 2.35.1. La restauración completa de
MariaDB sigue siendo obligatoria ante corrupción, pérdida de datos o cualquier
delta de invariantes.

## Test opt-in

`tests/hf12_n1_schema293_stop_rehearsal.php` rechaza producción por contrato:
requiere localhost, puerto distinto de 3306, ACK explícito, runtime f91
blob-exact, ambos marcadores y todos los flags apagados.

```text
ERP_2361_N1_ACK=DISPOSABLE_HF12_SCHEMA293_STOP_REHEARSAL
ERP_2361_N1_DSN=mysql:host=127.0.0.1;port=<puerto-local>;dbname=<clon>;charset=utf8mb4
ERP_2361_N1_RUNTIME=<runtime-f91-git-exact>
php tests/hf12_n1_schema293_stop_rehearsal.php
```
