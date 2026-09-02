# Guía técnica timezone 2.5

- Almacén operativo recomendado: UTC.
- Zona visual/agrupación: `app.timezone`, por defecto `America/Bogota`.
- Las fechas recibidas desde Mercado Libre se parsean respetando offset.
- Para días/meses se usan rangos semiabiertos: `[desde, hasta)`.
- No usar `23:59:59` para nuevos cierres operativos.
- Los campos `*_local_date` son la base recomendada para auditoría diaria.
