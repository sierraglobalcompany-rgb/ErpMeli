# Mapa de API Mercado Libre — ERP Meli 2.29.0

Última verificación: 2026-08-01.

## Reglas operativas

- Toda operación remota pasa por `MeliApiClient`, `MeliEndpointRegistry`,
  `ApiGuardService` y el presupuesto compartido.
- La integración permanece en solo lectura. `ML_WRITE_ENABLED=false` bloquea
  cualquier mutación.
- Los jobs son CLI. Ningún controlador web procesa colas ni transporta
  solicitudes a Mercado Libre.
- Los registros no contienen tokens, respuestas comerciales completas ni datos
  fiscales.
- HTTP 429 respeta `Retry-After` y aplica backoff con jitter.
- HTTP 403 se limita a la cuenta y capacidad afectadas.
- `unauthorized_scopes` y bloqueo de aplicación son señales críticas.
- Un 404 esperado no es señal de bloqueo.

## Contratos confirmados

| Operación | Endpoint de solo lectura | Uso en el ERP | Fuente verificada |
|---|---|---|---|
| Cuenta conectada | `GET /users/me` | Comprobar identidad de la cuenta | Documentación de usuarios |
| Buscar órdenes | `GET /orders/search` | Captura paginada y control de ventas | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_ar/mercadolider-tiendas-oficiales/gestiona-ventas) |
| Orden exacta | `GET /orders/{order_id}` | Webhook-First y recuperación exacta | Documentación de órdenes |
| Datos fiscales | `GET /orders/billing-info/{site_id}/{billing_info_id}` | Preparación fiscal cifrada | Documentación de Billing Info |
| Pack | `GET /packs/{pack_id}` | Relación de órdenes y envío | Documentación de órdenes |
| Billing agrupado | `GET /billing/integration/group/ML/order/details` | Cargos oficiales de una o varias órdenes de la misma venta | Contrato materializado de Billing |
| Billing por período | `GET /billing/integration/periods/key/{key}/group/ML/details` | Reparación histórica secuencial con `document_type`, `limit` y `from_id` | Buenas prácticas oficiales de reportes de facturación |
| Envío | `GET /shipments/{shipment_id}` | Seguimiento local | Documentación de Mercado Envíos |
| Preguntas | `GET /questions/search` | Búsqueda de preguntas | Documentación de preguntas |
| Pregunta exacta | `GET /questions/{question_id}` | Evento exacto; 404 cierra como ausencia esperada | Documentación de preguntas |
| Reclamos | `GET /post-purchase/v1/claims/search` | Búsqueda de reclamos | Documentación de posventa |
| Reclamo exacto | `GET /post-purchase/v1/claims/{claim_id}` | Snapshot local | Documentación de posventa |
| Publicaciones del vendedor | `GET /users/{user_id}/items/search` | Descubrir IDs | [Ítems y búsquedas](https://developers.mercadolibre.com.co/es_ar/items-y-busquedas) |
| Publicación exacta | `GET /items/{item_id}` | Snapshot, atributos e imágenes | Documentación de ítems |
| Descripción | `GET /items/{item_id}/description` | Snapshot local individual | Documentación de descripciones |

### Contrato técnico OAuth confirmado

| Operación | Endpoint | Uso en el ERP | Fuente verificada |
|---|---|---|---|
| Intercambiar o renovar token | `POST /oauth/token` | Alta y renovación OAuth; nunca modifica datos comerciales | [Autenticación y autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |

Este POST técnico es la única excepción al mapa de lecturas. Usa formulario en
el cuerpo, pasa por parada de emergencia, pausa manual, pacing, presupuesto,
circuitos y telemetría, y nunca registra `client_secret`, códigos ni tokens.

## Operaciones bloqueadas o fuera de alcance

- `GET /payments/{payment_id}` permanece bloqueado. Los pagos proceden de
  `orders[].payments[]`.
- `GET /orders/{order_id}/billing_info` es un contrato legado y no se utiliza.
- No se ejecutan `POST`, `PUT`, `PATCH` ni `DELETE` hacia publicaciones,
  precios, stock, imágenes, preguntas, reclamos, mensajes o promociones.
- Etiquetas de envío para Colombia permanecen sin habilitar hasta confirmar el
  contrato oficial aplicable.

## Identidad de venta y packs

- El número que la interfaz de Mercado Libre presenta como “Venta” se conserva
  como `pack_id` y es la referencia visible principal del ERP.
- Las órdenes de API continúan almacenadas individualmente y se relacionan
  mediante `meli_pack_orders`; no se inventa una orden principal.
- Buscar un `pack_id` o cualquiera de sus `order_id` abre la misma venta
  agrupada.
- `GET /packs/{pack_id}` se usa gradualmente para comprobar la lista esperada
  de órdenes y recuperar únicamente las hijas que falten.
- Billing agrupado se captura desde CLI. HTTP 206, documentos en procesamiento
  o conceptos desconocidos impiden aprobar el neto.
- Los cargos compartidos se contabilizan una sola vez por venta. Su reparto por
  producto es un cálculo analítico del ERP y se etiqueta como tal.

## Cobertura de órdenes y control de ventas

`GET /orders/search` no se presenta por sí solo como el universo fiscal
completo:

- la disponibilidad histórica es aproximada y se comunica como una ventana
  móvil, no como garantía de un año calendario;
- la búsqueda del vendedor tiene limitaciones conocidas sobre órdenes
  canceladas;
- el ERP diferencia búsqueda, webhook, consulta exacta y existencia solo local;
- las capturas usan rangos UTC semiabiertos y revalidan `date_created`;
- cada página conserva offset, límite, total informado, HTTP, campos ausentes y
  huella de IDs;
- HTTP 206, huecos, páginas repetidas, total cambiante o IDs faltantes impiden
  certificar la captura;
- un mes histórico requiere dos capturas independientes, separadas al menos 15
  minutos, con la misma huella;
- el mes actual siempre permanece provisional;
- los cierres son inmutables y una reapertura crea una revisión nueva.

## Rate limit y carga

El límite remoto se controla por cuenta, aplicación, endpoint, ventana y
`Retry-After`. La carga interna se mide aparte: duración, bytes, memoria,
fan-out y escrituras locales. Una descripción individual puede consumir una
sola llamada y ser más pesada que otras operaciones.

El máximo de 60 `order_ids` documentado para billing agrupado es el tamaño de
ese lote, no un límite universal de 60 solicitudes HTTP por minuto. El ERP no
debe deducir una tasa fija de ese valor: presupuesto, telemetría, respuesta
429 y `Retry-After` siempre prevalecen sobre el techo administrativo.

Fuentes:

- [Rate limit 429](https://developers.mercadolibre.com.co/es_ar/rate-limit-error-429)
- [Ítems y búsquedas](https://developers.mercadolibre.com.co/es_ar/items-y-busquedas)
- [Gestionar órdenes](https://developers.mercadolibre.com.co/es_ar/mercadolider-tiendas-oficiales/gestiona-ventas)
- [Buenas prácticas de reportes de facturación](https://developers.mercadolibre.com.ar/es_ar/api-docs-es/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion)

## Estado Cron V3

- `orders_search_page`, `order_exact`, `pack_exact`, `shipment_exact`,
  `questions_search_page`, `question_exact`, `claims_search_page`,
  `claim_exact`, `items_search_page`, `item_exact`,
  `catalog_description_exact`, `sale_billing_capture`, `oauth_refresh` y
  `module_logistics_exact` tienen handlers unitarios.
- Cada intento remoto permite una llamada lógica y un transporte físico como
  máximo. Las páginas descubren hasta 20 identificadores y solo encolan
  unidades exactas.
- `sales_fiscal_exact` permanece deshabilitado hasta que el servicio fiscal
  exponga claim y persistencia exactos por empresa, cuenta y trabajo.
- Billing histórico por período está confirmado, pero permanece sin productor
  automático hasta completar shadow y canario de las reparaciones exactas.
