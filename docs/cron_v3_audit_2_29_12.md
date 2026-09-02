# Auditoría Cron V3 2.29.12 — Corte de familias certificadas

## Diagnóstico

Producción 2.29.11 demuestra que Cron V3 no está roto: el canario remoto produjo
HTTP reales, recursos finalizados y cero errores/429/leases perdidos. El
problema operativo restante es que V3 sigue limitado a un subconjunto pequeño,
mientras V2 conserva familias certificadas y colas grandes.

No se aumentan límites de Mercado Libre. Subir rpm no resuelve un planificador
que no posee suficiente trabajo útil.

## Cambio aplicado

Se agrega `CronV3CertifiedCutoverService`, ejecutado desde los launchers CLI V3
activos. Si el canario base está sano y existe evidencia suficiente, aplica
ownership V3 a las familias certificadas:

- `financial_recalc` local;
- `pack_exact` remoto;
- `shipment_exact` remoto;
- `sale_billing_capture` remoto.

La migración 256 solo arma la autorización de corte y versionado. No ejecuta
handlers, no consulta Mercado Libre, no modifica datos comerciales, no pisa
configuración operativa existente y no cambia ownership directamente. El corte
ocurre en CLI, con `ML_WRITE_ENABLED=false`, canario base sano y evidencia HTTP
mínima.

Durante la certificación se corrigió además la migración 244 para que su SQL sea
portable entre MariaDB y MySQL. Las instalaciones MariaDB que ya hubieran
aplicado la 244 anterior se adoptan por contrato de esquema: tabla
`cron_v3_rate_buckets`, dimensiones de ritmo e índices esperados. No se ejecuta
SQL de la 244 si ya estaba aplicada.

## Resultado esperado

V2 deja de competir en familias certificadas y V3 puede materializar/procesar
trabajo remoto financiero exacto además de pack/shipment. V3 completo continúa
bloqueado; este no es un corte total.

## Rollback

El botón existente `Volver a V2` apaga `CRON_V3_ENABLED` y devuelve ownership
del canario base y de las familias certificadas ampliadas a V2. La evidencia V3
se conserva.
