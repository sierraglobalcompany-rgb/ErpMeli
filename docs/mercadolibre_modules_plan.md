# Plan de módulos Mercado Libre API — solo lectura

Fuente primaria: Mercado Libre Developers Colombia. Cada módulo debe validar endpoint, permisos, riesgos y frecuencia antes de implementarse.

| Módulo | Datos | Tabla destino | Frecuencia sugerida | Riesgo API | Estado |
|---|---|---|---|---|---|
| Publicaciones / Ítems | estado, precio, stock, SKU, categoría, variaciones, atributos, imágenes | `meli_items` y relacionadas | bajo demanda / incremental | medio | base 2.1 implementada |
| Órdenes | comprador, estado, ítems, pagos resumidos, envío, pack | `meli_orders`, `meli_order_items` | incremental | bajo/medio | funcional, mejorar detalle |
| Packs | relación pack → órdenes, envío asociado | `meli_packs`, `meli_pack_orders` | al sincronizar órdenes | bajo | funcional básico |
| Envíos | estado, subestado, logística, costos si están disponibles | `meli_shipments` | al sincronizar órdenes/webhooks | medio | funcional básico |
| Pagos | resumen incluido en orden, estados, importes | `meli_payments` | al sincronizar órdenes | medio | detalle `/payments/{id}` no confirmado |
| Facturación / datos fiscales | datos fiscales consultables | por definir | bajo demanda | medio/alto | pendiente validación |
| Notificaciones / Webhooks | órdenes, envíos, pagos y auditoría de eventos | `meli_webhook_events` | tiempo real + cola | bajo | funcional básico |
| Preguntas | lectura de preguntas | por definir | incremental | medio | futuro, sin responder |
| Reclamos / disputas | lectura de reclamos | por definir | incremental | medio/alto | futuro, sin actuar |
| Métricas de integración | llamadas, errores, 429, 403, 404, tiempos | `api_error_logs`, `meli_sync_runs` | continuo | bajo | 2.0.1 |
| Auditoría antibloqueo | health API, reintentos, backoff, cuentas en riesgo | `system_logs`, settings | continuo | bajo | 2.0.1 base |

## Reglas

- No hacer scraping ni leer HTML de Mercado Libre.
- Trabajar solo con API oficial documentada.
- Reducir frecuencia si aparece HTTP 429.
- Pausar y revisar si aparece HTTP 403.
- No insistir indefinidamente con 404 permanentes.
- No escribir en Mercado Libre con `ML_WRITE_ENABLED=false`.
