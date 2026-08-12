# ERP MELI 2.38.0 — Inventario / Bodega Real V1

## Alcance

La migración `295_inventory_warehouse_v1_2_38_0.sql` agrega bodegas, saldos,
movimientos append-only y revisiones. No crea un catálogo paralelo: usa
`internal_products`, `product_meli_links`, `meli_items`, `meli_order_items`,
`meli_accounts` y `companies`.

La cantidad de bodega consumida por una venta es:

`meli_order_items.quantity × product_meli_links.conversion_factor`

Todos los saldos y costos usan `DECIMAL`; no se usan `float` para cálculos de
negocio. Cada empresa debe seleccionar explícitamente una sola bodega activa
como predeterminada. Si no existe, la venta queda en revisión y no se descuenta.

## Integración con Queue V4 Clean

El único hook automático se ejecuta después de que `order_exact` ha persistido
la orden y sus líneas. Una orden `paid` produce una salida idempotente por orden,
cuenta y producto. Una orden `cancelled`/`canceled` revierte exactamente una vez
el movimiento original, usando su costo capturado. El pack nunca genera un
movimiento propio, por lo que dos órdenes de un pack se cuentan una vez cada una.

Un reembolso parcial, un producto sin vínculo exacto, una autoridad de vínculo
ambigua, la falta de bodega predeterminada o existencias insuficientes producen
una revisión; no se adivinan cantidades y el lote no queda parcialmente aplicado.
No se añade ninguna llamada HTTP a Mercado Libre.

## Operación manual

La pantalla `/inventory` permite a usuarios permanentes `admin` u `operador`:

- crear, activar e inactivar bodegas;
- seleccionar la bodega predeterminada;
- registrar saldo inicial, recepción, ajustes, reserva y liberación;
- consultar existencias y resolver/reintentar revisiones.

`/inventory/kardex` ofrece filtros por empresa, bodega, producto, cuenta, tipo y
fecha, con paginación. Todos los POST exigen sesión, rol, same-origin y CSRF, y
cada movimiento conserva actor, motivo, referencia e identidad idempotente.

## Despliegue y verificación

1. Hacer el respaldo normal del ERP y de la base de datos.
2. Instalar el paquete 2.38.0 mediante el actualizador o el overlay certificado.
3. Ejecutar la migración 295 mediante `actualizar.php`.
4. Confirmar `VERSION=2.38.0`, `app.version=2.38.0`, marker `2.38.0`, schema 295
   y pendientes 0.
5. Crear al menos una bodega por empresa y seleccionar explícitamente la
   predeterminada antes de esperar salidas automáticas.
6. Registrar saldos iniciales o recepciones y revisar el kardex.

El update nunca debe sobrescribir `config.env`, `.env`, `storage/**`,
`shared/storage/**`, pausas, sesiones, OAuth, respaldos ni
`shared/current-release.json`.

## Rollback

Antes de registrar movimientos productivos, el rollback de release puede volver
a 2.37.2 y restaurar la copia de base de datos. Después de crear movimientos, no
se deben borrar ni editar filas del kardex: detener Queue V4, conservar las tablas
295 como evidencia y corregir con movimientos compensatorios autorizados. Una
reversión técnica que requiera eliminar las tablas sólo es válida restaurando el
backup completo tomado antes de 2.38.0.

## Autoridades y exclusiones

- Schema: 295; no existe migración 296.
- Queue V4 continúa siendo el único motor operativo.
- `ML_WRITE_ENABLED=false` sigue prohibiendo mutaciones remotas.
- Este módulo no lee, enumera ni modifica `storage/raw`.
- No se importan estados de Queue V2/V3 ni se crea otro scheduler.
