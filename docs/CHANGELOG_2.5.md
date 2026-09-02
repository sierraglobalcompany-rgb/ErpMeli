# ERP Meli 2.5 - Changelog técnico

- Normalizador `MeliDateTimeNormalizer` para fechas de Mercado Libre con offset.
- Servicio `MeliDateRangeService` para rangos locales semiabiertos y conversión UTC.
- Migración `029_sales_integrity_billing_safety_2_5.sql`.
- Auditoría de ventas reforzada: totales rápidos, comparación liviana de IDs, sospecha de timezone, diagnóstico de fechas.
- Reparación en cola: recalculo desde `raw_json` y descarga puntual de órdenes faltantes.
- Facturación mensual/por fechas guarda snapshot de cobertura/auditoría y bloquea aprobación/facturación insegura.
- Modo recomendado de sincronización histórica: diario.
