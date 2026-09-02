---
title: Mercado Libre API - consulta humana e IA
generated_at: 2026-07-25T06:19:57.009Z
cutoff_date: 2026-07-25
source_root: https://developers.mercadolibre.com.co/es_co/guia-para-producto
page_count: 199
endpoint_count: 1700
extraction_mode: browser-internal-strict-inner-content
format: markdown-with-stable-query-blocks
---

# Mercado Libre API — archivo de consulta humana e IA

Este archivo es una vista compacta y navegable del índice auditable. Está pensado para dos usos a la vez: lectura humana rápida y recuperación precisa por IA/RAG.

## Cómo consultarlo

- Para humanos: empieza por el mapa de módulos, luego abre la sección del módulo y revisa páginas fuente + endpoints.
- Para IA: usa los IDs `MOD-*`, `PAGE-*` y `EP-*` como unidades de recuperación. Cada fila mantiene fuente oficial, módulo y ruta.
- Para auditoría: el snapshot con hashes sigue siendo `mercadolibre-api-snapshot.json`; este archivo es la capa cómoda de consulta.

## Resumen rápido

- Páginas documentadas: **199**
- Endpoints/rutas detectadas: **1700**
- Módulos: **15**
- Páginas bloqueadas o inciertas: **0**

## Mapa de módulos

| ID | Módulo | Páginas | Endpoints | Integraciones |
|---|---|---:|---:|---|
| MOD-01 | [Autenticación y aplicaciones](#autenticacion-y-aplicaciones) | 12 | 52 | Catálogo, Facturación, Mensajería, Mercado Ads, Mercado Envíos, Mercado Pago, Notificaciones, OAuth |
| MOD-02 | [FAQs, límites y soporte](#faqs-limites-y-soporte) | 2 | 1 | MCP, OAuth |
| MOD-03 | [General](#general) | 17 | 118 | Catálogo, Facturación, Mensajería, Mercado Envíos, Mercado Pago, Notificaciones, OAuth |
| MOD-04 | [Inmuebles](#inmuebles) | 20 | 84 | Catálogo, Mercado Envíos, Notificaciones, OAuth |
| MOD-05 | [Mensajería, reclamos y devoluciones](#mensajeria-reclamos-y-devoluciones) | 11 | 147 | Mensajería, Mercado Envíos, Mercado Pago, Notificaciones, OAuth |
| MOD-06 | [Mercado Ads](#mercado-ads) | 5 | 55 | Catálogo, Mercado Ads, Mercado Envíos, Mercado Pago, OAuth |
| MOD-07 | [Mercado Envíos](#mercado-envios) | 14 | 181 | Catálogo, Facturación, Mensajería, Mercado Envíos, Mercado Pago, Notificaciones, OAuth |
| MOD-08 | [Preguntas, ventas y postventa](#preguntas-ventas-y-postventa) | 19 | 182 | Catálogo, Facturación, Mensajería, Mercado Ads, Mercado Envíos, Mercado Pago, Notificaciones, OAuth |
| MOD-09 | [Productos, ítems y catálogo](#productos-items-y-catalogo) | 45 | 561 | Catálogo, Facturación, Mensajería, Mercado Ads, Mercado Envíos, Mercado Pago, Notificaciones, OAuth |
| MOD-10 | [Promociones y pricing](#promociones-y-pricing) | 11 | 109 | Mercado Pago, OAuth |
| MOD-11 | [Reputación, métricas y calidad](#reputacion-metricas-y-calidad) | 6 | 47 | Catálogo, Facturación, Mensajería, Mercado Ads, Mercado Envíos, Mercado Pago, Notificaciones, OAuth |
| MOD-12 | [Seguridad](#seguridad) | 5 | 3 | Mercado Pago, Notificaciones, OAuth |
| MOD-13 | [Servicios](#servicios) | 7 | 20 | Catálogo, Mercado Pago, OAuth |
| MOD-14 | [Usuarios y recursos cross](#usuarios-y-recursos-cross) | 16 | 88 | Catálogo, Facturación, Mercado Envíos, Mercado Pago, OAuth |
| MOD-15 | [Vehículos](#vehiculos) | 9 | 52 | Catálogo, Mercado Envíos, Notificaciones, OAuth |

## Consulta rápida por operación


### Autenticación

| Método | Ruta | Fuente |
|---|---|---|
| `GET` | `/orders?access_token=APP-1234567890` | [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) |
| `POST` | `/oauth/token` | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| `POST` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=$REDIRECT_URL` | [Obtención del Access Token](https://developers.mercadolibre.com.co/es_co/obtencion-del-access-token) |
| `POST` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=$YOUR_URL&code_challenge=$CODE_CHALLENGE&code_challenge_method=$CODE_METHOD` | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| `POST` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=https:/mercadolibre.com.ar` | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| `UNKNOWN` | `/orders?access_token=APP-1234567890` | [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) |
| `UNKNOWN` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=$REDIRECT_URL` | [Obtención del Access Token](https://developers.mercadolibre.com.co/es_co/obtencion-del-access-token) |
| `UNKNOWN` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=$YOUR_URL&code_challenge=$CODE_CHALLENGE&code_challenge_method=$CODE_METHOD` | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| `UNKNOWN` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=https:/mercadolibre.com.ar` | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| `UNKNOWN` | `/packs/20000154314645307/notes?access_token={accessToken` | [Crear nota informativa](https://developers.mercadolibre.com.co/es_co/notas-de-packs) |
| `UNKNOWN` | `/packs/20000154314645307/notes/681bace17c69893ae6558c52?access_token={accessToken` | [Crear nota informativa](https://developers.mercadolibre.com.co/es_co/notas-de-packs) |

### Productos / ítems

| Método | Ruta | Fuente |
|---|---|---|
| `GET` | `/catalog/charts/$SITE_ID/configurations/active_domains` | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `GET` | `/catalog/charts/domains/search` | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `GET` | `/catalog/charts/search` | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `GET` | `/catalog/charts/search?offset=1&limit=100` | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `GET` | `/items` | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| `GET` | `/items/$ITEM_ID` | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| `GET` | `/items/$ITEM_ID/available_downgrades` | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/$ITEM_ID/available_listing_types` | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/$ITEM_ID/available_upgrades` | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/$ITEM_ORIGINAL/migration_live_listing?` | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/items/$ITEM_ORIGINAL/user_product_listings/validate` | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/items/$TIEM_ID?attributes=stop_time` | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/$TIEM_ID/listing_type` | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/MLA123456/migration_live_listing?` | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/items/MLA12345678/user_product_listings/validate` | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/items/MLA1389403099?attributes=stop_time` | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/MLA1389403099/available_downgrades` | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/MLA1389403099/available_listing_types` | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |

### Precios / promociones

| Método | Ruta | Fuente |
|---|---|---|
| `GET` | `/users/{User_id}/classifieds_promotion_packs` | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/$USER_ID/classifieds_promotion_packs` | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/$USER_ID/classifieds_promotion_packs/$LISTING_TYPE&categoryId=$CATEGORY_ID` | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/135146148/classifieds_promotion_packs` | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/206946886/classifieds_promotion_packs/silver?categoryId=MLA1459` | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `UNKNOWN` | `/users/{User_id}/classifieds_promotion_packs` | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/categories/$CATEGORY_ID/classifieds_promotion_packs` | [Gestionar paquetes de inmuebles](https://developers.mercadolibre.com.co/es_co/gestionar-paquetes-de-inmuebles) |
| `GET` | `/users/$USER_ID/classifieds_promotion_packs?package_content=$PACKAGE_CONTENT&status=$STATUS` | [Gestionar paquetes de inmuebles](https://developers.mercadolibre.com.co/es_co/gestionar-paquetes-de-inmuebles) |
| `GET` | `/users/$USER_ID/classifieds_promotion_packs/$LISTING_TYPE?categoryId=$CATEGORY_ID` | [Gestionar paquetes de inmuebles](https://developers.mercadolibre.com.co/es_co/gestionar-paquetes-de-inmuebles) |
| `UNKNOWN` | `/users/$USER_ID/classifieds_promotion_packs?package_content=$PACKAGE_CONTENT&status=$STATUS` | [Gestionar paquetes de inmuebles](https://developers.mercadolibre.com.co/es_co/gestionar-paquetes-de-inmuebles) |
| `GET` | `/users/$USER_ID/shipping_options/free?dimensions=$DIMENSIONES&verbose=$VERBOSE&item_price=$ITEM_PRICE&listing_type_id=$LISTING_TYPE&mode=$MODE&condition=$CONDITION&logistic_type=$LOGISTIC_TYPE&free_shipping=$FREE_SHIPPING` | [Costos de envío](https://developers.mercadolibre.com.co/es_co/costos-de-envios) |
| `GET` | `/users/244878077/shipping_options/free?dimensions=9x17x22,462&verbose=true&item_price=300&listing_type_id=gold_pro&mode=me2&condition=new&logistic_type=drop_off&free_shipping=True` | [Costos de envío](https://developers.mercadolibre.com.co/es_co/costos-de-envios) |
| `GET` | `/items/{item_id}/sale_price` | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| `GET` | `/items/{item_id}/sale_price:` | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| `GET` | `/seller-promotions/offers/{offer_id}:` | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| `UNKNOWN` | `/items/{item_id}/sale_price` | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| `DELETE` | `/seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&promotion_id=$PROMOTION_ID&offer_id=$OFFER_ID` | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| `DELETE` | `/seller-promotions/items/MLA1387793467?promotion_type=PRICE_MATCHING_MELI_ALL&promotion_id=P-MLA2072013&offer_id=OFFER-MLA1387793467-1000000151&app_version=v2` | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |

### Stock

| Método | Ruta | Fuente |
|---|---|---|
| `GET` | `/sites/$SITE_ID/user-products-families/$FAMILY_ID` | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/sites/MLA/user-products-families/9871232123` | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/user-products-families/{family_id` | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/user-products-families/tasks/{task_id` | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/user-products/$USER_PRODUCT_ID` | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/user-products/$USER_PRODUCT_ID/items` | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/user-products/MLBU22012` | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/user-products/MLMU3691277914/items` | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `POST` | `/user-products-families/{family_id` | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `POST` | `/user-products/$USER_PRODUCT_ID/items` | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `POST` | `/user-products/MLMU3691277914/items` | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `UNKNOWN` | `/sites/$SITE_ID/user-products-families/$FAMILY_ID` | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `UNKNOWN` | `/user-products/$USER_PRODUCT_ID` | [User Products](https://developers.mercadolibre.com.co/es_co/user-products) |
| `GET` | `/customers/marketplace/sites/$SITE_ID/user-products/$USER_PRODUCT_ID/contracts/shippability/services` | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| `GET` | `/customers/marketplace/sites/MLA/user-products/MLAU1234567890/contracts/shippability/services?legacy_attributes=true` | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| `GET` | `/inventories/$INVENTORY_ID/stock/fulfillment` | [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) |
| `GET` | `/inventories/$INVENTORY_ID/stock/fulfillment?include_attributes=conditions` | [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) |
| `GET` | `/inventories/LCQI05831/stock/fulfillment` | [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) |

### Envíos

| Método | Ruta | Fuente |
|---|---|---|
| `GET` | `/shipping/seller/$SELLER_ID/working_day_middleend` | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| `GET` | `/shipping/seller/$SELLER_ID/working_day_middleend/optout` | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| `GET` | `/shipping/seller/$SELLER_ID/working_day_middleend/optout?date=2022-10-17` | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| `GET` | `/shipping/seller/$SELLER_ID/working_day_middleend/optout?date=AAAA-MM-DD` | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| `GET` | `/shipping/seller/12345678/working_day_middleend` | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| `PUT` | `/shipping/seller/$SELLER_ID/working_day_middleend` | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| `GET` | `/shipments/$SHIPMENT_ID/costs` | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| `DELETE` | `/flex/sites/$SITE_ID/items/$ITEM_ID/v2` | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| `DELETE` | `/flex/sites/MLB/items/MLB1493119403/v2` | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| `GET` | `/catalog_domains/$DOMAIN_ID/shipping_attributes` | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| `GET` | `/catalog_domains/MLA-AUTOMOTIVE_TIRES/shipping_attributes` | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| `GET` | `/catalog_domains/MLB-AUTOMOTIVE_TIRES/shipping_attributes` | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| `GET` | `/categories` | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| `GET` | `/categories/$CATEGORY_ID/shipping_preferences` | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| `GET` | `/categories/MCO7159/shipping_preferences` | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| `GET` | `/categories/MLA418448/shipping_preferences` | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| `GET` | `/categories/MLA45502/shipping_preferences` | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| `GET` | `/categories/MLB438794/shipping_preferences` | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |

### Órdenes / pagos

| Método | Ruta | Fuente |
|---|---|---|
| `GET` | `/orders` | [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) |
| `GET` | `/orders?access_token=APP-1234567890` | [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) |
| `GET` | `/orders/123` | [Seguridad de aplicaciones](https://developers.mercadolibre.com.co/es_co/seguridad-apps) |
| `GET` | `/users/{User_id}/classifieds_promotion_packs` | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/$USER_ID/classifieds_promotion_packs` | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/$USER_ID/classifieds_promotion_packs/$LISTING_TYPE&categoryId=$CATEGORY_ID` | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/135146148/classifieds_promotion_packs` | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/206946886/classifieds_promotion_packs/silver?categoryId=MLA1459` | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `UNKNOWN` | `/orders` | [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) |
| `UNKNOWN` | `/orders?access_token=APP-1234567890` | [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) |
| `UNKNOWN` | `/orders/123` | [Seguridad de aplicaciones](https://developers.mercadolibre.com.co/es_co/seguridad-apps) |
| `UNKNOWN` | `/users/{User_id}/classifieds_promotion_packs` | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/messages/action_guide/packs/$PACK_ID?tag=post_sale` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/$PACK_ID/caps_available?tag=post_sale` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/$PACK_ID/option?tag=post_sale` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/20000000000?tag=post_sale` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/200000000000/caps_available?tag=post_sale` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/2000000000000000/option` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |

### Mensajería / reclamos

| Método | Ruta | Fuente |
|---|---|---|
| `GET` | `/messages` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/$PACK_ID?tag=post_sale` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/$PACK_ID/caps_available?tag=post_sale` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/$PACK_ID/option?tag=post_sale` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/20000000000?tag=post_sale` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/200000000000/caps_available?tag=post_sale` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/2000000000000000/option` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/2000000000000000/option?tag=post_sale` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/2000000000000012?tag=post_sale` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `POST` | `/messages/action_guide/packs/$PACK_ID/option?tag=post_sale` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `POST` | `/messages/action_guide/packs/2000000000000000/option` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `POST` | `/messages/action_guide/packs/2000000000000000/option?tag=post_sale` | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/claims/$CLAIM_ID` | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| `GET` | `/claims/$CLAIM_ID/detail` | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| `GET` | `/claims/$CLAIM_ID/returns` | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| `GET` | `/claims/$CLAIM_ID/returns/attachments` | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| `GET` | `/claims/$CLAIMS` | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| `GET` | `/claims/$CLAIMS_ID/messages` | [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo) |

### Ads

| Método | Ruta | Fuente |
|---|---|---|
| `GET` | `/vis/leads/$LEAD_ID` | [Solicitud de visita](https://developers.mercadolibre.com.co/es_co/solicitud-de-visita) |
| `GET` | `/questions/$QUESTION_ID?api_version=4` | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `GET` | `/users/806525693/leads/buyers?scope=test-public` | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `GET` | `/vis/users/$USER_ID/leads/buyers` | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `GET` | `/vis/users/$USER_ID/leads/buyers?contact_types=question` | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `GET` | `/vis/users/$USER_ID/leads/buyers?contact_types=whatsapp` | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `GET` | `/vis/users/$USER_ID/leads/buyers?item_id=MLX1234` | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `GET` | `/vis/users/$USER_ID/leads/buyers?offset=$OFFSET&limit=$LIMIT&date_from=$DATE_FROM&date_to=$DATE_TO&contact_types=$CONTACT_TYPES&item_id=$ITEM_ID&buyer_ids=$BUYER_IDS` | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `GET` | `/vis/users/3052668868/leads/buyers?offset=0&limit=10&date_from=2026-01-15&date_to=2026-01-22&contact_types=credit,question,whatsapp&include_guest=true` | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `UNKNOWN` | `/users/806525693/leads/buyers?scope=test-public` | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ads/search?limit=1&offset=0&date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount` | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ads/search?limit=1&offset=0&date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount&aggregation_type=DAILY` | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ads/search?limit=1&offset=0&date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount&metrics_summary=true` | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/campaigns/search` | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/campaigns/search??limit=1&offset=0&date_from=2025-12-01&date_to=2025-12-30&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount&metrics_summary=true` | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/campaigns/search?limit=2&offset=0&date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount&aggregation_type=DAILY` | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/items/search` | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| `GET` | `/advertising/$ADVERTISER_SITE_ID/product_ads/ads/$ITEM_ID` | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |

## Módulos detallados

## Autenticación y aplicaciones

id: MOD-01

Resumen: 12 páginas fuente, 52 endpoints/rutas, integraciones detectadas: Catálogo, Facturación, Mensajería, Mercado Ads, Mercado Envíos, Mercado Pago, Notificaciones, OAuth.

### Páginas fuente

| ID | Página | Estado | Endpoints | Fechas visibles |
|---|---|---|---:|---|
| MOD-01-PAGE-001 | [Autenticación segura](https://developers.mercadolibre.com.co/es_co/autenticacion-segura) | `ok` | 0 | — |
| MOD-01-PAGE-002 | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) | `ok` | 7 | — |
| MOD-01-PAGE-003 | [Bloqueo de aplicaciones](https://developers.mercadolibre.com.co/es_co/bloqueo-de-aplicaciones) | `ok` | 0 | — |
| MOD-01-PAGE-004 | [Crea una aplicación en Mercado Libre](https://developers.mercadolibre.com.co/es_co/crea-una-aplicacion-en-mercado-libre-es) | `ok` | 0 | — |
| MOD-01-PAGE-005 | [Error 403](https://developers.mercadolibre.com.co/es_co/error-403) | `ok` | 0 | — |
| MOD-01-PAGE-006 | [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) | `ok` | 4 | 2026-01-15 |
| MOD-01-PAGE-007 | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) | `ok` | 8 | 2025-08-01, 2025-08-20 |
| MOD-01-PAGE-008 | [Gestionar IPs de una aplicación](https://developers.mercadolibre.com.co/es_co/gestionar-ips-de-una-aplicacion) | `ok` | 0 | — |
| MOD-01-PAGE-009 | [Obtención del Access Token](https://developers.mercadolibre.com.co/es_co/obtencion-del-access-token) | `ok` | 3 | — |
| MOD-01-PAGE-010 | [Permisos funcionales](https://developers.mercadolibre.com.co/es_co/permisos-funcionales) | `ok` | 0 | — |
| MOD-01-PAGE-011 | [Seguridad de aplicaciones](https://developers.mercadolibre.com.co/es_co/seguridad-apps) | `ok` | 2 | 2026-01-15 |
| MOD-01-PAGE-012 | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) | `ok` | 29 | — |

### Endpoints/rutas del módulo

| ID | Método | Ruta | Riesgo/área | Fuente |
|---|---|---|---|---|
| MOD-01-EP-0001 | `DELETE` | `/applications/{app_id}` | destructivo | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0002 | `DELETE` | `/users/{cust_Id` | destructivo | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0003 | `DELETE` | `/users/$USER_ID/applications/$APP_ID` | destructivo | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) |
| MOD-01-EP-0004 | `GET` | `/applications/{app_id}` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0005 | `GET` | `/applications/$APP_ID` | lectura/consulta | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) |
| MOD-01-EP-0006 | `GET` | `/applications/$APP_ID/grants` | lectura/consulta | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) |
| MOD-01-EP-0007 | `GET` | `/applications/$APPLICATION_ID` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0008 | `GET` | `/applications/12345` | lectura/consulta | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) |
| MOD-01-EP-0009 | `GET` | `/applications/3022782903258037` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0010 | `GET` | `/applications/v1/$APP_ID/consumed-applications?date_start=2025-08-01&date_end=2025-08-20` | lectura/consulta | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) |
| MOD-01-EP-0011 | `GET` | `/missed_feeds?app_id=$APP_ID` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0012 | `GET` | `/orders` | lectura/consulta | [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) |
| MOD-01-EP-0013 | `GET` | `/orders?access_token=APP-1234567890` | lectura/consulta | [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) |
| MOD-01-EP-0014 | `GET` | `/orders/123` | lectura/consulta | [Seguridad de aplicaciones](https://developers.mercadolibre.com.co/es_co/seguridad-apps) |
| MOD-01-EP-0015 | `GET` | `/users/{cust_Id` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0016 | `GET` | `/users/{User_id` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0017 | `GET` | `/users/{User_id}/classifieds_promotion_packs` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0018 | `GET` | `/users/$USER_ID` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0019 | `GET` | `/users/$USER_ID/accepted_payment_methods` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0020 | `GET` | `/users/$USER_ID/addresses` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0021 | `GET` | `/users/$USER_ID/applications` | lectura/consulta | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) |
| MOD-01-EP-0022 | `GET` | `/users/$USER_ID/applications/$APP_ID` | lectura/consulta | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) |
| MOD-01-EP-0023 | `GET` | `/users/$USER_ID/applications/$APPLICATION_ID` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0024 | `GET` | `/users/$USER_ID/available_listing_type/$LISTING_TYPE_ID?category_id=$CATEGORY_ID` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0025 | `GET` | `/users/$USER_ID/available_listing_types?category_id=$CATEGORY_ID` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0026 | `GET` | `/users/$USER_ID/brands` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0027 | `GET` | `/users/$USER_ID/classifieds_promotion_packs` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0028 | `GET` | `/users/$USER_ID/classifieds_promotion_packs/$LISTING_TYPE&categoryId=$CATEGORY_ID` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0029 | `GET` | `/users/12345678/brands` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0030 | `GET` | `/users/123456789` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0031 | `GET` | `/users/135146148/classifieds_promotion_packs` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0032 | `GET` | `/users/206946886/accepted_payment_methods` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0033 | `GET` | `/users/206946886/addresses` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0034 | `GET` | `/users/206946886/available_listing_type/gold_special?category_id=MLA6602` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0035 | `GET` | `/users/206946886/available_listing_types` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0036 | `GET` | `/users/206946886/classifieds_promotion_packs/silver?categoryId=MLA1459` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0037 | `GET` | `/users/26317316/applications` | lectura/consulta | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) |
| MOD-01-EP-0038 | `GET` | `/users/me` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0039 | `POST` | `/oauth/token` | mutación | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| MOD-01-EP-0040 | `POST` | `/users/me` | mutación | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| MOD-01-EP-0041 | `POST` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=$REDIRECT_URL` | mutación | [Obtención del Access Token](https://developers.mercadolibre.com.co/es_co/obtencion-del-access-token) |
| MOD-01-EP-0042 | `POST` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=$YOUR_URL&code_challenge=$CODE_CHALLENGE&code_challenge_method=$CODE_METHOD` | mutación | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| MOD-01-EP-0043 | `POST` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=https:/mercadolibre.com.ar` | mutación | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| MOD-01-EP-0044 | `PUT` | `/users/123456789` | mutación | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0045 | `UNKNOWN` | `/orders` | lectura/consulta | [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) |
| MOD-01-EP-0046 | `UNKNOWN` | `/orders?access_token=APP-1234567890` | lectura/consulta | [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) |
| MOD-01-EP-0047 | `UNKNOWN` | `/orders/123` | lectura/consulta | [Seguridad de aplicaciones](https://developers.mercadolibre.com.co/es_co/seguridad-apps) |
| MOD-01-EP-0048 | `UNKNOWN` | `/users/{User_id}/classifieds_promotion_packs` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| MOD-01-EP-0049 | `UNKNOWN` | `/users/me` | lectura/consulta | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| MOD-01-EP-0050 | `UNKNOWN` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=$REDIRECT_URL` | autenticación | [Obtención del Access Token](https://developers.mercadolibre.com.co/es_co/obtencion-del-access-token) |
| MOD-01-EP-0051 | `UNKNOWN` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=$YOUR_URL&code_challenge=$CODE_CHALLENGE&code_challenge_method=$CODE_METHOD` | autenticación | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| MOD-01-EP-0052 | `UNKNOWN` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=https:/mercadolibre.com.ar` | autenticación | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |

### Señales de autenticación/permisos

- [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion): `Authorization`, `X-12345678`, `authorization`, `accept`, `content-type`, `x-www-form-urlencoded`, `Bearer`, `redirect_uri`, `client_id`, `client_secret`, `access_token`, `scope`, `read`, `write`
- [Crea una aplicación en Mercado Libre](https://developers.mercadolibre.com.co/es_co/crea-una-aplicacion-en-mercado-libre-es): `scopes`, `scope`
- [Error 403](https://developers.mercadolibre.com.co/es_co/error-403): `scopes`
- [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens): `Authorization`, `refresh_token`, `access_token`, `Bearer`, `client_secret`
- [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones): `Authorization`, `Bearer`, `ACCESS_TOKEN`, `scopes`, `read`, `write`
- [Obtención del Access Token](https://developers.mercadolibre.com.co/es_co/obtencion-del-access-token): `authorization`, `accept`, `content-type`, `x-www-form-urlencoded`, `client_id`, `redirect_uri`, `client_secret`, `access_token`, `Bearer`, `scope`, `refresh_token`
- [Seguridad de aplicaciones](https://developers.mercadolibre.com.co/es_co/seguridad-apps): `access_token`
- [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones): `Authorization`, `Content-Type`, `Content-type`, `Bearer`, `ACCESS_TOKEN`, `scopes`

## FAQs, límites y soporte

id: MOD-02

Resumen: 2 páginas fuente, 1 endpoints/rutas, integraciones detectadas: MCP, OAuth.

### Páginas fuente

| ID | Página | Estado | Endpoints | Fechas visibles |
|---|---|---|---:|---|
| MOD-02-PAGE-001 | [MCP Server de Mercado Libre](https://developers.mercadolibre.com.co/es_co/mcp-server) | `ok` | 0 | — |
| MOD-02-PAGE-002 | [Rate limit / Error 429 y pedidos de aumento de RL](https://developers.mercadolibre.com.co/es_co/rate-limit-error-429) | `ok` | 1 | — |

### Endpoints/rutas del módulo

| ID | Método | Ruta | Riesgo/área | Fuente |
|---|---|---|---|---|
| MOD-02-EP-0001 | `UNKNOWN` | `/visits` | lectura/consulta | [Rate limit / Error 429 y pedidos de aumento de RL](https://developers.mercadolibre.com.co/es_co/rate-limit-error-429) |

### Señales de autenticación/permisos

_Sin señales explícitas de autenticación o headers en las páginas de este módulo._

## General

id: MOD-03

Resumen: 17 páginas fuente, 118 endpoints/rutas, integraciones detectadas: Catálogo, Facturación, Mensajería, Mercado Envíos, Mercado Pago, Notificaciones, OAuth.

### Páginas fuente

| ID | Página | Estado | Endpoints | Fechas visibles |
|---|---|---|---:|---|
| MOD-03-PAGE-001 | [¿Qué es Brand Protection Program?](https://developers.mercadolibre.com.co/es_co/que-es-brand-protection-program) | `ok` | 0 | — |
| MOD-03-PAGE-002 | [Buenas prácticas para uso de la plataforma](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-uso-de-la-plataforma) | `ok` | 0 | — |
| MOD-03-PAGE-003 | [Configuración o requisitos previos](https://developers.mercadolibre.com.co/es_co/configuracion-o-requisitos-previos) | `ok` | 1 | — |
| MOD-03-PAGE-004 | [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno) | `ok` | 7 | — |
| MOD-03-PAGE-005 | [Contratación de paquetes de publicación](https://developers.mercadolibre.com.co/es_co/contratacion-de-paquetes-de-publicacion) | `ok` | 0 | 05/11/2025 |
| MOD-03-PAGE-006 | [Developer Partner Program](https://developers.mercadolibre.com.co/es_co/developer-partner-program) | `ok` | 0 | — |
| MOD-03-PAGE-007 | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) | `ok` | 6 | 2022-09-26, 2022-09-27, 2022-10-17 |
| MOD-03-PAGE-008 | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) | `ok` | 9 | 2023-09-01 |
| MOD-03-PAGE-009 | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) | `ok` | 10 | — |
| MOD-03-PAGE-010 | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) | `ok` | 14 | 2022-10-25, 2024-10-07, 2025-05-01 |
| MOD-03-PAGE-011 | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) | `ok` | 12 | 20 de enero de 2025 |
| MOD-03-PAGE-012 | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) | `ok` | 23 | — |
| MOD-03-PAGE-013 | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) | `ok` | 13 | — |
| MOD-03-PAGE-014 | [Realiza pruebas](https://developers.mercadolibre.com.co/es_co/realiza-pruebas) | `ok` | 3 | — |
| MOD-03-PAGE-015 | [Solicitud de visita](https://developers.mercadolibre.com.co/es_co/solicitud-de-visita) | `ok` | 1 | 06/11/2025 |
| MOD-03-PAGE-016 | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) | `ok` | 24 | — |
| MOD-03-PAGE-017 | [User Products](https://developers.mercadolibre.com.co/es_co/user-products) | `ok` | 8 | — |

### Endpoints/rutas del módulo

| ID | Método | Ruta | Riesgo/área | Fuente |
|---|---|---|---|---|
| MOD-03-EP-0001 | `GET` | `/catalog/charts/$SITE_ID/configurations/active_domains` | lectura/consulta | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| MOD-03-EP-0002 | `GET` | `/catalog/charts/domains/search` | lectura/consulta | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| MOD-03-EP-0003 | `GET` | `/catalog/charts/search` | lectura/consulta | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| MOD-03-EP-0004 | `GET` | `/catalog/charts/search?offset=1&limit=100` | lectura/consulta | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| MOD-03-EP-0005 | `GET` | `/currencies` | lectura/consulta | [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno) |
| MOD-03-EP-0006 | `GET` | `/currencies?attributes=id` | lectura/consulta | [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno) |
| MOD-03-EP-0007 | `GET` | `/currencies/ARS` | lectura/consulta | [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno) |
| MOD-03-EP-0008 | `GET` | `/currencies/ARS?callback=foo` | lectura/consulta | [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno) |
| MOD-03-EP-0009 | `GET` | `/domains/$DOMAIN_ID/technical_specs` | lectura/consulta | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| MOD-03-EP-0010 | `GET` | `/domains/$DOMAIN_ID/technical_specs?section=grids` | lectura/consulta | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| MOD-03-EP-0011 | `GET` | `/domains/MLA-SNEAKERS/technical_specs` | lectura/consulta | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| MOD-03-EP-0012 | `GET` | `/domains/MLA-SNEAKERS/technical_specs?section=grids` | lectura/consulta | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| MOD-03-EP-0013 | `GET` | `/items` | lectura/consulta | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| MOD-03-EP-0014 | `GET` | `/items/$ITEM_ID` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| MOD-03-EP-0015 | `GET` | `/items/$ITEM_ID/available_downgrades` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0016 | `GET` | `/items/$ITEM_ID/available_listing_types` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0017 | `GET` | `/items/$ITEM_ID/available_upgrades` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0018 | `GET` | `/items/$ITEM_ORIGINAL/migration_live_listing?` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0019 | `GET` | `/items/$ITEM_ORIGINAL/user_product_listings/validate` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0020 | `GET` | `/items/$TIEM_ID?attributes=stop_time` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0021 | `GET` | `/items/$TIEM_ID/listing_type` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0022 | `GET` | `/items/MLA123456/migration_live_listing?` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0023 | `GET` | `/items/MLA12345678/user_product_listings/validate` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0024 | `GET` | `/items/MLA1389403099?attributes=stop_time` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0025 | `GET` | `/items/MLA1389403099/available_downgrades` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0026 | `GET` | `/items/MLA1389403099/available_listing_types` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0027 | `GET` | `/items/MLA1389403099/available_upgrades` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0028 | `GET` | `/items/MLA1389403099/listing_type` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0029 | `GET` | `/items/MLC1234567890` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| MOD-03-EP-0030 | `GET` | `/messages` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| MOD-03-EP-0031 | `GET` | `/messages/action_guide/packs/$PACK_ID?tag=post_sale` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| MOD-03-EP-0032 | `GET` | `/messages/action_guide/packs/$PACK_ID/caps_available?tag=post_sale` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| MOD-03-EP-0033 | `GET` | `/messages/action_guide/packs/$PACK_ID/option?tag=post_sale` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| MOD-03-EP-0034 | `GET` | `/messages/action_guide/packs/20000000000?tag=post_sale` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| MOD-03-EP-0035 | `GET` | `/messages/action_guide/packs/200000000000/caps_available?tag=post_sale` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| MOD-03-EP-0036 | `GET` | `/messages/action_guide/packs/2000000000000000/option` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| MOD-03-EP-0037 | `GET` | `/messages/action_guide/packs/2000000000000000/option?tag=post_sale` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| MOD-03-EP-0038 | `GET` | `/messages/action_guide/packs/2000000000000012?tag=post_sale` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| MOD-03-EP-0039 | `GET` | `/moderations/infractions/$USER_ID?date_created_since=YYYY-MM-DD&limit=2` | lectura/consulta | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| MOD-03-EP-0040 | `GET` | `/moderations/infractions/288230000?date_created_since=2023-09-01&limit=2` | lectura/consulta | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| MOD-03-EP-0041 | `GET` | `/moderations/last_moderation` | lectura/consulta | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| MOD-03-EP-0042 | `GET` | `/moderations/last_moderation/$ITEM_ID-ITM` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| MOD-03-EP-0043 | `GET` | `/moderations/last_moderation/$MODERATION_REFERENCE_ID` | lectura/consulta | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| MOD-03-EP-0044 | `GET` | `/moderations/last_moderation/MLA123444123-ITM` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| MOD-03-EP-0045 | `GET` | `/moderations/last_moderation/MLA926647862-ITM` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| MOD-03-EP-0046 | `GET` | `/moderations/pppi/case/$DENOUNCE_ID` | lectura/consulta | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| MOD-03-EP-0047 | `GET` | `/moderations/pppi/case/123` | lectura/consulta | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| MOD-03-EP-0048 | `GET` | `/moderations/pppi/denounces/$SITE_ID/ITM/options` | lectura/consulta | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| MOD-03-EP-0049 | `GET` | `/moderations/pppi/denounces/items/$ITEM_ID` | lectura/consulta | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| MOD-03-EP-0050 | `GET` | `/moderations/pppi/denounces/items/MLA123` | lectura/consulta | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| MOD-03-EP-0051 | `GET` | `/moderations/pppi/denounces/MLA/ITM/options` | lectura/consulta | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| MOD-03-EP-0052 | `GET` | `/shipping/seller/$SELLER_ID/working_day_middleend` | envíos | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| MOD-03-EP-0053 | `GET` | `/shipping/seller/$SELLER_ID/working_day_middleend/optout` | envíos | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| MOD-03-EP-0054 | `GET` | `/shipping/seller/$SELLER_ID/working_day_middleend/optout?date=2022-10-17` | envíos | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| MOD-03-EP-0055 | `GET` | `/shipping/seller/$SELLER_ID/working_day_middleend/optout?date=AAAA-MM-DD` | envíos | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| MOD-03-EP-0056 | `GET` | `/shipping/seller/12345678/working_day_middleend` | envíos | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| MOD-03-EP-0057 | `GET` | `/sites/$SITE_ID/listing_exposures` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0058 | `GET` | `/sites/$SITE_ID/listing_exposures/$EXPOSURE_LEVEL` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0059 | `GET` | `/sites/$SITE_ID/listing_types` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0060 | `GET` | `/sites/$SITE_ID/listing_types/$LISTING_TYPE_ID` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0061 | `GET` | `/sites/$SITE_ID/user-products-families/$FAMILY_ID` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0062 | `GET` | `/sites/MLA/listing_exposures` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0063 | `GET` | `/sites/MLA/listing_exposures/high` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0064 | `GET` | `/sites/MLA/listing_types` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0065 | `GET` | `/sites/MLA/listing_types/gold_special` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0066 | `GET` | `/sites/MLA/search?q=ipod` | lectura/consulta | [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno) |
| MOD-03-EP-0067 | `GET` | `/sites/MLA/user-products-families/9871232123` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0068 | `GET` | `/sites/MLM/items/user_product_listings` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0069 | `GET` | `/user-products-families/{family_id` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0070 | `GET` | `/user-products-families/tasks/{task_id` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0071 | `GET` | `/user-products/$USER_PRODUCT_ID` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0072 | `GET` | `/user-products/$USER_PRODUCT_ID/items` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0073 | `GET` | `/user-products/MLBU22012` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0074 | `GET` | `/user-products/MLMU3691277914/items` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0075 | `GET` | `/users/$SELLER_ID/items/search?user_product_id=$USER_PRODUCT_ID` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0076 | `GET` | `/users/$SELLER_ID/items/search?user_product_id=MLAU1234,MLAU12345` | lectura/consulta | [User Products](https://developers.mercadolibre.com.co/es_co/user-products) |
| MOD-03-EP-0077 | `GET` | `/users/$USER_ID/available_listing_type/free?category_id=$CATEGORY_ID` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0078 | `GET` | `/users/$USER_ID/items/search?status=pending` | lectura/consulta | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| MOD-03-EP-0079 | `GET` | `/users/0123456789/items/search?tags=moderation_penalty&status=paused` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| MOD-03-EP-0080 | `GET` | `/users/1234/available_listing_types?category_id=MLA1055` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0081 | `GET` | `/users/1234/items/search?user_product_id=MLBU206642488` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0082 | `GET` | `/users/123456/items/search?status=pending` | lectura/consulta | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| MOD-03-EP-0083 | `GET` | `/vis/leads/$LEAD_ID` | lectura/consulta | [Solicitud de visita](https://developers.mercadolibre.com.co/es_co/solicitud-de-visita) |
| MOD-03-EP-0084 | `POST` | `/catalog/charts/domains/search` | mutación | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| MOD-03-EP-0085 | `POST` | `/catalog/charts/search` | mutación | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| MOD-03-EP-0086 | `POST` | `/catalog/charts/search?offset=1&limit=100` | mutación | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| MOD-03-EP-0087 | `POST` | `/domains/$DOMAIN_ID/technical_specs?section=grids` | mutación | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| MOD-03-EP-0088 | `POST` | `/domains/MLA-SNEAKERS/technical_specs?section=grids` | mutación | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| MOD-03-EP-0089 | `POST` | `/items` | mutación | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0090 | `POST` | `/items/$TIEM_ID/listing_type` | mutación | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0091 | `POST` | `/items/MLA1389403099/listing_type` | mutación | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| MOD-03-EP-0092 | `POST` | `/messages/action_guide/packs/$PACK_ID/option?tag=post_sale` | mutación | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| MOD-03-EP-0093 | `POST` | `/messages/action_guide/packs/2000000000000000/option` | mutación | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| MOD-03-EP-0094 | `POST` | `/messages/action_guide/packs/2000000000000000/option?tag=post_sale` | mutación | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| MOD-03-EP-0095 | `POST` | `/moderations/pppi/case/$DENOUNCE_ID` | mutación | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| MOD-03-EP-0096 | `POST` | `/moderations/pppi/denounces/items/$ITEM_ID` | mutación | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| MOD-03-EP-0097 | `POST` | `/moderations/pppi/denounces/items/MLA123` | mutación | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| MOD-03-EP-0098 | `POST` | `/sites/MLM/items/user_product_listings` | mutación | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0099 | `POST` | `/user-products-families/{family_id` | mutación | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0100 | `POST` | `/user-products/$USER_PRODUCT_ID/items` | mutación | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0101 | `POST` | `/user-products/MLMU3691277914/items` | mutación | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0102 | `POST` | `/users/test_user` | mutación | [Realiza pruebas](https://developers.mercadolibre.com.co/es_co/realiza-pruebas) |
| MOD-03-EP-0103 | `PUT` | `/items/$ITEM_ID` | mutación | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| MOD-03-EP-0104 | `PUT` | `/shipping/seller/$SELLER_ID/working_day_middleend` | mutación | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| MOD-03-EP-0105 | `UNKNOWN` | `/categories` | lectura/consulta | [User Products](https://developers.mercadolibre.com.co/es_co/user-products) |
| MOD-03-EP-0106 | `UNKNOWN` | `/currencies` | lectura/consulta | [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno) |
| MOD-03-EP-0107 | `UNKNOWN` | `/items` | lectura/consulta | [User Products](https://developers.mercadolibre.com.co/es_co/user-products) |
| MOD-03-EP-0108 | `UNKNOWN` | `/items/MLC1234567890` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| MOD-03-EP-0109 | `UNKNOWN` | `/moderations/last_moderation/$ITEM_ID-ITM` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| MOD-03-EP-0110 | `UNKNOWN` | `/moderations/last_moderation/$MODERATION_REFERENCE_ID` | lectura/consulta | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| MOD-03-EP-0111 | `UNKNOWN` | `/moderations/last_moderation/MLA123444123-ITM` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| MOD-03-EP-0112 | `UNKNOWN` | `/moderations/last_moderation/MLA926647862-ITM` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| MOD-03-EP-0113 | `UNKNOWN` | `/sites/$SITE_ID/user-products-families/$FAMILY_ID` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| MOD-03-EP-0114 | `UNKNOWN` | `/sites/MLA/search?q=ipod` | lectura/consulta | [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno) |
| MOD-03-EP-0115 | `UNKNOWN` | `/user-products/$USER_PRODUCT_ID` | lectura/consulta | [User Products](https://developers.mercadolibre.com.co/es_co/user-products) |
| MOD-03-EP-0116 | `UNKNOWN` | `/users` | lectura/consulta | [User Products](https://developers.mercadolibre.com.co/es_co/user-products) |
| MOD-03-EP-0117 | `UNKNOWN` | `/users/$SELLER_ID/items/search?user_product_id=$USER_PRODUCT_ID` | lectura/consulta | [User Products](https://developers.mercadolibre.com.co/es_co/user-products) |
| MOD-03-EP-0118 | `UNKNOWN` | `/users/$SELLER_ID/items/search?user_product_id=MLAU1234` | lectura/consulta | [User Products](https://developers.mercadolibre.com.co/es_co/user-products) |

### Señales de autenticación/permisos

- [Configuración o requisitos previos](https://developers.mercadolibre.com.co/es_co/configuracion-o-requisitos-previos): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno): `Content-Type`
- [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`, `read`
- [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es): `Authorization`, `Content-Type`, `x-caller-id`, `Bearer`, `ACCESS_TOKEN`
- [Realiza pruebas](https://developers.mercadolibre.com.co/es_co/realiza-pruebas): `Authorization`, `X-12345678`, `Content-type`, `Bearer`, `ACCESS_TOKEN`
- [Solicitud de visita](https://developers.mercadolibre.com.co/es_co/solicitud-de-visita): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos): `Authorization`, `Content-Type`, `Accept`, `Bearer`, `ACCESS_TOKEN`

## Inmuebles

id: MOD-04

Resumen: 20 páginas fuente, 84 endpoints/rutas, integraciones detectadas: Catálogo, Mercado Envíos, Notificaciones, OAuth.

### Páginas fuente

| ID | Página | Estado | Endpoints | Fechas visibles |
|---|---|---|---:|---|
| MOD-04-PAGE-001 | [Actualiza las variaciones de tu inmueble](https://developers.mercadolibre.com.co/es_co/actualizacion-variacion-inmuebles) | `ok` | 6 | 06/11/2025 |
| MOD-04-PAGE-002 | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos-inmuebles) | `ok` | 4 | 20 de enero de 2026, 05/11/2025 |
| MOD-04-PAGE-003 | [Calidad de las Publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-las-publicaciones-inmuebles) | `ok` | 3 | 06/11/2025 |
| MOD-04-PAGE-004 | [Categorías](https://developers.mercadolibre.com.co/es_co/categorias-inmuebles) | `ok` | 5 | 06/11/2025 |
| MOD-04-PAGE-005 | [Categorías y Atributos](https://developers.mercadolibre.com.co/es_co/categorias-atributos-inmuebles) | `ok` | 0 | — |
| MOD-04-PAGE-006 | [Ciclo de vida de las publicaciones de Inmuebles](https://developers.mercadolibre.com.co/es_co/ciclo-de-vida-de-las-publicaciones-de-inmuebles) | `ok` | 0 | 1 de enero de 2022, 28 de septiembre de 2022, 06/11/2025 |
| MOD-04-PAGE-007 | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) | `ok` | 11 | 31 de julio de 2026, 06/11/2025 |
| MOD-04-PAGE-008 | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) | `ok` | 16 | 2021-01-01, 2021-02-01, 06/11/2025 |
| MOD-04-PAGE-009 | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) | `ok` | 15 | 2022-03-30 |
| MOD-04-PAGE-010 | [Gestionar paquetes de inmuebles](https://developers.mercadolibre.com.co/es_co/gestionar-paquetes-de-inmuebles) | `ok` | 5 | 20 de enero de 2026, 05/11/2025 |
| MOD-04-PAGE-011 | [Glosario](https://developers.mercadolibre.com.co/es_co/glosario-inmuebles) | `ok` | 0 | — |
| MOD-04-PAGE-012 | [Guía de Integración de Inmuebles](https://developers.mercadolibre.com.co/es_co/introduccion-guia-de-inmuebles) | `ok` | 0 | — |
| MOD-04-PAGE-013 | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) | `ok` | 10 | 2025-04-18, 2025-06-18, 2026-01-15, 2026-01-22, 2024-05-14, 2024-05-24, 06/11/2025, 26/01/2026 |
| MOD-04-PAGE-014 | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) | `ok` | 14 | 05/11/2025 |
| MOD-04-PAGE-015 | [Paquetes y permisos para proyectos, desarrollos o emprendimientos inmobiliarios](https://developers.mercadolibre.com.co/es_co/paquetes-y-permisos-para-proyectos-desarrollos-o-emprendimientos-inmobiliarios) | `ok` | 0 | 06/11/2025 |
| MOD-04-PAGE-016 | [Pasos Rápidos para Publicar un Inmueble de Prueba](https://developers.mercadolibre.com.co/es_co/pasos-rapidos-para-publicar-un-inmueble-de-prueba) | `ok` | 5 | — |
| MOD-04-PAGE-017 | [Primeros pasos: Publicación de Inmuebles en la API de MercadoLibre](https://developers.mercadolibre.com.co/es_co/primeros-pasos-inmuebles) | `ok` | 0 | — |
| MOD-04-PAGE-018 | [Publica Inmuebles](https://developers.mercadolibre.com.co/es_co/publica-inmueble) | `ok` | 1 | 23 de febrero de 2026, 06/11/2025 |
| MOD-04-PAGE-019 | [Publicaciones de tiendas oficiales para inmuebles](https://developers.mercadolibre.com.co/es_co/publicaciones-de-tiendas-oficiales-para-inmuebles) | `ok` | 1 | 06/11/2025 |
| MOD-04-PAGE-020 | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones-para-inmuebles) | `ok` | 6 | 06/11/2025 |

### Endpoints/rutas del módulo

| ID | Método | Ruta | Riesgo/área | Fuente |
|---|---|---|---|---|
| MOD-04-EP-0001 | `DELETE` | `/items/$ITEM_ID/address_line_by_reference` | destructivo | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| MOD-04-EP-0002 | `DELETE` | `/items/$ITEM_ID/variations/$VARIATION_ID` | destructivo | [Actualiza las variaciones de tu inmueble](https://developers.mercadolibre.com.co/es_co/actualizacion-variacion-inmuebles) |
| MOD-04-EP-0003 | `GET` | `/categories/${ID}` | lectura/consulta | [Categorías](https://developers.mercadolibre.com.co/es_co/categorias-inmuebles) |
| MOD-04-EP-0004 | `GET` | `/categories/$CATEGORY_ID/attributes` | lectura/consulta | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) |
| MOD-04-EP-0005 | `GET` | `/categories/$CATEGORY_ID/classifieds_promotion_packs` | lectura/consulta | [Gestionar paquetes de inmuebles](https://developers.mercadolibre.com.co/es_co/gestionar-paquetes-de-inmuebles) |
| MOD-04-EP-0006 | `GET` | `/categories/MLA1459` | lectura/consulta | [Categorías](https://developers.mercadolibre.com.co/es_co/categorias-inmuebles) |
| MOD-04-EP-0007 | `GET` | `/categories/MLA1466` | lectura/consulta | [Categorías](https://developers.mercadolibre.com.co/es_co/categorias-inmuebles) |
| MOD-04-EP-0008 | `GET` | `/categories/MLA1468` | lectura/consulta | [Categorías](https://developers.mercadolibre.com.co/es_co/categorias-inmuebles) |
| MOD-04-EP-0009 | `GET` | `/categories/MLA401685/attributes` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos-inmuebles) |
| MOD-04-EP-0010 | `GET` | `/categories/MLA401806` | lectura/consulta | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) |
| MOD-04-EP-0011 | `GET` | `/categories/MLA401806/attributes` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones-para-inmuebles) |
| MOD-04-EP-0012 | `GET` | `/classified_locations/cities/$CITY_ID` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| MOD-04-EP-0013 | `GET` | `/classified_locations/cities/TUxBQ1LNTzc4N2Fm` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| MOD-04-EP-0014 | `GET` | `/classified_locations/countries` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| MOD-04-EP-0015 | `GET` | `/classified_locations/countries/$COUNTRY_ID` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| MOD-04-EP-0016 | `GET` | `/classified_locations/countries/AR` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| MOD-04-EP-0017 | `GET` | `/classified_locations/neighborhoods/$NEIGHBORHOOD_ID` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| MOD-04-EP-0018 | `GET` | `/classified_locations/neighborhoods/TUxBQlLNTzM2NDg2OA` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| MOD-04-EP-0019 | `GET` | `/classified_locations/states/$STATE_ID` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| MOD-04-EP-0020 | `GET` | `/classified_locations/states/TUxBUENPUmFkZGIw` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| MOD-04-EP-0021 | `GET` | `/items/$ITEM_ID?attributes=variations` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones-para-inmuebles) |
| MOD-04-EP-0022 | `GET` | `/items/$ITEM_ID/address_line_by_reference` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| MOD-04-EP-0023 | `GET` | `/items/$ITEM_ID/contacts/phone_views?date_from=$DATE_FROM&date_to=$DATE_TO` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| MOD-04-EP-0024 | `GET` | `/items/$ITEM_ID/contacts/phone_views/time_window?last=$LAST&unit=$UNIT&ending=$ENDING` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| MOD-04-EP-0025 | `GET` | `/items/$ITEM_ID/contacts/questions?date_from=$DATE_FROM&date_to=$DATE_TO` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| MOD-04-EP-0026 | `GET` | `/items/$ITEM_ID/contacts/whatsapp?date_from=$DATE_FROM&date_to=$DATE_TO` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| MOD-04-EP-0027 | `GET` | `/items/$ITEM_ID/contacts/whatsapp/time_window?unit=$UNIT&last=$LAST&ending=$ENDING` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| MOD-04-EP-0028 | `GET` | `/items/$ITEM_ID/description` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos-inmuebles) |
| MOD-04-EP-0029 | `GET` | `/items/$ITEM_ID/health` | lectura/consulta | [Calidad de las Publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-las-publicaciones-inmuebles) |
| MOD-04-EP-0030 | `GET` | `/items/$ITEM_ID/health/actions` | lectura/consulta | [Calidad de las Publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-las-publicaciones-inmuebles) |
| MOD-04-EP-0031 | `GET` | `/items/$ITEM_ID/variations/$Variation_id` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones-para-inmuebles) |
| MOD-04-EP-0032 | `GET` | `/items/contacts/phone_views/time_window?ids=$ID1,ID2&last=$LAST&unit=$UNIT&ending=$ENDING_NOTE` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| MOD-04-EP-0033 | `GET` | `/items/contacts/whatsapp/time_window?ids=$ID1,$ID2&unit=$UNIT&last=$LAST&ending=$ENDING` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| MOD-04-EP-0034 | `GET` | `/items/tags` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| MOD-04-EP-0035 | `GET` | `/items/visits?ids=$ITEM_ID&date_from=$DATE_FROM&date_to=$DATE_TO` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| MOD-04-EP-0036 | `GET` | `/questions/$QUESTION_ID?api_version=4` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| MOD-04-EP-0037 | `GET` | `/quotations/$QUOTATION_ID?caller.type=seller` | lectura/consulta | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) |
| MOD-04-EP-0038 | `GET` | `/quotations/items_ids?query=$ITEMID&caller.type=seller` | lectura/consulta | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) |
| MOD-04-EP-0039 | `GET` | `/quotations/items_ids?query=ItemId1,Itemid2,Itemid3&caller.type=seller` | lectura/consulta | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) |
| MOD-04-EP-0040 | `GET` | `/quotations/report?seller.id=$SELLER.ID` | lectura/consulta | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) |
| MOD-04-EP-0041 | `GET` | `/sites/$COUNTRY_ID/search?item_location=lat:$LATITUDE1_LATITUDE2,lon:$LONGITUDE1_LONGITUDE2&category=$CATEGORY_ID` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| MOD-04-EP-0042 | `GET` | `/sites/$SITE_ID/categories` | lectura/consulta | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) |
| MOD-04-EP-0043 | `GET` | `/sites/$SITE_ID/health_levels` | lectura/consulta | [Calidad de las Publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-las-publicaciones-inmuebles) |
| MOD-04-EP-0044 | `GET` | `/sites/MLA/categories` | lectura/consulta | [Categorías](https://developers.mercadolibre.com.co/es_co/categorias-inmuebles) |
| MOD-04-EP-0045 | `GET` | `/sites/MLA/search?item_location=lat:-37.987148_-30.987148,lon:-57.5483864_-50.5483864&category=MLA1459&limit=1` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| MOD-04-EP-0046 | `GET` | `/users/$USER_ID/classifieds_promotion_packs?package_content=$PACKAGE_CONTENT&status=$STATUS` | lectura/consulta | [Gestionar paquetes de inmuebles](https://developers.mercadolibre.com.co/es_co/gestionar-paquetes-de-inmuebles) |
| MOD-04-EP-0047 | `GET` | `/users/$USER_ID/classifieds_promotion_packs/$LISTING_TYPE?categoryId=$CATEGORY_ID` | lectura/consulta | [Gestionar paquetes de inmuebles](https://developers.mercadolibre.com.co/es_co/gestionar-paquetes-de-inmuebles) |
| MOD-04-EP-0048 | `GET` | `/users/$USER_ID/contacts/phone_views?date_from=$DATE_FROM&date_to=$DATE_TO` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| MOD-04-EP-0049 | `GET` | `/users/$USER_ID/contacts/phone_views/time_window?last=$LAST&unit=$UNIT&ending=$ENDING` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| MOD-04-EP-0050 | `GET` | `/users/$USER_ID/contacts/questions?date_from=$DATE_FROM&date_to=$DATE_TO` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| MOD-04-EP-0051 | `GET` | `/users/$USER_ID/contacts/questions/time_window?last=$LAST&unit=$UNIT&ending=$ENDING` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| MOD-04-EP-0052 | `GET` | `/users/$USER_ID/contacts/whatsapp/time_window?unit=$UNIT&last=$LAST&ending=$ENDING` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| MOD-04-EP-0053 | `GET` | `/users/$USER_ID/items_visits?date_from=$DATE_FROM&date_to=$DATE_TO` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| MOD-04-EP-0054 | `GET` | `/users/$USER_ID/items_visits/time_window?last=$LAST&unit=$UNIT&ending=$ENDING` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| MOD-04-EP-0055 | `GET` | `/users/806525693/leads/buyers?scope=test-public` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| MOD-04-EP-0056 | `GET` | `/vis-transactions-hub/{providerId` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| MOD-04-EP-0057 | `GET` | `/vis-transactions-hub/configurations/provider` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| MOD-04-EP-0058 | `GET` | `/vis-transactions-hub/configurations/provider/{pro` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| MOD-04-EP-0059 | `GET` | `/vis-transactions-hub/configurations/provider/$provider_Id` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| MOD-04-EP-0060 | `GET` | `/vis-transactions-hub/configurations/seller/$seller_Id` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| MOD-04-EP-0061 | `GET` | `/vis/users/$USER_ID/leads/buyers` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| MOD-04-EP-0062 | `GET` | `/vis/users/$USER_ID/leads/buyers?contact_types=question` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| MOD-04-EP-0063 | `GET` | `/vis/users/$USER_ID/leads/buyers?contact_types=whatsapp` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| MOD-04-EP-0064 | `GET` | `/vis/users/$USER_ID/leads/buyers?item_id=MLX1234` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| MOD-04-EP-0065 | `GET` | `/vis/users/$USER_ID/leads/buyers?offset=$OFFSET&limit=$LIMIT&date_from=$DATE_FROM&date_to=$DATE_TO&contact_types=$CONTACT_TYPES&item_id=$ITEM_ID&buyer_ids=$BUYER_IDS` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| MOD-04-EP-0066 | `GET` | `/vis/users/3052668868/leads/buyers?offset=0&limit=10&date_from=2026-01-15&date_to=2026-01-22&contact_types=credit,question,whatsapp&include_guest=true` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| MOD-04-EP-0067 | `GET` | `/visits/items?ids=$ITEM_ID` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| MOD-04-EP-0068 | `POST` | `/items/$ITEM_ID/variations/$VARIATION_ID` | mutación | [Actualiza las variaciones de tu inmueble](https://developers.mercadolibre.com.co/es_co/actualizacion-variacion-inmuebles) |
| MOD-04-EP-0069 | `POST` | `/items/MLA658778048/variations` | mutación | [Actualiza las variaciones de tu inmueble](https://developers.mercadolibre.com.co/es_co/actualizacion-variacion-inmuebles) |
| MOD-04-EP-0070 | `POST` | `/items/MLC2913388294` | mutación | [Actualiza las variaciones de tu inmueble](https://developers.mercadolibre.com.co/es_co/actualizacion-variacion-inmuebles) |
| MOD-04-EP-0071 | `PUT` | `/items/$ITEM_ID/address_line_by_reference` | mutación | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| MOD-04-EP-0072 | `PUT` | `/items/MLC2913388294` | mutación | [Actualiza las variaciones de tu inmueble](https://developers.mercadolibre.com.co/es_co/actualizacion-variacion-inmuebles) |
| MOD-04-EP-0073 | `PUT` | `/quotations/$QUOTATION_ID?caller.type=seller` | mutación | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) |
| MOD-04-EP-0074 | `UNKNOWN` | `/categories/MLA401806` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones-para-inmuebles) |
| MOD-04-EP-0075 | `UNKNOWN` | `/categories/MLA401806/attributes` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones-para-inmuebles) |
| MOD-04-EP-0076 | `UNKNOWN` | `/items/$ITEM_ID/description` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos-inmuebles) |
| MOD-04-EP-0077 | `UNKNOWN` | `/items/tags` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| MOD-04-EP-0078 | `UNKNOWN` | `/users/$USER_ID/classifieds_promotion_packs?package_content=$PACKAGE_CONTENT&status=$STATUS` | lectura/consulta | [Gestionar paquetes de inmuebles](https://developers.mercadolibre.com.co/es_co/gestionar-paquetes-de-inmuebles) |
| MOD-04-EP-0079 | `UNKNOWN` | `/users/806525693/leads/buyers?scope=test-public` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| MOD-04-EP-0080 | `UNKNOWN` | `/vis-transactions-hub/{providerId` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| MOD-04-EP-0081 | `UNKNOWN` | `/vis-transactions-hub/configurations/provider` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| MOD-04-EP-0082 | `UNKNOWN` | `/vis-transactions-hub/configurations/provider/{pro` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| MOD-04-EP-0083 | `UNKNOWN` | `/vis-transactions-hub/configurations/provider/$provider_Id` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| MOD-04-EP-0084 | `UNKNOWN` | `/vis-transactions-hub/configurations/seller/$seller_Id` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |

### Señales de autenticación/permisos

- [Actualiza las variaciones de tu inmueble](https://developers.mercadolibre.com.co/es_co/actualizacion-variacion-inmuebles): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Atributos](https://developers.mercadolibre.com.co/es_co/atributos-inmuebles): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Calidad de las Publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-las-publicaciones-inmuebles): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Categorías](https://developers.mercadolibre.com.co/es_co/categorias-inmuebles): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Gestionar paquetes de inmuebles](https://developers.mercadolibre.com.co/es_co/gestionar-paquetes-de-inmuebles): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles): `Authorization`, `Bearer`, `ACCESS_TOKEN`, `scope`
- [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Pasos Rápidos para Publicar un Inmueble de Prueba](https://developers.mercadolibre.com.co/es_co/pasos-rapidos-para-publicar-un-inmueble-de-prueba): `Authorization`, `Content-Type`, `client_id`, `client_secret`, `redirect_uri`, `Bearer`, `ACCESS_TOKEN`
- [Publica Inmuebles](https://developers.mercadolibre.com.co/es_co/publica-inmueble): `Authorization`, `Content-Type`, `access_token`, `Bearer`, `ACCESS_TOKEN`
- [Publicaciones de tiendas oficiales para inmuebles](https://developers.mercadolibre.com.co/es_co/publicaciones-de-tiendas-oficiales-para-inmuebles): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones-para-inmuebles): `Authorization`, `Bearer`, `ACCESS_TOKEN`

## Mensajería, reclamos y devoluciones

id: MOD-05

Resumen: 11 páginas fuente, 147 endpoints/rutas, integraciones detectadas: Mensajería, Mercado Envíos, Mercado Pago, Notificaciones, OAuth.

### Páginas fuente

| ID | Página | Estado | Endpoints | Fechas visibles |
|---|---|---|---:|---|
| MOD-05-PAGE-001 | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) | `ok` | 21 | 2019-08-06, 2019-07-30, 2024-06-13 |
| MOD-05-PAGE-002 | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) | `ok` | 23 | — |
| MOD-05-PAGE-003 | [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo) | `ok` | 12 | — |
| MOD-05-PAGE-004 | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) | `ok` | 31 | — |
| MOD-05-PAGE-005 | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) | `ok` | 28 | — |
| MOD-05-PAGE-006 | [Cambios - Changes & Allow Replace](https://developers.mercadolibre.com.co/es_co/changes) | `ok` | 6 | — |
| MOD-05-PAGE-007 | [Errores](https://developers.mercadolibre.com.co/es_co/errores) | `ok` | 0 | — |
| MOD-05-PAGE-008 | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) | `ok` | 17 | 02 de febrero de 2026, 02/02/2026 |
| MOD-05-PAGE-009 | [Mensajes bloqueados](https://developers.mercadolibre.com.co/es_co/mensajes-bloqueados) | `ok` | 4 | — |
| MOD-05-PAGE-010 | [Mensajes pendientes](https://developers.mercadolibre.com.co/es_co/mensajes-pendientes) | `ok` | 14 | — |
| MOD-05-PAGE-011 | [Qué es mensajería](https://developers.mercadolibre.com.co/es_co/que-es-mensajeria) | `ok` | 0 | — |

### Endpoints/rutas del módulo

| ID | Método | Ruta | Riesgo/área | Fuente |
|---|---|---|---|---|
| MOD-05-EP-0001 | `GET` | `/claims/$CLAIM_ID` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0002 | `GET` | `/claims/$CLAIM_ID/detail` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0003 | `GET` | `/claims/$CLAIM_ID/returns` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0004 | `GET` | `/claims/$CLAIM_ID/returns/attachments` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0005 | `GET` | `/claims/$CLAIMS` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0006 | `GET` | `/claims/$CLAIMS_ID/messages` | lectura/consulta | [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo) |
| MOD-05-EP-0007 | `GET` | `/claims/actions-history` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0008 | `GET` | `/claims/actions/evidences` | lectura/consulta | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0009 | `GET` | `/claims/affects-reputation` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0010 | `GET` | `/claims/detail` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0011 | `GET` | `/claims/reasons/$REASON_ID` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0012 | `GET` | `/claims/search` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0013 | `GET` | `/claims/search?offset=0&limit=30` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0014 | `GET` | `/claims/search?resource_id=123` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0015 | `GET` | `/claims/search?status=opened` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0016 | `GET` | `/messages/$MESSAGE_ID?tag=post_sale` | lectura/consulta | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0017 | `GET` | `/messages/attachments?tag=post_sale&site_id=MLB` | lectura/consulta | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0018 | `GET` | `/messages/attachments?tag=post_sale&site_id=SITE_ID` | lectura/consulta | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0019 | `GET` | `/messages/attachments/$ATTACHMENT_ID?tag=post_sale&site_id=SITE_ID` | lectura/consulta | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0020 | `GET` | `/messages/packs/$PACK_ID/sellers/$SELLER_ID?tag=post_sale` | lectura/consulta | [Mensajes bloqueados](https://developers.mercadolibre.com.co/es_co/mensajes-bloqueados) |
| MOD-05-EP-0021 | `GET` | `/messages/packs/$PACK_ID/sellers/$USER_ID?tag=post_sale` | lectura/consulta | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0022 | `GET` | `/messages/packs/2000000089077943/sellers/415458330?limit=2&offset=1&tag=post_sale` | lectura/consulta | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0023 | `GET` | `/messages/packs/2000000089077943/sellers/415458330?tag=post_sale` | lectura/consulta | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0024 | `GET` | `/messages/packs/22175467/sellers/32086568493?tag=post_sale` | lectura/consulta | [Mensajes bloqueados](https://developers.mercadolibre.com.co/es_co/mensajes-bloqueados) |
| MOD-05-EP-0025 | `GET` | `/messages/packs/pack_id/sellers/seller_id` | lectura/consulta | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0026 | `GET` | `/messages/packs/pack_id/sellers/seller_id?mark_as_read=false` | lectura/consulta | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0027 | `GET` | `/messages/unread?role=$ROLE&tag=post_sale` | lectura/consulta | [Mensajes pendientes](https://developers.mercadolibre.com.co/es_co/mensajes-pendientes) |
| MOD-05-EP-0028 | `GET` | `/messages/unread?tag=post_sale` | lectura/consulta | [Mensajes pendientes](https://developers.mercadolibre.com.co/es_co/mensajes-pendientes) |
| MOD-05-EP-0029 | `GET` | `/messages/unread/$RESOURCE?tag=post_sale` | lectura/consulta | [Mensajes pendientes](https://developers.mercadolibre.com.co/es_co/mensajes-pendientes) |
| MOD-05-EP-0030 | `GET` | `/messages/unread/packs/1234/sellers/2345?tag=post_sale` | lectura/consulta | [Mensajes pendientes](https://developers.mercadolibre.com.co/es_co/mensajes-pendientes) |
| MOD-05-EP-0031 | `GET` | `/packs` | lectura/consulta | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0032 | `GET` | `/packs/{pack_id}/sellers/{seller_id}/conversations/{type}` | lectura/consulta | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0033 | `GET` | `/packs/1234/sellers/2345` | lectura/consulta | [Mensajes pendientes](https://developers.mercadolibre.com.co/es_co/mensajes-pendientes) |
| MOD-05-EP-0034 | `GET` | `/packs/1977056109/sellers/378136913` | lectura/consulta | [Mensajes pendientes](https://developers.mercadolibre.com.co/es_co/mensajes-pendientes) |
| MOD-05-EP-0035 | `GET` | `/packs/2000000089077943/seller/415458330` | lectura/consulta | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0036 | `GET` | `/packs/22175467/sellers/32086568493` | lectura/consulta | [Mensajes bloqueados](https://developers.mercadolibre.com.co/es_co/mensajes-bloqueados) |
| MOD-05-EP-0037 | `GET` | `/post-purchase/v1/claims/:CLAIM_ID/expected-resolutions/allow-replace` | lectura/consulta | [Cambios - Changes & Allow Replace](https://developers.mercadolibre.com.co/es_co/changes) |
| MOD-05-EP-0038 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0039 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/actions-history` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0040 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/actions/evidences` | lectura/consulta | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0041 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/actions/send-message` | lectura/consulta | [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo) |
| MOD-05-EP-0042 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/affects-reputation` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0043 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/attachments` | lectura/consulta | [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo) |
| MOD-05-EP-0044 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/attachments-evidences` | lectura/consulta | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0045 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/attachments-evidences/$ATTACHMENT_ID` | lectura/consulta | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0046 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/attachments-evidences/$ATTACHMENTS_ID/download` | lectura/consulta | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0047 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/attachments/$ATTACHMENTS_ID` | lectura/consulta | [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo) |
| MOD-05-EP-0048 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/attachments/$ATTACHMENTS_ID/download` | lectura/consulta | [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo) |
| MOD-05-EP-0049 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/changes` | lectura/consulta | [Cambios - Changes & Allow Replace](https://developers.mercadolibre.com.co/es_co/changes) |
| MOD-05-EP-0050 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/charges/return-cost` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0051 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/charges/return-cost?calculate_amount_usd=true` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0052 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/detail` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0053 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/evidences` | lectura/consulta | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0054 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/expected-resolutions` | lectura/consulta | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0055 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/messages` | lectura/consulta | [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo) |
| MOD-05-EP-0056 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/partial-refund/available-offers` | lectura/consulta | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0057 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/returns/attachments` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0058 | `GET` | `/post-purchase/v1/claims/$CLAIM_ID/status-history` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0059 | `GET` | `/post-purchase/v1/claims/5123456/attachments-evidences` | lectura/consulta | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0060 | `GET` | `/post-purchase/v1/claims/5123456/attachments-evidences/$ATTACHMENT_ID` | lectura/consulta | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0061 | `GET` | `/post-purchase/v1/claims/5123456/attachments-evidences/$ATTACHMENTS_ID/download` | lectura/consulta | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0062 | `GET` | `/post-purchase/v1/claims/5175748308/actions-history` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0063 | `GET` | `/post-purchase/v1/claims/5175748308/status-history` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0064 | `GET` | `/post-purchase/v1/claims/5204934310/actions/evidences` | lectura/consulta | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0065 | `GET` | `/post-purchase/v1/claims/5204934310/actions/send-message` | lectura/consulta | [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo) |
| MOD-05-EP-0066 | `GET` | `/post-purchase/v1/claims/5204934310/detail` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0067 | `GET` | `/post-purchase/v1/claims/5204934310/evidences` | lectura/consulta | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0068 | `GET` | `/post-purchase/v1/claims/5204934310/expected-resolutions` | lectura/consulta | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0069 | `GET` | `/post-purchase/v1/claims/5204934310/messages` | lectura/consulta | [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo) |
| MOD-05-EP-0070 | `GET` | `/post-purchase/v1/claims/5204934310/partial-refund/available-offers` | lectura/consulta | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0071 | `GET` | `/post-purchase/v1/claims/5224172034/affects-reputation` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0072 | `GET` | `/post-purchase/v1/claims/5255026166/returns/attachments` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0073 | `GET` | `/post-purchase/v1/claims/5255498215/changes` | lectura/consulta | [Cambios - Changes & Allow Replace](https://developers.mercadolibre.com.co/es_co/changes) |
| MOD-05-EP-0074 | `GET` | `/post-purchase/v1/claims/5281510459` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0075 | `GET` | `/post-purchase/v1/claims/555555555/attachments/1325224382_181a6330-d9f6-410c-a2c9-d03f8323bd16.jpg/download` | lectura/consulta | [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo) |
| MOD-05-EP-0076 | `GET` | `/post-purchase/v1/claims/949903015/actions/evidences` | lectura/consulta | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0077 | `GET` | `/post-purchase/v1/claims/949903019/evidences` | lectura/consulta | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0078 | `GET` | `/post-purchase/v1/claims/reasons/$REASON_ID` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0079 | `GET` | `/post-purchase/v1/claims/reasons/PDD9939` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0080 | `GET` | `/post-purchase/v1/claims/search?players.user_id=123456789&players.role=respondent&status=opened&limit=30` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0081 | `GET` | `/post-purchase/v1/returns/{return_id}/return-review` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0082 | `GET` | `/post-purchase/v1/returns/$RETURN_ID/return-review` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0083 | `GET` | `/post-purchase/v1/returns/$RETURN_ID/reviews` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0084 | `GET` | `/post-purchase/v1/returns/267582953/return-review` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0085 | `GET` | `/post-purchase/v1/returns/54640533964/reviews` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0086 | `GET` | `/post-purchase/v1/returns/reasons?flow=$FLOW&claim_id=$CLAIM_ID` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0087 | `GET` | `/post-purchase/v1/returns/reasons?flow=seller_return_failed&claim_id=5555555` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0088 | `GET` | `/post-purchase/v2/claims/$CLAIM_ID/returns` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0089 | `GET` | `/returns/attachments` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0090 | `GET` | `/returns/reasons` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0091 | `GET` | `/shipments/$SHIPMENT_ID/costs` | envíos | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0092 | `GET` | `/v1/claims/search` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0093 | `GET` | `/v1/claims/search?offset=0&limit=30` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0094 | `GET` | `/v1/claims/search?resource_id=123` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0095 | `GET` | `/v1/claims/search?status=opened` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0096 | `POST` | `/claims/{claim_id}/partial-refund/available-offers` | mutación | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0097 | `POST` | `/claims/expected-resolutions` | mutación | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0098 | `POST` | `/claims/partial-refund/available-offers-resolutions` | mutación | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0099 | `POST` | `/messages/attachments?tag=post_sale&site_id=MLB` | mutación | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0100 | `POST` | `/messages/attachments?tag=post_sale&site_id=SITE_ID` | mutación | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0101 | `POST` | `/messages/packs/$PACK_ID/sellers/$USER_ID?tag=post_sale` | mutación | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0102 | `POST` | `/messages/packs/2000000089077943/sellers/415458330?tag=post_sale` | mutación | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0103 | `POST` | `/post-purchase/v1/claims/$CLAIM_ID/actions/evidences` | mutación | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0104 | `POST` | `/post-purchase/v1/claims/$CLAIM_ID/actions/open-dispute` | mutación | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0105 | `POST` | `/post-purchase/v1/claims/$CLAIM_ID/actions/send-message` | mutación | [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo) |
| MOD-05-EP-0106 | `POST` | `/post-purchase/v1/claims/$CLAIM_ID/attachments` | mutación | [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo) |
| MOD-05-EP-0107 | `POST` | `/post-purchase/v1/claims/$CLAIM_ID/attachments-evidences` | mutación | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0108 | `POST` | `/post-purchase/v1/claims/$CLAIM_ID/evidences` | mutación | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0109 | `POST` | `/post-purchase/v1/claims/$CLAIM_ID/expected-resolutions` | mutación | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0110 | `POST` | `/post-purchase/v1/claims/$CLAIM_ID/expected-resolutions/allow-return` | mutación | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0111 | `POST` | `/post-purchase/v1/claims/$CLAIM_ID/expected-resolutions/partial-refund` | mutación | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0112 | `POST` | `/post-purchase/v1/claims/$CLAIM_ID/expected-resolutions/refund` | mutación | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0113 | `POST` | `/post-purchase/v1/claims/$CLAIM_ID/partial-refund/available-offers` | mutación | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0114 | `POST` | `/post-purchase/v1/claims/$CLAIM_ID/returns/attachments` | mutación | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0115 | `POST` | `/post-purchase/v1/claims/5123456/attachments-evidences` | mutación | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0116 | `POST` | `/post-purchase/v1/claims/5204934310/actions/evidences` | mutación | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0117 | `POST` | `/post-purchase/v1/claims/5204934310/actions/open-dispute` | mutación | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0118 | `POST` | `/post-purchase/v1/claims/5204934310/actions/send-message` | mutación | [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo) |
| MOD-05-EP-0119 | `POST` | `/post-purchase/v1/claims/5204934310/expected-resolutions` | mutación | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0120 | `POST` | `/post-purchase/v1/claims/5204934310/expected-resolutions/allow-return` | mutación | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0121 | `POST` | `/post-purchase/v1/claims/5204934310/expected-resolutions/partial-refund` | mutación | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0122 | `POST` | `/post-purchase/v1/claims/5204934310/expected-resolutions/refund` | mutación | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0123 | `POST` | `/post-purchase/v1/claims/5204934310/partial-refund/available-offers` | mutación | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0124 | `POST` | `/post-purchase/v1/claims/5255026166/returns/attachments` | mutación | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0125 | `POST` | `/post-purchase/v1/claims/5341941616/partial-refund/available-offers` | mutación | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0126 | `POST` | `/post-purchase/v1/claims/949903015/actions/evidences` | mutación | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0127 | `POST` | `/post-purchase/v1/claims/949903019/evidences` | mutación | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0128 | `POST` | `/post-purchase/v1/returns/$RETURN_ID/return-review` | mutación | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0129 | `POST` | `/post-purchase/v1/returns/267582953/return-review` | mutación | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0130 | `UNKNOWN` | `/claims/{claim_id}/partial-refund/available-offers` | lectura/consulta | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0131 | `UNKNOWN` | `/claims/$CLAIM_ID` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0132 | `UNKNOWN` | `/claims/$CLAIM_ID/returns/attachments` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0133 | `UNKNOWN` | `/claims/actions/evidences` | lectura/consulta | [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) |
| MOD-05-EP-0134 | `UNKNOWN` | `/claims/search` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0135 | `UNKNOWN` | `/claims/search?offset=0&limit=30` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0136 | `UNKNOWN` | `/claims/search?resource_id=123` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0137 | `UNKNOWN` | `/claims/search?status=opened` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| MOD-05-EP-0138 | `UNKNOWN` | `/packs/1234/sellers/2345` | lectura/consulta | [Mensajes pendientes](https://developers.mercadolibre.com.co/es_co/mensajes-pendientes) |
| MOD-05-EP-0139 | `UNKNOWN` | `/packs/1977056109/sellers/378136913` | lectura/consulta | [Mensajes pendientes](https://developers.mercadolibre.com.co/es_co/mensajes-pendientes) |
| MOD-05-EP-0140 | `UNKNOWN` | `/packs/2000000089077943/seller/415458330` | lectura/consulta | [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) |
| MOD-05-EP-0141 | `UNKNOWN` | `/packs/22175467/sellers/32086568493` | lectura/consulta | [Mensajes bloqueados](https://developers.mercadolibre.com.co/es_co/mensajes-bloqueados) |
| MOD-05-EP-0142 | `UNKNOWN` | `/post-purchase/v1/claims/:CLAIM_ID/expected-resolutions/allow-replace` | lectura/consulta | [Cambios - Changes & Allow Replace](https://developers.mercadolibre.com.co/es_co/changes) |
| MOD-05-EP-0143 | `UNKNOWN` | `/post-purchase/v1/claims/$CLAIM_ID/partial-refund/available-offers` | lectura/consulta | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0144 | `UNKNOWN` | `/post-purchase/v1/claims/5341941616/partial-refund/available-offers` | lectura/consulta | [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) |
| MOD-05-EP-0145 | `UNKNOWN` | `/post-purchase/v1/returns/{return_id}/return-review` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0146 | `UNKNOWN` | `/post-purchase/v1/returns/reasons?flow=$FLOW&claim_id=$CLAIM_ID` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| MOD-05-EP-0147 | `UNKNOWN` | `/post-purchase/v2/claims/$CLAIM_ID/returns` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |

### Señales de autenticación/permisos

- [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos): `Authorization`, `x-public`, `Bearer`, `ACCESS_TOKEN`
- [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos): `Authorization`, `Bearer`, `ACCESS_TOKEN`, `client_id`
- [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`, `access_token`
- [Cambios - Changes & Allow Replace](https://developers.mercadolibre.com.co/es_co/changes): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`, `client_id`, `read`
- [Mensajes bloqueados](https://developers.mercadolibre.com.co/es_co/mensajes-bloqueados): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Mensajes pendientes](https://developers.mercadolibre.com.co/es_co/mensajes-pendientes): `Authorization`, `Bearer`, `ACCESS_TOKEN`, `access_token`, `client_id`, `read`

## Mercado Ads

id: MOD-06

Resumen: 5 páginas fuente, 55 endpoints/rutas, integraciones detectadas: Catálogo, Mercado Ads, Mercado Envíos, Mercado Pago, OAuth.

### Páginas fuente

| ID | Página | Estado | Endpoints | Fechas visibles |
|---|---|---|---:|---|
| MOD-06-PAGE-001 | [Bonificaciones para Product Ads](https://developers.mercadolibre.com.co/es_co/bonificaciones-para-product-ads) | `ok` | 1 | — |
| MOD-06-PAGE-002 | [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) | `ok` | 14 | 17 de junio de 2026, 2023-02-09, 2025-04-01, 2025-04-07, 2025-04-02, 2025-04-03, 2025-04-04, 2025-04-05, 2025-04-06, 2025-05-01, 2025-05-05, 2025-05-02, 2025-05-03, 2025-05-04, 2024-07-01, 2024-07-10, 2025-02-13 |
| MOD-06-PAGE-003 | [Display Ads](https://developers.mercadolibre.com.co/es_co/display) | `ok` | 14 | 1 de septiembre de 2022, 2024-04-01, 2024-04-15, 2024-02-01, 2024-09-19, 2024-09-20 |
| MOD-06-PAGE-004 | [Mercado Ads](https://developers.mercadolibre.com.co/es_co/introduccion-a-mercado-ads) | `ok` | 0 | — |
| MOD-06-PAGE-005 | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) | `ok` | 28 | 26 de febrero de 2026, 30 de marzo de 2026, 2025-12-01, 2025-12-30, 2024-01-01, 2024-02-28, 2023-01-01 |

### Endpoints/rutas del módulo

| ID | Método | Ruta | Riesgo/área | Fuente |
|---|---|---|---|---|
| MOD-06-EP-0001 | `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ads/search?limit=1&offset=0&date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0002 | `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ads/search?limit=1&offset=0&date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount&aggregation_type=DAILY` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0003 | `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ads/search?limit=1&offset=0&date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount&metrics_summary=true` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0004 | `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/campaigns/search` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0005 | `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/campaigns/search??limit=1&offset=0&date_from=2025-12-01&date_to=2025-12-30&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount&metrics_summary=true` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0006 | `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/campaigns/search?limit=2&offset=0&date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount&aggregation_type=DAILY` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0007 | `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/items/search` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0008 | `GET` | `/advertising/$ADVERTISER_SITE_ID/product_ads/ads/$ITEM_ID` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0009 | `GET` | `/advertising/$ADVERTISER_SITE_ID/product_ads/ads/$ITEM_ID?date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0010 | `GET` | `/advertising/$ADVERTISER_SITE_ID/product_ads/ads/$ITEM_ID?date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount&aggregation_type=DAILY` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0011 | `GET` | `/advertising/$ADVERTISER_SITE_ID/product_ads/campaigns/$CAMPAIGN_ID??date_from=2025-12-01&date_to=2025-12-30&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount,impression_share,top_impression_share,lost_impression_share_by_budget,lost_impression_share_by_ad_rank,acos_benchmark` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0012 | `GET` | `/advertising/$ADVERTISER_SITE_ID/product_ads/campaigns/$CAMPAIGN_ID?date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount,impression_share,top_impression_share,lost_impression_share_by_budget,lost_impression_share_by_ad_rank,acos_benchmark&aggregation_type=DAILY` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0013 | `GET` | `/advertising/$ADVERTISER_SITE_ID/product_ads/items/$ITEM_ID` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0014 | `GET` | `/advertising/advertisers?product_id=$PRODUCT_ID` | lectura/consulta | [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) |
| MOD-06-EP-0015 | `GET` | `/advertising/advertisers?product_id=BADS` | lectura/consulta | [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) |
| MOD-06-EP-0016 | `GET` | `/advertising/advertisers?product_id=DISPLAY` | lectura/consulta | [Display Ads](https://developers.mercadolibre.com.co/es_co/display) |
| MOD-06-EP-0017 | `GET` | `/advertising/advertisers?product_id=PADS` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0018 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/brand_ads/campaigns` | lectura/consulta | [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) |
| MOD-06-EP-0019 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/brand_ads/campaigns/$CAMPAIGN_ID` | lectura/consulta | [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) |
| MOD-06-EP-0020 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/brand_ads/campaigns/$CAMPAIGN_ID/items` | lectura/consulta | [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) |
| MOD-06-EP-0021 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/brand_ads/campaigns/$CAMPAIGN_ID/keywords` | lectura/consulta | [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) |
| MOD-06-EP-0022 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/brand_ads/campaigns/$CAMPAIGN_ID/keywords/metrics?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD` | lectura/consulta | [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) |
| MOD-06-EP-0023 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/brand_ads/campaigns/$CAMPAIGN_ID/metrics?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD` | lectura/consulta | [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) |
| MOD-06-EP-0024 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/brand_ads/campaigns/full_summary?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD` | lectura/consulta | [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) |
| MOD-06-EP-0025 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/brand_ads/campaigns/metrics?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&aggregation_type=daily` | lectura/consulta | [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) |
| MOD-06-EP-0026 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/display/campaigns` | lectura/consulta | [Display Ads](https://developers.mercadolibre.com.co/es_co/display) |
| MOD-06-EP-0027 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/display/campaigns/$CAMPAIGN_ID/line_items?sort_by=start_date&sort_order=asc` | lectura/consulta | [Display Ads](https://developers.mercadolibre.com.co/es_co/display) |
| MOD-06-EP-0028 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/display/campaigns/$CAMPAIGN_ID/line_items/$LINE_ITEM_ID/creatives?sort_by=start_date&sort_order=asc` | lectura/consulta | [Display Ads](https://developers.mercadolibre.com.co/es_co/display) |
| MOD-06-EP-0029 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/display/campaigns/$CAMPAIGN_ID/metrics?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD` | lectura/consulta | [Display Ads](https://developers.mercadolibre.com.co/es_co/display) |
| MOD-06-EP-0030 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/display/campaigns/999999/line_items/0000001/creatives?sort_by=start_date&sort_order=asc` | lectura/consulta | [Display Ads](https://developers.mercadolibre.com.co/es_co/display) |
| MOD-06-EP-0031 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/display/metrics?dimension=creatives&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&line_item_id=$LINE_ITEM_ID` | lectura/consulta | [Display Ads](https://developers.mercadolibre.com.co/es_co/display) |
| MOD-06-EP-0032 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/display/metrics?dimension=line_items&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&campaign_id=$CAMPAIGN_ID` | lectura/consulta | [Display Ads](https://developers.mercadolibre.com.co/es_co/display) |
| MOD-06-EP-0033 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/product_ads/campaigns` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0034 | `GET` | `/advertising/advertisers/$ADVERTISER_ID/product_ads/items` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0035 | `GET` | `/advertising/advertisers/0000/display/metrics?dimension=creatives&date_from=2024-09-19&date_to=2024-09-20&line_item_id=4321` | lectura/consulta | [Display Ads](https://developers.mercadolibre.com.co/es_co/display) |
| MOD-06-EP-0036 | `GET` | `/advertising/advertisers/0000/display/metrics?dimension=line_items&date_from=2024-09-19&date_to=2024-09-19&campaign_id=1111` | lectura/consulta | [Display Ads](https://developers.mercadolibre.com.co/es_co/display) |
| MOD-06-EP-0037 | `GET` | `/advertising/advertisers/101010/brand_ads/campaigns/123456/keywords/metrics?date_from=2024-07-01&date_to=2024-07-10` | lectura/consulta | [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) |
| MOD-06-EP-0038 | `GET` | `/advertising/advertisers/101010/brand_ads/campaigns/full_summary?date_from=2024-07-01&date_to=2024-07-10` | lectura/consulta | [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) |
| MOD-06-EP-0039 | `GET` | `/advertising/advertisers/10101010/brand_ads/campaigns/123456/metrics?date_from=2025-05-01&date_to=2025-05-05` | lectura/consulta | [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) |
| MOD-06-EP-0040 | `GET` | `/advertising/advertisers/10101010/brand_ads/campaigns/metrics?date_from=2025-04-01&date_to=2025-04-07&aggregation_type=daily` | lectura/consulta | [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) |
| MOD-06-EP-0041 | `GET` | `/advertising/advertisers/11111/display/campaigns/80/metrics?date_from=2024-04-01&date_to=2024-04-15` | lectura/consulta | [Display Ads](https://developers.mercadolibre.com.co/es_co/display) |
| MOD-06-EP-0042 | `GET` | `/advertising/advertisers/123456/display/campaigns/987654/line_items?sort_by=start_date&sort_order=asc` | lectura/consulta | [Display Ads](https://developers.mercadolibre.com.co/es_co/display) |
| MOD-06-EP-0043 | `GET` | `/advertising/advertisers/61/display/campaigns?sort_by=start_date&sort_order=desc` | lectura/consulta | [Display Ads](https://developers.mercadolibre.com.co/es_co/display) |
| MOD-06-EP-0044 | `GET` | `/advertising/MLA/advertisers/882927/product_ads/campaigns/search?limit=1&offset=0&date_from=2025-12-01&date_to=2025-12-30&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0045 | `GET` | `/advertising/MLM/advertisers/35300/product_ads/ads/search?limit=1&offset=0&date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0046 | `GET` | `/advertising/MLM/product_ads/ads/MLM12345678` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0047 | `GET` | `/advertising/product_ads_2/campaigns/$CAMPAIGN_ID/ads/metrics` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0048 | `GET` | `/advertising/product_ads_2/campaigns/$CAMPAIGN_ID/metrics` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0049 | `GET` | `/advertising/product_ads/ads/search` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0050 | `GET` | `/advertising/product_ads/campaigns/$CAMPAIGN_ID` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0051 | `GET` | `/advertising/product_ads/campaigns/$CAMPAIGN_ID/ads/metrics` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0052 | `GET` | `/advertising/product_ads/campaigns/$CAMPAIGN_ID/metrics` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0053 | `GET` | `/advertising/product_ads/items/$ITEM_ID` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0054 | `UNKNOWN` | `/advertising/$ADVERTISER_SITE_ID/product_ads/campaigns/$CAMPAIGN_ID??date_from=2025-12-01&date_to=2025-12-30&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount,impression_share,top_impression_share,lost_impression_share_by_budget,lost_impression_share_by_ad_rank,acos_benchmark` | lectura/consulta | [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) |
| MOD-06-EP-0055 | `UNKNOWN` | `/advertising/advertisers/bonifications` | lectura/consulta | [Bonificaciones para Product Ads](https://developers.mercadolibre.com.co/es_co/bonificaciones-para-product-ads) |

### Señales de autenticación/permisos

- [Bonificaciones para Product Ads](https://developers.mercadolibre.com.co/es_co/bonificaciones-para-product-ads): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Display Ads](https://developers.mercadolibre.com.co/es_co/display): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`

## Mercado Envíos

id: MOD-07

Resumen: 14 páginas fuente, 181 endpoints/rutas, integraciones detectadas: Catálogo, Facturación, Mensajería, Mercado Envíos, Mercado Pago, Notificaciones, OAuth.

### Páginas fuente

| ID | Página | Estado | Endpoints | Fechas visibles |
|---|---|---|---:|---|
| MOD-07-PAGE-001 | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) | `ok` | 20 | — |
| MOD-07-PAGE-002 | [Costos de envío](https://developers.mercadolibre.com.co/es_co/costos-de-envios) | `ok` | 8 | — |
| MOD-07-PAGE-003 | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) | `ok` | 23 | 2025-09-30, 2025-10-10 |
| MOD-07-PAGE-004 | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) | `ok` | 28 | 2021-12-25 |
| MOD-07-PAGE-005 | [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) | `ok` | 13 | 2020-06-01, 2020-06-30, 2020-06-29, 2020-07-28 |
| MOD-07-PAGE-006 | [Envíos Personalizados](https://developers.mercadolibre.com.co/es_co/envios-personalizados) | `ok` | 9 | — |
| MOD-07-PAGE-007 | [Envíos Turbo](https://developers.mercadolibre.com.co/es_co/envios-turbo) | `ok` | 12 | — |
| MOD-07-PAGE-008 | [Flete Dinámico](https://developers.mercadolibre.com.co/es_co/flete-dinamico) | `ok` | 12 | — |
| MOD-07-PAGE-009 | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) | `ok` | 20 | — |
| MOD-07-PAGE-010 | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) | `ok` | 20 | — |
| MOD-07-PAGE-011 | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) | `ok` | 31 | — |
| MOD-07-PAGE-012 | [ME1 / ME2 y envío gratis](https://developers.mercadolibre.com.co/es_co/me1-me2-y-envio-gratis) | `ok` | 0 | — |
| MOD-07-PAGE-013 | [Mercado Envíos - Costos y cotizaciones](https://developers.mercadolibre.com.co/es_co/mercado-envios-costos-y-cotizaciones) | `ok` | 3 | — |
| MOD-07-PAGE-014 | [Mercado Envíos 1](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-1) | `ok` | 9 | — |

### Endpoints/rutas del módulo

| ID | Método | Ruta | Riesgo/área | Fuente |
|---|---|---|---|---|
| MOD-07-EP-0001 | `DELETE` | `/flex/sites/$SITE_ID/items/$ITEM_ID/v2` | destructivo | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0002 | `DELETE` | `/flex/sites/MLB/items/MLB1493119403/v2` | destructivo | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0003 | `DELETE` | `/soe/bundles/{bundle_id` | destructivo | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0004 | `DELETE` | `/soe/bundles/12345/volumes` | destructivo | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0005 | `GET` | `/catalog_domains/$DOMAIN_ID/shipping_attributes` | envíos | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| MOD-07-EP-0006 | `GET` | `/catalog_domains/MLA-AUTOMOTIVE_TIRES/shipping_attributes` | envíos | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| MOD-07-EP-0007 | `GET` | `/catalog_domains/MLB-AUTOMOTIVE_TIRES/shipping_attributes` | envíos | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0008 | `GET` | `/categories` | lectura/consulta | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| MOD-07-EP-0009 | `GET` | `/categories/$CATEGORY_ID/shipping_preferences` | envíos | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| MOD-07-EP-0010 | `GET` | `/categories/MCO7159/shipping_preferences` | envíos | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0011 | `GET` | `/categories/MLA418448/shipping_preferences` | envíos | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| MOD-07-EP-0012 | `GET` | `/categories/MLA45502/shipping_preferences` | envíos | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0013 | `GET` | `/categories/MLB438794/shipping_preferences` | envíos | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0014 | `GET` | `/customers/marketplace/sites/{SITE_ID` | lectura/consulta | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| MOD-07-EP-0015 | `GET` | `/customers/marketplace/sites/$SITE_ID/user-products/$USER_PRODUCT_ID/contracts/shippability/services` | lectura/consulta | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| MOD-07-EP-0016 | `GET` | `/customers/marketplace/sites/MLA/user-products/MLAU1234567890/contracts/shippability/services?legacy_attributes=true` | lectura/consulta | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| MOD-07-EP-0017 | `GET` | `/flex/sites/{SITE_ID` | lectura/consulta | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0018 | `GET` | `/flex/sites/$SITE_ID/items/$ITEM_ID/v2` | lectura/consulta | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0019 | `GET` | `/flex/sites/$SITE_ID/shipments/$SHIPMENT_ID/assignment/v2` | envíos | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0020 | `GET` | `/flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/coverage/radius/v1` | lectura/consulta | [Envíos Turbo](https://developers.mercadolibre.com.co/es_co/envios-turbo) |
| MOD-07-EP-0021 | `GET` | `/flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/coverage/radius/v1?show_availables=boolean` | lectura/consulta | [Envíos Turbo](https://developers.mercadolibre.com.co/es_co/envios-turbo) |
| MOD-07-EP-0022 | `GET` | `/flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/coverage/zones/v1` | lectura/consulta | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0023 | `GET` | `/flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/coverage/zones/v1?show_availables=$boolean` | lectura/consulta | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0024 | `GET` | `/flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/delivery-ranges/v1` | lectura/consulta | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0025 | `GET` | `/flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/delivery-ranges/v1?show_availables=boolean` | lectura/consulta | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0026 | `GET` | `/flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/holidays/v1` | lectura/consulta | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0027 | `GET` | `/flex/sites/$SITE_ID/users/$USER_ID/subscriptions/v1` | lectura/consulta | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0028 | `GET` | `/flex/sites/MLA/shipments/40070866801/assignment/v2` | envíos | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0029 | `GET` | `/flex/sites/MLA/users/1438865529/subscriptions/v1` | lectura/consulta | [Envíos Turbo](https://developers.mercadolibre.com.co/es_co/envios-turbo) |
| MOD-07-EP-0030 | `GET` | `/flex/sites/MLA/users/1444885522/courier-shipment/v1` | envíos | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0031 | `GET` | `/flex/sites/MLB/items/MLB1493119403/v2` | lectura/consulta | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0032 | `GET` | `/inventories/$INVENTORY_ID/stock/fulfillment` | lectura/consulta | [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) |
| MOD-07-EP-0033 | `GET` | `/inventories/$INVENTORY_ID/stock/fulfillment?include_attributes=conditions` | lectura/consulta | [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) |
| MOD-07-EP-0034 | `GET` | `/inventories/LCQI05831/stock/fulfillment` | lectura/consulta | [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) |
| MOD-07-EP-0035 | `GET` | `/inventories/YLXH33638/stock/fulfillment?include_attributes=conditions` | lectura/consulta | [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) |
| MOD-07-EP-0036 | `GET` | `/items/$ITEM_ID/shipping_options?city_to=$CITY_TO` | envíos | [Costos de envío](https://developers.mercadolibre.com.co/es_co/costos-de-envios) |
| MOD-07-EP-0037 | `GET` | `/items/$ITEM_ID/shipping_options?zip_code=$ZIP_CODE` | envíos | [Costos de envío](https://developers.mercadolibre.com.co/es_co/costos-de-envios) |
| MOD-07-EP-0038 | `GET` | `/items/MLA1122334488` | lectura/consulta | [Costos de envío](https://developers.mercadolibre.com.co/es_co/costos-de-envios) |
| MOD-07-EP-0039 | `GET` | `/items/MLA1398714241/shipping_options?city_to=Q08tRENCb2dvdA` | envíos | [Costos de envío](https://developers.mercadolibre.com.co/es_co/costos-de-envios) |
| MOD-07-EP-0040 | `GET` | `/items/MLA1398714241/shipping_options?zip_code=1675` | envíos | [Costos de envío](https://developers.mercadolibre.com.co/es_co/costos-de-envios) |
| MOD-07-EP-0041 | `GET` | `/items/MLA1718222111` | lectura/consulta | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| MOD-07-EP-0042 | `GET` | `/items/MLA803066380/shipping_options?zip_code=$1234` | envíos | [Envíos Personalizados](https://developers.mercadolibre.com.co/es_co/envios-personalizados) |
| MOD-07-EP-0043 | `GET` | `/items/MLB1557246024` | lectura/consulta | [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) |
| MOD-07-EP-0044 | `GET` | `/nodes/$NETWORK_NODE_ID/capacity_middleend` | lectura/consulta | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0045 | `GET` | `/nodes/$NETWORK_NODE_ID/schedule/$LOGISTIC_TYPE` | lectura/consulta | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0046 | `GET` | `/nodes/$NETWORK_NODE_ID/service/$SERVICE_TYPE/processing_time_tool` | lectura/consulta | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0047 | `GET` | `/nodes/$NODE_ID/capacity_middleend` | lectura/consulta | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0048 | `GET` | `/nodes/MXP20157465171/schedule/xd_drop_off` | lectura/consulta | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0049 | `GET` | `/nodes/MXP20157465171/service/carrier_pickup/processing_time_tool` | lectura/consulta | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0050 | `GET` | `/nodes/MXP20214899242/capacity_middleend` | lectura/consulta | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0051 | `GET` | `/orders/$ORDER_ID` | lectura/consulta | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0052 | `GET` | `/orders/$ORDER_ID?options` | lectura/consulta | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0053 | `GET` | `/orders/$ORDER_ID/shipments` | envíos | [Envíos Turbo](https://developers.mercadolibre.com.co/es_co/envios-turbo) |
| MOD-07-EP-0054 | `GET` | `/orders/2053577644` | lectura/consulta | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0055 | `GET` | `/shipment_labels?shipment_ids=$SHIPPING_ID1,$SHIPPING_ID2&response_type=pdf` | envíos | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0056 | `GET` | `/shipment_labels?shipment_ids=$SHIPPING_ID1,$SHIPPING_ID2&response_type=zpl2` | envíos | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0057 | `GET` | `/shipment_labels?shipment_ids=43308302844&response_type=pdf` | envíos | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0058 | `GET` | `/shipment_labels?shipment_ids=43308302844&response_type=zpl2` | envíos | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0059 | `GET` | `/shipment_statuses` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0060 | `GET` | `/shipments` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0061 | `GET` | `/shipments/$SHIPMENT_ID` | envíos | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0062 | `GET` | `/shipments/$SHIPMENT_ID/carrier` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0063 | `GET` | `/shipments/$SHIPMENT_ID/delays` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0064 | `GET` | `/shipments/$SHIPMENT_ID/items` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0065 | `GET` | `/shipments/$SHIPMENT_ID/lead_time` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0066 | `GET` | `/shipments/$SHIPMENT_ID/payments` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0067 | `GET` | `/shipments/$SHIPMENT_ID/sla` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0068 | `GET` | `/shipments/$SHIPMENT_ID/split` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0069 | `GET` | `/shipments/1111111111/payments` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0070 | `GET` | `/shipments/12345678` | envíos | [Mercado Envíos 1](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-1) |
| MOD-07-EP-0071 | `GET` | `/shipments/1234567899/history` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0072 | `GET` | `/shipments/27691621451/carrier` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0073 | `GET` | `/shipments/30143583389/delays` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0074 | `GET` | `/shipments/40173236996` | envíos | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0075 | `GET` | `/shipments/42469883906` | envíos | [Envíos Turbo](https://developers.mercadolibre.com.co/es_co/envios-turbo) |
| MOD-07-EP-0076 | `GET` | `/shipments/43308302844` | envíos | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0077 | `GET` | `/shipments/43319685225` | envíos | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0078 | `GET` | `/shipments/43416180080/sla` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0079 | `GET` | `/shipments/shipment_id/` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0080 | `GET` | `/shipments/shipment_id/costs` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0081 | `GET` | `/shipments/shipment_id/payments` | envíos | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0082 | `GET` | `/shipping/me1/sites/{site_id}/metrics` | envíos | [Flete Dinámico](https://developers.mercadolibre.com.co/es_co/flete-dinamico) |
| MOD-07-EP-0083 | `GET` | `/shipping/me1/sites/MLB/metrics?seller_id=123456789&ts_from=2023-10-01T00:00:00Z&ts_to=2023-10-31T23:59:59Z` | envíos | [Flete Dinámico](https://developers.mercadolibre.com.co/es_co/flete-dinamico) |
| MOD-07-EP-0084 | `GET` | `/shipping/me1/sites/MLB/metrics?ts_from=2023-10-01T00:00:00Z&ts_to=2023-10-31T23:59:59Z` | envíos | [Flete Dinámico](https://developers.mercadolibre.com.co/es_co/flete-dinamico) |
| MOD-07-EP-0085 | `GET` | `/shipping/me1/v1/quotation/simulate` | envíos | [Flete Dinámico](https://developers.mercadolibre.com.co/es_co/flete-dinamico) |
| MOD-07-EP-0086 | `GET` | `/shipping/me1/v1/tariff/{resource_id}` | envíos | [Flete Dinámico](https://developers.mercadolibre.com.co/es_co/flete-dinamico) |
| MOD-07-EP-0087 | `GET` | `/shipping/me1/v1/tariff/550e8400-e29b-41d4-a716-446655440000` | envíos | [Flete Dinámico](https://developers.mercadolibre.com.co/es_co/flete-dinamico) |
| MOD-07-EP-0088 | `GET` | `/shipping/me1/v1/tariff/template` | envíos | [Flete Dinámico](https://developers.mercadolibre.com.co/es_co/flete-dinamico) |
| MOD-07-EP-0089 | `GET` | `/shipping/me1/v1/tariff/template?site=MLB` | envíos | [Flete Dinámico](https://developers.mercadolibre.com.co/es_co/flete-dinamico) |
| MOD-07-EP-0090 | `GET` | `/shipping/me1/v1/tariff/update` | envíos | [Flete Dinámico](https://developers.mercadolibre.com.co/es_co/flete-dinamico) |
| MOD-07-EP-0091 | `GET` | `/sites/{site_id}/metrics` | lectura/consulta | [Flete Dinámico](https://developers.mercadolibre.com.co/es_co/flete-dinamico) |
| MOD-07-EP-0092 | `GET` | `/sites/$SITE_ID/shipping_methods` | envíos | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| MOD-07-EP-0093 | `GET` | `/soe/bundles` | lectura/consulta | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0094 | `GET` | `/soe/bundles/{bundle_id` | lectura/consulta | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0095 | `GET` | `/soe/bundles/{bundleId` | lectura/consulta | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0096 | `GET` | `/soe/bundles/12345/summary` | lectura/consulta | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0097 | `GET` | `/soe/bundles/12345/volumes` | lectura/consulta | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0098 | `GET` | `/soe/bundles/12345/volumes/search?volume_reference=451235132` | lectura/consulta | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0099 | `GET` | `/soe/bundles/789` | lectura/consulta | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0100 | `GET` | `/soe/bundles/789/file` | lectura/consulta | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0101 | `GET` | `/soe/bundles/label` | lectura/consulta | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0102 | `GET` | `/soe/bundles/search` | lectura/consulta | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0103 | `GET` | `/soe/bundles/search?bundle_reference=ABCD1234` | lectura/consulta | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0104 | `GET` | `/soe/bundles/users/validate` | lectura/consulta | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0105 | `GET` | `/stock/fulfillment/operations/$OPERATION_ID` | lectura/consulta | [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) |
| MOD-07-EP-0106 | `GET` | `/stock/fulfillment/operations/329663159` | lectura/consulta | [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) |
| MOD-07-EP-0107 | `GET` | `/stock/fulfillment/operations/search?seller_id=$SELLER_ID&inventory_id=$INVENTORY_ID&date_from=$aaammdd&date_to=$aaammdd` | lectura/consulta | [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) |
| MOD-07-EP-0108 | `GET` | `/stock/fulfillment/operations/search?seller_id=$SELLER_ID&inventory_id=$INVENTORY_ID&date_from=$aaammdd&date_to=$aaammdd&scroll=YXBpY29yZS1pdGVtcw==:ZHMtYXBpY29yZS1pdGVtcy0wMQ==:DXF1ZXJ5QW5kRmV0Y2gBAAAAABIu7AgWMXl6anF3SU5SMVNaQXFxTkZubHBqQQ==` | lectura/consulta | [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) |
| MOD-07-EP-0109 | `GET` | `/stock/fulfillment/operations/search?seller_id=384324657&inventory_id=DEHW09303&date_from=2020-06-01&date_to=2020-06-30` | lectura/consulta | [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) |
| MOD-07-EP-0110 | `GET` | `/stock/fulfillment/operations/search?seller_id=384741716&inventory_id=NFWV18668&date_from=2020-06-29&date_to=2020-07-28&type=SALE_CONFIRMATION&external_references.shipment_id=1111?` | envíos | [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) |
| MOD-07-EP-0111 | `GET` | `/user-products/{USER_PRODUCT_ID}/contracts/shippability/services` | lectura/consulta | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| MOD-07-EP-0112 | `GET` | `/users` | lectura/consulta | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0113 | `GET` | `/users/{COURIER_USER_ID}/courier-shipment/v1` | envíos | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0114 | `GET` | `/users/$USER_ID/capacity_middleend/$LOGISTIC_TYPE` | lectura/consulta | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0115 | `GET` | `/users/$USER_ID/service/$SERVICE_TYPE/processing_time_tool` | lectura/consulta | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0116 | `GET` | `/users/$USER_ID/shipping_modes` | envíos | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| MOD-07-EP-0117 | `GET` | `/users/$USER_ID/shipping_options/free?dimensions=$DIMENSIONES&verbose=$VERBOSE&item_price=$ITEM_PRICE&listing_type_id=$LISTING_TYPE&mode=$MODE&condition=$CONDITION&logistic_type=$LOGISTIC_TYPE&free_shipping=$FREE_SHIPPING` | envíos | [Costos de envío](https://developers.mercadolibre.com.co/es_co/costos-de-envios) |
| MOD-07-EP-0118 | `GET` | `/users/$USER_ID/shipping_preferences` | envíos | [Envíos Turbo](https://developers.mercadolibre.com.co/es_co/envios-turbo) |
| MOD-07-EP-0119 | `GET` | `/users/$USER_ID/shipping/schedule/$LOGISTIC_TYPE` | envíos | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0120 | `GET` | `/users/12345678/shipping_preferences` | envíos | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| MOD-07-EP-0121 | `GET` | `/users/123456789/capacity_middleend/cross_docking` | lectura/consulta | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0122 | `GET` | `/users/123456789/service/carrier_pickup/processing_time_tool` | lectura/consulta | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0123 | `GET` | `/users/123456789/shipping_modes` | envíos | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| MOD-07-EP-0124 | `GET` | `/users/123456789/shipping/schedule/cross_docking` | envíos | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0125 | `GET` | `/users/244878077/shipping_options/free?dimensions=9x17x22,462&verbose=true&item_price=300&listing_type_id=gold_pro&mode=me2&condition=new&logistic_type=drop_off&free_shipping=True` | envíos | [Costos de envío](https://developers.mercadolibre.com.co/es_co/costos-de-envios) |
| MOD-07-EP-0126 | `POST` | `/catalog_domains/$DOMAIN_ID/shipping_attributes` | mutación | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0127 | `POST` | `/catalog_domains/MLB-AUTOMOTIVE_TIRES/shipping_attributes` | mutación | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0128 | `POST` | `/categories/MCO7159/shipping_preferences` | mutación | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0129 | `POST` | `/categories/MLB278114/attributes` | mutación | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0130 | `POST` | `/flex/sites/{SITE_ID` | mutación | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0131 | `POST` | `/flex/sites/$SITE_ID/items/$ITEM_ID/v2` | mutación | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0132 | `POST` | `/flex/sites/MLA/users/1444885522/courier-shipment/v1` | mutación | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0133 | `POST` | `/flex/sites/MLB/items/MLB1493119403/v2` | mutación | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0134 | `POST` | `/items/$ITEM_ID` | mutación | [Envíos Personalizados](https://developers.mercadolibre.com.co/es_co/envios-personalizados) |
| MOD-07-EP-0135 | `POST` | `/items/$ITEM_ID/shipping_options?zip_code=$ZIP_CODE` | mutación | [Envíos Personalizados](https://developers.mercadolibre.com.co/es_co/envios-personalizados) |
| MOD-07-EP-0136 | `POST` | `/items/MLA1644124644` | mutación | [Mercado Envíos 1](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-1) |
| MOD-07-EP-0137 | `POST` | `/items/MLA803066380/shipping_options?zip_code=$1234` | mutación | [Envíos Personalizados](https://developers.mercadolibre.com.co/es_co/envios-personalizados) |
| MOD-07-EP-0138 | `POST` | `/orders/$ORDER_ID` | mutación | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0139 | `POST` | `/orders/$ORDER_ID?options` | mutación | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0140 | `POST` | `/orders/2053577644` | mutación | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0141 | `POST` | `/shipment_labels?shipment_ids=$SHIPPING_ID1,$SHIPPING_ID2&response_type=pdf` | mutación | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0142 | `POST` | `/shipment_labels?shipment_ids=$SHIPPING_ID1,$SHIPPING_ID2&response_type=zpl2` | mutación | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0143 | `POST` | `/shipment_labels?shipment_ids=43308302844&response_type=pdf` | mutación | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0144 | `POST` | `/shipment_labels?shipment_ids=43308302844&response_type=zpl2` | mutación | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0145 | `POST` | `/shipments` | mutación | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0146 | `POST` | `/shipments/$SHIPMENT_ID` | mutación | [Envíos Personalizados](https://developers.mercadolibre.com.co/es_co/envios-personalizados) |
| MOD-07-EP-0147 | `POST` | `/shipments/$SHIPMENT_ID/split` | mutación | [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) |
| MOD-07-EP-0148 | `POST` | `/shipments/12345678` | mutación | [Mercado Envíos 1](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-1) |
| MOD-07-EP-0149 | `POST` | `/shipments/40173236996` | mutación | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0150 | `POST` | `/shipments/43308302844` | mutación | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0151 | `POST` | `/shipping/me1/v1/quotation/simulate` | mutación | [Flete Dinámico](https://developers.mercadolibre.com.co/es_co/flete-dinamico) |
| MOD-07-EP-0152 | `POST` | `/shipping/me1/v1/tariff/update` | mutación | [Flete Dinámico](https://developers.mercadolibre.com.co/es_co/flete-dinamico) |
| MOD-07-EP-0153 | `POST` | `/soe/bundles` | mutación | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0154 | `POST` | `/soe/bundles/label` | mutación | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0155 | `POST` | `/users/$USER_ID/shipping_preferences` | mutación | [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) |
| MOD-07-EP-0156 | `PUT` | `/flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/coverage/radius/v1` | mutación | [Envíos Turbo](https://developers.mercadolibre.com.co/es_co/envios-turbo) |
| MOD-07-EP-0157 | `PUT` | `/flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/coverage/zones/v1` | mutación | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0158 | `PUT` | `/flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/delivery-ranges/v1` | mutación | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0159 | `PUT` | `/flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/holidays/v1` | mutación | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0160 | `PUT` | `/items/MLA1644124644` | mutación | [Mercado Envíos 1](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-1) |
| MOD-07-EP-0161 | `PUT` | `/nodes/$NETWORK_NODE_ID/capacity_middleend` | mutación | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0162 | `PUT` | `/nodes/$NETWORK_NODE_ID/service/$SERVICE_TYPE/processing_time_tool` | mutación | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0163 | `PUT` | `/nodes/MXP20157465171/service/carrier_pickup/processing_time_tool` | mutación | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0164 | `PUT` | `/nodes/MXP20214899242/capacity_middleend` | mutación | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0165 | `PUT` | `/shipments/$SHIPMENT_ID` | mutación | [Envíos Personalizados](https://developers.mercadolibre.com.co/es_co/envios-personalizados) |
| MOD-07-EP-0166 | `PUT` | `/soe/bundles/{bundle_id` | mutación | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0167 | `PUT` | `/soe/bundles/{bundleId` | mutación | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0168 | `PUT` | `/soe/bundles/12345/volumes` | mutación | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0169 | `PUT` | `/soe/bundles/789` | mutación | [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) |
| MOD-07-EP-0170 | `PUT` | `/users/$USER_ID/capacity_middleend/$LOGISTIC_TYPE` | mutación | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0171 | `PUT` | `/users/$USER_ID/service/$SERVICE_TYPE/processing_time_tool` | mutación | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0172 | `PUT` | `/users/123456789/capacity_middleend/cross_docking` | mutación | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0173 | `PUT` | `/users/123456789/service/carrier_pickup/processing_time_tool` | mutación | [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) |
| MOD-07-EP-0174 | `UNKNOWN` | `/customers/marketplace/sites/{SITE_ID` | lectura/consulta | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| MOD-07-EP-0175 | `UNKNOWN` | `/flex/sites/{SITE_ID` | lectura/consulta | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0176 | `UNKNOWN` | `/shipments/{id}/costs` | envíos | [Mercado Envíos - Costos y cotizaciones](https://developers.mercadolibre.com.co/es_co/mercado-envios-costos-y-cotizaciones) |
| MOD-07-EP-0177 | `UNKNOWN` | `/user-products/{USER_PRODUCT_ID}/contracts/shippability/services` | lectura/consulta | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |
| MOD-07-EP-0178 | `UNKNOWN` | `/users/{COURIER_USER_ID}/courier-shipment/v1` | envíos | [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) |
| MOD-07-EP-0179 | `UNKNOWN` | `/users/{user_id}/shipping_options/free` | envíos | [Mercado Envíos - Costos y cotizaciones](https://developers.mercadolibre.com.co/es_co/mercado-envios-costos-y-cotizaciones) |
| MOD-07-EP-0180 | `UNKNOWN` | `/users/{user_id}/shipping_options/free?` | envíos | [Mercado Envíos - Costos y cotizaciones](https://developers.mercadolibre.com.co/es_co/mercado-envios-costos-y-cotizaciones) |
| MOD-07-EP-0181 | `UNKNOWN` | `/users/$USER_ID/shipping_modes` | envíos | [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) |

### Señales de autenticación/permisos

- [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta): `x-scope`, `Content-Type`, `Authorization`, `scope`, `Bearer`, `ACCESS_TOKEN`, `read`, `write`, `client_id`
- [Costos de envío](https://developers.mercadolibre.com.co/es_co/costos-de-envios): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places): `Authorization`, `X-Version`, `x-version`, `Bearer`, `ACCESS_TOKEN`
- [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex): `Authorization`, `Bearer`, `ACCESS_TOKEN`, `scope`, `access_token`
- [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Envíos Personalizados](https://developers.mercadolibre.com.co/es_co/envios-personalizados): `Authorization`, `Content-Type`, `Accept`, `Bearer`, `ACCESS_TOKEN`
- [Envíos Turbo](https://developers.mercadolibre.com.co/es_co/envios-turbo): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Flete Dinámico](https://developers.mercadolibre.com.co/es_co/flete-dinamico): `x-format-new`, `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios): `Authorization`, `authorization`, `Content-Type`, `Accept`, `x-multichannel`, `X-Format-New`, `Bearer`, `ACCESS_TOKEN`, `access_token`, `client_id`
- [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios): `x-format-new`, `Authorization`, `authorization`, `Bearer`, `ACCESS_TOKEN`, `access_token`
- [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2): `Authorization`, `Content-Type`, `X-Format-New`, `x-format-new`, `Bearer`, `ACCESS_TOKEN`, `client_id`, `access_token`
- [Mercado Envíos 1](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-1): `Authorization`, `Bearer`, `ACCESS_TOKEN`

## Preguntas, ventas y postventa

id: MOD-08

Resumen: 19 páginas fuente, 182 endpoints/rutas, integraciones detectadas: Catálogo, Facturación, Mensajería, Mercado Ads, Mercado Envíos, Mercado Pago, Notificaciones, OAuth.

### Páginas fuente

| ID | Página | Estado | Endpoints | Fechas visibles |
|---|---|---|---:|---|
| MOD-08-PAGE-001 | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) | `ok` | 28 | 2024-01-01 |
| MOD-08-PAGE-002 | [Cargar y Obtener Facturas](https://developers.mercadolibre.com.co/es_co/cargar-factura) | `ok` | 12 | — |
| MOD-08-PAGE-003 | [Crear nota informativa](https://developers.mercadolibre.com.co/es_co/notas-de-packs) | `ok` | 5 | — |
| MOD-08-PAGE-004 | [Datos de Facturación](https://developers.mercadolibre.com.co/es_co/facturacion) | `ok` | 4 | — |
| MOD-08-PAGE-005 | [Descarga de documento legal](https://developers.mercadolibre.com.co/es_co/reportes-descargas) | `ok` | 10 | 2021-08-01, 2021-08-04 |
| MOD-08-PAGE-006 | [Estados de órdenes y seguimiento](https://developers.mercadolibre.com.co/es_co/estados-de-ordenes-me1) | `ok` | 6 | — |
| MOD-08-PAGE-007 | [Facturación / Billing info](https://developers.mercadolibre.com.co/es_co/facturacion-billing-info) | `ok` | 5 | — |
| MOD-08-PAGE-008 | [Feedback sobre venta](https://developers.mercadolibre.com.co/es_co/feedback-sobre-venta) | `ok` | 11 | — |
| MOD-08-PAGE-009 | [Gestión de packs](https://developers.mercadolibre.com.co/es_co/gestion-packs) | `ok` | 12 | — |
| MOD-08-PAGE-010 | [Gestiona pagos](https://developers.mercadolibre.com.co/es_co/pagos) | `ok` | 0 | — |
| MOD-08-PAGE-011 | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) | `ok` | 36 | — |
| MOD-08-PAGE-012 | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) | `ok` | 21 | 01/01/2001 |
| MOD-08-PAGE-013 | [Notas en órdenes](https://developers.mercadolibre.com.co/es_co/notas-en-ordenes) | `ok` | 6 | — |
| MOD-08-PAGE-014 | [Preguntas Frecuentes (FAQs)](https://developers.mercadolibre.com.co/es_co/faq-preguntas-frecuentes) | `ok` | 0 | — |
| MOD-08-PAGE-015 | [Preguntas y Respuestas](https://developers.mercadolibre.com.co/es_co/preguntas-y-respuestas) | `ok` | 13 | — |
| MOD-08-PAGE-016 | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) | `ok` | 24 | 2024-11-01, 2021-06-01, 2024-05-01, 2023-03-01, 2022-10-01 |
| MOD-08-PAGE-017 | [Reporte de pagos](https://developers.mercadolibre.com.co/es_co/reportes-pagos) | `ok` | 4 | 2023-08-01 |
| MOD-08-PAGE-018 | [Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/reportes-de-facturacion) | `ok` | 7 | 2023-06-01, 2020-02-19, 2020-03-18, 2020-03-01, 2020-03-24, 2021-06-01, 2021-06-02, 2021-05-03, 2023-10-01, 2023-06-19, 2023-07-18, 2023-07-24, 2023-07-01 |
| MOD-08-PAGE-019 | [Resumen de Percepciones](https://developers.mercadolibre.com.co/es_co/resumen-percepciones) | `ok` | 6 | 2021-08-01, 2021-11-29, 2021-10-30 |

### Endpoints/rutas del módulo

| ID | Método | Ruta | Riesgo/área | Fuente |
|---|---|---|---|---|
| MOD-08-EP-0001 | `DELETE` | `/orders/$ORDER_ID/notes/$NOTE_ID` | destructivo | [Notas en órdenes](https://developers.mercadolibre.com.co/es_co/notas-en-ordenes) |
| MOD-08-EP-0002 | `DELETE` | `/packs/$PACK_ID/fiscal_documents` | destructivo | [Cargar y Obtener Facturas](https://developers.mercadolibre.com.co/es_co/cargar-factura) |
| MOD-08-EP-0003 | `DELETE` | `/packs/2000000089077943/fiscal_documents` | destructivo | [Cargar y Obtener Facturas](https://developers.mercadolibre.com.co/es_co/cargar-factura) |
| MOD-08-EP-0004 | `DELETE` | `/questions/:id:` | destructivo | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0005 | `DELETE` | `/questions/$QUESTION_ID` | destructivo | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0006 | `DELETE` | `/users/$SELLER_ID/questions_blacklist/$USER_ID` | destructivo | [Preguntas y Respuestas](https://developers.mercadolibre.com.co/es_co/preguntas-y-respuestas) |
| MOD-08-EP-0007 | `GET` | `/answers` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0008 | `GET` | `/answers/:` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0009 | `GET` | `/billing/integration/group/ML/order/details?order_ids={order` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0010 | `GET` | `/billing/integration/group/ML/order/details?order_ids=$ORDER_ID` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0011 | `GET` | `/billing/integration/group/ML/order/details?order_ids=1234567890000` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0012 | `GET` | `/billing/integration/group/ML/perceptions/details` | lectura/consulta | [Resumen de Percepciones](https://developers.mercadolibre.com.co/es_co/resumen-percepciones) |
| MOD-08-EP-0013 | `GET` | `/billing/integration/group/ML/perceptions/details?document_id=333555777&tax_type=CIVA&offset=1&limit=2&currency=USD` | lectura/consulta | [Resumen de Percepciones](https://developers.mercadolibre.com.co/es_co/resumen-percepciones) |
| MOD-08-EP-0014 | `GET` | `/billing/integration/group/MP/perceptions/details` | lectura/consulta | [Resumen de Percepciones](https://developers.mercadolibre.com.co/es_co/resumen-percepciones) |
| MOD-08-EP-0015 | `GET` | `/billing/integration/group/MP/perceptions/details?document_id=333555777&tax_type=CIVAMP&tax_id=12345&offset=1&limit=2&currency=USD` | lectura/consulta | [Resumen de Percepciones](https://developers.mercadolibre.com.co/es_co/resumen-percepciones) |
| MOD-08-EP-0016 | `GET` | `/billing/integration/legal_document/$FILE_ID` | lectura/consulta | [Descarga de documento legal](https://developers.mercadolibre.com.co/es_co/reportes-descargas) |
| MOD-08-EP-0017 | `GET` | `/billing/integration/legal_document/1234_FE_MEPF00869625_pdf` | lectura/consulta | [Descarga de documento legal](https://developers.mercadolibre.com.co/es_co/reportes-descargas) |
| MOD-08-EP-0018 | `GET` | `/billing/integration/monthly/periods` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0019 | `GET` | `/billing/integration/monthly/periods?group=MP&document_type=BILL&offset=1&limit=2` | lectura/consulta | [Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/reportes-de-facturacion) |
| MOD-08-EP-0020 | `GET` | `/billing/integration/payment/$PAYMENT_ID/charges` | lectura/consulta | [Reporte de pagos](https://developers.mercadolibre.com.co/es_co/reportes-pagos) |
| MOD-08-EP-0021 | `GET` | `/billing/integration/payment/111111abcde/charges` | lectura/consulta | [Reporte de pagos](https://developers.mercadolibre.com.co/es_co/reportes-pagos) |
| MOD-08-EP-0022 | `GET` | `/billing/integration/periods/key/{key}/documents` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0023 | `GET` | `/billing/integration/periods/key/{KEY}/group/ML/details` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0024 | `GET` | `/billing/integration/periods/key/{key}/group/ML/details?limit=1000&from_id=0` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0025 | `GET` | `/billing/integration/periods/key/{KEY}/group/MP/details` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0026 | `GET` | `/billing/integration/periods/key/{key}/summary/details` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0027 | `GET` | `/billing/integration/periods/key/$KEY/documents` | lectura/consulta | [Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/reportes-de-facturacion) |
| MOD-08-EP-0028 | `GET` | `/billing/integration/periods/key/$KEY/group/ML/details` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0029 | `GET` | `/billing/integration/periods/key/$KEY/group/ML/flex/details` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0030 | `GET` | `/billing/integration/periods/key/$KEY/group/ML/full/details` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0031 | `GET` | `/billing/integration/periods/key/$KEY/group/ML/insurtech/details` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0032 | `GET` | `/billing/integration/periods/key/$KEY/group/ML/payment/details` | lectura/consulta | [Reporte de pagos](https://developers.mercadolibre.com.co/es_co/reportes-pagos) |
| MOD-08-EP-0033 | `GET` | `/billing/integration/periods/key/$KEY/group/MP/details` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0034 | `GET` | `/billing/integration/periods/key/$KEY/perceptions/summary` | lectura/consulta | [Resumen de Percepciones](https://developers.mercadolibre.com.co/es_co/resumen-percepciones) |
| MOD-08-EP-0035 | `GET` | `/billing/integration/periods/key/$KEY/reports` | lectura/consulta | [Descarga de documento legal](https://developers.mercadolibre.com.co/es_co/reportes-descargas) |
| MOD-08-EP-0036 | `GET` | `/billing/integration/periods/key/$KEY/summary/details` | lectura/consulta | [Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/reportes-de-facturacion) |
| MOD-08-EP-0037 | `GET` | `/billing/integration/periods/key/2021-06-01/documents?group=MP&document_type=BILL&limit=1` | lectura/consulta | [Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/reportes-de-facturacion) |
| MOD-08-EP-0038 | `GET` | `/billing/integration/periods/key/2021-06-01/group/ML/details?document_type=BILL&limit=1` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0039 | `GET` | `/billing/integration/periods/key/2021-08-01/perceptions/summary?group=MP` | lectura/consulta | [Resumen de Percepciones](https://developers.mercadolibre.com.co/es_co/resumen-percepciones) |
| MOD-08-EP-0040 | `GET` | `/billing/integration/periods/key/2021-08-01/reports` | lectura/consulta | [Descarga de documento legal](https://developers.mercadolibre.com.co/es_co/reportes-descargas) |
| MOD-08-EP-0041 | `GET` | `/billing/integration/periods/key/2022-10-01/group/ML/insurtech/details?document_type=BILL&limit=1` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0042 | `GET` | `/billing/integration/periods/key/2023-03-01/group/ML/flex/details?document_type=BILL&limit=1` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0043 | `GET` | `/billing/integration/periods/key/2023-03-01/group/ML/full/details?document_type=BILL&limit=1` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0044 | `GET` | `/billing/integration/periods/key/2023-08-01/group/ML/payment/details?limit=1` | lectura/consulta | [Reporte de pagos](https://developers.mercadolibre.com.co/es_co/reportes-pagos) |
| MOD-08-EP-0045 | `GET` | `/billing/integration/periods/key/2023-10-01/summary/details` | lectura/consulta | [Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/reportes-de-facturacion) |
| MOD-08-EP-0046 | `GET` | `/billing/integration/periods/key/2024-05-01/group/MP/details?document_type=BILL&limit=1` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0047 | `GET` | `/billing/integration/periods/key/2024-11-01/group/MP/details?document_type=BILL&limit=1000&from_id=0` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0048 | `GET` | `/billing/integration/periods/key/2024-11-01/group/MP/details?document_type=BILL&limit=1000&from_id=12345678` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0049 | `GET` | `/billing/integration/reports/$FILE_ID` | lectura/consulta | [Descarga de documento legal](https://developers.mercadolibre.com.co/es_co/reportes-descargas) |
| MOD-08-EP-0050 | `GET` | `/billing/integration/reports/$FILE_ID/status` | lectura/consulta | [Descarga de documento legal](https://developers.mercadolibre.com.co/es_co/reportes-descargas) |
| MOD-08-EP-0051 | `GET` | `/billing/integration/reports/ML-report-BILL-2021-08-04-11119999-CSV-v2?document_type=BILL` | lectura/consulta | [Descarga de documento legal](https://developers.mercadolibre.com.co/es_co/reportes-descargas) |
| MOD-08-EP-0052 | `GET` | `/billing/integration/reports/ML-report-BILL-2021-08-04-11119999-CSV-v2/status?document_type=BILL` | lectura/consulta | [Descarga de documento legal](https://developers.mercadolibre.com.co/es_co/reportes-descargas) |
| MOD-08-EP-0053 | `GET` | `/billing/monthly/periods` | lectura/consulta | [Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/reportes-de-facturacion) |
| MOD-08-EP-0054 | `GET` | `/block-api/search/users/72641919?type=blocked_by_questions` | lectura/consulta | [Preguntas y Respuestas](https://developers.mercadolibre.com.co/es_co/preguntas-y-respuestas) |
| MOD-08-EP-0055 | `GET` | `/currency_conversions/search?from=$CURRENCY_ID&to=$CURRENCY_ID` | lectura/consulta | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) |
| MOD-08-EP-0056 | `GET` | `/currency_conversions/search?from=ARS&to=BRL` | lectura/consulta | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) |
| MOD-08-EP-0057 | `GET` | `/feedback/$FEEDBACK_ID` | lectura/consulta | [Feedback sobre venta](https://developers.mercadolibre.com.co/es_co/feedback-sobre-venta) |
| MOD-08-EP-0058 | `GET` | `/feedback/9041207884458` | lectura/consulta | [Feedback sobre venta](https://developers.mercadolibre.com.co/es_co/feedback-sobre-venta) |
| MOD-08-EP-0059 | `GET` | `/feedbacks/$feedback_id` | lectura/consulta | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) |
| MOD-08-EP-0060 | `GET` | `/items/:id` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0061 | `GET` | `/items/{item_id}/sale_price` | pricing | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0062 | `GET` | `/items/{item_id}/sale_price:` | pricing | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0063 | `GET` | `/moderations/infractions` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0064 | `GET` | `/my/received_questions/search` | lectura/consulta | [Preguntas y Respuestas](https://developers.mercadolibre.com.co/es_co/preguntas-y-respuestas) |
| MOD-08-EP-0065 | `GET` | `/orders:` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0066 | `GET` | `/orders/:id` | lectura/consulta | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) |
| MOD-08-EP-0067 | `GET` | `/orders/{id}/discounts` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0068 | `GET` | `/orders/{order_id}/discounts:` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0069 | `GET` | `/orders/$id/discounts` | lectura/consulta | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) |
| MOD-08-EP-0070 | `GET` | `/orders/$ORDER_ID/discounts` | lectura/consulta | [Gestión de packs](https://developers.mercadolibre.com.co/es_co/gestion-packs) |
| MOD-08-EP-0071 | `GET` | `/orders/$order_id/feedback` | lectura/consulta | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) |
| MOD-08-EP-0072 | `GET` | `/orders/$ORDER_ID/feedback` | lectura/consulta | [Feedback sobre venta](https://developers.mercadolibre.com.co/es_co/feedback-sobre-venta) |
| MOD-08-EP-0073 | `GET` | `/orders/$ORDER_ID/notes` | lectura/consulta | [Notas en órdenes](https://developers.mercadolibre.com.co/es_co/notas-en-ordenes) |
| MOD-08-EP-0074 | `GET` | `/orders/$ORDER_ID/product` | lectura/consulta | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) |
| MOD-08-EP-0075 | `GET` | `/orders/2000003508419013` | lectura/consulta | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) |
| MOD-08-EP-0076 | `GET` | `/orders/2000003508419013/discounts` | lectura/consulta | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) |
| MOD-08-EP-0077 | `GET` | `/orders/2000003508419013/feedback` | lectura/consulta | [Feedback sobre venta](https://developers.mercadolibre.com.co/es_co/feedback-sobre-venta) |
| MOD-08-EP-0078 | `GET` | `/orders/2000003508419013/shipments` | envíos | [Estados de órdenes y seguimiento](https://developers.mercadolibre.com.co/es_co/estados-de-ordenes-me1) |
| MOD-08-EP-0079 | `GET` | `/orders/2000008779458474` | lectura/consulta | [Gestión de packs](https://developers.mercadolibre.com.co/es_co/gestion-packs) |
| MOD-08-EP-0080 | `GET` | `/orders/2000010733434062` | lectura/consulta | [Datos de Facturación](https://developers.mercadolibre.com.co/es_co/facturacion) |
| MOD-08-EP-0081 | `GET` | `/orders/billing-info/$SITE_ID/$BILLING_INFO.ID` | lectura/consulta | [Datos de Facturación](https://developers.mercadolibre.com.co/es_co/facturacion) |
| MOD-08-EP-0082 | `GET` | `/orders/billing-info/MLB/677487519924852462` | lectura/consulta | [Datos de Facturación](https://developers.mercadolibre.com.co/es_co/facturacion) |
| MOD-08-EP-0083 | `GET` | `/orders/search` | lectura/consulta | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) |
| MOD-08-EP-0084 | `GET` | `/orders/search?seller=$SELLER_ID&order.date_created.from=2015-07-01T00:00:00.000-00:00&order.date_created.to=2015-07-31T00:00:00.000-00:00` | lectura/consulta | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) |
| MOD-08-EP-0085 | `GET` | `/orders/search?seller=$SELLER_ID&order.status=paid` | lectura/consulta | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) |
| MOD-08-EP-0086 | `GET` | `/orders/search?seller=$SELLER_ID&order.status=paid&sort=date_desc` | lectura/consulta | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) |
| MOD-08-EP-0087 | `GET` | `/orders/search?seller=89660613&q=2032217210` | lectura/consulta | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) |
| MOD-08-EP-0088 | `GET` | `/packs:` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0089 | `GET` | `/packs/$PACK_ID` | lectura/consulta | [Gestión de packs](https://developers.mercadolibre.com.co/es_co/gestion-packs) |
| MOD-08-EP-0090 | `GET` | `/packs/$PACK_ID/fiscal_documents` | lectura/consulta | [Cargar y Obtener Facturas](https://developers.mercadolibre.com.co/es_co/cargar-factura) |
| MOD-08-EP-0091 | `GET` | `/packs/$PACK_ID/fiscal_documents/$FISCAL_DOCUMENT_ID` | lectura/consulta | [Cargar y Obtener Facturas](https://developers.mercadolibre.com.co/es_co/cargar-factura) |
| MOD-08-EP-0092 | `GET` | `/packs/2000000089077943/fiscal_documents` | lectura/consulta | [Cargar y Obtener Facturas](https://developers.mercadolibre.com.co/es_co/cargar-factura) |
| MOD-08-EP-0093 | `GET` | `/packs/2000000089077943/fiscal_documents/415460047_a96d8dea-38cd-4402-938e-80a1c134fc5d` | lectura/consulta | [Cargar y Obtener Facturas](https://developers.mercadolibre.com.co/es_co/cargar-factura) |
| MOD-08-EP-0094 | `GET` | `/packs/2000006181551917` | lectura/consulta | [Gestión de packs](https://developers.mercadolibre.com.co/es_co/gestion-packs) |
| MOD-08-EP-0095 | `GET` | `/payments/{id}` | lectura/consulta | [Gestión de packs](https://developers.mercadolibre.com.co/es_co/gestion-packs) |
| MOD-08-EP-0096 | `GET` | `/questions` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0097 | `GET` | `/questions:` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0098 | `GET` | `/questions/:id` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0099 | `GET` | `/questions/:id:` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0100 | `GET` | `/questions/$QUESTION_ID` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0101 | `GET` | `/questions/11751825075?api_version=4` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0102 | `GET` | `/questions/3957150025` | lectura/consulta | [Preguntas y Respuestas](https://developers.mercadolibre.com.co/es_co/preguntas-y-respuestas) |
| MOD-08-EP-0103 | `GET` | `/questions/hidden:` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0104 | `GET` | `/questions/search` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0105 | `GET` | `/questions/search?item_id=$ITEM_ID&api_version=4` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0106 | `GET` | `/questions/search?item_id=MLA608007087` | lectura/consulta | [Preguntas y Respuestas](https://developers.mercadolibre.com.co/es_co/preguntas-y-respuestas) |
| MOD-08-EP-0107 | `GET` | `/questions/search?item=$ITEM_ID` | lectura/consulta | [Preguntas y Respuestas](https://developers.mercadolibre.com.co/es_co/preguntas-y-respuestas) |
| MOD-08-EP-0108 | `GET` | `/questions/search?item=$ITEM_ID&api_version=4` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0109 | `GET` | `/questions/search?item=$ITEM_ID&from=$CUST_ID&api_version=4` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0110 | `GET` | `/questions/search?item=MLB1623490410&api_version=4` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0111 | `GET` | `/questions/search?seller_id=$SELLER_ID&api_version=4` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0112 | `GET` | `/questions/search?seller_id=$SELLER_ID&sort_fields=item_id,date_created&api_version=4` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0113 | `GET` | `/questions/search?seller_id=$SELLER_ID&sort_fields=item_id,date_created&sort_types=ASC&api_version=4` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0114 | `GET` | `/questions/search?seller_id=419059118&api_version=4` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0115 | `GET` | `/seller-promotions/offers/{offer_id}:` | lectura/consulta | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0116 | `GET` | `/shipments:` | envíos | [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) |
| MOD-08-EP-0117 | `GET` | `/shipments/:shipping_id` | envíos | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) |
| MOD-08-EP-0118 | `GET` | `/shipments/$SHIPMENT_ID/process/ready_to_ship` | envíos | [Gestión de packs](https://developers.mercadolibre.com.co/es_co/gestion-packs) |
| MOD-08-EP-0119 | `GET` | `/shipments/$SHIPMENT_ID/seller_notifications` | envíos | [Estados de órdenes y seguimiento](https://developers.mercadolibre.com.co/es_co/estados-de-ordenes-me1) |
| MOD-08-EP-0120 | `GET` | `/shipments/28264263908/seller_notifications` | envíos | [Estados de órdenes y seguimiento](https://developers.mercadolibre.com.co/es_co/estados-de-ordenes-me1) |
| MOD-08-EP-0121 | `GET` | `/shipments/43664723386/process/ready_to_ship` | envíos | [Gestión de packs](https://developers.mercadolibre.com.co/es_co/gestion-packs) |
| MOD-08-EP-0122 | `GET` | `/shipments/shipping.id` | envíos | [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) |
| MOD-08-EP-0123 | `GET` | `/users/:id` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0124 | `GET` | `/users/$SELLER_ID/questions_blacklist/$USER_ID` | lectura/consulta | [Preguntas y Respuestas](https://developers.mercadolibre.com.co/es_co/preguntas-y-respuestas) |
| MOD-08-EP-0125 | `GET` | `/users/$USER_ID/questions/response_time` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0126 | `GET` | `/users/1111111/questions/response_time` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0127 | `POST` | `/answers` | mutación | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0128 | `POST` | `/answers/:` | mutación | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0129 | `POST` | `/billing/integration/periods/key/$KEY/reports` | mutación | [Descarga de documento legal](https://developers.mercadolibre.com.co/es_co/reportes-descargas) |
| MOD-08-EP-0130 | `POST` | `/billing/integration/periods/key/2021-08-01/reports` | mutación | [Descarga de documento legal](https://developers.mercadolibre.com.co/es_co/reportes-descargas) |
| MOD-08-EP-0131 | `POST` | `/feedback/$FEEDBACK_ID` | mutación | [Feedback sobre venta](https://developers.mercadolibre.com.co/es_co/feedback-sobre-venta) |
| MOD-08-EP-0132 | `POST` | `/feedback/$FEEDBACK_ID/reply` | mutación | [Feedback sobre venta](https://developers.mercadolibre.com.co/es_co/feedback-sobre-venta) |
| MOD-08-EP-0133 | `POST` | `/feedback/9041207884458` | mutación | [Feedback sobre venta](https://developers.mercadolibre.com.co/es_co/feedback-sobre-venta) |
| MOD-08-EP-0134 | `POST` | `/my/questions/hidden:` | mutación | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0135 | `POST` | `/orders/` | mutación | [Cargar y Obtener Facturas](https://developers.mercadolibre.com.co/es_co/cargar-factura) |
| MOD-08-EP-0136 | `POST` | `/orders/$order_id/feedback` | mutación | [Feedback sobre venta](https://developers.mercadolibre.com.co/es_co/feedback-sobre-venta) |
| MOD-08-EP-0137 | `POST` | `/orders/$ORDER_ID/feedback` | mutación | [Feedback sobre venta](https://developers.mercadolibre.com.co/es_co/feedback-sobre-venta) |
| MOD-08-EP-0138 | `POST` | `/orders/$ORDER_ID/notes` | mutación | [Notas en órdenes](https://developers.mercadolibre.com.co/es_co/notas-en-ordenes) |
| MOD-08-EP-0139 | `POST` | `/orders/$ORDER_ID/notes/$NOTE_ID` | mutación | [Notas en órdenes](https://developers.mercadolibre.com.co/es_co/notas-en-ordenes) |
| MOD-08-EP-0140 | `POST` | `/orders/2000003508419013/feedback` | mutación | [Feedback sobre venta](https://developers.mercadolibre.com.co/es_co/feedback-sobre-venta) |
| MOD-08-EP-0141 | `POST` | `/packs` | mutación | [Cargar y Obtener Facturas](https://developers.mercadolibre.com.co/es_co/cargar-factura) |
| MOD-08-EP-0142 | `POST` | `/packs/$PACK_ID/fiscal_documents` | mutación | [Cargar y Obtener Facturas](https://developers.mercadolibre.com.co/es_co/cargar-factura) |
| MOD-08-EP-0143 | `POST` | `/packs/$PACK_ID/fiscal_documents/$FISCAL_DOCUMENT_ID` | mutación | [Cargar y Obtener Facturas](https://developers.mercadolibre.com.co/es_co/cargar-factura) |
| MOD-08-EP-0144 | `POST` | `/packs/2000000089077943/fiscal_documents` | mutación | [Cargar y Obtener Facturas](https://developers.mercadolibre.com.co/es_co/cargar-factura) |
| MOD-08-EP-0145 | `POST` | `/packs/2000000089077943/fiscal_documents/415460047_a96d8dea-38cd-4402-938e-80a1c134fc5d` | mutación | [Cargar y Obtener Facturas](https://developers.mercadolibre.com.co/es_co/cargar-factura) |
| MOD-08-EP-0146 | `POST` | `/questions` | mutación | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0147 | `POST` | `/shipments/$SHIPMENT_ID/process/ready_to_ship` | mutación | [Gestión de packs](https://developers.mercadolibre.com.co/es_co/gestion-packs) |
| MOD-08-EP-0148 | `POST` | `/shipments/$SHIPMENT_ID/seller_notifications` | mutación | [Estados de órdenes y seguimiento](https://developers.mercadolibre.com.co/es_co/estados-de-ordenes-me1) |
| MOD-08-EP-0149 | `POST` | `/shipments/28264263908/seller_notifications` | mutación | [Estados de órdenes y seguimiento](https://developers.mercadolibre.com.co/es_co/estados-de-ordenes-me1) |
| MOD-08-EP-0150 | `POST` | `/shipments/43664723386/process/ready_to_ship` | mutación | [Gestión de packs](https://developers.mercadolibre.com.co/es_co/gestion-packs) |
| MOD-08-EP-0151 | `POST` | `/users/$CUST_ID/order_blacklist` | mutación | [Notas en órdenes](https://developers.mercadolibre.com.co/es_co/notas-en-ordenes) |
| MOD-08-EP-0152 | `PUT` | `/orders/$ORDER_ID/notes/$NOTE_ID` | mutación | [Notas en órdenes](https://developers.mercadolibre.com.co/es_co/notas-en-ordenes) |
| MOD-08-EP-0153 | `UNKNOWN` | `/billing` | lectura/consulta | [Facturación / Billing info](https://developers.mercadolibre.com.co/es_co/facturacion-billing-info) |
| MOD-08-EP-0154 | `UNKNOWN` | `/billing/integration/group/ML/order/details` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0155 | `UNKNOWN` | `/billing/integration/group/ML/order/details?order_ids={order}` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0156 | `UNKNOWN` | `/billing/integration/legal_document/{file_id}` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0157 | `UNKNOWN` | `/billing/integration/monthly/periods` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0158 | `UNKNOWN` | `/billing/integration/periods/key/{key}/documents` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0159 | `UNKNOWN` | `/billing/integration/periods/key/{key}/group/ML/details` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0160 | `UNKNOWN` | `/billing/integration/periods/key/{KEY}/group/ML/details` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0161 | `UNKNOWN` | `/billing/integration/periods/key/{key}/group/ML/details?limit=1000&from_id=0` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0162 | `UNKNOWN` | `/billing/integration/periods/key/{key}/group/ML/payment/details` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0163 | `UNKNOWN` | `/billing/integration/periods/key/{key}/group/MP/details` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0164 | `UNKNOWN` | `/billing/integration/periods/key/{KEY}/group/MP/details` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0165 | `UNKNOWN` | `/billing/integration/periods/key/{key}/perceptions/summary` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0166 | `UNKNOWN` | `/billing/integration/periods/key/{key}/summary/details` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0167 | `UNKNOWN` | `/billing/integration/reports/{file_id}` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0168 | `UNKNOWN` | `/feedback/$FEEDBACK_ID` | lectura/consulta | [Feedback sobre venta](https://developers.mercadolibre.com.co/es_co/feedback-sobre-venta) |
| MOD-08-EP-0169 | `UNKNOWN` | `/items/:id` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |
| MOD-08-EP-0170 | `UNKNOWN` | `/items/{item_id}/sale_price` | pricing | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0171 | `UNKNOWN` | `/orders/{id}/discounts` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0172 | `UNKNOWN` | `/orders/{order_id}/billing_info` | lectura/consulta | [Facturación / Billing info](https://developers.mercadolibre.com.co/es_co/facturacion-billing-info) |
| MOD-08-EP-0173 | `UNKNOWN` | `/orders/{order_id}/discounts` | lectura/consulta | [Facturación / Billing info](https://developers.mercadolibre.com.co/es_co/facturacion-billing-info) |
| MOD-08-EP-0174 | `UNKNOWN` | `/orders/billing-info/{site_id}/{billing_info_id}` | lectura/consulta | [Facturación / Billing info](https://developers.mercadolibre.com.co/es_co/facturacion-billing-info) |
| MOD-08-EP-0175 | `UNKNOWN` | `/packs` | lectura/consulta | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0176 | `UNKNOWN` | `/packs/{packID` | lectura/consulta | [Crear nota informativa](https://developers.mercadolibre.com.co/es_co/notas-de-packs) |
| MOD-08-EP-0177 | `UNKNOWN` | `/packs/$PACK_ID/notes` | lectura/consulta | [Crear nota informativa](https://developers.mercadolibre.com.co/es_co/notas-de-packs) |
| MOD-08-EP-0178 | `UNKNOWN` | `/packs/20000154314645307/notes` | lectura/consulta | [Crear nota informativa](https://developers.mercadolibre.com.co/es_co/notas-de-packs) |
| MOD-08-EP-0179 | `UNKNOWN` | `/packs/20000154314645307/notes?access_token={accessToken` | lectura/consulta | [Crear nota informativa](https://developers.mercadolibre.com.co/es_co/notas-de-packs) |
| MOD-08-EP-0180 | `UNKNOWN` | `/packs/20000154314645307/notes/681bace17c69893ae6558c52?access_token={accessToken` | lectura/consulta | [Crear nota informativa](https://developers.mercadolibre.com.co/es_co/notas-de-packs) |
| MOD-08-EP-0181 | `UNKNOWN` | `/shipments` | envíos | [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) |
| MOD-08-EP-0182 | `UNKNOWN` | `/users/:id` | lectura/consulta | [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) |

### Señales de autenticación/permisos

- [Cargar y Obtener Facturas](https://developers.mercadolibre.com.co/es_co/cargar-factura): `Content-Type`, `Authorization`, `content-type`, `access_token`, `Bearer`, `ACCESS_TOKEN`
- [Crear nota informativa](https://developers.mercadolibre.com.co/es_co/notas-de-packs): `Authorization`, `Content-Type`, `X-Public`, `Bearer`, `ACCESS_TOKEN`, `access_token`
- [Datos de Facturación](https://developers.mercadolibre.com.co/es_co/facturacion): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Descarga de documento legal](https://developers.mercadolibre.com.co/es_co/reportes-descargas): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Estados de órdenes y seguimiento](https://developers.mercadolibre.com.co/es_co/estados-de-ordenes-me1): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Facturación / Billing info](https://developers.mercadolibre.com.co/es_co/facturacion-billing-info): `Content-Type`
- [Feedback sobre venta](https://developers.mercadolibre.com.co/es_co/feedback-sobre-venta): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Gestión de packs](https://developers.mercadolibre.com.co/es_co/gestion-packs): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas): `Authorization`, `X-Content-Missing`, `Bearer`, `ACCESS_TOKEN`, `access_token`
- [Notas en órdenes](https://developers.mercadolibre.com.co/es_co/notas-en-ordenes): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Preguntas y Respuestas](https://developers.mercadolibre.com.co/es_co/preguntas-y-respuestas): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Reporte de pagos](https://developers.mercadolibre.com.co/es_co/reportes-pagos): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/reportes-de-facturacion): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Resumen de Percepciones](https://developers.mercadolibre.com.co/es_co/resumen-percepciones): `Authorization`, `Bearer`, `ACCESS_TOKEN`

## Productos, ítems y catálogo

id: MOD-09

Resumen: 45 páginas fuente, 561 endpoints/rutas, integraciones detectadas: Catálogo, Facturación, Mensajería, Mercado Ads, Mercado Envíos, Mercado Pago, Notificaciones, OAuth.

### Páginas fuente

| ID | Página | Estado | Endpoints | Fechas visibles |
|---|---|---|---:|---|
| MOD-09-PAGE-001 | [Actualiza tus publicaciones](https://developers.mercadolibre.com.co/es_co/actualiza-tus-publicaciones) | `ok` | 5 | 12 de marzo de 2026, 06/11/2025 |
| MOD-09-PAGE-002 | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) | `ok` | 17 | — |
| MOD-09-PAGE-003 | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) | `ok` | 35 | — |
| MOD-09-PAGE-004 | [Calidad de publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones) | `ok` | 8 | — |
| MOD-09-PAGE-005 | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) | `ok` | 22 | — |
| MOD-09-PAGE-006 | [Categorización de productos](https://developers.mercadolibre.com.co/es_co/categoriza-productos) | `ok` | 9 | 14 de diciembre de 2022 |
| MOD-09-PAGE-007 | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) | `ok` | 75 | 15/07/2026 |
| MOD-09-PAGE-008 | [Competencia](https://developers.mercadolibre.com.co/es_co/competencia-en-catalogo) | `ok` | 7 | — |
| MOD-09-PAGE-009 | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) | `ok` | 19 | — |
| MOD-09-PAGE-010 | [Descripción de productos](https://developers.mercadolibre.com.co/es_co/descripcion-de-articulos) | `ok` | 5 | — |
| MOD-09-PAGE-011 | [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item) | `ok` | 17 | — |
| MOD-09-PAGE-012 | [Diagnóstico de imágenes](https://developers.mercadolibre.com.co/es_co/diagnostico-imagenes) | `ok` | 1 | — |
| MOD-09-PAGE-013 | [Elegibilidad de catálogo](https://developers.mercadolibre.com.co/es_co/elegibilidad-catalogo) | `ok` | 11 | — |
| MOD-09-PAGE-014 | [Gestión de stock en convivencia Full/Flex (MLA y MLC)](https://developers.mercadolibre.com.co/es_co/convivencia-full-y-flex) | `ok` | 8 | — |
| MOD-09-PAGE-015 | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) | `ok` | 38 | — |
| MOD-09-PAGE-016 | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) | `ok` | 32 | 18 de marzo de 2026 |
| MOD-09-PAGE-017 | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) | `ok` | 23 | — |
| MOD-09-PAGE-018 | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) | `ok` | 33 | 21 de julio de 2026 |
| MOD-09-PAGE-019 | [Gestionar referencias de precios](https://developers.mercadolibre.com.co/es_co/referencias-de-precios) | `ok` | 7 | 2024-06-16, 2024-07-20 |
| MOD-09-PAGE-020 | [Guía para productos](https://developers.mercadolibre.com.co/es_co/guia-para-producto) | `ok` | 0 | — |
| MOD-09-PAGE-021 | [Identificadores de productos](https://developers.mercadolibre.com.co/es_co/identificadores-de-productos) | `ok` | 8 | — |
| MOD-09-PAGE-022 | [Imágenes en publicaciones](https://developers.mercadolibre.com.co/es_co/trabajar-con-imagenes) | `ok` | 10 | — |
| MOD-09-PAGE-023 | [Imágenes y moderaciones](https://developers.mercadolibre.com.co/es_co/imagenes-y-moderaciones) | `ok` | 0 | — |
| MOD-09-PAGE-024 | [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) | `ok` | 21 | — |
| MOD-09-PAGE-025 | [Moderaciones de imágenes](https://developers.mercadolibre.com.co/es_co/moderaciones-de-imagenes) | `ok` | 2 | 2022-03-22, 2025-04-30, 2022-06-18 |
| MOD-09-PAGE-026 | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) | `ok` | 62 | 31 de julio de 2026 |
| MOD-09-PAGE-027 | [Opiniones de productos](https://developers.mercadolibre.com.co/es_co/opiniones-sobre-producto) | `ok` | 3 | — |
| MOD-09-PAGE-028 | [Prácticas, validaciones y requerimientos de seguridad para integradores](https://developers.mercadolibre.com.co/es_co/introduccion-seguridad) | `ok` | 0 | — |
| MOD-09-PAGE-029 | [Precios de productos](https://developers.mercadolibre.com.co/es_co/api-de-precios) | `ok` | 8 | 18 de marzo de 2026 |
| MOD-09-PAGE-030 | [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) | `ok` | 19 | — |
| MOD-09-PAGE-031 | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) | `ok` | 44 | 27 de mayo de 2026, 2025-08-31, 2025-09-30, 30 de marzo de 2026, 2025-12-01, 2025-12-30, 2024-01-01, 2024-02-28, 30 de mayo de 2026, 2025-10-28, 2025-10-29, 2026-04-01, 2025-09-20, 2025-10-08, 2025-08-01 |
| MOD-09-PAGE-032 | [Productos reacondicionados](https://developers.mercadolibre.com.co/es_co/catalogo-reacondicionados) | `ok` | 4 | — |
| MOD-09-PAGE-033 | [Publicaciones denunciadas](https://developers.mercadolibre.com.co/es_co/publicaciones-denunciadas) | `ok` | 7 | 2022-04-01 |
| MOD-09-PAGE-034 | [Publicaciones requeridas](https://developers.mercadolibre.com.co/es_co/publicaciones-requeridas-en-catalogo) | `ok` | 12 | — |
| MOD-09-PAGE-035 | [Publicar en catálogo](https://developers.mercadolibre.com.co/es_co/publicacion-en-catalogo) | `ok` | 10 | — |
| MOD-09-PAGE-036 | [Publicar productos](https://developers.mercadolibre.com.co/es_co/publica-productos) | `ok` | 13 | 9 de septiembre de 2024 |
| MOD-09-PAGE-037 | [Qué es catálogo](https://developers.mercadolibre.com.co/es_co/que-es-catalogo) | `ok` | 0 | — |
| MOD-09-PAGE-038 | [Republicar ítems](https://developers.mercadolibre.com.co/es_co/re-publica) | `ok` | 7 | — |
| MOD-09-PAGE-039 | [Sincroniza y modifica publicaciones](https://developers.mercadolibre.com.co/es_co/producto-sincroniza-modifica-publicaciones) | `ok` | 12 | 18 de marzo de 2026 |
| MOD-09-PAGE-040 | [Stock distribuido](https://developers.mercadolibre.com.co/es_co/stock-distribuido) | `ok` | 12 | — |
| MOD-09-PAGE-041 | [Stock Multi Origen](https://developers.mercadolibre.com.co/es_co/stock-multi-origen) | `ok` | 14 | — |
| MOD-09-PAGE-042 | [Validación de guía de talles](https://developers.mercadolibre.com.co/es_co/validacion-de-guia-de-talles) | `ok` | 2 | — |
| MOD-09-PAGE-043 | [Validaciones](https://developers.mercadolibre.com.co/es_co/validaciones) | `ok` | 3 | — |
| MOD-09-PAGE-044 | [Validador de publicaciones](https://developers.mercadolibre.com.co/es_co/validador-de-publicaciones) | `ok` | 1 | — |
| MOD-09-PAGE-045 | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) | `ok` | 23 | 14 de diciembre de 2022 |

### Endpoints/rutas del módulo

| ID | Método | Ruta | Riesgo/área | Fuente |
|---|---|---|---|---|
| MOD-09-EP-0001 | `DELETE` | `/catalog/charts/$CHART_ID` | destructivo | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0002 | `DELETE` | `/catalog/charts/124125` | destructivo | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0003 | `DELETE` | `/items/{item_id}/compatibilities` | destructivo | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0004 | `DELETE` | `/items/{item_id}/compatibilities/{compatibility_id}` | destructivo | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0005 | `DELETE` | `/items/MLA599099879/variations/10449631060` | destructivo | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0006 | `DELETE` | `/items/MLA658778048/variations/15092589430` | destructivo | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0007 | `DELETE` | `/pricing-automation/items/$ITEM_ID/automation` | destructivo | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0008 | `DELETE` | `/pricing-automation/items/MLA12345678/automation` | destructivo | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0009 | `DELETE` | `/seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&promotion_id=$PROMOTION_ID&offer_id=$OFFER_ID` | destructivo | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0010 | `DELETE` | `/seller-promotions/items/MLA1387793467?promotion_type=PRICE_MATCHING_MELI_ALL&promotion_id=P-MLA2072013&offer_id=OFFER-MLA1387793467-1000000151&app_version=v2` | destructivo | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0011 | `DELETE` | `/seller-promotions/items/MLB10203040?promotion_type=UNHEALTHY_STOCK&promotion_id=P-MLB12345&offer_id=MLB10203040-f588cf87-e298-498e-82ad-285b16dd11d5` | destructivo | [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item) |
| MOD-09-EP-0012 | `DELETE` | `/seller-promotions/items/MLB1834747833?promotion_type=PRE_NEGOTIATED&promotion_id=P-MLM394001&offer_id=MLM1834747833-9eafadd4-16d2-49ae-b272-9a7a34585cb8` | destructivo | [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item) |
| MOD-09-EP-0013 | `DELETE` | `/seller-promotions/items/MLB3538191898?promotion_type=SMART&promotion_id=P-MLB1812010&offer_id=OFFER-MLB3538191898-177685&app_version=v2` | destructivo | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0014 | `DELETE` | `/seller-promotions/items/MLB4048719074?promotion_type=PRICE_MATCHING&promotion_id=P-MLB2087012&offer_id=OFFER-MLB4048719074-10000001972&app_version=v2` | destructivo | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0015 | `GET` | `/$resource` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0016 | `GET` | `/$RESOURCE` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0017 | `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ad_groups/search` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0018 | `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ad_groups/search?date_to=2025-09-30&date_from=2025-08-01&limit=800&sort=desc&sort_by=clicks&metrics=CLICKS,PRINTS,COST,CPC,CTR,DIRECT_AMOUNT,INDIRECT_AMOUNT,TOTAL_AMOUNT,DIRECT_UNITS_QUANTITY,INDIRECT_UNITS_QUANTITY,UNITS_QUANTITY,DIRECT_ITEMS_QUANTITY,INDIRECT_ITEMS_QUANTITY,ADVERTISING_ITEMS_QUANTITY,ORGANIC_UNITS_QUANTITY,ORGANIC_UNITS_AMOUNT,ORGANIC_ITEMS_QUANTITY,ACOS,TACOS,SOV,CVR,ROAS&metrics_summary=true&filters[ad_group_id` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0019 | `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ad_groups/search?filters[item_ids` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0020 | `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ads/search?filters[item_id` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0021 | `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/campaigns/$CAMPAIGN_ID/ads/metrics?date_from=2025-10-28&date_to=2025-10-29&filters[item_ids` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0022 | `GET` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/campaigns/search?limit=1&offset=0&date_from=2025-12-01&date_to=2025-12-30&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount&metrics_summary=true` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0023 | `GET` | `/advertising/$ADVERTISER_SITE_ID/product_ads/ad_groups/$AD_GROUP_ID` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0024 | `GET` | `/advertising/$ADVERTISER_SITE_ID/product_ads/ad_groups/$AD_GROUP_ID/ads?date_from=2025-09-20&date_to=2025-10-08&metrics=clicks,prints,cost,cpc,ctr,direct_amount,indirect_amount,total_amount,direct_units_quantity,indirect_units_quantity,units_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,organic_units_quantity,organic_units_amount,organic_items_quantity,acos,tacos,sov,cvr,roas` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0025 | `GET` | `/advertising/$ADVERTISER_SITE_ID/product_ads/campaigns/$CAMPAIGN_ID?date_from=2025-12-01&date_to=2025-12-30&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount,impression_share,top_impression_share,lost_impression_share_by_budget,lost_impression_share_by_ad_rank,acos_benchmark` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0026 | `GET` | `/advertising/$ADVERTISER_SITE_ID/product_ads/campaigns/$CAMPAIGN_ID/ad_groups/metrics?date_from=2026-04-01&date_to=2026-04-01&metrics=clicks,prints,cost,cpc,ctr,direct_amount,indirect_amount,total_amount,direct_units_quantity,indirect_units_quantity,units_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,organic_units_quantity,organic_units_amount,organic_items_quantity,acos,sov,roas,cvr,tacos` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0027 | `GET` | `/advertising/MCO/product_ads/campaigns/355771832/ad_groups/metrics?date_from=2026-04-01&date_to=2026-04-01&metrics=clicks,prints,cost,cpc,ctr,direct_amount,indirect_amount,total_amount,direct_units_quantity,indirect_units_quantity,units_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,organic_units_quantity,organic_units_amount,organic_items_quantity,acos,sov,roas,cvr,tacos` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0028 | `GET` | `/advertising/MLA/advertisers/882927/product_ads/ad_groups/search?filters[item_ids` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0029 | `GET` | `/advertising/MLM/advertisers/12345/product_ads/campaigns/search?filters[status` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0030 | `GET` | `/advertising/MLM/advertisers/4622/product_ads/ad_groups/search?date_to=2025-09-30&date_from=2025-08-01&limit=800&sort=desc&sort_by=clicks&metrics=CLICKS,PRINTS,COST,CPC,CTR,DIRECT_AMOUNT,INDIRECT_AMOUNT,TOTAL_AMOUNT,DIRECT_UNITS_QUANTITY,INDIRECT_UNITS_QUANTITY,UNITS_QUANTITY,DIRECT_ITEMS_QUANTITY,INDIRECT_ITEMS_QUANTITY,ADVERTISING_ITEMS_QUANTITY,ORGANIC_UNITS_QUANTITY,ORGANIC_UNITS_AMOUNT,ORGANIC_ITEMS_QUANTITY,ACOS,TACOS,SOV,CVR,ROAS&metrics_summary=true&filters[ad_group_id` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0031 | `GET` | `/advertising/MLM/product_ads/ad_groups/1142185192/ads?date_from=2025-09-20&date_to=2025-10-08&metrics=clicks,prints,cost,cpc,ctr,direct_amount,indirect_amount,total_amount,direct_units_quantity,indirect_units_quantity,units_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,organic_units_quantity,organic_units_amount,organic_items_quantity,acos,tacos,sov,cvr,roas` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0032 | `GET` | `/advertising/MLM/product_ads/ad_groups/65867?date_from=2025-08-31&date_to=2025-09-30&metrics=CLICKS,PRINTS,COST,CPC,CTR,DIRECT_AMOUNT,INDIRECT_AMOUNT,TOTAL_AMOUNT,DIRECT_UNITS_QUANTITY,INDIRECT_UNITS_QUANTITY,UNITS_QUANTITY,DIRECT_ITEMS_QUANTITY,INDIRECT_ITEMS_QUANTITY,ADVERTISING_ITEMS_QUANTITY,ORGANIC_UNITS_QUANTITY,ORGANIC_UNITS_AMOUNT,ORGANIC_ITEMS_QUANTITY,ACOS` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0033 | `GET` | `/catalog_compatibilities/products_search/count_family_products` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0034 | `GET` | `/catalog_compatibilities/products_search/new?categoryId=$CATEGORY_ID` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0035 | `GET` | `/catalog_compatibilities/restrictions/values?main_domain_id=MLA-CARS_AND_VANS&secondary_domain_id=MLA-VEHICLE_ENGINE_MOUNTS` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0036 | `GET` | `/catalog_compatibilities/restrictions/values?main_domain_id=MLA-CARS_AND_VANS&secondary_domain_id=MLA-VEHICLE_SHOCK_ABSORBERS` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0037 | `GET` | `/catalog_domains/DOMAIN_ID/categories` | lectura/consulta | [Categorización de productos](https://developers.mercadolibre.com.co/es_co/categoriza-productos) |
| MOD-09-EP-0038 | `GET` | `/catalog_domains/MLA-CELLPHONES/categories` | lectura/consulta | [Categorización de productos](https://developers.mercadolibre.com.co/es_co/categoriza-productos) |
| MOD-09-EP-0039 | `GET` | `/catalog_domains/MLB-CARS_AND_VANS/compatibilities/cards` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0040 | `GET` | `/catalog_forewarning/date` | lectura/consulta | [Publicaciones requeridas](https://developers.mercadolibre.com.co/es_co/publicaciones-requeridas-en-catalogo) |
| MOD-09-EP-0041 | `GET` | `/catalog_suggestions/$SUGGESTION_ID` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0042 | `GET` | `/catalog_suggestions/MLA123456` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0043 | `GET` | `/catalog/charts/$CHART_ID` | lectura/consulta | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0044 | `GET` | `/catalog/charts/232382` | lectura/consulta | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0045 | `GET` | `/catalog/dumps/domains/$SITE_ID/compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0046 | `GET` | `/catalog/dumps/domains/MLB/catalog_only` | lectura/consulta | [Publicaciones requeridas](https://developers.mercadolibre.com.co/es_co/publicaciones-requeridas-en-catalogo) |
| MOD-09-EP-0047 | `GET` | `/catalog/dumps/domains/MLB/catalog_required` | lectura/consulta | [Publicaciones requeridas](https://developers.mercadolibre.com.co/es_co/publicaciones-requeridas-en-catalogo) |
| MOD-09-EP-0048 | `GET` | `/catalog/dumps/domains/MLB/compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0049 | `GET` | `/categories/$CATEGORY_ID` | lectura/consulta | [Categorización de productos](https://developers.mercadolibre.com.co/es_co/categoriza-productos) |
| MOD-09-EP-0050 | `GET` | `/categories/$CATEGORY_ID/sale_terms` | lectura/consulta | [Publicar productos](https://developers.mercadolibre.com.co/es_co/publica-productos) |
| MOD-09-EP-0051 | `GET` | `/categories/MLA126186/attributes` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0052 | `GET` | `/categories/MLA1577` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0053 | `GET` | `/categories/MLA1577/sale_terms` | lectura/consulta | [Sincroniza y modifica publicaciones](https://developers.mercadolibre.com.co/es_co/producto-sincroniza-modifica-publicaciones) |
| MOD-09-EP-0054 | `GET` | `/categories/MLA1642/sale_terms` | lectura/consulta | [Publicar productos](https://developers.mercadolibre.com.co/es_co/publica-productos) |
| MOD-09-EP-0055 | `GET` | `/categories/MLA30835/attributes` | lectura/consulta | [Publicar productos](https://developers.mercadolibre.com.co/es_co/publica-productos) |
| MOD-09-EP-0056 | `GET` | `/categories/MLA3530` | lectura/consulta | [Categorización de productos](https://developers.mercadolibre.com.co/es_co/categoriza-productos) |
| MOD-09-EP-0057 | `GET` | `/categories/MLM167991/sale_terms` | lectura/consulta | [Sincroniza y modifica publicaciones](https://developers.mercadolibre.com.co/es_co/producto-sincroniza-modifica-publicaciones) |
| MOD-09-EP-0058 | `GET` | `/claims/5108684499` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0059 | `GET` | `/claims/search?reason_id=$reason_id` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0060 | `GET` | `/collections/$PAYMENT_ID` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0061 | `GET` | `/collections/3043111111` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0062 | `GET` | `/compats-snapshots/orders/$ORDER_ID` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0063 | `GET` | `/compats-snapshots/orders/2000006372967416` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0064 | `GET` | `/compats-snapshots/orders/2000006372967424` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0065 | `GET` | `/compats-snapshots/orders/2000006372967684` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0066 | `GET` | `/flex/sites/$SITE_ID/shipments/$SHIPMENT_ID/assignment/v1` | envíos | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0067 | `GET` | `/item/$ITEM_ID/performance` | lectura/consulta | [Calidad de publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones) |
| MOD-09-EP-0068 | `GET` | `/item/MLA1435540505/performance` | lectura/consulta | [Calidad de publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones) |
| MOD-09-EP-0069 | `GET` | `/items?ids=$ITEM_ID1` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0070 | `GET` | `/items?ids=$ITEM_ID1,$ITEM_ID2` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0071 | `GET` | `/items?ids=$ITEM_ID1,$ITEM_ID2&attributes=$ATTRIBUTE1,$ATTRIBUTE2,$ATTRIBUTE3` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0072 | `GET` | `/items?ids=MLA599260060,MLA594239600` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0073 | `GET` | `/items?ids=MLA599260060,MLA594239600&attributes=id,price,category_id,title` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0074 | `GET` | `/items/{item_id` | lectura/consulta | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0075 | `GET` | `/items/{Item_id` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0076 | `GET` | `/items/{item_id}` | lectura/consulta | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0077 | `GET` | `/items/{item_id}:` | lectura/consulta | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0078 | `GET` | `/items/{item_id}/compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0079 | `GET` | `/items/{item_id}/compatibilities?extended=true` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0080 | `GET` | `/items/{item_id}/compatibilities/{compatibility_id}` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0081 | `GET` | `/items/{itemId}/details` | lectura/consulta | [Gestionar referencias de precios](https://developers.mercadolibre.com.co/es_co/referencias-de-precios) |
| MOD-09-EP-0082 | `GET` | `/items/{itemId}/sale_price` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0083 | `GET` | `/items/$ITEM_ID/automation` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0084 | `GET` | `/items/$ITEM_ID/bundle/prices_configuration` | pricing | [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) |
| MOD-09-EP-0085 | `GET` | `/items/$ITEM_ID/catalog_forewarning/date` | lectura/consulta | [Publicaciones requeridas](https://developers.mercadolibre.com.co/es_co/publicaciones-requeridas-en-catalogo) |
| MOD-09-EP-0086 | `GET` | `/items/$ITEM_ID/catalog_listing_eligibility` | lectura/consulta | [Elegibilidad de catálogo](https://developers.mercadolibre.com.co/es_co/elegibilidad-catalogo) |
| MOD-09-EP-0087 | `GET` | `/items/$ITEM_ID/compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0088 | `GET` | `/items/$ITEM_ID/compatibilities?extended=true` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0089 | `GET` | `/items/$ITEM_ID/compatibilities/$COMPATIBILITY_ID` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0090 | `GET` | `/items/$ITEM_ID/compatibilities/$COMPATIBILITY_ID/note` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0091 | `GET` | `/items/$item_id/compatibilities/exception` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0092 | `GET` | `/items/$ITEM_ID/description?api_version=2` | lectura/consulta | [Descripción de productos](https://developers.mercadolibre.com.co/es_co/descripcion-de-articulos) |
| MOD-09-EP-0093 | `GET` | `/items/$ITEM_ID/details` | lectura/consulta | [Gestionar referencias de precios](https://developers.mercadolibre.com.co/es_co/referencias-de-precios) |
| MOD-09-EP-0094 | `GET` | `/items/$ITEM_ID/price_to_win` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0095 | `GET` | `/items/$ITEM_ID/price_to_win?SITE_ID&version=v2` | lectura/consulta | [Competencia](https://developers.mercadolibre.com.co/es_co/competencia-en-catalogo) |
| MOD-09-EP-0096 | `GET` | `/items/$ITEM_ID/price/history` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0097 | `GET` | `/items/$ITEM_ID/prices` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0098 | `GET` | `/items/$ITEM_ID/prices?display_version=true` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0099 | `GET` | `/items/$ITEM_ID/prices/price-per-quantity` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0100 | `GET` | `/items/$ITEM_ID/prices/standard` | pricing | [Precios de productos](https://developers.mercadolibre.com.co/es_co/api-de-precios) |
| MOD-09-EP-0101 | `GET` | `/items/$ITEM_ID/prices/standard/quantity` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0102 | `GET` | `/items/$ITEM_ID/rules` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0103 | `GET` | `/items/$ITEM_ID/sale_price?context=$CHANNEL,LOYALTY_LEVEL` | pricing | [Precios de productos](https://developers.mercadolibre.com.co/es_co/api-de-precios) |
| MOD-09-EP-0104 | `GET` | `/items/$ITEM_ID/sale_price?context=$CONTEXT` | pricing | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0105 | `GET` | `/items/$ITEM_ID/sale_price?context=channel_marketplace` | pricing | [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) |
| MOD-09-EP-0106 | `GET` | `/items/$ITEM_ID/sale_price?quantity=5` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0107 | `GET` | `/items/$ITEM_ID/variations` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0108 | `GET` | `/items/$ITEMS_ID/prices` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0109 | `GET` | `/items/$ITEMS_ID/sale_price?context=$CONTEXTS&quantity` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0110 | `GET` | `/items/$ITEMS_ID/sale_price?context=$CONTEXTS&quantity=$CANTIDAD` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0111 | `GET` | `/items/catalog_domains/$DOMAIN_ID/compatibilities/cards` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0112 | `GET` | `/items/compatibilities_summary` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0113 | `GET` | `/items/ITEM_ID/price_to_win` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0114 | `GET` | `/items/kits` | lectura/consulta | [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) |
| MOD-09-EP-0115 | `GET` | `/items/MLA000000?include_attributes=all` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0116 | `GET` | `/items/MLA1136716168` | lectura/consulta | [Publicar productos](https://developers.mercadolibre.com.co/es_co/publica-productos) |
| MOD-09-EP-0117 | `GET` | `/items/MLA1150086340` | lectura/consulta | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0118 | `GET` | `/items/MLA1234567/price_to_win?version=v2` | lectura/consulta | [Competencia](https://developers.mercadolibre.com.co/es_co/competencia-en-catalogo) |
| MOD-09-EP-0119 | `GET` | `/items/MLA123456789/catalog_listing_eligibility` | lectura/consulta | [Elegibilidad de catálogo](https://developers.mercadolibre.com.co/es_co/elegibilidad-catalogo) |
| MOD-09-EP-0120 | `GET` | `/items/MLA1417560910` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0121 | `GET` | `/items/MLA3191390879/sale_price?context=channel_marketplace,buyer_loyalty_3` | pricing | [Precios de productos](https://developers.mercadolibre.com.co/es_co/api-de-precios) |
| MOD-09-EP-0122 | `GET` | `/items/MLA456789/price_to_win?version=v2` | lectura/consulta | [Competencia](https://developers.mercadolibre.com.co/es_co/competencia-en-catalogo) |
| MOD-09-EP-0123 | `GET` | `/items/MLA599099879/variations/10449631060` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0124 | `GET` | `/items/MLA640992661?include_attributes=all` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0125 | `GET` | `/items/MLA658778048` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0126 | `GET` | `/items/MLA658778048?attributes=variations` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0127 | `GET` | `/items/MLA658778048/variations` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0128 | `GET` | `/items/MLA658778048/variations/15092589430` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0129 | `GET` | `/items/MLA686791111` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0130 | `GET` | `/items/MLA765432/price_to_win?version=v2` | lectura/consulta | [Competencia](https://developers.mercadolibre.com.co/es_co/competencia-en-catalogo) |
| MOD-09-EP-0131 | `GET` | `/items/MLA794706391/compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0132 | `GET` | `/items/MLA820048955` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0133 | `GET` | `/items/MLA821614634/relist` | lectura/consulta | [Republicar ítems](https://developers.mercadolibre.com.co/es_co/re-publica) |
| MOD-09-EP-0134 | `GET` | `/items/MLA830570458/catalog_forewarning/date` | lectura/consulta | [Publicaciones requeridas](https://developers.mercadolibre.com.co/es_co/publicaciones-requeridas-en-catalogo) |
| MOD-09-EP-0135 | `GET` | `/items/MLA832998780` | lectura/consulta | [Republicar ítems](https://developers.mercadolibre.com.co/es_co/re-publica) |
| MOD-09-EP-0136 | `GET` | `/items/MLA832998780/relist` | lectura/consulta | [Republicar ítems](https://developers.mercadolibre.com.co/es_co/re-publica) |
| MOD-09-EP-0137 | `GET` | `/items/MLA935110000/description` | lectura/consulta | [Descripción de productos](https://developers.mercadolibre.com.co/es_co/descripcion-de-articulos) |
| MOD-09-EP-0138 | `GET` | `/items/MLA9876543/price_to_win?version=v2` | lectura/consulta | [Competencia](https://developers.mercadolibre.com.co/es_co/competencia-en-catalogo) |
| MOD-09-EP-0139 | `GET` | `/items/MLB1234/catalog_listing_eligibility` | lectura/consulta | [Elegibilidad de catálogo](https://developers.mercadolibre.com.co/es_co/elegibilidad-catalogo) |
| MOD-09-EP-0140 | `GET` | `/items/MLB123450000/prices/standard/quantity` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0141 | `GET` | `/items/MLB12345678/compatibilities/exception` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0142 | `GET` | `/items/MLB123456789/prices` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0143 | `GET` | `/items/MLB123456789/prices/standard/quantity` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0144 | `GET` | `/items/MLB3647026655/sale_price?context=channel_marketplace,user_type_business&quantity=30` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0145 | `GET` | `/items/MLB3863034063/compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0146 | `GET` | `/items/MLB3863097751/compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0147 | `GET` | `/items/MLB3868780585` | lectura/consulta | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0148 | `GET` | `/items/MLB4462690924` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0149 | `GET` | `/items/MLB4642967339/prices/price-per-quantity` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0150 | `GET` | `/items/MLB5586809854/prices` | pricing | [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) |
| MOD-09-EP-0151 | `GET` | `/items/MLB6646853040/prices` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0152 | `GET` | `/items/MLB6646853040/sale_price?quantity=5` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0153 | `GET` | `/items/MLB6713483676` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0154 | `GET` | `/items/MLB6713484994` | lectura/consulta | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0155 | `GET` | `/items/MLB6713484994/prices?display_version=true` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0156 | `GET` | `/items/MLM12456789/compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0157 | `GET` | `/items/MLM12456789/compatibilities/bcbd413f-cd65-0e0f-88c9-5eb4aebb5372/note` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0158 | `GET` | `/items/MLM1881484643` | lectura/consulta | [Publicar en catálogo](https://developers.mercadolibre.com.co/es_co/publicacion-en-catalogo) |
| MOD-09-EP-0159 | `GET` | `/items/MLM237323192/prices` | pricing | [Precios de productos](https://developers.mercadolibre.com.co/es_co/api-de-precios) |
| MOD-09-EP-0160 | `GET` | `/items/MLM623075370` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0161 | `GET` | `/items/MLM794706391/compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0162 | `GET` | `/items/MLM794706391/compatibilities?extended=true` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0163 | `GET` | `/items/MLM794706391/compatibilities/$COMPATIBILITY_ID` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0164 | `GET` | `/items/MLM794706391/compatibilities/4cb9af35-8e9b-ebfd-9e7f-2245ac363d10` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0165 | `GET` | `/items/multiwarehouse` | lectura/consulta | [Stock Multi Origen](https://developers.mercadolibre.com.co/es_co/stock-multi-origen) |
| MOD-09-EP-0166 | `GET` | `/items/search` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0167 | `GET` | `/messages/$RESOURCE` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0168 | `GET` | `/missed_feeds?app_id=$APP_ID&offset=1&limit=5` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0169 | `GET` | `/missed_feeds?app_id=$APP_ID&topic=$TOPIC` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0170 | `GET` | `/missed_feeds?app_id=3486171129139063` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0171 | `GET` | `/missed_feeds?app_id=3486171129139063&topic=payments` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0172 | `GET` | `/moderations/infractions/$USER_ID` | lectura/consulta | [Publicaciones requeridas](https://developers.mercadolibre.com.co/es_co/publicaciones-requeridas-en-catalogo) |
| MOD-09-EP-0173 | `GET` | `/moderations/infractions/1234567` | lectura/consulta | [Publicaciones requeridas](https://developers.mercadolibre.com.co/es_co/publicaciones-requeridas-en-catalogo) |
| MOD-09-EP-0174 | `GET` | `/moderations/pppi/case/$CASE_ID` | lectura/consulta | [Publicaciones denunciadas](https://developers.mercadolibre.com.co/es_co/publicaciones-denunciadas) |
| MOD-09-EP-0175 | `GET` | `/moderations/pppi/case/12344` | lectura/consulta | [Publicaciones denunciadas](https://developers.mercadolibre.com.co/es_co/publicaciones-denunciadas) |
| MOD-09-EP-0176 | `GET` | `/moderations/pppi/case/36408927` | lectura/consulta | [Publicaciones denunciadas](https://developers.mercadolibre.com.co/es_co/publicaciones-denunciadas) |
| MOD-09-EP-0177 | `GET` | `/moderations/pppi/cases?offset=0&date_created=2022-04-01&status=$STATUS_ID` | lectura/consulta | [Publicaciones denunciadas](https://developers.mercadolibre.com.co/es_co/publicaciones-denunciadas) |
| MOD-09-EP-0178 | `GET` | `/moderations/pppi/cases?offset=0&date_created=2022-04-01&status=DOCUMENTATION_APPROVED` | lectura/consulta | [Publicaciones denunciadas](https://developers.mercadolibre.com.co/es_co/publicaciones-denunciadas) |
| MOD-09-EP-0179 | `GET` | `/multiget/catalog_listing_eligibility?ids=$ITEM_ID,$ITEM_ID` | lectura/consulta | [Elegibilidad de catálogo](https://developers.mercadolibre.com.co/es_co/elegibilidad-catalogo) |
| MOD-09-EP-0180 | `GET` | `/multiget/catalog_listing_eligibility?ids=MLA818878419,MLA820167922` | lectura/consulta | [Elegibilidad de catálogo](https://developers.mercadolibre.com.co/es_co/elegibilidad-catalogo) |
| MOD-09-EP-0181 | `GET` | `/orders/$ORDER_ID/bundle` | lectura/consulta | [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) |
| MOD-09-EP-0182 | `GET` | `/orders/2195160686` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0183 | `GET` | `/pictures` | lectura/consulta | [Publicaciones denunciadas](https://developers.mercadolibre.com.co/es_co/publicaciones-denunciadas) |
| MOD-09-EP-0184 | `GET` | `/pictures/$PICTURE_ID/errors?` | lectura/consulta | [Imágenes en publicaciones](https://developers.mercadolibre.com.co/es_co/trabajar-con-imagenes) |
| MOD-09-EP-0185 | `GET` | `/pictures/970736-MLU11111111111_092017/errors` | lectura/consulta | [Imágenes en publicaciones](https://developers.mercadolibre.com.co/es_co/trabajar-con-imagenes) |
| MOD-09-EP-0186 | `GET` | `/post-purchase` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0187 | `GET` | `/prices` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0188 | `GET` | `/prices_configuration` | pricing | [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) |
| MOD-09-EP-0189 | `GET` | `/pricing-automation/items/$ITEM_ID/automation` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0190 | `GET` | `/pricing-automation/items/$ITEM_ID/automation/by-product/$CATALOG_PRODUCT_ID` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0191 | `GET` | `/pricing-automation/items/$ITEM_ID/price/history` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0192 | `GET` | `/pricing-automation/items/$ITEM_ID/rules` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0193 | `GET` | `/pricing-automation/items/MLA12345678/automation` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0194 | `GET` | `/pricing-automation/items/MLA12345678/price/history` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0195 | `GET` | `/pricing-automation/items/MLA12345678/rules` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0196 | `GET` | `/pricing-automation/items/MLB4211305575/automation/by-product/MLB38607446` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0197 | `GET` | `/pricing-automation/products/$CATALOG_PRODUCT_ID/rules` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0198 | `GET` | `/pricing-automation/products/MLA123456/rules` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0199 | `GET` | `/pricing-automation/users/$USER_ID/items` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0200 | `GET` | `/pricing-automation/users/$USER_ID/items?offset=100&limit=100` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0201 | `GET` | `/pricing-automation/users/1167132037/items` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0202 | `GET` | `/products/{product_id}` | lectura/consulta | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0203 | `GET` | `/products/$CATALOG_PRODUCT_ID` | lectura/consulta | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0204 | `GET` | `/products/$CATALOG_PRODUCT_ID:` | lectura/consulta | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0205 | `GET` | `/products/$CATALOG_PRODUCT_ID/rules` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0206 | `GET` | `/products/$PRODUCT_ID` | lectura/consulta | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0207 | `GET` | `/products/MLA14719808` | lectura/consulta | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0208 | `GET` | `/products/MLA18500843` | lectura/consulta | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0209 | `GET` | `/products/MLA18500852` | lectura/consulta | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0210 | `GET` | `/products/search` | lectura/consulta | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0211 | `GET` | `/products/search?status=$STATUS_ID&site_id=$SITE_ID&product_identifier=$PRODUCT_IDENTIFIER` | lectura/consulta | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0212 | `GET` | `/products/search?status=$STATUS_ID&site_id=$SITE_ID&q={q` | lectura/consulta | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0213 | `GET` | `/products/search?status=active&site_id=$SITE_ID&listing_strategy=catalog_required&q={q` | lectura/consulta | [Publicaciones requeridas](https://developers.mercadolibre.com.co/es_co/publicaciones-requeridas-en-catalogo) |
| MOD-09-EP-0214 | `GET` | `/products/search?status=active&site_id=MLA&product_identifier=0123456789` | lectura/consulta | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0215 | `GET` | `/products/search?status=active&site_id=MLA&q=N93035` | lectura/consulta | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0216 | `GET` | `/products/search?status=active&site_id=MLA&q=Samsung` | lectura/consulta | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0217 | `GET` | `/products/search?status=active&site_id=MLA&skip_cache=true&listing_strategy=catalog_required&q=Huawei` | lectura/consulta | [Publicaciones requeridas](https://developers.mercadolibre.com.co/es_co/publicaciones-requeridas-en-catalogo) |
| MOD-09-EP-0218 | `GET` | `/products/search?status=active&site_id=site_id&q=PART_NUMBER` | lectura/consulta | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0219 | `GET` | `/products/search?status=active&site_id=site_id&q=PRODUCT_ID` | lectura/consulta | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0220 | `GET` | `/public/buybox/sync/$ITEM_ID` | lectura/consulta | [Publicar en catálogo](https://developers.mercadolibre.com.co/es_co/publicacion-en-catalogo) |
| MOD-09-EP-0221 | `GET` | `/questions/5036111111` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0222 | `GET` | `/questions/search?search_type=scan&item=$ITEM_ID` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0223 | `GET` | `/reviews/item/$ITEM_ID` | lectura/consulta | [Opiniones de productos](https://developers.mercadolibre.com.co/es_co/opiniones-sobre-producto) |
| MOD-09-EP-0224 | `GET` | `/reviews/item/MLB1625519814` | lectura/consulta | [Opiniones de productos](https://developers.mercadolibre.com.co/es_co/opiniones-sobre-producto) |
| MOD-09-EP-0225 | `GET` | `/reviews/item/MLB1632704547?catalog_product_id=MLB14186226` | lectura/consulta | [Opiniones de productos](https://developers.mercadolibre.com.co/es_co/opiniones-sobre-producto) |
| MOD-09-EP-0226 | `GET` | `/sale_price` | pricing | [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) |
| MOD-09-EP-0227 | `GET` | `/sale_price:` | pricing | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0228 | `GET` | `/seller-promotions/candidates:` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0229 | `GET` | `/seller-promotions/candidates/$CANDIDATE_ID` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0230 | `GET` | `/seller-promotions/candidates/CANDIDATE-MLA1111111111-11111111` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0231 | `GET` | `/seller-promotions/items/$ITEM_ID` | lectura/consulta | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0232 | `GET` | `/seller-promotions/items/$ITEM_ID?app_version=v2` | lectura/consulta | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0233 | `GET` | `/seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&promotion_id=$PROMOTION_ID&offer_id=$OFFER_ID` | lectura/consulta | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0234 | `GET` | `/seller-promotions/items/MLA1387793467?promotion_type=PRICE_MATCHING_MELI_ALL&promotion_id=P-MLA2072013&offer_id=OFFER-MLA1387793467-1000000151&app_version=v2` | lectura/consulta | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0235 | `GET` | `/seller-promotions/items/MLB10203040` | lectura/consulta | [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item) |
| MOD-09-EP-0236 | `GET` | `/seller-promotions/items/MLB10203040?promotion_type=UNHEALTHY_STOCK&promotion_id=P-MLB12345&offer_id=MLB10203040-f588cf87-e298-498e-82ad-285b16dd11d5` | lectura/consulta | [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item) |
| MOD-09-EP-0237 | `GET` | `/seller-promotions/items/MLB1834747833?promotion_type=PRE_NEGOTIATED&promotion_id=P-MLM394001&offer_id=MLM1834747833-9eafadd4-16d2-49ae-b272-9a7a34585cb8` | lectura/consulta | [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item) |
| MOD-09-EP-0238 | `GET` | `/seller-promotions/items/MLB3538191898?app_version=v2` | lectura/consulta | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0239 | `GET` | `/seller-promotions/items/MLB3538191898?promotion_type=SMART&promotion_id=P-MLB1812010&offer_id=OFFER-MLB3538191898-177685&app_version=v2` | lectura/consulta | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0240 | `GET` | `/seller-promotions/items/MLB4048719074?app_version=v2` | lectura/consulta | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0241 | `GET` | `/seller-promotions/items/MLB4048719074?promotion_type=PRICE_MATCHING&promotion_id=P-MLB2087012&offer_id=OFFER-MLB4048719074-10000001972&app_version=v2` | lectura/consulta | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0242 | `GET` | `/seller-promotions/items/MLM848619385` | lectura/consulta | [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item) |
| MOD-09-EP-0243 | `GET` | `/seller-promotions/offers:` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0244 | `GET` | `/seller-promotions/offers/$OFFERS_ID` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0245 | `GET` | `/seller-promotions/offers/1234567` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0246 | `GET` | `/seller-promotions/promotions/$PROMOTION_ID/items` | lectura/consulta | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0247 | `GET` | `/seller-promotions/promotions/P-MLB12345?promotion_type=PRE_NEGOTIATED&app_version=v2` | lectura/consulta | [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item) |
| MOD-09-EP-0248 | `GET` | `/seller-promotions/promotions/P-MLB12345/items?promotion_type=UNHEALTHY_STOCK&app_version=v2` | lectura/consulta | [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item) |
| MOD-09-EP-0249 | `GET` | `/seller-promotions/promotions/P-MLB1812010?promotion_type=SMART&app_version=v2` | lectura/consulta | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0250 | `GET` | `/seller-promotions/promotions/P-MLB1812010/items?promotion_type=SMART&app_version=v2` | lectura/consulta | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0251 | `GET` | `/seller-promotions/promotions/P-MLB2087012?promotion_type=PRICE_MATCHING&app_version=v2` | lectura/consulta | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0252 | `GET` | `/seller-promotions/promotions/P-MLB2087012/items?promotion_type=PRICE_MATCHING&app_version=v2` | lectura/consulta | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0253 | `GET` | `/seller-promotions/promotions/P-MLB3528002/items?promotion_type=PRICE_MATCHING_MELI_ALL&app_version=v2` | lectura/consulta | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0254 | `GET` | `/seller-promotions/promotions/P-MLB35280024?promotion_type=PRICE_MATCHING_MELI_ALL&app_version=v2` | lectura/consulta | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0255 | `GET` | `/seller-promotions/promotions/P-MLM394001?promotion_type=PRE_NEGOTIATED&app_version=v2` | lectura/consulta | [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item) |
| MOD-09-EP-0256 | `GET` | `/seller-promotions/promotions/P-MLM394001/items?promotion_type=PRE_NEGOTIATED&app_version=v2` | lectura/consulta | [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item) |
| MOD-09-EP-0257 | `GET` | `/shipments/40546549876` | envíos | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0258 | `GET` | `/sites` | lectura/consulta | [Categorización de productos](https://developers.mercadolibre.com.co/es_co/categoriza-productos) |
| MOD-09-EP-0259 | `GET` | `/sites/$SITE_ID/domain_discovery/search?q=$Q` | lectura/consulta | [Categorización de productos](https://developers.mercadolibre.com.co/es_co/categoriza-productos) |
| MOD-09-EP-0260 | `GET` | `/sites/$SITE_ID/search?` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0261 | `GET` | `/sites/$SITE_ID/search?nickname=$NICKNAME` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0262 | `GET` | `/sites/$SITE_ID/search?seller_id=$SELLER_ID` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0263 | `GET` | `/sites/$SITE_ID/search?seller_id=$SELLER_ID&category=$CATEGORY_ID` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0264 | `GET` | `/sites/$SITE_ID/search?seller_id=$SELLER_ID&shipping_cost=free` | envíos | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0265 | `GET` | `/sites/$SITE_ID/search?seller_id=$SELLER_ID&sort=price_asc` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0266 | `GET` | `/sites/$SITE/listing_prices??category_id=$CATEGORY_ID&price=$PRICE&currency_id=$CURRENCY_ID&logistic_` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0267 | `GET` | `/sites/$SITE/listing_prices?category_id=$CATEGORY_ID&price=$PRICE&currency_id=$CURRENCY_ID&logistic_type=$LOGISTIC_TYPE` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0268 | `GET` | `/sites/$SITE/listing_prices?category_id=$CATEGORY_ID&price=$PRICE&currency_id=$CURRENCY_ID&logistic_type=$LOGISTIC_TYPE&shipping_modes=$SHIPPING_MODES&listing_type_id=$LISTING_TYPE_ID` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0269 | `GET` | `/sites/$SITE/listing_prices?price=$PRICE` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0270 | `GET` | `/sites/$SITE/listing_prices?price=$PRICE&category_id=$CATEGORY_ID` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0271 | `GET` | `/sites/$SITE/listing_prices?price=$PRICE&category_id=$CATEGORY_ID&tags=$CAMPAIGN_TAG_ID&listing_type_id=$LISTING_TYPE` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0272 | `GET` | `/sites/$SITE/listing_prices?price=$PRICE&currency_id` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0273 | `GET` | `/sites/$SITE/listing_prices?price=$PRICE&listing_type_id=$LISTING_TYPE_ID` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0274 | `GET` | `/sites/$SITE/listing_prices?price=$PRICE&quantity=$QUANTITY` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0275 | `GET` | `/sites/categories/$CATEGORY_ID` | lectura/consulta | [Publicar productos](https://developers.mercadolibre.com.co/es_co/publica-productos) |
| MOD-09-EP-0276 | `GET` | `/sites/MLA/domain_discovery/search?limit=1&q=celular%20iphone` | lectura/consulta | [Categorización de productos](https://developers.mercadolibre.com.co/es_co/categoriza-productos) |
| MOD-09-EP-0277 | `GET` | `/sites/MLA/listing_prices?category_id=MLA6711&price=80.12&currency_id=ARS&logistic_type=drop_off` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0278 | `GET` | `/sites/MLA/listing_prices?price=100&category_id=MLA3551&tags=ahora-3&listing_type_id=gold_pro` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0279 | `GET` | `/sites/MLA/listing_prices?price=10630&quantity=80` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0280 | `GET` | `/sites/MLA/listing_prices?price=19500&category_id=MLA120353` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0281 | `GET` | `/sites/MLA/listing_prices?price=500&category_id=MLA1403&tags=supermarket_eligible` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0282 | `GET` | `/sites/MLA/listing_prices?price=5000` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0283 | `GET` | `/sites/MLA/listing_prices?price=5000&currency_id=ARS&category_id=MLA418448&listing_type_id=gold_pro&logistic_type=drop_off&shipping_mode=me2&billable_weight=5828&tags=ahora-3` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0284 | `GET` | `/sites/MLA/listing_prices?price=5290&listing_type_id=gold_special` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0285 | `GET` | `/sites/MLA/listing_prices?price=6649&currency_id=ARS` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0286 | `GET` | `/sites/MLA/listing_pricesprice=10345&listing_type_id=gold_special&category_id=MLA120350` | pricing | [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) |
| MOD-09-EP-0287 | `GET` | `/sites/MLA/shipments/407323124706/assignment/v1` | envíos | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0288 | `GET` | `/stock` | lectura/consulta | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0289 | `GET` | `/stock-availability-default` | lectura/consulta | [Calidad de publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones) |
| MOD-09-EP-0290 | `GET` | `/stock-sku-default` | lectura/consulta | [Calidad de publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones) |
| MOD-09-EP-0291 | `GET` | `/stock/` | lectura/consulta | [Stock distribuido](https://developers.mercadolibre.com.co/es_co/stock-distribuido) |
| MOD-09-EP-0292 | `GET` | `/stock/fulfillment/operations:` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0293 | `GET` | `/stock/fulfillment/operations/9876` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0294 | `GET` | `/stock/type/seller_warehouse` | lectura/consulta | [Stock distribuido](https://developers.mercadolibre.com.co/es_co/stock-distribuido) |
| MOD-09-EP-0295 | `GET` | `/stock/type/selling_address` | lectura/consulta | [Stock distribuido](https://developers.mercadolibre.com.co/es_co/stock-distribuido) |
| MOD-09-EP-0296 | `GET` | `/suggestions/items/$ITEM_ID/details` | lectura/consulta | [Gestionar referencias de precios](https://developers.mercadolibre.com.co/es_co/referencias-de-precios) |
| MOD-09-EP-0297 | `GET` | `/suggestions/items/MLA12345678/details` | lectura/consulta | [Gestionar referencias de precios](https://developers.mercadolibre.com.co/es_co/referencias-de-precios) |
| MOD-09-EP-0298 | `GET` | `/suggestions/user/$USER_ID/items` | lectura/consulta | [Gestionar referencias de precios](https://developers.mercadolibre.com.co/es_co/referencias-de-precios) |
| MOD-09-EP-0299 | `GET` | `/suggestions/user/12345678/items` | lectura/consulta | [Gestionar referencias de precios](https://developers.mercadolibre.com.co/es_co/referencias-de-precios) |
| MOD-09-EP-0300 | `GET` | `/user-product/$USER-PRODUCT-ID/performance` | lectura/consulta | [Calidad de publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones) |
| MOD-09-EP-0301 | `GET` | `/user-product/MLAU395977691/performance` | lectura/consulta | [Calidad de publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones) |
| MOD-09-EP-0302 | `GET` | `/user-products/{id}/stock/type/seller_warehouse` | lectura/consulta | [Gestión de stock en convivencia Full/Flex (MLA y MLC)](https://developers.mercadolibre.com.co/es_co/convivencia-full-y-flex) |
| MOD-09-EP-0303 | `GET` | `/user-products/{up_id}/compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0304 | `GET` | `/user-products/{up_id}/compatibilities?main_domain_id=` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0305 | `GET` | `/user-products/{up_id}/compatibilities/copy-paste` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0306 | `GET` | `/user-products/{user_product_id` | lectura/consulta | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0307 | `GET` | `/user-products/{user_product_id}/stock` | lectura/consulta | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0308 | `GET` | `/user-products/{user_product_id}/stock:` | lectura/consulta | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0309 | `GET` | `/user-products/&USER_PRODUCT_ID/compatibilities?main_domain_id=MLM-CARS_AND_VANS_FOR_COMPATIBILITIES&extended=true` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0310 | `GET` | `/user-products/$USER_PRODUCT_ID/bundles` | lectura/consulta | [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) |
| MOD-09-EP-0311 | `GET` | `/user-products/$USER_PRODUCT_ID/compatibilities/exception` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0312 | `GET` | `/user-products/$USER_PRODUCT_ID/stock` | lectura/consulta | [Gestión de stock en convivencia Full/Flex (MLA y MLC)](https://developers.mercadolibre.com.co/es_co/convivencia-full-y-flex) |
| MOD-09-EP-0313 | `GET` | `/user-products/$USER_PRODUCT_ID/stock/type/seller_warehouse` | lectura/consulta | [Stock distribuido](https://developers.mercadolibre.com.co/es_co/stock-distribuido) |
| MOD-09-EP-0314 | `GET` | `/user-products/$USER_PRODUCT_ID/stock/type/selling_address` | lectura/consulta | [Gestión de stock en convivencia Full/Flex (MLA y MLC)](https://developers.mercadolibre.com.co/es_co/convivencia-full-y-flex) |
| MOD-09-EP-0315 | `GET` | `/user-products/MLAU12345678/stock` | lectura/consulta | [Gestión de stock en convivencia Full/Flex (MLA y MLC)](https://developers.mercadolibre.com.co/es_co/convivencia-full-y-flex) |
| MOD-09-EP-0316 | `GET` | `/user-products/MLAU12345678/stock/type/selling_address` | lectura/consulta | [Gestión de stock en convivencia Full/Flex (MLA y MLC)](https://developers.mercadolibre.com.co/es_co/convivencia-full-y-flex) |
| MOD-09-EP-0317 | `GET` | `/user-products/MLAU123456789/stock` | lectura/consulta | [Stock distribuido](https://developers.mercadolibre.com.co/es_co/stock-distribuido) |
| MOD-09-EP-0318 | `GET` | `/user-products/MLAU2339836140/compatibilities/exception` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0319 | `GET` | `/user-products/MLMU123456789/stock` | lectura/consulta | [Stock Multi Origen](https://developers.mercadolibre.com.co/es_co/stock-multi-origen) |
| MOD-09-EP-0320 | `GET` | `/user-products/MLMU123456789/stock/type/seller_warehouse` | lectura/consulta | [Stock Multi Origen](https://developers.mercadolibre.com.co/es_co/stock-multi-origen) |
| MOD-09-EP-0321 | `GET` | `/user-products/MLMU427597763/compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0322 | `GET` | `/user-products/MLMU427597763/compatibilities?main_domain_id=MLM-CARS_AND_VANS_FOR_COMPATIBILITIES&extended=true` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0323 | `GET` | `/user-products/MLMU427597763/compatibilities/copy-paste` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0324 | `GET` | `/user-products/MLU1443000156/compatibilities/exception` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0325 | `GET` | `/users:` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0326 | `GET` | `/users?ids=$USER_ID1` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0327 | `GET` | `/users?ids=$USER_ID1,$USER_ID2` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0328 | `GET` | `/users?ids=401114259,287440999` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0329 | `GET` | `/users/$SELLER_ID/items/search?reputation_health_gauge=unhealthy` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0330 | `GET` | `/users/$SELLER_ID/items/search?status=active&tags=catalog_boost` | lectura/consulta | [Publicar en catálogo](https://developers.mercadolibre.com.co/es_co/publicacion-en-catalogo) |
| MOD-09-EP-0331 | `GET` | `/users/$SELLER_ID/items/search?tags=incomplete_compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0332 | `GET` | `/users/$SELLER_ID/items/search?tags=pending_compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0333 | `GET` | `/users/$SELLER_ID/kits/components/search?searchText=$STRING&limit=2` | lectura/consulta | [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) |
| MOD-09-EP-0334 | `GET` | `/users/$USER_ID/invoices/$INVOICE_ID` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0335 | `GET` | `/users/$USER_ID/items` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0336 | `GET` | `/users/$USER_ID/items?offset=100&limit=100` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0337 | `GET` | `/users/$USER_ID/items/search` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0338 | `GET` | `/users/$USER_ID/items/search?` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0339 | `GET` | `/users/$USER_ID/items/search?catalog_listing=false` | lectura/consulta | [Elegibilidad de catálogo](https://developers.mercadolibre.com.co/es_co/elegibilidad-catalogo) |
| MOD-09-EP-0340 | `GET` | `/users/$USER_ID/items/search?catalog_listing=true` | lectura/consulta | [Elegibilidad de catálogo](https://developers.mercadolibre.com.co/es_co/elegibilidad-catalogo) |
| MOD-09-EP-0341 | `GET` | `/users/$USER_ID/items/search?include_filters=true` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0342 | `GET` | `/users/$USER_ID/items/search?listing_type_id=gold_pro` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0343 | `GET` | `/users/$USER_ID/items/search?missing_product_identifiers=true` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0344 | `GET` | `/users/$USER_ID/items/search?orders=start_time_desc` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0345 | `GET` | `/users/$USER_ID/items/search?search_type=scan` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0346 | `GET` | `/users/$USER_ID/items/search?search_type=scan&scroll_id=YXBpY29yZS1pdGVtcw==:ZHMtYXBpY29yZS1pdGVtcy0wMQ==:DXF1ZXJ5QW5kRmV0Y2gBAAAAABIu7AgWMXl6anF3SU5SMVNaQXFxTkZubHBqQQ==` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0347 | `GET` | `/users/$USER_ID/items/search?seller_sku=$SELLER_SKU` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0348 | `GET` | `/users/$USER_ID/items/search?sku=$SELLER_CUSTOM_FIELD` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0349 | `GET` | `/users/$USER_ID/items/search?status=active` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0350 | `GET` | `/users/$USER_ID/items/search?status=active&has_compatibilities=true` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0351 | `GET` | `/users/$USER_ID/items/search?tags=catalog_forewarning` | lectura/consulta | [Publicaciones requeridas](https://developers.mercadolibre.com.co/es_co/publicaciones-requeridas-en-catalogo) |
| MOD-09-EP-0352 | `GET` | `/users/$USER_ID/items/search?tags=catalog_listing_eligible` | lectura/consulta | [Elegibilidad de catálogo](https://developers.mercadolibre.com.co/es_co/elegibilidad-catalogo) |
| MOD-09-EP-0353 | `GET` | `/users/$USER_ID/items/search/restrictions` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0354 | `GET` | `/users/$USER_ID/stores/search?tags=stock_location` | lectura/consulta | [Stock Multi Origen](https://developers.mercadolibre.com.co/es_co/stock-multi-origen) |
| MOD-09-EP-0355 | `GET` | `/users/1008002397` | lectura/consulta | [Stock Multi Origen](https://developers.mercadolibre.com.co/es_co/stock-multi-origen) |
| MOD-09-EP-0356 | `GET` | `/users/1008002397/stores/search?tags=stock_location` | lectura/consulta | [Stock Multi Origen](https://developers.mercadolibre.com.co/es_co/stock-multi-origen) |
| MOD-09-EP-0357 | `GET` | `/users/123456/items/search?tags=catalog_forewarning` | lectura/consulta | [Publicaciones requeridas](https://developers.mercadolibre.com.co/es_co/publicaciones-requeridas-en-catalogo) |
| MOD-09-EP-0358 | `GET` | `/users/123456789/invoices/$INVOICE_ID` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0359 | `GET` | `/users/123456789/items/search?catalog_listing=false` | lectura/consulta | [Elegibilidad de catálogo](https://developers.mercadolibre.com.co/es_co/elegibilidad-catalogo) |
| MOD-09-EP-0360 | `GET` | `/users/123456789/items/search?catalog_listing=true` | lectura/consulta | [Elegibilidad de catálogo](https://developers.mercadolibre.com.co/es_co/elegibilidad-catalogo) |
| MOD-09-EP-0361 | `GET` | `/users/123456789/items/search?reputation_health_gauge=unhealthy` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0362 | `GET` | `/users/123456789/items/search?tags=catalog_listing_eligible` | lectura/consulta | [Elegibilidad de catálogo](https://developers.mercadolibre.com.co/es_co/elegibilidad-catalogo) |
| MOD-09-EP-0363 | `GET` | `/users/123456789/items/search/restrictions` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0364 | `GET` | `/users/1695976736/items/search?tags=pending_compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0365 | `GET` | `/users/1871678972/items/search?tags=incomplete_position_compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0366 | `GET` | `/users/206946886` | lectura/consulta | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0367 | `GET` | `/v1/claims/search?reason_id=$reason_id` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0368 | `GET` | `/vis/loans/$CREDIT_ID?seller_id=$SELLER_ID` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0369 | `GET` | `/vis/users/$USER_ID/leads` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0370 | `POST` | `/business/v1/sites/$SITE_ID/users/$USER_ID/items/$ITEM_ID/options/net-prices/seller/eligibility` | mutación | [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) |
| MOD-09-EP-0371 | `POST` | `/business/v1/sites/MLB/users/655590662/items/MLB4177849003/options/net-prices/seller/eligibility` | mutación | [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) |
| MOD-09-EP-0372 | `POST` | `/catalog_compatibilities/products_search/count_family_products` | mutación | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0373 | `POST` | `/catalog_domains/MLB-CARS_AND_VANS/compatibilities/cards` | mutación | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0374 | `POST` | `/catalog/charts` | mutación | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0375 | `POST` | `/catalog/charts/$chart_id` | mutación | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0376 | `POST` | `/catalog/charts/$CHART_ID` | mutación | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0377 | `POST` | `/catalog/charts/$chart_id/rows` | mutación | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0378 | `POST` | `/catalog/charts/$CHART_ID/rows` | mutación | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0379 | `POST` | `/catalog/charts/$chart_id/rows/$row_id` | mutación | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0380 | `POST` | `/catalog/charts/$CHART_ID/rows/$ROW_ID` | mutación | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0381 | `POST` | `/catalog/charts/124125` | mutación | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0382 | `POST` | `/catalog/charts/232382` | mutación | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0383 | `POST` | `/catalog/charts/4/rows` | mutación | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0384 | `POST` | `/catalog/charts/5` | mutación | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0385 | `POST` | `/catalog/charts/569686/rows/1` | mutación | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0386 | `POST` | `/items/{item_id` | mutación | [Imágenes en publicaciones](https://developers.mercadolibre.com.co/es_co/trabajar-con-imagenes) |
| MOD-09-EP-0387 | `POST` | `/items/{ITEM_ID` | mutación | [Actualiza tus publicaciones](https://developers.mercadolibre.com.co/es_co/actualiza-tus-publicaciones) |
| MOD-09-EP-0388 | `POST` | `/items/$ITEM_ID/compatibilities` | mutación | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0389 | `POST` | `/items/$ITEM_ID/description` | mutación | [Descripción de productos](https://developers.mercadolibre.com.co/es_co/descripcion-de-articulos) |
| MOD-09-EP-0390 | `POST` | `/items/$ITEM_ID/prices` | mutación | [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) |
| MOD-09-EP-0391 | `POST` | `/items/$ITEM_ID/prices/standard` | mutación | [Precios de productos](https://developers.mercadolibre.com.co/es_co/api-de-precios) |
| MOD-09-EP-0392 | `POST` | `/items/$ITEM_ID/prices/standard/quantity` | mutación | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0393 | `POST` | `/items/$ITEM_ID/sale_price?context=channel_marketplace,user_type_business&quantity=6&destination_states=BR-SP&buyer_id=$BUYER_ID` | mutación | [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) |
| MOD-09-EP-0394 | `POST` | `/items/$ITEMS_ID/prices` | mutación | [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) |
| MOD-09-EP-0395 | `POST` | `/items/catalog_domains/$DOMAIN_ID/compatibilities/cards` | mutación | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0396 | `POST` | `/items/catalog_listings` | mutación | [Productos reacondicionados](https://developers.mercadolibre.com.co/es_co/catalogo-reacondicionados) |
| MOD-09-EP-0397 | `POST` | `/items/compatibilities_summary` | mutación | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0398 | `POST` | `/items/kits` | mutación | [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) |
| MOD-09-EP-0399 | `POST` | `/items/MLA421101451/pictures` | mutación | [Imágenes en publicaciones](https://developers.mercadolibre.com.co/es_co/trabajar-con-imagenes) |
| MOD-09-EP-0400 | `POST` | `/items/MLA794706391/compatibilities` | mutación | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0401 | `POST` | `/items/MLA821614634/relist` | mutación | [Republicar ítems](https://developers.mercadolibre.com.co/es_co/re-publica) |
| MOD-09-EP-0402 | `POST` | `/items/MLA832998780/relist` | mutación | [Republicar ítems](https://developers.mercadolibre.com.co/es_co/re-publica) |
| MOD-09-EP-0403 | `POST` | `/items/MLB123450000/prices/standard/quantity` | mutación | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0404 | `POST` | `/items/MLB123456789/prices/standard/quantity` | mutación | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0405 | `POST` | `/items/MLB3863097751/compatibilities` | mutación | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0406 | `POST` | `/items/MLB558680985` | mutación | [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) |
| MOD-09-EP-0407 | `POST` | `/items/MLB5586809854/prices` | mutación | [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) |
| MOD-09-EP-0408 | `POST` | `/items/MLB5586809854/sale_price?context=channel_marketplace,user_type_business&quantity=6&destination_states=BR-SP&buyer_id=655590662` | mutación | [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) |
| MOD-09-EP-0409 | `POST` | `/items/MLB5593631496/prices/standard/quantity` | mutación | [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) |
| MOD-09-EP-0410 | `POST` | `/items/MLM1881484643` | mutación | [Publicar en catálogo](https://developers.mercadolibre.com.co/es_co/publicacion-en-catalogo) |
| MOD-09-EP-0411 | `POST` | `/items/MLM794706391/compatibilities` | mutación | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0412 | `POST` | `/items/validate` | mutación | [Validador de publicaciones](https://developers.mercadolibre.com.co/es_co/validador-de-publicaciones) |
| MOD-09-EP-0413 | `POST` | `/moderations/pppi/case/12344` | mutación | [Publicaciones denunciadas](https://developers.mercadolibre.com.co/es_co/publicaciones-denunciadas) |
| MOD-09-EP-0414 | `POST` | `/pictures/$PICTURE_ID/errors?` | mutación | [Imágenes en publicaciones](https://developers.mercadolibre.com.co/es_co/trabajar-con-imagenes) |
| MOD-09-EP-0415 | `POST` | `/pictures/970736-MLU11111111111_092017/errors` | mutación | [Imágenes en publicaciones](https://developers.mercadolibre.com.co/es_co/trabajar-con-imagenes) |
| MOD-09-EP-0416 | `POST` | `/pictures/items/upload` | mutación | [Imágenes en publicaciones](https://developers.mercadolibre.com.co/es_co/trabajar-con-imagenes) |
| MOD-09-EP-0417 | `POST` | `/pricing-automation/items/$ITEM_ID/automation` | mutación | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0418 | `POST` | `/pricing-automation/items/$ITEM_ID/automation/by-product/$CATALOG_PRODUCT_ID` | mutación | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0419 | `POST` | `/pricing-automation/items/MLA12345678/automation` | mutación | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0420 | `POST` | `/pricing-automation/items/MLB4211305575/automation/by-product/MLB38607446` | mutación | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0421 | `POST` | `/products/search` | mutación | [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) |
| MOD-09-EP-0422 | `POST` | `/public/buybox/sync` | mutación | [Publicar en catálogo](https://developers.mercadolibre.com.co/es_co/publicacion-en-catalogo) |
| MOD-09-EP-0423 | `POST` | `/public/buybox/sync/$ITEM_ID` | mutación | [Publicar en catálogo](https://developers.mercadolibre.com.co/es_co/publicacion-en-catalogo) |
| MOD-09-EP-0424 | `POST` | `/seller-promotions/items/$ITEM_ID` | mutación | [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item) |
| MOD-09-EP-0425 | `POST` | `/seller-promotions/items/$ITEM_ID?app_version=v2` | mutación | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0426 | `POST` | `/seller-promotions/items/MLB10203040` | mutación | [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item) |
| MOD-09-EP-0427 | `POST` | `/seller-promotions/items/MLB3538191898?app_version=v2` | mutación | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0428 | `POST` | `/seller-promotions/items/MLB4048719074?app_version=v2` | mutación | [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) |
| MOD-09-EP-0429 | `POST` | `/seller-promotions/items/MLM848619385` | mutación | [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item) |
| MOD-09-EP-0430 | `POST` | `/stock/type/seller_warehouse` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0431 | `POST` | `/user-products` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0432 | `POST` | `/user-products/{up_id}/compatibilities/copy-paste` | mutación | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0433 | `POST` | `/user-products/{user_product_id` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0434 | `POST` | `/user-products/{user_product_id}/stock/type/seller_warehouse` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0435 | `POST` | `/user-products/{user_product_id}/stock/type/seller_warehouse:` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0436 | `POST` | `/user-products/MLMU427597763/compatibilities/copy-paste` | mutación | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0437 | `POST` | `/users/$SELLER_ID/items/search?status=active&tags=catalog_boost` | mutación | [Publicar en catálogo](https://developers.mercadolibre.com.co/es_co/publicacion-en-catalogo) |
| MOD-09-EP-0438 | `PUT` | `/catalog/charts/$CHART_ID` | mutación | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0439 | `PUT` | `/catalog/charts/$CHART_ID/rows/$ROW_ID` | mutación | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0440 | `PUT` | `/catalog/charts/5` | mutación | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0441 | `PUT` | `/catalog/charts/569686/rows/1` | mutación | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0442 | `PUT` | `/categories/$CATEGORY_ID/sale_terms` | mutación | [Sincroniza y modifica publicaciones](https://developers.mercadolibre.com.co/es_co/producto-sincroniza-modifica-publicaciones) |
| MOD-09-EP-0443 | `PUT` | `/categories/MLA1577/sale_terms` | mutación | [Sincroniza y modifica publicaciones](https://developers.mercadolibre.com.co/es_co/producto-sincroniza-modifica-publicaciones) |
| MOD-09-EP-0444 | `PUT` | `/categories/MLM167991/sale_terms` | mutación | [Sincroniza y modifica publicaciones](https://developers.mercadolibre.com.co/es_co/producto-sincroniza-modifica-publicaciones) |
| MOD-09-EP-0445 | `PUT` | `/items` | mutación | [Actualiza tus publicaciones](https://developers.mercadolibre.com.co/es_co/actualiza-tus-publicaciones) |
| MOD-09-EP-0446 | `PUT` | `/items/{item_id` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0447 | `PUT` | `/items/{ITEM_ID` | mutación | [Actualiza tus publicaciones](https://developers.mercadolibre.com.co/es_co/actualiza-tus-publicaciones) |
| MOD-09-EP-0448 | `PUT` | `/items/{item_id}` | mutación | [Actualiza tus publicaciones](https://developers.mercadolibre.com.co/es_co/actualiza-tus-publicaciones) |
| MOD-09-EP-0449 | `PUT` | `/items/{item_id}:` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0450 | `PUT` | `/items/$ITEM_ID/bundle/prices_configuration` | mutación | [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) |
| MOD-09-EP-0451 | `PUT` | `/items/$ITEM_ID/compatibilities` | mutación | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0452 | `PUT` | `/items/$ITEM_ID/description?api_version=2` | mutación | [Descripción de productos](https://developers.mercadolibre.com.co/es_co/descripcion-de-articulos) |
| MOD-09-EP-0453 | `PUT` | `/items/11000222` | mutación | [Sincroniza y modifica publicaciones](https://developers.mercadolibre.com.co/es_co/producto-sincroniza-modifica-publicaciones) |
| MOD-09-EP-0454 | `PUT` | `/items/110002223` | mutación | [Sincroniza y modifica publicaciones](https://developers.mercadolibre.com.co/es_co/producto-sincroniza-modifica-publicaciones) |
| MOD-09-EP-0455 | `PUT` | `/items/11122233` | mutación | [Sincroniza y modifica publicaciones](https://developers.mercadolibre.com.co/es_co/producto-sincroniza-modifica-publicaciones) |
| MOD-09-EP-0456 | `PUT` | `/items/MLA658778048` | mutación | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0457 | `PUT` | `/items/MLA832998780` | mutación | [Republicar ítems](https://developers.mercadolibre.com.co/es_co/re-publica) |
| MOD-09-EP-0458 | `PUT` | `/items/MLB3863034063/compatibilities` | mutación | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0459 | `PUT` | `/items/MLM12456789/compatibilities` | mutación | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0460 | `PUT` | `/items/MLM623075370` | mutación | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0461 | `PUT` | `/pricing-automation/items/$ITEM_ID/automation` | mutación | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0462 | `PUT` | `/pricing-automation/items/MLA12345678/automation` | mutación | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0463 | `PUT` | `/stock` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0464 | `PUT` | `/stock/type/seller_warehouse` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0465 | `PUT` | `/stock/type/selling_address` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0466 | `PUT` | `/user-products` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0467 | `PUT` | `/user-products/{id}/stock?` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0468 | `PUT` | `/user-products/{id}/stock/type/seller_warehouse` | mutación | [Gestión de stock en convivencia Full/Flex (MLA y MLC)](https://developers.mercadolibre.com.co/es_co/convivencia-full-y-flex) |
| MOD-09-EP-0469 | `PUT` | `/user-products/{id}/stock/type/selling_address` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0470 | `PUT` | `/user-products/{user_product_id` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0471 | `PUT` | `/user-products/{user_product_id}/stock` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0472 | `PUT` | `/user-products/{user_product_id}/stock:` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0473 | `PUT` | `/user-products/{user_product_id}/stock/type/{seller_warehouse}` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0474 | `PUT` | `/user-products/{user_product_id}/stock/type/seller_warehouse` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0475 | `PUT` | `/user-products/{user_product_id}/stock/type/seller_warehouse:` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0476 | `PUT` | `/user-products/{user_product_id}/stock/type/selling_address` | mutación | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0477 | `PUT` | `/user-products/$USER_PRODUCT_ID/stock/type/seller_warehouse` | mutación | [Stock distribuido](https://developers.mercadolibre.com.co/es_co/stock-distribuido) |
| MOD-09-EP-0478 | `PUT` | `/user-products/$USER_PRODUCT_ID/stock/type/selling_address` | mutación | [Gestión de stock en convivencia Full/Flex (MLA y MLC)](https://developers.mercadolibre.com.co/es_co/convivencia-full-y-flex) |
| MOD-09-EP-0479 | `PUT` | `/user-products/MLAU12345678/stock/type/selling_address` | mutación | [Gestión de stock en convivencia Full/Flex (MLA y MLC)](https://developers.mercadolibre.com.co/es_co/convivencia-full-y-flex) |
| MOD-09-EP-0480 | `PUT` | `/user-products/MLMU123456789/stock/type/seller_warehouse` | mutación | [Stock Multi Origen](https://developers.mercadolibre.com.co/es_co/stock-multi-origen) |
| MOD-09-EP-0481 | `UNKNOWN` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ad_groups/search?filters[item_ids` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0482 | `UNKNOWN` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ads/search?filters[item_id` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0483 | `UNKNOWN` | `/advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/campaigns/$CAMPAIGN_ID/ads/metrics?date_from=2025-10-28&date_to=2025-10-29&filters[item_ids` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0484 | `UNKNOWN` | `/advertising/$ADVERTISER_SITE_ID/product_ads/ad_groups/$AD_GROUP_ID` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0485 | `UNKNOWN` | `/advertising/$ADVERTISER_SITE_ID/product_ads/campaigns/$CAMPAIGN_ID?date_from=2025-12-01&date_to=2025-12-30&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount,impression_share,top_impression_share,lost_impression_share_by_budget,lost_impression_share_by_ad_rank,acos_benchmark` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0486 | `UNKNOWN` | `/advertising/$ADVERTISER_SITE_ID/product_ads/campaigns/$CAMPAIGN_ID/ad_groups/metrics?date_from=2026-04-01&date_to=2026-04-01&metrics=clicks,prints,cost,cpc,ctr,direct_amount,indirect_amount,total_amount,direct_units_quantity,indirect_units_quantity,units_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,organic_units_quantity,organic_units_amount,organic_items_quantity,acos,sov,roas,cvr,tacos` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0487 | `UNKNOWN` | `/advertising/MLA/advertisers/882927/product_ads/ad_groups/search?filters[item_ids` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0488 | `UNKNOWN` | `/advertising/MLM/product_ads/ad_groups/65867?date_from=2025-08-31&date_to=2025-09-30&metrics=CLICKS,PRINTS,COST,CPC,CTR,DIRECT_AMOUNT,INDIRECT_AMOUNT,TOTAL_AMOUNT,DIRECT_UNITS_QUANTITY,INDIRECT_UNITS_QUANTITY,UNITS_QUANTITY,DIRECT_ITEMS_QUANTITY,INDIRECT_ITEMS_QUANTITY,ADVERTISING_ITEMS_QUANTITY,ORGANIC_UNITS_QUANTITY,ORGANIC_UNITS_AMOUNT,ORGANIC_ITEMS_QUANTITY,ACOS` | lectura/consulta | [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) |
| MOD-09-EP-0489 | `UNKNOWN` | `/business/v1/sites/$SITE_ID/users/$USER_ID/items/$ITEM_ID/options/net-prices/seller/eligibility` | pricing | [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) |
| MOD-09-EP-0490 | `UNKNOWN` | `/business/v1/sites/MLB/users/655590662/items/MLB4177849003/options/net-prices/seller/eligibility` | pricing | [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) |
| MOD-09-EP-0491 | `UNKNOWN` | `/catalog_compatibilities/restrictions/values?main_domain_id=MLA-CARS_AND_VANS&secondary_domain_id=MLA-VEHICLE_ENGINE_MOUNTS` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0492 | `UNKNOWN` | `/catalog_domains/$DOMAIN_ID/attributes/GENDER` | lectura/consulta | [Validación de guía de talles](https://developers.mercadolibre.com.co/es_co/validacion-de-guia-de-talles) |
| MOD-09-EP-0493 | `UNKNOWN` | `/catalog_suggestions/MLA123456` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0494 | `UNKNOWN` | `/catalog/charts` | lectura/consulta | [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) |
| MOD-09-EP-0495 | `UNKNOWN` | `/categories/$CATEGORY_ID` | lectura/consulta | [Validaciones](https://developers.mercadolibre.com.co/es_co/validaciones) |
| MOD-09-EP-0496 | `UNKNOWN` | `/categories/$CATEGORY_ID/$TYPE_ID` | lectura/consulta | [Validaciones](https://developers.mercadolibre.com.co/es_co/validaciones) |
| MOD-09-EP-0497 | `UNKNOWN` | `/categories/$CATEGORY_ID/attributes` | lectura/consulta | [Identificadores de productos](https://developers.mercadolibre.com.co/es_co/identificadores-de-productos) |
| MOD-09-EP-0498 | `UNKNOWN` | `/categories/$CATEGORY_ID/attributes:` | lectura/consulta | [Identificadores de productos](https://developers.mercadolibre.com.co/es_co/identificadores-de-productos) |
| MOD-09-EP-0499 | `UNKNOWN` | `/claims/5108684499` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0500 | `UNKNOWN` | `/collections/3043111111` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0501 | `UNKNOWN` | `/items/` | lectura/consulta | [Identificadores de productos](https://developers.mercadolibre.com.co/es_co/identificadores-de-productos) |
| MOD-09-EP-0502 | `UNKNOWN` | `/items/{item_id}` | lectura/consulta | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0503 | `UNKNOWN` | `/items/{item_id}/compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0504 | `UNKNOWN` | `/items/{item_id}/compatibilities?extended=true` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0505 | `UNKNOWN` | `/items/{item_id}/compatibilities/{compatibility_id}` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0506 | `UNKNOWN` | `/items/$ITEM_ID` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0507 | `UNKNOWN` | `/items/$ITEM_ID?include_attributes=all` | lectura/consulta | [Identificadores de productos](https://developers.mercadolibre.com.co/es_co/identificadores-de-productos) |
| MOD-09-EP-0508 | `UNKNOWN` | `/items/$ITEM_ID/details` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0509 | `UNKNOWN` | `/items/$ITEM_ID/prices` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0510 | `UNKNOWN` | `/items/$ITEM_ID/prices/price-per-quantity` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0511 | `UNKNOWN` | `/items/$ITEM_ID/sale_price?context=channel_marketplace` | pricing | [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) |
| MOD-09-EP-0512 | `UNKNOWN` | `/items/$ITEM_ID/sale_price?context=channel_marketplace,user_type_business&quantity=6&destination_states=BR-SP&buyer_id=$BUYER_ID` | pricing | [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) |
| MOD-09-EP-0513 | `UNKNOWN` | `/items/$ITEM_ID/sale_price?quantity=5` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0514 | `UNKNOWN` | `/items/catalog_listings` | lectura/consulta | [Productos reacondicionados](https://developers.mercadolibre.com.co/es_co/catalogo-reacondicionados) |
| MOD-09-EP-0515 | `UNKNOWN` | `/items/ITEM_ID/price_to_win` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0516 | `UNKNOWN` | `/items/kits` | lectura/consulta | [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) |
| MOD-09-EP-0517 | `UNKNOWN` | `/items/MLA1363353921` | lectura/consulta | [Identificadores de productos](https://developers.mercadolibre.com.co/es_co/identificadores-de-productos) |
| MOD-09-EP-0518 | `UNKNOWN` | `/items/MLA1378022956` | lectura/consulta | [Identificadores de productos](https://developers.mercadolibre.com.co/es_co/identificadores-de-productos) |
| MOD-09-EP-0519 | `UNKNOWN` | `/items/MLA640992661?include_attributes=all` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) |
| MOD-09-EP-0520 | `UNKNOWN` | `/items/MLA686791111` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0521 | `UNKNOWN` | `/items/MLA820048955` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0522 | `UNKNOWN` | `/items/MLB4642967339/prices/price-per-quantity` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0523 | `UNKNOWN` | `/items/MLB558680985` | lectura/consulta | [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) |
| MOD-09-EP-0524 | `UNKNOWN` | `/items/MLB5586809854/sale_price?context=channel_marketplace,user_type_business&quantity=6&destination_states=BR-SP&buyer_id=655590662` | pricing | [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) |
| MOD-09-EP-0525 | `UNKNOWN` | `/items/MLB6646853040/prices` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0526 | `UNKNOWN` | `/items/MLB6646853040/sale_price?quantity=5` | pricing | [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) |
| MOD-09-EP-0527 | `UNKNOWN` | `/items/MLB6713483676` | lectura/consulta | [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) |
| MOD-09-EP-0528 | `UNKNOWN` | `/items/multiwarehouse` | lectura/consulta | [Stock Multi Origen](https://developers.mercadolibre.com.co/es_co/stock-multi-origen) |
| MOD-09-EP-0529 | `UNKNOWN` | `/items/y` | lectura/consulta | [Identificadores de productos](https://developers.mercadolibre.com.co/es_co/identificadores-de-productos) |
| MOD-09-EP-0530 | `UNKNOWN` | `/moderations/pictures/diagnostic` | lectura/consulta | [Diagnóstico de imágenes](https://developers.mercadolibre.com.co/es_co/diagnostico-imagenes) |
| MOD-09-EP-0531 | `UNKNOWN` | `/orders/2195160686` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0532 | `UNKNOWN` | `/pictures/items/upload` | lectura/consulta | [Moderaciones de imágenes](https://developers.mercadolibre.com.co/es_co/moderaciones-de-imagenes) |
| MOD-09-EP-0533 | `UNKNOWN` | `/prices_configuration` | pricing | [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) |
| MOD-09-EP-0534 | `UNKNOWN` | `/products/search` | lectura/consulta | [Productos reacondicionados](https://developers.mercadolibre.com.co/es_co/catalogo-reacondicionados) |
| MOD-09-EP-0535 | `UNKNOWN` | `/questions/5036111111` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0536 | `UNKNOWN` | `/sale_price` | pricing | [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) |
| MOD-09-EP-0537 | `UNKNOWN` | `/seller-promotions/candidates/CANDIDATE-MLA1111111111-11111111` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0538 | `UNKNOWN` | `/seller-promotions/offers/1234567` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0539 | `UNKNOWN` | `/shipments/40546549876` | envíos | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0540 | `UNKNOWN` | `/sites/categories/$CATEGORY_ID` | lectura/consulta | [Publicar productos](https://developers.mercadolibre.com.co/es_co/publica-productos) |
| MOD-09-EP-0541 | `UNKNOWN` | `/sites/MLA/shipments/407323124706/assignment/v1` | envíos | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0542 | `UNKNOWN` | `/stock-availability-default` | lectura/consulta | [Calidad de publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones) |
| MOD-09-EP-0543 | `UNKNOWN` | `/stock-sku-default` | lectura/consulta | [Calidad de publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones) |
| MOD-09-EP-0544 | `UNKNOWN` | `/stock/fulfillment/operations/9876` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0545 | `UNKNOWN` | `/user-products` | lectura/consulta | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0546 | `UNKNOWN` | `/user-products/{id}/stock` | lectura/consulta | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0547 | `UNKNOWN` | `/user-products/{id}/stock/type/selling_address` | lectura/consulta | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0548 | `UNKNOWN` | `/user-products/{up_id}/compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0549 | `UNKNOWN` | `/user-products/{up_id}/compatibilities?main_domain_id=` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0550 | `UNKNOWN` | `/user-products/{up_id}/compatibilities/copy-paste` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0551 | `UNKNOWN` | `/user-products/{user_product_id}/stock` | lectura/consulta | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0552 | `UNKNOWN` | `/user-products/{user_product_id}/stock/type/{seller_warehouse}` | lectura/consulta | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0553 | `UNKNOWN` | `/user-products/{user_product_id}/stock/type/seller_warehouse` | lectura/consulta | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0554 | `UNKNOWN` | `/user-products/{user_product_id}/stock/type/selling_address` | lectura/consulta | [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) |
| MOD-09-EP-0555 | `UNKNOWN` | `/user-products/$USER_PRODUCT_ID/stock` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0556 | `UNKNOWN` | `/user-products/$USER_PRODUCT_ID/stock/type/seller_warehouse` | lectura/consulta | [Stock distribuido](https://developers.mercadolibre.com.co/es_co/stock-distribuido) |
| MOD-09-EP-0557 | `UNKNOWN` | `/user-products/MLMU427597763/compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |
| MOD-09-EP-0558 | `UNKNOWN` | `/users/$SELLER_ID/kits/components/search?searchText=$STRING&limit=2` | lectura/consulta | [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) |
| MOD-09-EP-0559 | `UNKNOWN` | `/users/$USER_ID/items/search` | lectura/consulta | [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) |
| MOD-09-EP-0560 | `UNKNOWN` | `/users/123456789/invoices/$INVOICE_ID` | lectura/consulta | [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) |
| MOD-09-EP-0561 | `UNKNOWN` | `/users/1871678972/items/search?tags=incomplete_position_compatibilities` | lectura/consulta | [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) |

### Señales de autenticación/permisos

- [Actualiza tus publicaciones](https://developers.mercadolibre.com.co/es_co/actualiza-tus-publicaciones): `Authorization`, `Content-Type`, `Accept`, `access_token`, `Bearer`, `ACCESS_TOKEN`
- [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Calidad de publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones): `Authorization`, `Bearer`, `ACCESS_TOKEN`, `access_token`
- [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Categorización de productos](https://developers.mercadolibre.com.co/es_co/categoriza-productos): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos): `Authorization`, `Content-Type`, `content-type`, `Bearer`, `ACCESS_TOKEN`
- [Competencia](https://developers.mercadolibre.com.co/es_co/competencia-en-catalogo): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Descripción de productos](https://developers.mercadolibre.com.co/es_co/descripcion-de-articulos): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Diagnóstico de imágenes](https://developers.mercadolibre.com.co/es_co/diagnostico-imagenes): `Content-Type`, `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Elegibilidad de catálogo](https://developers.mercadolibre.com.co/es_co/elegibilidad-catalogo): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Gestión de stock en convivencia Full/Flex (MLA y MLC)](https://developers.mercadolibre.com.co/es_co/convivencia-full-y-flex): `Authorization`, `x-version`, `Content-Type`, `X-Version`, `Bearer`, `ACCESS_TOKEN`
- [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad): `Authorization`, `Content-Type`, `x-version`, `Bearer`, `ACCESS_TOKEN`
- [Gestionar referencias de precios](https://developers.mercadolibre.com.co/es_co/referencias-de-precios): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Identificadores de productos](https://developers.mercadolibre.com.co/es_co/identificadores-de-productos): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Imágenes en publicaciones](https://developers.mercadolibre.com.co/es_co/trabajar-con-imagenes): `Authorization`, `content-type`, `Content-Type`, `Accept`, `Bearer`, `ACCESS_TOKEN`
- [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales): `Content-Type`, `Accept`, `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Moderaciones de imágenes](https://developers.mercadolibre.com.co/es_co/moderaciones-de-imagenes): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones): `Authorization`, `accept`, `content-type`, `Bearer`, `ACCESS_TOKEN`, `read`
- [Opiniones de productos](https://developers.mercadolibre.com.co/es_co/opiniones-sobre-producto): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- _13 páginas adicionales con señales en el JSON._

## Promociones y pricing

id: MOD-10

Resumen: 11 páginas fuente, 109 endpoints/rutas, integraciones detectadas: Mercado Pago, OAuth.

### Páginas fuente

| ID | Página | Estado | Endpoints | Fechas visibles |
|---|---|---|---:|---|
| MOD-10-PAGE-001 | [Campaña co-fondeada para PIX](https://developers.mercadolibre.com.co/es_co/pix) | `ok` | 8 | — |
| MOD-10-PAGE-002 | [Campañas co-fondeadas](https://developers.mercadolibre.com.co/es_co/campanas-co-fondeadas) | `ok` | 10 | — |
| MOD-10-PAGE-003 | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) | `ok` | 18 | — |
| MOD-10-PAGE-004 | [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) | `ok` | 16 | — |
| MOD-10-PAGE-005 | [Campañas del vendedor](https://developers.mercadolibre.com.co/es_co/campanas-del-vendedor) | `ok` | 17 | — |
| MOD-10-PAGE-006 | [Campañas tradicionales](https://developers.mercadolibre.com.co/es_co/deals) | `ok` | 14 | — |
| MOD-10-PAGE-007 | [Descuento individual](https://developers.mercadolibre.com.co/es_co/descuento-individual) | `ok` | 8 | — |
| MOD-10-PAGE-008 | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) | `ok` | 26 | — |
| MOD-10-PAGE-009 | [Ofertas del día](https://developers.mercadolibre.com.co/es_co/ofertas-del-dia) | `ok` | 10 | — |
| MOD-10-PAGE-010 | [Ofertas relámpago](https://developers.mercadolibre.com.co/es_co/ofertas-relampago) | `ok` | 11 | — |
| MOD-10-PAGE-011 | [Promotions / Pricing](https://developers.mercadolibre.com.co/es_co/promotions-pricing) | `ok` | 0 | — |

### Endpoints/rutas del módulo

| ID | Método | Ruta | Riesgo/área | Fuente |
|---|---|---|---|---|
| MOD-10-EP-0001 | `DELETE` | `/seller-promotions/items/$ITEM_ID?app_version=v2` | destructivo | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0002 | `DELETE` | `/seller-promotions/items/$ITEM_ID?app_version=v2&promotion_type=$PROMOTION_TYPE` | destructivo | [Ofertas del día](https://developers.mercadolibre.com.co/es_co/ofertas-del-dia) |
| MOD-10-EP-0003 | `DELETE` | `/seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&app_version=v2` | destructivo | [Descuento individual](https://developers.mercadolibre.com.co/es_co/descuento-individual) |
| MOD-10-EP-0004 | `DELETE` | `/seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&promotion_id=$PROMOTION_ID&app_version=v2` | destructivo | [Campañas tradicionales](https://developers.mercadolibre.com.co/es_co/deals) |
| MOD-10-EP-0005 | `DELETE` | `/seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&promotion_id=$PROMOTION&app_version=v2` | destructivo | [Campañas del vendedor](https://developers.mercadolibre.com.co/es_co/campanas-del-vendedor) |
| MOD-10-EP-0006 | `DELETE` | `/seller-promotions/items/$ITEM_ID?promotion_type=BANK&promotion_id=$PROMOTION_ID&offer_id=$OFFER_ID&app_version=v2` | destructivo | [Campaña co-fondeada para PIX](https://developers.mercadolibre.com.co/es_co/pix) |
| MOD-10-EP-0007 | `DELETE` | `/seller-promotions/items/$ITEM_ID?promotion_type=SELLER_COUPON_CAMPAIGN&promotion_id=$PROMOTION_ID&app_version=v2` | destructivo | [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) |
| MOD-10-EP-0008 | `DELETE` | `/seller-promotions/items/MLA1399846831?app_version=v2` | destructivo | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0009 | `DELETE` | `/seller-promotions/items/MLA632979587??app_version=v2&promotion_type=DOD` | destructivo | [Ofertas del día](https://developers.mercadolibre.com.co/es_co/ofertas-del-dia) |
| MOD-10-EP-0010 | `DELETE` | `/seller-promotions/items/MLA632979587?app_version=v2&promotion_type=LIGHTNING` | destructivo | [Ofertas relámpago](https://developers.mercadolibre.com.co/es_co/ofertas-relampago) |
| MOD-10-EP-0011 | `DELETE` | `/seller-promotions/items/MLA632979587?promotion_type=MARKETPLACE_CAMPAIGN&promotion_id=1804&offer_id=MLA876618673-9eafadd4-16d2-49ae-b272-9a7a34585cb8&app_version=v2` | destructivo | [Campañas co-fondeadas](https://developers.mercadolibre.com.co/es_co/campanas-co-fondeadas) |
| MOD-10-EP-0012 | `DELETE` | `/seller-promotions/items/MLA632979587?promotion_type=VOLUME&promotion_id=1804&offer_id=MLA876618673-9eafadd4-16d2-49ae-b272-9a7a34585cb8&app_version=v2` | destructivo | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) |
| MOD-10-EP-0013 | `DELETE` | `/seller-promotions/items/MLA876768946?promotion_type=PRICE_DISCOUNT&app_version=v2` | destructivo | [Descuento individual](https://developers.mercadolibre.com.co/es_co/descuento-individual) |
| MOD-10-EP-0014 | `DELETE` | `/seller-promotions/items/MLB123456789?promotion_type=SELLER_COUPON_CAMPAIGN&promotion_id=C-MLB1081&app_version=v2` | destructivo | [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) |
| MOD-10-EP-0015 | `DELETE` | `/seller-promotions/items/MLB3295112047?promotion_type=DEAL&promotion_id=P-MLB1806019&app_version=v2` | destructivo | [Campañas tradicionales](https://developers.mercadolibre.com.co/es_co/deals) |
| MOD-10-EP-0016 | `DELETE` | `/seller-promotions/items/MLB3538191898?promotion_type=SELLER_CAMPAIGN&promotion_id=C-MLB302` | destructivo | [Campañas del vendedor](https://developers.mercadolibre.com.co/es_co/campanas-del-vendedor) |
| MOD-10-EP-0017 | `DELETE` | `/seller-promotions/promotions/{{Promo-ID` | destructivo | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) |
| MOD-10-EP-0018 | `DELETE` | `/seller-promotions/promotions/$PROMOTION_ID?promotion_type=SELLER_CAMPAIGN&app_version=v2` | destructivo | [Campañas del vendedor](https://developers.mercadolibre.com.co/es_co/campanas-del-vendedor) |
| MOD-10-EP-0019 | `DELETE` | `/seller-promotions/promotions/$PROMOTION_ID?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2` | destructivo | [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) |
| MOD-10-EP-0020 | `DELETE` | `/seller-promotions/promotions/C-MLB1234?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2` | destructivo | [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) |
| MOD-10-EP-0021 | `DELETE` | `/seller-promotions/promotions/C-MLB360923?promotion_type=SELLER_CAMPAIGN&app_version=v2` | destructivo | [Campañas del vendedor](https://developers.mercadolibre.com.co/es_co/campanas-del-vendedor) |
| MOD-10-EP-0022 | `GET` | `/seller-promotions` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0023 | `GET` | `/seller-promotions/candidates` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0024 | `GET` | `/seller-promotions/candidates/$CANDIDATE_ID?app_version=v2` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0025 | `GET` | `/seller-promotions/candidates/CANDIDATE-MLB1254949426-803130663?app_version=v2` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0026 | `GET` | `/seller-promotions/exclusion-list/item?app_version=v2` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0027 | `GET` | `/seller-promotions/exclusion-list/seller?app_version=v2` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0028 | `GET` | `/seller-promotions/exclusion-list/seller/{item_id` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0029 | `GET` | `/seller-promotions/items/$ITEM_ID?app_version=v2&promotion_type=$PROMOTION_TYPE` | lectura/consulta | [Ofertas del día](https://developers.mercadolibre.com.co/es_co/ofertas-del-dia) |
| MOD-10-EP-0030 | `GET` | `/seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&promotion_id=$PROMOTION_ID&app_version=v2` | lectura/consulta | [Campañas tradicionales](https://developers.mercadolibre.com.co/es_co/deals) |
| MOD-10-EP-0031 | `GET` | `/seller-promotions/items/$ITEM_ID?promotion_type=BANK&promotion_id=$PROMOTION_ID&offer_id=$OFFER_ID&app_version=v2` | lectura/consulta | [Campaña co-fondeada para PIX](https://developers.mercadolibre.com.co/es_co/pix) |
| MOD-10-EP-0032 | `GET` | `/seller-promotions/items/MLA1399846831?app_version=v2` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0033 | `GET` | `/seller-promotions/items/MLA1658866847?app_version=v2` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0034 | `GET` | `/seller-promotions/items/MLA632979587??app_version=v2&promotion_type=DOD` | lectura/consulta | [Ofertas del día](https://developers.mercadolibre.com.co/es_co/ofertas-del-dia) |
| MOD-10-EP-0035 | `GET` | `/seller-promotions/items/MLA632979587?app_version=v2&promotion_type=LIGHTNING` | lectura/consulta | [Ofertas relámpago](https://developers.mercadolibre.com.co/es_co/ofertas-relampago) |
| MOD-10-EP-0036 | `GET` | `/seller-promotions/items/MLA632979587?promotion_type=MARKETPLACE_CAMPAIGN&promotion_id=1804&offer_id=MLA876618673-9eafadd4-16d2-49ae-b272-9a7a34585cb8&app_version=v2` | lectura/consulta | [Campañas co-fondeadas](https://developers.mercadolibre.com.co/es_co/campanas-co-fondeadas) |
| MOD-10-EP-0037 | `GET` | `/seller-promotions/items/MLA876768946?app_version=v2` | lectura/consulta | [Ofertas del día](https://developers.mercadolibre.com.co/es_co/ofertas-del-dia) |
| MOD-10-EP-0038 | `GET` | `/seller-promotions/items/MLB3293401659?app_version=v2` | lectura/consulta | [Campañas co-fondeadas](https://developers.mercadolibre.com.co/es_co/campanas-co-fondeadas) |
| MOD-10-EP-0039 | `GET` | `/seller-promotions/items/MLB3293401743?app_version=v2` | lectura/consulta | [Ofertas relámpago](https://developers.mercadolibre.com.co/es_co/ofertas-relampago) |
| MOD-10-EP-0040 | `GET` | `/seller-promotions/items/MLB3293481659?app_version=v2` | lectura/consulta | [Campaña co-fondeada para PIX](https://developers.mercadolibre.com.co/es_co/pix) |
| MOD-10-EP-0041 | `GET` | `/seller-promotions/items/MLB3295112047?app_version=v2` | lectura/consulta | [Campañas tradicionales](https://developers.mercadolibre.com.co/es_co/deals) |
| MOD-10-EP-0042 | `GET` | `/seller-promotions/items/MLB3295112047?promotion_type=DEAL&promotion_id=P-MLB1806019&app_version=v2` | lectura/consulta | [Campañas tradicionales](https://developers.mercadolibre.com.co/es_co/deals) |
| MOD-10-EP-0043 | `GET` | `/seller-promotions/offers` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0044 | `GET` | `/seller-promotions/offers/$OFFERS_ID?app_version=v2` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0045 | `GET` | `/seller-promotions/offers/OFFER-MLB1970246686-42701792?app_version=v2` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0046 | `GET` | `/seller-promotions/promotions/$PROMOTION_ID?promotion_type=$PROMOTION_TYPE&app_version=v2` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0047 | `GET` | `/seller-promotions/promotions/$PROMOTION_ID?promotion_type=BANK&app_version=v2` | lectura/consulta | [Campaña co-fondeada para PIX](https://developers.mercadolibre.com.co/es_co/pix) |
| MOD-10-EP-0048 | `GET` | `/seller-promotions/promotions/$PROMOTION_ID/items?app_version=v2&promotion_type=LIGHTNING` | lectura/consulta | [Ofertas relámpago](https://developers.mercadolibre.com.co/es_co/ofertas-relampago) |
| MOD-10-EP-0049 | `GET` | `/seller-promotions/promotions/$PROMOTION_ID/items?promotion_type=$PROMOTION_TYPE&app_version=v2` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0050 | `GET` | `/seller-promotions/promotions/$PROMOTION_ID/items?promotion_type=$PROMOTION_TYPE&app_version=v2&limit=50&search_after={$SEARCH_AFTER` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0051 | `GET` | `/seller-promotions/promotions/$PROMOTION_ID/items?promotion_type=$PROMOTION_TYPE&status=$STATUS&item_id=$ITEM_ID&app_version=v2` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0052 | `GET` | `/seller-promotions/promotions/$PROMOTION_ID/items?promotion_type=BANK&app_version=v2` | lectura/consulta | [Campaña co-fondeada para PIX](https://developers.mercadolibre.com.co/es_co/pix) |
| MOD-10-EP-0053 | `GET` | `/seller-promotions/promotions/$PROMOTION_ID/items?promotion_type=DOD&app_version=v2` | lectura/consulta | [Ofertas del día](https://developers.mercadolibre.com.co/es_co/ofertas-del-dia) |
| MOD-10-EP-0054 | `GET` | `/seller-promotions/promotions/$PROMOTION_ID/items?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2` | lectura/consulta | [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) |
| MOD-10-EP-0055 | `GET` | `/seller-promotions/promotions/C-MLB300?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2` | lectura/consulta | [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) |
| MOD-10-EP-0056 | `GET` | `/seller-promotions/promotions/C-MLB300/items?promotion_type=SELLER_CAMPAIGN&app_version=v2` | lectura/consulta | [Campañas del vendedor](https://developers.mercadolibre.com.co/es_co/campanas-del-vendedor) |
| MOD-10-EP-0057 | `GET` | `/seller-promotions/promotions/C-MLB302?promotion_type=SELLER_CAMPAIGN&app_version=v2` | lectura/consulta | [Campañas del vendedor](https://developers.mercadolibre.com.co/es_co/campanas-del-vendedor) |
| MOD-10-EP-0058 | `GET` | `/seller-promotions/promotions/DOD-MLB1000/items?promotion_type=DOD&app_version=v2` | lectura/consulta | [Ofertas del día](https://developers.mercadolibre.com.co/es_co/ofertas-del-dia) |
| MOD-10-EP-0059 | `GET` | `/seller-promotions/promotions/LGH-MLB1000/items?app_version=v2&promotion_type=LIGHTNING` | lectura/consulta | [Ofertas relámpago](https://developers.mercadolibre.com.co/es_co/ofertas-relampago) |
| MOD-10-EP-0060 | `GET` | `/seller-promotions/promotions/MLA1111/items?promotion_type=DEAL&item_id=MLA604400000&app_version=v2` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0061 | `GET` | `/seller-promotions/promotions/MLA1111/items?promotion_type=DEAL&status_item=active&app_version=v2` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0062 | `GET` | `/seller-promotions/promotions/MLA1111/items?promotion_type=DEAL&status=started&app_version=v2` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0063 | `GET` | `/seller-promotions/promotions/P-MLB1806015?promotion_type=MARKETPLACE_CAMPAIGN&app_version=v2` | lectura/consulta | [Campañas co-fondeadas](https://developers.mercadolibre.com.co/es_co/campanas-co-fondeadas) |
| MOD-10-EP-0064 | `GET` | `/seller-promotions/promotions/P-MLB1806015/items?promotion_type=MARKETPLACE_CAMPAIGN&app_version=v2` | lectura/consulta | [Campañas co-fondeadas](https://developers.mercadolibre.com.co/es_co/campanas-co-fondeadas) |
| MOD-10-EP-0065 | `GET` | `/seller-promotions/promotions/P-MLB1806017?promotion_type=VOLUME&app_version=v2` | lectura/consulta | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) |
| MOD-10-EP-0066 | `GET` | `/seller-promotions/promotions/P-MLB1806017/items?promotion_type=VOLUME&app_version=v2` | lectura/consulta | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) |
| MOD-10-EP-0067 | `GET` | `/seller-promotions/promotions/P-MLB1806019?promotion_type=DEAL&app_version=v2` | lectura/consulta | [Campañas tradicionales](https://developers.mercadolibre.com.co/es_co/deals) |
| MOD-10-EP-0068 | `GET` | `/seller-promotions/promotions/P-MLB1806019/items?promotion_type=DEAL&app_version=v2` | lectura/consulta | [Campañas tradicionales](https://developers.mercadolibre.com.co/es_co/deals) |
| MOD-10-EP-0069 | `GET` | `/seller-promotions/users/$USER_ID?app_version=v2` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0070 | `GET` | `/seller-promotions/users/1356551933?app_version=v2` | lectura/consulta | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0071 | `POST` | `/seller-promotions/exclusion-list/item?app_version=v2` | mutación | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0072 | `POST` | `/seller-promotions/exclusion-list/seller?app_version=v2` | mutación | [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) |
| MOD-10-EP-0073 | `POST` | `/seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&app_version=v2` | mutación | [Descuento individual](https://developers.mercadolibre.com.co/es_co/descuento-individual) |
| MOD-10-EP-0074 | `POST` | `/seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&promotion_id=$PROMOTION_ID&offer_id=$OFFER_ID` | mutación | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) |
| MOD-10-EP-0075 | `POST` | `/seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&promotion_id=$PROMOTION&app_version=v2` | mutación | [Campañas del vendedor](https://developers.mercadolibre.com.co/es_co/campanas-del-vendedor) |
| MOD-10-EP-0076 | `POST` | `/seller-promotions/items/$ITEM_ID?promotion_type=SELLER_COUPON_CAMPAIGN&promotion_id=$PROMOTION_ID&app_version=v2` | mutación | [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) |
| MOD-10-EP-0077 | `POST` | `/seller-promotions/items/MLA632979587?promotion_type=VOLUME&promotion_id=1804&offer_id=MLA876618673-9eafadd4-16d2-49ae-b272-9a7a34585cb8&app_version=v2` | mutación | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) |
| MOD-10-EP-0078 | `POST` | `/seller-promotions/items/MLA876768946?app_version=v2` | mutación | [Descuento individual](https://developers.mercadolibre.com.co/es_co/descuento-individual) |
| MOD-10-EP-0079 | `POST` | `/seller-promotions/items/MLA876768946?promotion_type=PRICE_DISCOUNT&app_version=v2` | mutación | [Descuento individual](https://developers.mercadolibre.com.co/es_co/descuento-individual) |
| MOD-10-EP-0080 | `POST` | `/seller-promotions/items/MLB123456789?app_version=v2` | mutación | [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) |
| MOD-10-EP-0081 | `POST` | `/seller-promotions/items/MLB123456789?promotion_type=SELLER_COUPON_CAMPAIGN&promotion_id=C-MLB1081&app_version=v2` | mutación | [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) |
| MOD-10-EP-0082 | `POST` | `/seller-promotions/items/MLB1834747833&app_version=v2` | mutación | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) |
| MOD-10-EP-0083 | `POST` | `/seller-promotions/items/MLB3293401659?app_version=v2` | mutación | [Campañas co-fondeadas](https://developers.mercadolibre.com.co/es_co/campanas-co-fondeadas) |
| MOD-10-EP-0084 | `POST` | `/seller-promotions/items/MLB3293401743?app_version=v2` | mutación | [Ofertas relámpago](https://developers.mercadolibre.com.co/es_co/ofertas-relampago) |
| MOD-10-EP-0085 | `POST` | `/seller-promotions/items/MLB3293481659?app_version=v2` | mutación | [Campaña co-fondeada para PIX](https://developers.mercadolibre.com.co/es_co/pix) |
| MOD-10-EP-0086 | `POST` | `/seller-promotions/items/MLB3295112047?app_version=v2` | mutación | [Campañas tradicionales](https://developers.mercadolibre.com.co/es_co/deals) |
| MOD-10-EP-0087 | `POST` | `/seller-promotions/items/MLB3538191898?promotion_type=SELLER_CAMPAIGN&promotion_id=C-MLB302` | mutación | [Campañas del vendedor](https://developers.mercadolibre.com.co/es_co/campanas-del-vendedor) |
| MOD-10-EP-0088 | `POST` | `/seller-promotions/promotions?app_version=v2` | mutación | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) |
| MOD-10-EP-0089 | `POST` | `/seller-promotions/promotions?app_version=v2&version=test` | mutación | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) |
| MOD-10-EP-0090 | `POST` | `/seller-promotions/promotions/{{Promo-ID` | mutación | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) |
| MOD-10-EP-0091 | `POST` | `/seller-promotions/promotions/$PROMOTION_ID?app_version=v2` | mutación | [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) |
| MOD-10-EP-0092 | `POST` | `/seller-promotions/promotions/$PROMOTION_ID?promotion_type=SELLER_CAMPAIGN&app_version=v2` | mutación | [Campañas del vendedor](https://developers.mercadolibre.com.co/es_co/campanas-del-vendedor) |
| MOD-10-EP-0093 | `POST` | `/seller-promotions/promotions/$PROMOTION_ID?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2` | mutación | [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) |
| MOD-10-EP-0094 | `POST` | `/seller-promotions/promotions/$PROMOTION_ID/items?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2` | mutación | [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) |
| MOD-10-EP-0095 | `POST` | `/seller-promotions/promotions/C-MLB1234?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2` | mutación | [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) |
| MOD-10-EP-0096 | `POST` | `/seller-promotions/promotions/C-MLB300?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2` | mutación | [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) |
| MOD-10-EP-0097 | `POST` | `/seller-promotions/promotions/C-MLB300/items?promotion_type=SELLER_CAMPAIGN&app_version=v2` | mutación | [Campañas del vendedor](https://developers.mercadolibre.com.co/es_co/campanas-del-vendedor) |
| MOD-10-EP-0098 | `POST` | `/seller-promotions/promotions/C-MLB302?promotion_type=SELLER_CAMPAIGN&app_version=v2` | mutación | [Campañas del vendedor](https://developers.mercadolibre.com.co/es_co/campanas-del-vendedor) |
| MOD-10-EP-0099 | `POST` | `/seller-promotions/promotions/C-MLB360923?promotion_type=SELLER_CAMPAIGN&app_version=v2` | mutación | [Campañas del vendedor](https://developers.mercadolibre.com.co/es_co/campanas-del-vendedor) |
| MOD-10-EP-0100 | `POST` | `/seller-promotions/promotions/C-MLB5783?app_version=v2&version=test` | mutación | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) |
| MOD-10-EP-0101 | `POST` | `/seller-promotions/promotions/P-MLB1806017?promotion_type=VOLUME&app_version=v2` | mutación | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) |
| MOD-10-EP-0102 | `POST` | `/seller-promotions/promotions/P-MLB1806017/items?promotion_type=VOLUME&app_version=v2` | mutación | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) |
| MOD-10-EP-0103 | `PUT` | `/seller-promotions/items/$ITEM_ID?app_version=v2` | mutación | [Campañas tradicionales](https://developers.mercadolibre.com.co/es_co/deals) |
| MOD-10-EP-0104 | `PUT` | `/seller-promotions/items/MLB3295112047?app_version=v2` | mutación | [Campañas tradicionales](https://developers.mercadolibre.com.co/es_co/deals) |
| MOD-10-EP-0105 | `PUT` | `/seller-promotions/items/MLB3538191898?app_version=v2` | mutación | [Campañas del vendedor](https://developers.mercadolibre.com.co/es_co/campanas-del-vendedor) |
| MOD-10-EP-0106 | `PUT` | `/seller-promotions/promotions/$PROMOTION_ID?app_version=v2` | mutación | [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) |
| MOD-10-EP-0107 | `PUT` | `/seller-promotions/promotions/C-MLB5783?app_version=v2&version=test` | mutación | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) |
| MOD-10-EP-0108 | `UNKNOWN` | `/seller-promotions/promotions/{{Promo-ID` | lectura/consulta | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) |
| MOD-10-EP-0109 | `UNKNOWN` | `/seller-promotions/promotions/C-MLB5783?app_version=v2&version=test` | lectura/consulta | [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) |

### Señales de autenticación/permisos

- [Campaña co-fondeada para PIX](https://developers.mercadolibre.com.co/es_co/pix): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Campañas co-fondeadas](https://developers.mercadolibre.com.co/es_co/campanas-co-fondeadas): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad): `Content-Type`, `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Campañas del vendedor](https://developers.mercadolibre.com.co/es_co/campanas-del-vendedor): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Campañas tradicionales](https://developers.mercadolibre.com.co/es_co/deals): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Descuento individual](https://developers.mercadolibre.com.co/es_co/descuento-individual): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Ofertas del día](https://developers.mercadolibre.com.co/es_co/ofertas-del-dia): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Ofertas relámpago](https://developers.mercadolibre.com.co/es_co/ofertas-relampago): `Authorization`, `Bearer`, `ACCESS_TOKEN`

## Reputación, métricas y calidad

id: MOD-11

Resumen: 6 páginas fuente, 47 endpoints/rutas, integraciones detectadas: Catálogo, Facturación, Mensajería, Mercado Ads, Mercado Envíos, Mercado Pago, Notificaciones, OAuth.

### Páginas fuente

| ID | Página | Estado | Endpoints | Fechas visibles |
|---|---|---|---:|---|
| MOD-11-PAGE-001 | [Experiencia de compra](https://developers.mercadolibre.com.co/es_co/experiencia-de-compra) | `ok` | 4 | — |
| MOD-11-PAGE-002 | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) | `ok` | 24 | 01/01/2001 |
| MOD-11-PAGE-003 | [Programa de Despegue y Beneficio de Reputación](https://developers.mercadolibre.com.co/es_co/recuperacion-reputacion) | `ok` | 7 | — |
| MOD-11-PAGE-004 | [Tendencias](https://developers.mercadolibre.com.co/es_co/tendencias) | `ok` | 5 | — |
| MOD-11-PAGE-005 | [Tiendas Oficiales](https://developers.mercadolibre.com.co/es_co/tienda-oficial) | `ok` | 7 | — |
| MOD-11-PAGE-006 | [Visitas](https://developers.mercadolibre.com.co/es_co/recurso-de-visitas) | `ok` | 10 | 2021-01-01, 2021-02-01, 2021-08-06 |

### Endpoints/rutas del módulo

| ID | Método | Ruta | Riesgo/área | Fuente |
|---|---|---|---|---|
| MOD-11-EP-0001 | `GET` | `/block-api/search/users/123456?type=blocked_by_order` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0002 | `GET` | `/feedback/$FEEDBACK_ID/reply` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0003 | `GET` | `/feedback/9040351529869` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0004 | `GET` | `/feedback/9040351529869/reply` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0005 | `GET` | `/items/$ITEM_ID/visits/time_window?last=$LAST&unit=$UNIT&ending=$ENDING` | lectura/consulta | [Visitas](https://developers.mercadolibre.com.co/es_co/recurso-de-visitas) |
| MOD-11-EP-0006 | `GET` | `/items/MCO471870973/visits/time_window?last=2&unit=day&ending=2021-08-06` | lectura/consulta | [Visitas](https://developers.mercadolibre.com.co/es_co/recurso-de-visitas) |
| MOD-11-EP-0007 | `GET` | `/items/visits?ids=MCO473861358&date_from=2021-01-01&date_to=2021-02-01` | lectura/consulta | [Visitas](https://developers.mercadolibre.com.co/es_co/recurso-de-visitas) |
| MOD-11-EP-0008 | `GET` | `/orders/1068825849/feedback` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0009 | `GET` | `/orders/search?buyer=$BUYER_ID` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0010 | `GET` | `/orders/search?buyer=207040551` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0011 | `GET` | `/orders/search?seller=$SELLER_ID` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0012 | `GET` | `/orders/search?seller=$SELLER_ID&q=$ORDER_ID` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0013 | `GET` | `/orders/search?seller=207035636` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0014 | `GET` | `/orders/search?seller=207035636&q=` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0015 | `GET` | `/payments/$PAYMENT_ID` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0016 | `GET` | `/payments/28382111111` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0017 | `GET` | `/reputation/items/$ITEM_ID/purchase_experience/integrators` | lectura/consulta | [Experiencia de compra](https://developers.mercadolibre.com.co/es_co/experiencia-de-compra) |
| MOD-11-EP-0018 | `GET` | `/reputation/items/MLA1391786841/purchase_experience/integrators?locale=es_AR` | lectura/consulta | [Experiencia de compra](https://developers.mercadolibre.com.co/es_co/experiencia-de-compra) |
| MOD-11-EP-0019 | `GET` | `/reputation/user_products/{UP_ID` | lectura/consulta | [Experiencia de compra](https://developers.mercadolibre.com.co/es_co/experiencia-de-compra) |
| MOD-11-EP-0020 | `GET` | `/reputation/user_products/MLAU1391786841/purchase_experience/integrators?locale=es_AR` | lectura/consulta | [Experiencia de compra](https://developers.mercadolibre.com.co/es_co/experiencia-de-compra) |
| MOD-11-EP-0021 | `GET` | `/sites/$SITE_ID/payment_methods` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0022 | `GET` | `/sites/MLA/payment_methods` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0023 | `GET` | `/sites/MLA/payment_methods/amex` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0024 | `GET` | `/trends` | lectura/consulta | [Tendencias](https://developers.mercadolibre.com.co/es_co/tendencias) |
| MOD-11-EP-0025 | `GET` | `/trends/$SITE_ID` | lectura/consulta | [Tendencias](https://developers.mercadolibre.com.co/es_co/tendencias) |
| MOD-11-EP-0026 | `GET` | `/trends/$SITE_ID/$CATEGORY_ID` | lectura/consulta | [Tendencias](https://developers.mercadolibre.com.co/es_co/tendencias) |
| MOD-11-EP-0027 | `GET` | `/trends/MLA` | lectura/consulta | [Tendencias](https://developers.mercadolibre.com.co/es_co/tendencias) |
| MOD-11-EP-0028 | `GET` | `/trends/MLA/MLA1246` | lectura/consulta | [Tendencias](https://developers.mercadolibre.com.co/es_co/tendencias) |
| MOD-11-EP-0029 | `GET` | `/users/:userID/order_blacklist?offset=100&limit=50` | lectura/consulta | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0030 | `GET` | `/users/$USER_ID/brands/$BRAND` | lectura/consulta | [Tiendas Oficiales](https://developers.mercadolibre.com.co/es_co/tienda-oficial) |
| MOD-11-EP-0031 | `GET` | `/users/1000011398/items_visits?date_from=2021-01-01&date_to=2021-02-01` | lectura/consulta | [Visitas](https://developers.mercadolibre.com.co/es_co/recurso-de-visitas) |
| MOD-11-EP-0032 | `GET` | `/users/1000011398/items_visits/time_window?last=2&unit=day` | lectura/consulta | [Visitas](https://developers.mercadolibre.com.co/es_co/recurso-de-visitas) |
| MOD-11-EP-0033 | `GET` | `/users/1477536226/brands/14501111111` | lectura/consulta | [Tiendas Oficiales](https://developers.mercadolibre.com.co/es_co/tienda-oficial) |
| MOD-11-EP-0034 | `GET` | `/users/1477536226/brands/aaaaa` | lectura/consulta | [Tiendas Oficiales](https://developers.mercadolibre.com.co/es_co/tienda-oficial) |
| MOD-11-EP-0035 | `GET` | `/users/14775362261111111/brands` | lectura/consulta | [Tiendas Oficiales](https://developers.mercadolibre.com.co/es_co/tienda-oficial) |
| MOD-11-EP-0036 | `GET` | `/users/2275117700/brands` | lectura/consulta | [Tiendas Oficiales](https://developers.mercadolibre.com.co/es_co/tienda-oficial) |
| MOD-11-EP-0037 | `GET` | `/users/2275117700/brands/294894` | lectura/consulta | [Tiendas Oficiales](https://developers.mercadolibre.com.co/es_co/tienda-oficial) |
| MOD-11-EP-0038 | `GET` | `/users/reputation/seller_recovery/activate` | lectura/consulta | [Programa de Despegue y Beneficio de Reputación](https://developers.mercadolibre.com.co/es_co/recuperacion-reputacion) |
| MOD-11-EP-0039 | `GET` | `/users/reputation/seller_recovery/cancel_guarantee` | lectura/consulta | [Programa de Despegue y Beneficio de Reputación](https://developers.mercadolibre.com.co/es_co/recuperacion-reputacion) |
| MOD-11-EP-0040 | `GET` | `/users/reputation/seller_recovery/legal-document?type=(PREVIEW|COMPLETE` | lectura/consulta | [Programa de Despegue y Beneficio de Reputación](https://developers.mercadolibre.com.co/es_co/recuperacion-reputacion) |
| MOD-11-EP-0041 | `GET` | `/users/reputation/seller_recovery/status` | lectura/consulta | [Programa de Despegue y Beneficio de Reputación](https://developers.mercadolibre.com.co/es_co/recuperacion-reputacion) |
| MOD-11-EP-0042 | `GET` | `/visits/items?ids=MLB9992242141` | lectura/consulta | [Visitas](https://developers.mercadolibre.com.co/es_co/recurso-de-visitas) |
| MOD-11-EP-0043 | `POST` | `/feedback/9040351529869/reply` | mutación | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0044 | `POST` | `/orders/1068825849/feedback` | mutación | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0045 | `POST` | `/users/reputation/seller_recovery/activate` | mutación | [Programa de Despegue y Beneficio de Reputación](https://developers.mercadolibre.com.co/es_co/recuperacion-reputacion) |
| MOD-11-EP-0046 | `PUT` | `/feedback/9040351529869` | mutación | [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) |
| MOD-11-EP-0047 | `PUT` | `/users/reputation/seller_recovery/cancel_guarantee` | mutación | [Programa de Despegue y Beneficio de Reputación](https://developers.mercadolibre.com.co/es_co/recuperacion-reputacion) |

### Señales de autenticación/permisos

- [Experiencia de compra](https://developers.mercadolibre.com.co/es_co/experiencia-de-compra): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones): `Authorization`, `authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`, `client_id`
- [Programa de Despegue y Beneficio de Reputación](https://developers.mercadolibre.com.co/es_co/recuperacion-reputacion): `Authorization`, `Content-Type`, `Bearer`, `write`
- [Tendencias](https://developers.mercadolibre.com.co/es_co/tendencias): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Tiendas Oficiales](https://developers.mercadolibre.com.co/es_co/tienda-oficial): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Visitas](https://developers.mercadolibre.com.co/es_co/recurso-de-visitas): `Authorization`, `Bearer`, `ACCESS_TOKEN`

## Seguridad

id: MOD-12

Resumen: 5 páginas fuente, 3 endpoints/rutas, integraciones detectadas: Mercado Pago, Notificaciones, OAuth.

### Páginas fuente

| ID | Página | Estado | Endpoints | Fechas visibles |
|---|---|---|---:|---|
| MOD-12-PAGE-001 | [Control de acceso y autorización](https://developers.mercadolibre.com.co/es_co/control-de-acceso-y-autorizacion) | `ok` | 4 | — |
| MOD-12-PAGE-002 | [Gestión de incidentes](https://developers.mercadolibre.com.co/es_co/gestion-de-incidentes) | `ok` | 0 | — |
| MOD-12-PAGE-003 | [Infraestructura: Cifrado y seguridad de transporte](https://developers.mercadolibre.com.co/es_co/infraestructura) | `ok` | 0 | — |
| MOD-12-PAGE-004 | [Monitoreo](https://developers.mercadolibre.com.co/es_co/monitoreo) | `ok` | 0 | — |
| MOD-12-PAGE-005 | [Seguridad en Integraciones](https://developers.mercadolibre.com.co/es_co/seguridad-desarrollo-seguro) | `ok` | 0 | — |

### Endpoints/rutas del módulo

| ID | Método | Ruta | Riesgo/área | Fuente |
|---|---|---|---|---|
| MOD-12-EP-0001 | `UNKNOWN` | `/orders/{order_id}` | lectura/consulta | [Control de acceso y autorización](https://developers.mercadolibre.com.co/es_co/control-de-acceso-y-autorizacion) |
| MOD-12-EP-0002 | `UNKNOWN` | `/orders/12345` | lectura/consulta | [Control de acceso y autorización](https://developers.mercadolibre.com.co/es_co/control-de-acceso-y-autorizacion) |
| MOD-12-EP-0003 | `UNKNOWN` | `/orders/123456` | lectura/consulta | [Control de acceso y autorización](https://developers.mercadolibre.com.co/es_co/control-de-acceso-y-autorizacion) |

### Señales de autenticación/permisos

- [Control de acceso y autorización](https://developers.mercadolibre.com.co/es_co/control-de-acceso-y-autorizacion): `Authorization`, `authorization`
- [Seguridad en Integraciones](https://developers.mercadolibre.com.co/es_co/seguridad-desarrollo-seguro): `X-Content-Type-Options`, `X-Frame-Options`

## Servicios

id: MOD-13

Resumen: 7 páginas fuente, 20 endpoints/rutas, integraciones detectadas: Catálogo, Mercado Pago, OAuth.

### Páginas fuente

| ID | Página | Estado | Endpoints | Fechas visibles |
|---|---|---|---:|---|
| MOD-13-PAGE-001 | [Administra áreas de cobertura](https://developers.mercadolibre.com.co/es_co/administra-areas-de-cobertura) | `ok` | 4 | — |
| MOD-13-PAGE-002 | [Consultas avanzadas](https://developers.mercadolibre.com.co/es_co/consultas-avanzadas-2) | `ok` | 0 | — |
| MOD-13-PAGE-003 | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/servicios-consulta-usuarios) | `ok` | 12 | — |
| MOD-13-PAGE-004 | [Elige tipo de servicio](https://developers.mercadolibre.com.co/es_co/elige-tipo-de-servicio) | `ok` | 6 | — |
| MOD-13-PAGE-005 | [Guía para Servicios](https://developers.mercadolibre.com.co/es_co/guia-para-servicios) | `ok` | 0 | — |
| MOD-13-PAGE-006 | [Publica servicios](https://developers.mercadolibre.com.co/es_co/publica-servicios-vis) | `ok` | 7 | — |
| MOD-13-PAGE-007 | [Sincroniza publicaciones](https://developers.mercadolibre.com.co/es_co/servicio-sincroniza-publicaciones) | `ok` | 1 | — |

### Endpoints/rutas del módulo

| ID | Método | Ruta | Riesgo/área | Fuente |
|---|---|---|---|---|
| MOD-13-EP-0001 | `GET` | `/block-api/search/users/{user_id` | lectura/consulta | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/servicios-consulta-usuarios) |
| MOD-13-EP-0002 | `GET` | `/block-api/search/users/123456?type=blocked_by_questions` | lectura/consulta | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/servicios-consulta-usuarios) |
| MOD-13-EP-0003 | `GET` | `/categories/MLA1071` | lectura/consulta | [Elige tipo de servicio](https://developers.mercadolibre.com.co/es_co/elige-tipo-de-servicio) |
| MOD-13-EP-0004 | `GET` | `/categories/MLA24272/attributes` | lectura/consulta | [Elige tipo de servicio](https://developers.mercadolibre.com.co/es_co/elige-tipo-de-servicio) |
| MOD-13-EP-0005 | `GET` | `/categories/MLA58257` | lectura/consulta | [Elige tipo de servicio](https://developers.mercadolibre.com.co/es_co/elige-tipo-de-servicio) |
| MOD-13-EP-0006 | `GET` | `/coverage_areas/TUxBUEpVSnk3YmUz` | lectura/consulta | [Administra áreas de cobertura](https://developers.mercadolibre.com.co/es_co/administra-areas-de-cobertura) |
| MOD-13-EP-0007 | `GET` | `/items/Item_id` | lectura/consulta | [Publica servicios](https://developers.mercadolibre.com.co/es_co/publica-servicios-vis) |
| MOD-13-EP-0008 | `GET` | `/items/ITEM_ID` | lectura/consulta | [Administra áreas de cobertura](https://developers.mercadolibre.com.co/es_co/administra-areas-de-cobertura) |
| MOD-13-EP-0009 | `GET` | `/items/MLA599074368` | lectura/consulta | [Publica servicios](https://developers.mercadolibre.com.co/es_co/publica-servicios-vis) |
| MOD-13-EP-0010 | `GET` | `/items/MLA612001263` | lectura/consulta | [Publica servicios](https://developers.mercadolibre.com.co/es_co/publica-servicios-vis) |
| MOD-13-EP-0011 | `GET` | `/sites/MLA/coverage_areas` | lectura/consulta | [Administra áreas de cobertura](https://developers.mercadolibre.com.co/es_co/administra-areas-de-cobertura) |
| MOD-13-EP-0012 | `GET` | `/sites/MLA/search?category=MLA5726` | lectura/consulta | [Elige tipo de servicio](https://developers.mercadolibre.com.co/es_co/elige-tipo-de-servicio) |
| MOD-13-EP-0013 | `GET` | `/users/202593498` | lectura/consulta | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/servicios-consulta-usuarios) |
| MOD-13-EP-0014 | `GET` | `/users/202593498/address` | lectura/consulta | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/servicios-consulta-usuarios) |
| MOD-13-EP-0015 | `GET` | `/users/202593498/private` | lectura/consulta | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/servicios-consulta-usuarios) |
| MOD-13-EP-0016 | `PUT` | `/items/ITEM_ID` | mutación | [Administra áreas de cobertura](https://developers.mercadolibre.com.co/es_co/administra-areas-de-cobertura) |
| MOD-13-EP-0017 | `PUT` | `/items/MLA599074368` | mutación | [Publica servicios](https://developers.mercadolibre.com.co/es_co/publica-servicios-vis) |
| MOD-13-EP-0018 | `PUT` | `/users/{User_id` | mutación | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/servicios-consulta-usuarios) |
| MOD-13-EP-0019 | `PUT` | `/users/202593498/address` | mutación | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/servicios-consulta-usuarios) |
| MOD-13-EP-0020 | `UNKNOWN` | `/items/Item_id` | lectura/consulta | [Publica servicios](https://developers.mercadolibre.com.co/es_co/publica-servicios-vis) |

### Señales de autenticación/permisos

- [Administra áreas de cobertura](https://developers.mercadolibre.com.co/es_co/administra-areas-de-cobertura): `Authorization`, `Content-Type`, `Accept`, `Bearer`, `ACCESS_TOKEN`
- [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/servicios-consulta-usuarios): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Elige tipo de servicio](https://developers.mercadolibre.com.co/es_co/elige-tipo-de-servicio): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Publica servicios](https://developers.mercadolibre.com.co/es_co/publica-servicios-vis): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`, `access_token`
- [Sincroniza publicaciones](https://developers.mercadolibre.com.co/es_co/servicio-sincroniza-publicaciones): `Authorization`, `Content-Type`, `Accept`, `access_token`, `Bearer`, `ACCESS_TOKEN`

## Usuarios y recursos cross

id: MOD-14

Resumen: 16 páginas fuente, 88 endpoints/rutas, integraciones detectadas: Catálogo, Facturación, Mercado Envíos, Mercado Pago, OAuth.

### Páginas fuente

| ID | Página | Estado | Endpoints | Fechas visibles |
|---|---|---|---:|---|
| MOD-14-PAGE-001 | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) | `ok` | 31 | — |
| MOD-14-PAGE-002 | [Carga de atributos](https://developers.mercadolibre.com.co/es_co/conoce-como-estan-los-vendedores-frente-la-carga-de-atributos) | `ok` | 8 | — |
| MOD-14-PAGE-003 | [Categorías y Atributos](https://developers.mercadolibre.com.co/es_co/categorias-y-atributos) | `ok` | 17 | — |
| MOD-14-PAGE-004 | [Comunicaciones](https://developers.mercadolibre.com.co/es_co/conoce-las-novedades-que-reciben-los-vendedores) | `ok` | 1 | — |
| MOD-14-PAGE-005 | [Consulta de Usuarios](https://developers.mercadolibre.com.co/es_co/consulta-de-usuarios) | `ok` | 9 | 05/11/2025 |
| MOD-14-PAGE-006 | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/consulta-usuarios) | `ok` | 15 | 6 de enero de 2016 |
| MOD-14-PAGE-007 | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/producto-consulta-usuarios) | `ok` | 3 | — |
| MOD-14-PAGE-008 | [Direcciones del usuario](https://developers.mercadolibre.com.co/es_co/direcciones-del-usuario) | `ok` | 2 | — |
| MOD-14-PAGE-009 | [Dominios y Categorías](https://developers.mercadolibre.com.co/es_co/dominios-y-categorias) | `ok` | 17 | 29/10/2025 |
| MOD-14-PAGE-010 | [Items - Atributos de envío y dimensiones](https://developers.mercadolibre.com.co/es_co/items-atributos-de-envio-y-dimensiones) | `ok` | 0 | — |
| MOD-14-PAGE-011 | [Marcadores](https://developers.mercadolibre.com.co/es_co/marcadores) | `ok` | 4 | — |
| MOD-14-PAGE-012 | [Preguntas frecuentes sobre validación de datos](https://developers.mercadolibre.com.co/es_co/validacion-de-datos) | `ok` | 0 | — |
| MOD-14-PAGE-013 | [Referencias de dominios, productos y atributos para Autopartes](https://developers.mercadolibre.com.co/es_co/referencias-de-dominios-productos-y-atributos-para-autopartes) | `ok` | 16 | 15/07/2026 |
| MOD-14-PAGE-014 | [Reputación de vendedores](https://developers.mercadolibre.com.co/es_co/reputacion-de-vendedores) | `ok` | 2 | — |
| MOD-14-PAGE-015 | [Ubicación y Monedas](https://developers.mercadolibre.com.co/es_co/ubicacion-y-monedas) | `ok` | 13 | — |
| MOD-14-PAGE-016 | [Validar datos de vendedores](https://developers.mercadolibre.com.co/es_co/validar-datos-de-vendedores) | `ok` | 3 | — |

### Endpoints/rutas del módulo

| ID | Método | Ruta | Riesgo/área | Fuente |
|---|---|---|---|---|
| MOD-14-EP-0001 | `DELETE` | `/users/{user_id` | destructivo | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/consulta-usuarios) |
| MOD-14-EP-0002 | `DELETE` | `/users/$USER_ID/immediate_payment/by_user` | destructivo | [Consulta de Usuarios](https://developers.mercadolibre.com.co/es_co/consulta-de-usuarios) |
| MOD-14-EP-0003 | `DELETE` | `/users/$YOUR_CUST_ID/order_blacklist/$SELLER_ID` | destructivo | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/consulta-usuarios) |
| MOD-14-EP-0004 | `DELETE` | `/users/me/bookmarks/MLA5529` | destructivo | [Marcadores](https://developers.mercadolibre.com.co/es_co/marcadores) |
| MOD-14-EP-0005 | `GET` | `/catalog_compatibilities/products_search/chunks` | lectura/consulta | [Referencias de dominios, productos y atributos para Autopartes](https://developers.mercadolibre.com.co/es_co/referencias-de-dominios-productos-y-atributos-para-autopartes) |
| MOD-14-EP-0006 | `GET` | `/catalog_domains/$DOAMAIN_ID/attributes/$ATTRIBUTE_ID/top_values` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0007 | `GET` | `/catalog_domains/$DOMAIN_ID` | lectura/consulta | [Referencias de dominios, productos y atributos para Autopartes](https://developers.mercadolibre.com.co/es_co/referencias-de-dominios-productos-y-atributos-para-autopartes) |
| MOD-14-EP-0008 | `GET` | `/catalog_domains/$DOMAIN_ID/attributes/$ATTRIBUTE_ID/top_values` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0009 | `GET` | `/catalog_domains/MLA-CARS_AND_VANS` | lectura/consulta | [Referencias de dominios, productos y atributos para Autopartes](https://developers.mercadolibre.com.co/es_co/referencias-de-dominios-productos-y-atributos-para-autopartes) |
| MOD-14-EP-0010 | `GET` | `/catalog_domains/MLA-CARS_AND_VANS/attributes/BRAND/top_values` | lectura/consulta | [Categorías y Atributos](https://developers.mercadolibre.com.co/es_co/categorias-y-atributos) |
| MOD-14-EP-0011 | `GET` | `/catalog_domains/MLA-CARS_AND_VANS/attributes/MODEL/top_values` | lectura/consulta | [Categorías y Atributos](https://developers.mercadolibre.com.co/es_co/categorias-y-atributos) |
| MOD-14-EP-0012 | `GET` | `/catalog_domains/MLA-CARS_AND_VANS/attributes/VEHICLE_YEAR/top_values` | lectura/consulta | [Referencias de dominios, productos y atributos para Autopartes](https://developers.mercadolibre.com.co/es_co/referencias-de-dominios-productos-y-atributos-para-autopartes) |
| MOD-14-EP-0013 | `GET` | `/catalog_domains/MLA-CELLPHONES/attributes/BRAND/top_values` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0014 | `GET` | `/catalog_domains/MLA-CELLPHONES/attributes/MODEL/top_values` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0015 | `GET` | `/catalog_domains/MLB-CARS_AND_VANS/categories` | lectura/consulta | [Categorías y Atributos](https://developers.mercadolibre.com.co/es_co/categorias-y-atributos) |
| MOD-14-EP-0016 | `GET` | `/catalog_quality/status?item_id=$ITEM_ID&v=3` | lectura/consulta | [Carga de atributos](https://developers.mercadolibre.com.co/es_co/conoce-como-estan-los-vendedores-frente-la-carga-de-atributos) |
| MOD-14-EP-0017 | `GET` | `/catalog_quality/status?item_id=MLA123456789&v=3` | lectura/consulta | [Carga de atributos](https://developers.mercadolibre.com.co/es_co/conoce-como-estan-los-vendedores-frente-la-carga-de-atributos) |
| MOD-14-EP-0018 | `GET` | `/catalog_quality/status?seller_id=$SELLER_ID&include_items=$BOOL&v=$VERSION` | lectura/consulta | [Carga de atributos](https://developers.mercadolibre.com.co/es_co/conoce-como-estan-los-vendedores-frente-la-carga-de-atributos) |
| MOD-14-EP-0019 | `GET` | `/catalog_quality/status?seller_id=321654987&include_items=true&v=32` | lectura/consulta | [Carga de atributos](https://developers.mercadolibre.com.co/es_co/conoce-como-estan-los-vendedores-frente-la-carga-de-atributos) |
| MOD-14-EP-0020 | `GET` | `/categories/$CATEGORY_ID/attributes/conditional` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0021 | `GET` | `/categories/$CATEGORY_ID/technical_specs/input` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0022 | `GET` | `/categories/$CATEGORY_ID/technical_specs/output` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0023 | `GET` | `/categories/MLA1002/technical_specs/input` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0024 | `GET` | `/categories/MLA1002/technical_specs/output` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0025 | `GET` | `/categories/MLA109291/attributes` | lectura/consulta | [Dominios y Categorías](https://developers.mercadolibre.com.co/es_co/dominios-y-categorias) |
| MOD-14-EP-0026 | `GET` | `/categories/MLA1234/attributes` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0027 | `GET` | `/categories/MLA12345/attributes` | lectura/consulta | [Referencias de dominios, productos y atributos para Autopartes](https://developers.mercadolibre.com.co/es_co/referencias-de-dominios-productos-y-atributos-para-autopartes) |
| MOD-14-EP-0028 | `GET` | `/categories/MLA125703/attributes` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0029 | `GET` | `/categories/MLA1743` | lectura/consulta | [Categorías y Atributos](https://developers.mercadolibre.com.co/es_co/categorias-y-atributos) |
| MOD-14-EP-0030 | `GET` | `/categories/MLA1743/classifieds_promotion_packs` | lectura/consulta | [Dominios y Categorías](https://developers.mercadolibre.com.co/es_co/dominios-y-categorias) |
| MOD-14-EP-0031 | `GET` | `/categories/MLA1744` | lectura/consulta | [Categorías y Atributos](https://developers.mercadolibre.com.co/es_co/categorias-y-atributos) |
| MOD-14-EP-0032 | `GET` | `/categories/MLA1744/attributes` | lectura/consulta | [Categorías y Atributos](https://developers.mercadolibre.com.co/es_co/categorias-y-atributos) |
| MOD-14-EP-0033 | `GET` | `/categories/MLA403656/attributes/conditional` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0034 | `GET` | `/categories/MLA5725` | lectura/consulta | [Dominios y Categorías](https://developers.mercadolibre.com.co/es_co/dominios-y-categorias) |
| MOD-14-EP-0035 | `GET` | `/classified_locations/cities/TUxVQ0NBQjY1MmQ1` | lectura/consulta | [Ubicación y Monedas](https://developers.mercadolibre.com.co/es_co/ubicacion-y-monedas) |
| MOD-14-EP-0036 | `GET` | `/classified_locations/countries/UY` | lectura/consulta | [Ubicación y Monedas](https://developers.mercadolibre.com.co/es_co/ubicacion-y-monedas) |
| MOD-14-EP-0037 | `GET` | `/classified_locations/states/UY-RO` | lectura/consulta | [Ubicación y Monedas](https://developers.mercadolibre.com.co/es_co/ubicacion-y-monedas) |
| MOD-14-EP-0038 | `GET` | `/communications/notices?limit=$LIMIT&offset=$OFFSET` | lectura/consulta | [Comunicaciones](https://developers.mercadolibre.com.co/es_co/conoce-las-novedades-que-reciben-los-vendedores) |
| MOD-14-EP-0039 | `GET` | `/countries/AR/zip_codes/5000` | lectura/consulta | [Ubicación y Monedas](https://developers.mercadolibre.com.co/es_co/ubicacion-y-monedas) |
| MOD-14-EP-0040 | `GET` | `/country/AR/zip_codes/search_between?zip_code_from=5000&zip_code_to=5100` | lectura/consulta | [Ubicación y Monedas](https://developers.mercadolibre.com.co/es_co/ubicacion-y-monedas) |
| MOD-14-EP-0041 | `GET` | `/currencies/` | lectura/consulta | [Ubicación y Monedas](https://developers.mercadolibre.com.co/es_co/ubicacion-y-monedas) |
| MOD-14-EP-0042 | `GET` | `/currencies/CLP` | lectura/consulta | [Ubicación y Monedas](https://developers.mercadolibre.com.co/es_co/ubicacion-y-monedas) |
| MOD-14-EP-0043 | `GET` | `/currency_conversions/search?from=ARS&to=CLP` | lectura/consulta | [Ubicación y Monedas](https://developers.mercadolibre.com.co/es_co/ubicacion-y-monedas) |
| MOD-14-EP-0044 | `GET` | `/domains/MLA-JACKETS_AND_COATS/technical_specs` | lectura/consulta | [Dominios y Categorías](https://developers.mercadolibre.com.co/es_co/dominios-y-categorias) |
| MOD-14-EP-0045 | `GET` | `/items/{item_id?attributes=attributes&include_internal_attributes=true` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0046 | `GET` | `/items/MLA0000000` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0047 | `GET` | `/items/MLA0000000?attributes=attributes&include_internal_attributes=true` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0048 | `GET` | `/items/MLA20805195516` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0049 | `GET` | `/items/MLA621092868` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0050 | `GET` | `/sites/$SITE_ID/listing_prices?price=$PRICE` | pricing | [Dominios y Categorías](https://developers.mercadolibre.com.co/es_co/dominios-y-categorias) |
| MOD-14-EP-0051 | `GET` | `/sites/MLA/categories/all` | lectura/consulta | [Categorías y Atributos](https://developers.mercadolibre.com.co/es_co/categorias-y-atributos) |
| MOD-14-EP-0052 | `GET` | `/sites/MLA/domain_discovery/search?limit=1&q=fiat%20uno` | lectura/consulta | [Categorías y Atributos](https://developers.mercadolibre.com.co/es_co/categorias-y-atributos) |
| MOD-14-EP-0053 | `GET` | `/sites/MLA/listing_prices?price=1` | pricing | [Dominios y Categorías](https://developers.mercadolibre.com.co/es_co/dominios-y-categorias) |
| MOD-14-EP-0054 | `GET` | `/users/{user_id` | lectura/consulta | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/consulta-usuarios) |
| MOD-14-EP-0055 | `GET` | `/users/$SELLER_ID/questions_blacklist` | lectura/consulta | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/consulta-usuarios) |
| MOD-14-EP-0056 | `GET` | `/users/$USER_ID?attributes=status` | lectura/consulta | [Validar datos de vendedores](https://developers.mercadolibre.com.co/es_co/validar-datos-de-vendedores) |
| MOD-14-EP-0057 | `GET` | `/users/$USER_ID/immediate_payment` | lectura/consulta | [Consulta de Usuarios](https://developers.mercadolibre.com.co/es_co/consulta-de-usuarios) |
| MOD-14-EP-0058 | `GET` | `/users/$USER_ID/immediate_payment/by_user` | lectura/consulta | [Consulta de Usuarios](https://developers.mercadolibre.com.co/es_co/consulta-de-usuarios) |
| MOD-14-EP-0059 | `GET` | `/users/$USER_ID/items/search?tags=incomplete_technical_specs` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0060 | `GET` | `/users/$YOUR_CUST_ID/order_blacklist/$SELLER_ID` | lectura/consulta | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/consulta-usuarios) |
| MOD-14-EP-0061 | `GET` | `/users/123456789?attributes=status` | lectura/consulta | [Validar datos de vendedores](https://developers.mercadolibre.com.co/es_co/validar-datos-de-vendedores) |
| MOD-14-EP-0062 | `GET` | `/users/128885` | lectura/consulta | [Reputación de vendedores](https://developers.mercadolibre.com.co/es_co/reputacion-de-vendedores) |
| MOD-14-EP-0063 | `GET` | `/users/145834937/addresses` | lectura/consulta | [Direcciones del usuario](https://developers.mercadolibre.com.co/es_co/direcciones-del-usuario) |
| MOD-14-EP-0064 | `GET` | `/users/205159033` | lectura/consulta | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/consulta-usuarios) |
| MOD-14-EP-0065 | `GET` | `/users/465432224/items/search?tags=incomplete_technical_specs` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0066 | `GET` | `/users/me/bookmarks` | lectura/consulta | [Marcadores](https://developers.mercadolibre.com.co/es_co/marcadores) |
| MOD-14-EP-0067 | `GET` | `/users/me/bookmarks/MLA5529` | lectura/consulta | [Marcadores](https://developers.mercadolibre.com.co/es_co/marcadores) |
| MOD-14-EP-0068 | `POST` | `/catalog_compatibilities/products_search/chunks` | mutación | [Referencias de dominios, productos y atributos para Autopartes](https://developers.mercadolibre.com.co/es_co/referencias-de-dominios-productos-y-atributos-para-autopartes) |
| MOD-14-EP-0069 | `POST` | `/catalog_domains/$DOAMAIN_ID/attributes/$ATTRIBUTE_ID/top_values` | mutación | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0070 | `POST` | `/catalog_domains/$DOMAIN_ID/attributes/$ATTRIBUTE_ID/top_values` | mutación | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0071 | `POST` | `/catalog_domains/MLA-CARS_AND_VANS/attributes/BRAND/top_values` | mutación | [Categorías y Atributos](https://developers.mercadolibre.com.co/es_co/categorias-y-atributos) |
| MOD-14-EP-0072 | `POST` | `/catalog_domains/MLA-CARS_AND_VANS/attributes/MODEL/top_values` | mutación | [Categorías y Atributos](https://developers.mercadolibre.com.co/es_co/categorias-y-atributos) |
| MOD-14-EP-0073 | `POST` | `/catalog_domains/MLA-CARS_AND_VANS/attributes/VEHICLE_YEAR/top_values` | mutación | [Referencias de dominios, productos y atributos para Autopartes](https://developers.mercadolibre.com.co/es_co/referencias-de-dominios-productos-y-atributos-para-autopartes) |
| MOD-14-EP-0074 | `POST` | `/catalog_domains/MLA-CELLPHONES/attributes/BRAND/top_values` | mutación | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0075 | `POST` | `/catalog_domains/MLA-CELLPHONES/attributes/MODEL/top_values` | mutación | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0076 | `POST` | `/categories/$CATEGORY_ID/attributes/conditional` | mutación | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0077 | `POST` | `/categories/MLA403656/attributes/conditional` | mutación | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0078 | `POST` | `/users/me/bookmarks` | mutación | [Marcadores](https://developers.mercadolibre.com.co/es_co/marcadores) |
| MOD-14-EP-0079 | `PUT` | `/items/MLA621092868` | mutación | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0080 | `PUT` | `/users/$USER_ID/immediate_payment` | mutación | [Consulta de Usuarios](https://developers.mercadolibre.com.co/es_co/consulta-de-usuarios) |
| MOD-14-EP-0081 | `UNKNOWN` | `/catalog_quality/status?item_id=$ITEM_ID&v=3` | lectura/consulta | [Carga de atributos](https://developers.mercadolibre.com.co/es_co/conoce-como-estan-los-vendedores-frente-la-carga-de-atributos) |
| MOD-14-EP-0082 | `UNKNOWN` | `/catalog_quality/status?item_id=MLA123456789&v=3` | lectura/consulta | [Carga de atributos](https://developers.mercadolibre.com.co/es_co/conoce-como-estan-los-vendedores-frente-la-carga-de-atributos) |
| MOD-14-EP-0083 | `UNKNOWN` | `/catalog_quality/status?seller_id=$SELLER_ID&include_items=$BOOL&v=$VERSION` | lectura/consulta | [Carga de atributos](https://developers.mercadolibre.com.co/es_co/conoce-como-estan-los-vendedores-frente-la-carga-de-atributos) |
| MOD-14-EP-0084 | `UNKNOWN` | `/catalog_quality/status?seller_id=321654987&include_items=true&v=32` | lectura/consulta | [Carga de atributos](https://developers.mercadolibre.com.co/es_co/conoce-como-estan-los-vendedores-frente-la-carga-de-atributos) |
| MOD-14-EP-0085 | `UNKNOWN` | `/currency_conversions/search?from=ARS&to=CLP` | lectura/consulta | [Ubicación y Monedas](https://developers.mercadolibre.com.co/es_co/ubicacion-y-monedas) |
| MOD-14-EP-0086 | `UNKNOWN` | `/domains/MLA-JACKETS_AND_COATS/technical_specs` | lectura/consulta | [Dominios y Categorías](https://developers.mercadolibre.com.co/es_co/dominios-y-categorias) |
| MOD-14-EP-0087 | `UNKNOWN` | `/items/MLA20805195516` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) |
| MOD-14-EP-0088 | `UNKNOWN` | `/users/{user_id` | lectura/consulta | [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/consulta-usuarios) |

### Señales de autenticación/permisos

- [Atributos](https://developers.mercadolibre.com.co/es_co/atributos): `Authorization`, `Content-Type`, `Accept`, `Bearer`, `ACCESS_TOKEN`
- [Carga de atributos](https://developers.mercadolibre.com.co/es_co/conoce-como-estan-los-vendedores-frente-la-carga-de-atributos): `Authorization`, `Bearer`, `ACCESS_TOKEN`, `access_token`
- [Categorías y Atributos](https://developers.mercadolibre.com.co/es_co/categorias-y-atributos): `X-Content-Created`, `X-Content-MD5`, `content-type`, `x-amz-id-2`, `x-amz-replication-status`, `x-amz-request-id`, `x-amz-version-id`, `x-content-type-options`, `x-request-id`, `x-frame-options`, `x-xss-protection`, `Content-Type`, `accept`, `x-content-created`
- [Comunicaciones](https://developers.mercadolibre.com.co/es_co/conoce-las-novedades-que-reciben-los-vendedores): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Consulta de Usuarios](https://developers.mercadolibre.com.co/es_co/consulta-de-usuarios): `Authorization`, `Content-type`, `Bearer`, `ACCESS_TOKEN`
- [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/consulta-usuarios): `Authorization`, `Content-type`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/producto-consulta-usuarios): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Direcciones del usuario](https://developers.mercadolibre.com.co/es_co/direcciones-del-usuario): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Dominios y Categorías](https://developers.mercadolibre.com.co/es_co/dominios-y-categorias): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Items - Atributos de envío y dimensiones](https://developers.mercadolibre.com.co/es_co/items-atributos-de-envio-y-dimensiones): `read`
- [Marcadores](https://developers.mercadolibre.com.co/es_co/marcadores): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`
- [Referencias de dominios, productos y atributos para Autopartes](https://developers.mercadolibre.com.co/es_co/referencias-de-dominios-productos-y-atributos-para-autopartes): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Reputación de vendedores](https://developers.mercadolibre.com.co/es_co/reputacion-de-vendedores): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Ubicación y Monedas](https://developers.mercadolibre.com.co/es_co/ubicacion-y-monedas): `Authorization`, `x-format-new`, `Bearer`, `ACCESS_TOKEN`
- [Validar datos de vendedores](https://developers.mercadolibre.com.co/es_co/validar-datos-de-vendedores): `Authorization`, `Bearer`, `ACCESS_TOKEN`

## Vehículos

id: MOD-15

Resumen: 9 páginas fuente, 52 endpoints/rutas, integraciones detectadas: Catálogo, Mercado Envíos, Notificaciones, OAuth.

### Páginas fuente

| ID | Página | Estado | Endpoints | Fechas visibles |
|---|---|---|---:|---|
| MOD-15-PAGE-001 | [Calidad de publicaciones (vehículos)](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones-vehiculos) | `ok` | 6 | — |
| MOD-15-PAGE-002 | [Créditos pre aprobados](https://developers.mercadolibre.com.co/es_co/credits-motors) | `ok` | 4 | — |
| MOD-15-PAGE-003 | [Gestión de Paquetes de Vehículos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-paquetes) | `ok` | 11 | 02/01/2025, 20 de enero de 2026, 01/01/2022, 01/06/2022 |
| MOD-15-PAGE-004 | [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) | `ok` | 21 | — |
| MOD-15-PAGE-005 | [Guía para vehículos](https://developers.mercadolibre.com.co/es_co/introduccion-vehiculos) | `ok` | 0 | — |
| MOD-15-PAGE-006 | [Localiza vehículos](https://developers.mercadolibre.com.co/es_co/localizacion-de-vehiculos) | `ok` | 9 | — |
| MOD-15-PAGE-007 | [Personas Interesadas](https://developers.mercadolibre.com.co/es_co/persona-interesadas) | `ok` | 9 | 2026-01-15, 2026-01-22, 2024-05-14, 2024-05-24 |
| MOD-15-PAGE-008 | [Publica vehículos](https://developers.mercadolibre.com.co/es_co/publica-vehiculos) | `ok` | 13 | 23 de febrero de 2026 |
| MOD-15-PAGE-009 | [Sincroniza publicaciones (vehículos)](https://developers.mercadolibre.com.co/es_co/vehiculos-sincroniza-publicaciones) | `ok` | 7 | 12 de marzo de 2026 |

### Endpoints/rutas del módulo

| ID | Método | Ruta | Riesgo/área | Fuente |
|---|---|---|---|---|
| MOD-15-EP-0001 | `GET` | `/categories/$CATETGORY_ID/classifieds_promotion_packs` | lectura/consulta | [Gestión de Paquetes de Vehículos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-paquetes) |
| MOD-15-EP-0002 | `GET` | `/categories/MLB1743/classifieds_promotion_packs` | lectura/consulta | [Gestión de Paquetes de Vehículos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-paquetes) |
| MOD-15-EP-0003 | `GET` | `/classified_locations/cities/{City_id` | lectura/consulta | [Localiza vehículos](https://developers.mercadolibre.com.co/es_co/localizacion-de-vehiculos) |
| MOD-15-EP-0004 | `GET` | `/classified_locations/cities/TUxBQ0NBUGZlZG1sYQ` | lectura/consulta | [Localiza vehículos](https://developers.mercadolibre.com.co/es_co/localizacion-de-vehiculos) |
| MOD-15-EP-0005 | `GET` | `/classified_locations/countries/{Country_Id` | lectura/consulta | [Localiza vehículos](https://developers.mercadolibre.com.co/es_co/localizacion-de-vehiculos) |
| MOD-15-EP-0006 | `GET` | `/classified_locations/neighborhoods/{Neighborhood_Id` | lectura/consulta | [Localiza vehículos](https://developers.mercadolibre.com.co/es_co/localizacion-de-vehiculos) |
| MOD-15-EP-0007 | `GET` | `/classified_locations/neighborhoods/TUxBQkNBQjM4MDda` | lectura/consulta | [Localiza vehículos](https://developers.mercadolibre.com.co/es_co/localizacion-de-vehiculos) |
| MOD-15-EP-0008 | `GET` | `/classified_locations/states/{State_id` | lectura/consulta | [Localiza vehículos](https://developers.mercadolibre.com.co/es_co/localizacion-de-vehiculos) |
| MOD-15-EP-0009 | `GET` | `/classified_locations/states/TUxBUENBUGw3M2E1` | lectura/consulta | [Localiza vehículos](https://developers.mercadolibre.com.co/es_co/localizacion-de-vehiculos) |
| MOD-15-EP-0010 | `GET` | `/items/$ITEM_ID/contacts/phone_views/time_window?last=$LAST&unit=$UNIT` | lectura/consulta | [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) |
| MOD-15-EP-0011 | `GET` | `/items/$ITEM_ID/contacts/questions/time_window?ids=$ID1,ID2&last=$LAST&unit=$UNIT&ending=$ENDING_DATE` | lectura/consulta | [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) |
| MOD-15-EP-0012 | `GET` | `/items/$ITEM_ID/contacts/questions/time_window?last=$LAST&unit=$UNIT` | lectura/consulta | [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) |
| MOD-15-EP-0013 | `GET` | `/items/$ITEM_ID/contacts/whatsapp/time_window?unit=$UNIT&last=$LAST` | lectura/consulta | [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) |
| MOD-15-EP-0014 | `GET` | `/items/contacts/phone_views/time_window?ids=MLA510272257,MLA489747739&last=2&unit=hour&ending=2014-05-28T00:00:00.000-03:00` | lectura/consulta | [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) |
| MOD-15-EP-0015 | `GET` | `/items/contacts/whatsapp/time_window?ids=$IDS&unit=$UNIT&last=$LAST&ending=$ENDING` | lectura/consulta | [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) |
| MOD-15-EP-0016 | `GET` | `/items/MLA111111111/listing_type` | lectura/consulta | [Gestión de Paquetes de Vehículos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-paquetes) |
| MOD-15-EP-0017 | `GET` | `/items/MLA1116194549/contacts/whatsapp?date_from=2014-05-28T00:00:00.000-03:00&date_to=2014-05-29T23:59:59.999` | lectura/consulta | [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) |
| MOD-15-EP-0018 | `GET` | `/items/MLA510272257/contacts/questions/time_window?last=2&unit=hour` | lectura/consulta | [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) |
| MOD-15-EP-0019 | `GET` | `/items/MLA932485344/description` | lectura/consulta | [Publica vehículos](https://developers.mercadolibre.com.co/es_co/publica-vehiculos) |
| MOD-15-EP-0020 | `GET` | `/items/MLB4277151191` | lectura/consulta | [Publica vehículos](https://developers.mercadolibre.com.co/es_co/publica-vehiculos) |
| MOD-15-EP-0021 | `GET` | `/items/MLM735814032/health` | lectura/consulta | [Calidad de publicaciones (vehículos)](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones-vehiculos) |
| MOD-15-EP-0022 | `GET` | `/items/MLM735814032/health/actions` | lectura/consulta | [Calidad de publicaciones (vehículos)](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones-vehiculos) |
| MOD-15-EP-0023 | `GET` | `/items/MLV421672596/contacts/questions?date_from=2014-08-01T00:00:00.000-03:00&date_to=2014-08-02T23:59:59.999` | lectura/consulta | [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) |
| MOD-15-EP-0024 | `GET` | `/leads/$LEAD_ID/details` | lectura/consulta | [Personas Interesadas](https://developers.mercadolibre.com.co/es_co/persona-interesadas) |
| MOD-15-EP-0025 | `GET` | `/leads/3f2dedf2-dfbd-4981-a726-40b13aa172ff/details` | lectura/consulta | [Personas Interesadas](https://developers.mercadolibre.com.co/es_co/persona-interesadas) |
| MOD-15-EP-0026 | `GET` | `/sites/MLB/health_levels` | lectura/consulta | [Calidad de publicaciones (vehículos)](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones-vehiculos) |
| MOD-15-EP-0027 | `GET` | `/users/$USER_ID/classifieds_promotion_packs/$LISTING_TYPE/available?categoryId=$CATEGORY_ID&upgrades=true` | lectura/consulta | [Gestión de Paquetes de Vehículos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-paquetes) |
| MOD-15-EP-0028 | `GET` | `/users/$USER_ID/contacts/phone_views/time_window?last=$LAST&unit=$UNIT` | lectura/consulta | [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) |
| MOD-15-EP-0029 | `GET` | `/users/$USER_ID/contacts/questions/time_window?last=$LAST&unit=$UNIT` | lectura/consulta | [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) |
| MOD-15-EP-0030 | `GET` | `/users/$USER_ID/contacts/whatsapp/time_window?unit=$UNIT&last=$LAST` | lectura/consulta | [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) |
| MOD-15-EP-0031 | `GET` | `/users/$USER_ID/items/search?tags=$TAG` | lectura/consulta | [Publica vehículos](https://developers.mercadolibre.com.co/es_co/publica-vehiculos) |
| MOD-15-EP-0032 | `GET` | `/users/$USER_ID/leads/buyers` | lectura/consulta | [Personas Interesadas](https://developers.mercadolibre.com.co/es_co/persona-interesadas) |
| MOD-15-EP-0033 | `GET` | `/users/123456789/classifieds_promotion_packs/gold_premium/available?categoryId=MLM1744&upgrades=true` | lectura/consulta | [Gestión de Paquetes de Vehículos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-paquetes) |
| MOD-15-EP-0034 | `GET` | `/users/127232529/contacts/phone_views?date_from=2014-05-28T00:00:00.000-03:00&date_to=2014-05-29T23:59:59.999` | lectura/consulta | [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) |
| MOD-15-EP-0035 | `GET` | `/users/127232529/contacts/whatsapp/time_window?unit=$UNIT&last=$LAST` | lectura/consulta | [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) |
| MOD-15-EP-0036 | `GET` | `/users/135146148/classifieds_promotion_packs?package_content=ALL` | lectura/consulta | [Gestión de Paquetes de Vehículos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-paquetes) |
| MOD-15-EP-0037 | `GET` | `/users/52366166/contacts/phone_views?date_from=2014-05-28T00:00:00.000-03:00&date_to=2014-05-29T23:59:59.999` | lectura/consulta | [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) |
| MOD-15-EP-0038 | `GET` | `/users/705332753/items/search?tags=misplaced_personal_data` | lectura/consulta | [Publica vehículos](https://developers.mercadolibre.com.co/es_co/publica-vehiculos) |
| MOD-15-EP-0039 | `GET` | `/vis/leads/3f2dedf2-dfbd-4981-a726-40b13aa172ff` | lectura/consulta | [Personas Interesadas](https://developers.mercadolibre.com.co/es_co/persona-interesadas) |
| MOD-15-EP-0040 | `GET` | `/vis/loans/14b52fd8-85dc-11eb-8436-2753cb1f9665?seller_id=707775316` | lectura/consulta | [Créditos pre aprobados](https://developers.mercadolibre.com.co/es_co/credits-motors) |
| MOD-15-EP-0041 | `GET` | `/vis/loans/search?seller_id=$SELLER_ID&date_from=AAAA-MM-DDTHH:MM:SS&date_to=AAAA-MM-DDTHH:MM:SS` | lectura/consulta | [Créditos pre aprobados](https://developers.mercadolibre.com.co/es_co/credits-motors) |
| MOD-15-EP-0042 | `GET` | `/vis/loans/search?seller_id=707775316&date_from=2020-12-10T00:00:00&date_to=2021-01-01T00:00:00` | lectura/consulta | [Créditos pre aprobados](https://developers.mercadolibre.com.co/es_co/credits-motors) |
| MOD-15-EP-0043 | `GET` | `/vis/users/$USER_ID/leads/buyers?offset=$OFFSET&limit=$LIMIT&date_from=$DATE_FROM&date_to=$DATE_TO&contact_types=$CONTACT_TYPES` | lectura/consulta | [Personas Interesadas](https://developers.mercadolibre.com.co/es_co/persona-interesadas) |
| MOD-15-EP-0044 | `GET` | `/vis/users/3052668868/leads/buyers?offset=0&limit=10&date_from=2026-01-15&date_to=2026-01-22&contac_types=credit,question,whatsapp` | lectura/consulta | [Personas Interesadas](https://developers.mercadolibre.com.co/es_co/persona-interesadas) |
| MOD-15-EP-0045 | `POST` | `/items/MLA111111111/listing_type` | mutación | [Gestión de Paquetes de Vehículos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-paquetes) |
| MOD-15-EP-0046 | `PUT` | `/items/{ItemID}` | mutación | [Sincroniza publicaciones (vehículos)](https://developers.mercadolibre.com.co/es_co/vehiculos-sincroniza-publicaciones) |
| MOD-15-EP-0047 | `PUT` | `/items/MLA1568702067` | mutación | [Sincroniza publicaciones (vehículos)](https://developers.mercadolibre.com.co/es_co/vehiculos-sincroniza-publicaciones) |
| MOD-15-EP-0048 | `PUT` | `/items/MLA2736093652` | mutación | [Sincroniza publicaciones (vehículos)](https://developers.mercadolibre.com.co/es_co/vehiculos-sincroniza-publicaciones) |
| MOD-15-EP-0049 | `UNKNOWN` | `/items/MLA1568702067` | lectura/consulta | [Sincroniza publicaciones (vehículos)](https://developers.mercadolibre.com.co/es_co/vehiculos-sincroniza-publicaciones) |
| MOD-15-EP-0050 | `UNKNOWN` | `/items/MLA2736093652` | lectura/consulta | [Sincroniza publicaciones (vehículos)](https://developers.mercadolibre.com.co/es_co/vehiculos-sincroniza-publicaciones) |
| MOD-15-EP-0051 | `UNKNOWN` | `/users/$USER_ID/items/search?tags=$TAG` | lectura/consulta | [Publica vehículos](https://developers.mercadolibre.com.co/es_co/publica-vehiculos) |
| MOD-15-EP-0052 | `UNKNOWN` | `/users/705332753/items/search?tags=misplaced_personal_data` | lectura/consulta | [Publica vehículos](https://developers.mercadolibre.com.co/es_co/publica-vehiculos) |

### Señales de autenticación/permisos

- [Calidad de publicaciones (vehículos)](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones-vehiculos): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Créditos pre aprobados](https://developers.mercadolibre.com.co/es_co/credits-motors): `x-sandbox`, `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Gestión de Paquetes de Vehículos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-paquetes): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Localiza vehículos](https://developers.mercadolibre.com.co/es_co/localizacion-de-vehiculos): `Authorization`, `Bearer`, `ACCESS_TOKEN`
- [Personas Interesadas](https://developers.mercadolibre.com.co/es_co/persona-interesadas): `Authorization`, `Bearer`, `ACCESS_TOKEN`, `scope`
- [Publica vehículos](https://developers.mercadolibre.com.co/es_co/publica-vehiculos): `Authorization`, `Content-Type`, `Bearer`, `ACCESS_TOKEN`, `access_token`
- [Sincroniza publicaciones (vehículos)](https://developers.mercadolibre.com.co/es_co/vehiculos-sincroniza-publicaciones): `Authorization`, `Content-Type`, `Accept`, `access_token`, `Bearer`, `ACCESS_TOKEN`

## Bloque para IA

```yaml
retrieval_guidance:
  preferred_units:
    - module_section
    - endpoint_row
    - source_page_row
  answer_policy:
    - cite source_url when answering about a specific endpoint
    - treat UNKNOWN method as a route mention, not a confirmed callable method
    - check mercadolibre-api-snapshot.json for hashes and change audits
    - verify destructive methods before implementation
  companion_files:
    - mercadolibre-api-index.json
    - mercadolibre-api-snapshot.json
    - mercadolibre-api-coverage.md
```
