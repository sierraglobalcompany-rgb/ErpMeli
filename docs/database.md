# Base de datos

El esquema usa InnoDB, UTF-8 y claves externas. Todas las entidades remotas tienen unicidad compuesta por cuenta e identificador externo. Los hijos usan claves internas (`meli_order_id`) y mantienen por separado el ID remoto.

Las migraciones se registran en `schema_migrations`; no se modifica una migración aplicada. Para cambios futuros agregue un nuevo archivo numerado.

## Retención

- Datos normalizados: permanentes mientras exista obligación operativa/contable.
- Archivo raw comprimido: 365 días por defecto mediante `RAW_RETENTION_DAYS`.
- Auditoría y cierres aprobados: no se eliminan desde la aplicación.
- Los reportes aprobados son inmutables; cancelaciones posteriores crean `monthly_adjustments` para el periodo siguiente.

Los timestamps de API se guardan en UTC. El mes contable se construye en `America/Bogota` usando la fecha de pago aprobado.
