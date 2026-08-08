# Auditoría Cron V3

Documento de auditoría fuente requerido por los contratos de release V3.

## Estado acumulado

- Cron V3 se instala apagado por defecto en la línea 2.29.x.
- La operación remota de V3 queda cercada por `company_id`, `meli_account_id`, `owner_token` y `lease_generation`.
- El transporte Mercado Libre real solo puede iniciarlo el carril remoto.
- El carril local no debe construir ni usar `MeliApiClient`.
- `ML_WRITE_ENABLED=false` sigue bloqueando mutaciones comerciales remotas.
- Los trabajos exactos se materializan como `cron_v3_work` con dedupe, scope y `input_version`.

## Reauditoría 2.35.1

La revisión de Cron V3 localizó el defecto operativo que impedía ver drenaje real en varias colas:

- V3 importaba trabajo legacy a `cron_v3_work`.
- El trabajo V3 podía completarse.
- La fuente legacy no siempre quedaba cerrada.
- El backlog visible podía permanecer alto o volver a aparecer aunque el HTTP y el recurso V3 hubieran terminado.

La corrección 2.35.1 agrega cierre seguro de fuentes legacy importadas, FIFO estable por `arrival_seq`, matriz de capacidades desde base de datos y registro de fallos de importación sin ocultarlos.

## Gates esperados

- ZIP y `SUBIR` idénticos.
- Sin `config.env`, `.env`, `PAUSE_*`, `docs`, `bin`, `tests`, `vendor`, `storage`, logs, dumps ni secretos en runtime.
- `composer test`, análisis estático, lint y compatibilidad PHP deben ejecutarse antes de entrega.
- MariaDB/MySQL real se considera gate de despliegue cuando exista DSN disponible.
