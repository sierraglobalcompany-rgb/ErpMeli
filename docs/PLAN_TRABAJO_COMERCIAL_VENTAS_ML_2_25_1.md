# Plan De Trabajo Auditado: Flujo Comercial De Ventas Mercado Libre

Version objetivo original: `2.25.1`
Version implementada: `2.25.2`
Version base auditada inicial: `2.25.0`; repo actual al implementar: `2.25.1`
Fecha: `2026-07-28`

## Resumen

El ERP Meli conserva el motor tecnico de auditoria, reparacion y cierre de ventas, pero mueve la experiencia principal a `Ventas de Mercado Libre`. El usuario comercial ahora debe poder seleccionar cuenta, seleccionar año y preparar la importacion anual sin entender paginacion, capturas, hashes, reparaciones o cierres.

`Control de ventas` se mantiene como modulo compatible y avanzado para evidencia, diferencias, datos fiscales y cierres.

Nota de implementacion: durante la auditoria se encontro una migracion `126_manual_campaign_scheduler_recovery_2_25_1.sql` ya existente. Para evitar conflicto de versiones, esta entrega usa `127_sales_import_commercial_flow_2_25_2.sql`.

## Objetivo De Producto

- Reducir operacion manual y soporte tecnico.
- Preparar el ERP para instalaciones comerciales en otras empresas.
- Mantener sincronizaciones idempotentes y compatibles con futuras versiones.
- Usar `date_created` real de Mercado Libre y fechas normalizadas locales para evitar discrepancias de servidor.
- No agregar endpoints no confirmados ni mutaciones remotas.

## Ruta Implementada

1. `POST /sales/import-year` queda como entrada comercial.
2. `SaleController::importYear()` valida rol, CSRF, cuenta y año, y delega en `SalesControlService::checkYear()`.
3. `SalesImportStatusService` actua como read model comercial de solo lectura.
4. `Ventas de Mercado Libre` muestra panel superior con cuenta, año, accion principal y avance anual.
5. `SalesControlService::recordAuditRun()` encola reparaciones seguras despues de auditorias primarias:
   - ventas faltantes con `SalesAuditExactRepairService::createFromRun()`;
   - fechas normalizadas con `OrderDateRepairService::createJob()`.
6. `Control de ventas` queda como `Evidencia avanzada de ventas`.

## Regla API

Endpoints confirmados:

- `GET /orders/search`
- `GET /orders/{order_id}`

La ventana historica se trata como aproximada. El ERP conserva `sales_control.remote_window_months` como configuracion de referencia y comunica al usuario:

> Mercado Libre puede limitar el historial disponible. El ERP importara y verificara todo lo disponible para esta cuenta.

## Compatibilidad

- No se eliminan tablas.
- No se renombran columnas.
- No se rompen rutas existentes de `/sales-control`.
- No se cambia la semantica base de auditorias exactas.
- La capa comercial es aditiva.
- Las reparaciones automaticas solo preparan jobs locales para CRON.
- El ejecutor web manual sigue limitado a pasos idempotentes.
- `ML_WRITE_ENABLED=false` sigue siendo compatible: el flujo no modifica Mercado Libre.

## Fallos Revisados Y Soluciones

| Fallo | Riesgo | Solucion aplicada |
|---|---|---|
| Ventas caen en otro dia | Fecha de servidor o UTC sin normalizacion local | Estado y auditoria se basan en fecha normalizada desde `date_created` |
| No aparecen ventas antiguas | Ventana historica ML limitada | Mensaje de historial aproximado y evidencia local |
| Usuario pulsa importar muchas veces | Jobs duplicados | `checkYear()` mantiene coordinacion anual e idempotencia existente |
| Auditoria encuentra faltantes | Webhook perdido o sync incompleta | Se encola reparacion exacta desde el run primario |
| Orden existe sin fecha normalizada | Datos viejos previos a migracion | Se encola reparacion de fechas desde `raw_json` |
| Otra cuenta o duplicado | Riesgo de cruce contable | No se autocorrige; queda para evidencia avanzada |
| Loop de reparacion | Venta remota no disponible o inconsistente | Solo se autocorrigen capturas primarias; verificaciones persistentes quedan para revision |
| API 429 o presupuesto | Sobrecarga o rate limit | CRON conserva backoff, `Retry-After` y presupuesto existente |
| UI tecnica | Baja vendibilidad | Ventas es entrada principal; Control de ventas pasa a evidencia avanzada |

## Criterios De Aceptacion

- El usuario puede preparar importacion anual desde `Ventas de Mercado Libre`.
- La ruta comercial no consulta Mercado Libre directamente.
- El estado anual se lee sin mutaciones.
- Las ventas faltantes se reparan automaticamente cuando el run primario es elegible.
- Las fechas se recalculan mediante job local cuando hay evidencia.
- Los casos peligrosos quedan para revision manual.
- Fiscalidad y cierres siguen disponibles como evidencia avanzada.
- Pruebas y lint pasan antes de empaquetar.
