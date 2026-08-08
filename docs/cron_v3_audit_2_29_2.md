# Auditoría Cron V3 RC3

Documento fuente requerido por el contrato `release_2292_contract`.

## Alcance RC3

- Cron V3 usa `App\Core\Env` para resolver configuración desde variables de proceso y `config.env`.
- La autoridad de activación queda en `env_resolver`.
- V3 y ownership continúan apagados por defecto en la línea RC.
- El Doctor debe bloquear si faltan migraciones, tablas críticas o `MELI_CLIENT_ID`.
- El rate gate falla cerrado si falta la autoridad multidimensional.

## Reauditoría acumulada 2.35.1

- La fuente acumulativa conserva las migraciones 240–246 y sus contratos.
- El runtime 2.35.1 corrige drenaje FIFO y cierre de fuentes legacy sin reactivar V2.
- No se habilitan escrituras remotas.
- `CRON_V3_RATE_LIMIT` queda como fallback; la política persistida y los buckets conservan prioridad.

## Gates pendientes según ambiente

- Cuando exista DSN local real, deben ejecutarse los ciclos shadow/replay y concurrencia MariaDB/MySQL.
- En producción, después de instalar, se debe observar que los trabajos V3 completados cierren también su fuente legacy y reduzcan backlog visible.
