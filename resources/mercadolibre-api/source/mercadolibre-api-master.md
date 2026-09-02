# Mercado Libre API — documento maestro auditable

Fecha de corte documental: **25 de julio de 2026**. Captura generada: **2026-07-25T06:19:57.009Z**.

Fuente inicial: [Guía para productos](https://developers.mercadolibre.com.co/es_co/guia-para-producto). Índice complementario: [API Docs](https://developers.mercadolibre.com.co/es_ar/api-docs-es).

Este archivo estructura la documentación oficial visible con navegador interno usando el contenido principal de cada página. Para auditoría fina, usa `mercadolibre-api-index.json` y `mercadolibre-api-snapshot.json`; para comparar una nueva captura contra esta, usa `node scripts/compare-snapshots.mjs snapshot-anterior.json snapshot-nuevo.json`.

## Resumen de cobertura

- Páginas oficiales capturadas: **199**
- Endpoints/rutas detectadas y normalizadas: **1700**
- Páginas OK: **199**
- Páginas bloqueadas o inciertas: **0**
- Módulos documentados: **15**

## Cómo funciona la API de Mercado Libre

La API se organiza alrededor de recursos REST expuestos principalmente en `api.mercadolibre.com`, con autenticación OAuth para operaciones privadas o de escritura. Los flujos típicos son: crear/gestionar una aplicación, obtener autorización del vendedor, intercambiar el código por access token, usar `Authorization: Bearer <token>`, refrescar tokens y operar recursos como usuarios, publicaciones, catálogo, stock, precios, envíos, órdenes, facturación, mensajería y ads.

Para integraciones productivas, trata cada módulo como un contrato independiente: algunos endpoints son de lectura pública, otros requieren token del dueño del recurso, y las operaciones de escritura pueden cambiar publicaciones, precios, stock, envíos, campañas o mensajes. Este maestro conserva fuentes oficiales y hashes para auditar cambios futuros.

## Módulos

| Módulo | Páginas | Endpoints | Integraciones detectadas |
|---|---:|---:|---|
| Autenticación y aplicaciones | 12 | 52 | Catálogo, Facturación, Mensajería, Mercado Ads, Mercado Envíos, Mercado Pago, Notificaciones, OAuth |
| FAQs, límites y soporte | 2 | 1 | MCP, OAuth |
| General | 17 | 118 | Catálogo, Facturación, Mensajería, Mercado Envíos, Mercado Pago, Notificaciones, OAuth |
| Inmuebles | 20 | 84 | Catálogo, Mercado Envíos, Notificaciones, OAuth |
| Mensajería, reclamos y devoluciones | 11 | 147 | Mensajería, Mercado Envíos, Mercado Pago, Notificaciones, OAuth |
| Mercado Ads | 5 | 55 | Catálogo, Mercado Ads, Mercado Envíos, Mercado Pago, OAuth |
| Mercado Envíos | 14 | 181 | Catálogo, Facturación, Mensajería, Mercado Envíos, Mercado Pago, Notificaciones, OAuth |
| Preguntas, ventas y postventa | 19 | 182 | Catálogo, Facturación, Mensajería, Mercado Ads, Mercado Envíos, Mercado Pago, Notificaciones, OAuth |
| Productos, ítems y catálogo | 45 | 561 | Catálogo, Facturación, Mensajería, Mercado Ads, Mercado Envíos, Mercado Pago, Notificaciones, OAuth |
| Promociones y pricing | 11 | 109 | Mercado Pago, OAuth |
| Reputación, métricas y calidad | 6 | 47 | Catálogo, Facturación, Mensajería, Mercado Ads, Mercado Envíos, Mercado Pago, Notificaciones, OAuth |
| Seguridad | 5 | 3 | Mercado Pago, Notificaciones, OAuth |
| Servicios | 7 | 20 | Catálogo, Mercado Pago, OAuth |
| Usuarios y recursos cross | 16 | 88 | Catálogo, Facturación, Mercado Envíos, Mercado Pago, OAuth |
| Vehículos | 9 | 52 | Catálogo, Mercado Envíos, Notificaciones, OAuth |

## Endpoints principales detectados

| Método | Ruta | Área/riesgo | Fuente |
|---|---|---|---|
| `DELETE` | `/applications/{app_id}` | destructivo | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `DELETE` | `/users/{cust_Id` | destructivo | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `DELETE` | `/users/$USER_ID/applications/$APP_ID` | destructivo | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) |
| `GET` | `/applications/{app_id}` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/applications/$APP_ID` | lectura/consulta | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) |
| `GET` | `/applications/$APP_ID/grants` | lectura/consulta | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) |
| `GET` | `/applications/$APPLICATION_ID` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/applications/12345` | lectura/consulta | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) |
| `GET` | `/applications/3022782903258037` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/applications/v1/$APP_ID/consumed-applications?date_start=2025-08-01&date_end=2025-08-20` | lectura/consulta | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) |
| `GET` | `/missed_feeds?app_id=$APP_ID` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/orders` | lectura/consulta | [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) |
| `GET` | `/orders?access_token=APP-1234567890` | lectura/consulta | [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) |
| `GET` | `/orders/123` | lectura/consulta | [Seguridad de aplicaciones](https://developers.mercadolibre.com.co/es_co/seguridad-apps) |
| `GET` | `/users/{cust_Id` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/{User_id` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/{User_id}/classifieds_promotion_packs` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/$USER_ID` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/$USER_ID/accepted_payment_methods` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/$USER_ID/addresses` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/$USER_ID/applications` | lectura/consulta | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) |
| `GET` | `/users/$USER_ID/applications/$APP_ID` | lectura/consulta | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) |
| `GET` | `/users/$USER_ID/applications/$APPLICATION_ID` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/$USER_ID/available_listing_type/$LISTING_TYPE_ID?category_id=$CATEGORY_ID` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/$USER_ID/available_listing_types?category_id=$CATEGORY_ID` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/$USER_ID/brands` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/$USER_ID/classifieds_promotion_packs` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/$USER_ID/classifieds_promotion_packs/$LISTING_TYPE&categoryId=$CATEGORY_ID` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/12345678/brands` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/123456789` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/135146148/classifieds_promotion_packs` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/206946886/accepted_payment_methods` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/206946886/addresses` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/206946886/available_listing_type/gold_special?category_id=MLA6602` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/206946886/available_listing_types` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/206946886/classifieds_promotion_packs/silver?categoryId=MLA1459` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `GET` | `/users/26317316/applications` | lectura/consulta | [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) |
| `GET` | `/users/me` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `POST` | `/oauth/token` | mutación | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| `POST` | `/users/me` | mutación | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| `POST` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=$REDIRECT_URL` | mutación | [Obtención del Access Token](https://developers.mercadolibre.com.co/es_co/obtencion-del-access-token) |
| `POST` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=$YOUR_URL&code_challenge=$CODE_CHALLENGE&code_challenge_method=$CODE_METHOD` | mutación | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| `POST` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=https:/mercadolibre.com.ar` | mutación | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| `PUT` | `/users/123456789` | mutación | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `UNKNOWN` | `/orders` | lectura/consulta | [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) |
| `UNKNOWN` | `/orders?access_token=APP-1234567890` | lectura/consulta | [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) |
| `UNKNOWN` | `/orders/123` | lectura/consulta | [Seguridad de aplicaciones](https://developers.mercadolibre.com.co/es_co/seguridad-apps) |
| `UNKNOWN` | `/users/{User_id}/classifieds_promotion_packs` | lectura/consulta | [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) |
| `UNKNOWN` | `/users/me` | lectura/consulta | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| `UNKNOWN` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=$REDIRECT_URL` | autenticación | [Obtención del Access Token](https://developers.mercadolibre.com.co/es_co/obtencion-del-access-token) |
| `UNKNOWN` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=$YOUR_URL&code_challenge=$CODE_CHALLENGE&code_challenge_method=$CODE_METHOD` | autenticación | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| `UNKNOWN` | `auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=https:/mercadolibre.com.ar` | autenticación | [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) |
| `UNKNOWN` | `/visits` | lectura/consulta | [Rate limit / Error 429 y pedidos de aumento de RL](https://developers.mercadolibre.com.co/es_co/rate-limit-error-429) |
| `GET` | `/catalog/charts/$SITE_ID/configurations/active_domains` | lectura/consulta | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `GET` | `/catalog/charts/domains/search` | lectura/consulta | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `GET` | `/catalog/charts/search` | lectura/consulta | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `GET` | `/catalog/charts/search?offset=1&limit=100` | lectura/consulta | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `GET` | `/currencies` | lectura/consulta | [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno) |
| `GET` | `/currencies?attributes=id` | lectura/consulta | [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno) |
| `GET` | `/currencies/ARS` | lectura/consulta | [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno) |
| `GET` | `/currencies/ARS?callback=foo` | lectura/consulta | [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno) |
| `GET` | `/domains/$DOMAIN_ID/technical_specs` | lectura/consulta | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `GET` | `/domains/$DOMAIN_ID/technical_specs?section=grids` | lectura/consulta | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `GET` | `/domains/MLA-SNEAKERS/technical_specs` | lectura/consulta | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `GET` | `/domains/MLA-SNEAKERS/technical_specs?section=grids` | lectura/consulta | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `GET` | `/items` | lectura/consulta | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| `GET` | `/items/$ITEM_ID` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| `GET` | `/items/$ITEM_ID/available_downgrades` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/$ITEM_ID/available_listing_types` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/$ITEM_ID/available_upgrades` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/$ITEM_ORIGINAL/migration_live_listing?` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/items/$ITEM_ORIGINAL/user_product_listings/validate` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/items/$TIEM_ID?attributes=stop_time` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/$TIEM_ID/listing_type` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/MLA123456/migration_live_listing?` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/items/MLA12345678/user_product_listings/validate` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/items/MLA1389403099?attributes=stop_time` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/MLA1389403099/available_downgrades` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/MLA1389403099/available_listing_types` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/MLA1389403099/available_upgrades` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/MLA1389403099/listing_type` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/items/MLC1234567890` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| `GET` | `/messages` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/$PACK_ID?tag=post_sale` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/$PACK_ID/caps_available?tag=post_sale` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/$PACK_ID/option?tag=post_sale` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/20000000000?tag=post_sale` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/200000000000/caps_available?tag=post_sale` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/2000000000000000/option` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/2000000000000000/option?tag=post_sale` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/messages/action_guide/packs/2000000000000012?tag=post_sale` | lectura/consulta | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `GET` | `/moderations/infractions/$USER_ID?date_created_since=YYYY-MM-DD&limit=2` | lectura/consulta | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| `GET` | `/moderations/infractions/288230000?date_created_since=2023-09-01&limit=2` | lectura/consulta | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| `GET` | `/moderations/last_moderation` | lectura/consulta | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| `GET` | `/moderations/last_moderation/$ITEM_ID-ITM` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| `GET` | `/moderations/last_moderation/$MODERATION_REFERENCE_ID` | lectura/consulta | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| `GET` | `/moderations/last_moderation/MLA123444123-ITM` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| `GET` | `/moderations/last_moderation/MLA926647862-ITM` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| `GET` | `/moderations/pppi/case/$DENOUNCE_ID` | lectura/consulta | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| `GET` | `/moderations/pppi/case/123` | lectura/consulta | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| `GET` | `/moderations/pppi/denounces/$SITE_ID/ITM/options` | lectura/consulta | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| `GET` | `/moderations/pppi/denounces/items/$ITEM_ID` | lectura/consulta | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| `GET` | `/moderations/pppi/denounces/items/MLA123` | lectura/consulta | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| `GET` | `/moderations/pppi/denounces/MLA/ITM/options` | lectura/consulta | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| `GET` | `/shipping/seller/$SELLER_ID/working_day_middleend` | envíos | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| `GET` | `/shipping/seller/$SELLER_ID/working_day_middleend/optout` | envíos | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| `GET` | `/shipping/seller/$SELLER_ID/working_day_middleend/optout?date=2022-10-17` | envíos | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| `GET` | `/shipping/seller/$SELLER_ID/working_day_middleend/optout?date=AAAA-MM-DD` | envíos | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| `GET` | `/shipping/seller/12345678/working_day_middleend` | envíos | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| `GET` | `/sites/$SITE_ID/listing_exposures` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/sites/$SITE_ID/listing_exposures/$EXPOSURE_LEVEL` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/sites/$SITE_ID/listing_types` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/sites/$SITE_ID/listing_types/$LISTING_TYPE_ID` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/sites/$SITE_ID/user-products-families/$FAMILY_ID` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/sites/MLA/listing_exposures` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/sites/MLA/listing_exposures/high` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/sites/MLA/listing_types` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/sites/MLA/listing_types/gold_special` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/sites/MLA/search?q=ipod` | lectura/consulta | [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno) |
| `GET` | `/sites/MLA/user-products-families/9871232123` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/sites/MLM/items/user_product_listings` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/user-products-families/{family_id` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/user-products-families/tasks/{task_id` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/user-products/$USER_PRODUCT_ID` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/user-products/$USER_PRODUCT_ID/items` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/user-products/MLBU22012` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/user-products/MLMU3691277914/items` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/users/$SELLER_ID/items/search?user_product_id=$USER_PRODUCT_ID` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/users/$SELLER_ID/items/search?user_product_id=MLAU1234,MLAU12345` | lectura/consulta | [User Products](https://developers.mercadolibre.com.co/es_co/user-products) |
| `GET` | `/users/$USER_ID/available_listing_type/free?category_id=$CATEGORY_ID` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/users/$USER_ID/items/search?status=pending` | lectura/consulta | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| `GET` | `/users/0123456789/items/search?tags=moderation_penalty&status=paused` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| `GET` | `/users/1234/available_listing_types?category_id=MLA1055` | lectura/consulta | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `GET` | `/users/1234/items/search?user_product_id=MLBU206642488` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `GET` | `/users/123456/items/search?status=pending` | lectura/consulta | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| `GET` | `/vis/leads/$LEAD_ID` | lectura/consulta | [Solicitud de visita](https://developers.mercadolibre.com.co/es_co/solicitud-de-visita) |
| `POST` | `/catalog/charts/domains/search` | mutación | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `POST` | `/catalog/charts/search` | mutación | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `POST` | `/catalog/charts/search?offset=1&limit=100` | mutación | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `POST` | `/domains/$DOMAIN_ID/technical_specs?section=grids` | mutación | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `POST` | `/domains/MLA-SNEAKERS/technical_specs?section=grids` | mutación | [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) |
| `POST` | `/items` | mutación | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `POST` | `/items/$TIEM_ID/listing_type` | mutación | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `POST` | `/items/MLA1389403099/listing_type` | mutación | [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) |
| `POST` | `/messages/action_guide/packs/$PACK_ID/option?tag=post_sale` | mutación | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `POST` | `/messages/action_guide/packs/2000000000000000/option` | mutación | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `POST` | `/messages/action_guide/packs/2000000000000000/option?tag=post_sale` | mutación | [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) |
| `POST` | `/moderations/pppi/case/$DENOUNCE_ID` | mutación | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| `POST` | `/moderations/pppi/denounces/items/$ITEM_ID` | mutación | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| `POST` | `/moderations/pppi/denounces/items/MLA123` | mutación | [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) |
| `POST` | `/sites/MLM/items/user_product_listings` | mutación | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `POST` | `/user-products-families/{family_id` | mutación | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `POST` | `/user-products/$USER_PRODUCT_ID/items` | mutación | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `POST` | `/user-products/MLMU3691277914/items` | mutación | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `POST` | `/users/test_user` | mutación | [Realiza pruebas](https://developers.mercadolibre.com.co/es_co/realiza-pruebas) |
| `PUT` | `/items/$ITEM_ID` | mutación | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| `PUT` | `/shipping/seller/$SELLER_ID/working_day_middleend` | mutación | [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) |
| `UNKNOWN` | `/categories` | lectura/consulta | [User Products](https://developers.mercadolibre.com.co/es_co/user-products) |
| `UNKNOWN` | `/currencies` | lectura/consulta | [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno) |
| `UNKNOWN` | `/items` | lectura/consulta | [User Products](https://developers.mercadolibre.com.co/es_co/user-products) |
| `UNKNOWN` | `/items/MLC1234567890` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| `UNKNOWN` | `/moderations/last_moderation/$ITEM_ID-ITM` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| `UNKNOWN` | `/moderations/last_moderation/$MODERATION_REFERENCE_ID` | lectura/consulta | [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) |
| `UNKNOWN` | `/moderations/last_moderation/MLA123444123-ITM` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| `UNKNOWN` | `/moderations/last_moderation/MLA926647862-ITM` | lectura/consulta | [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) |
| `UNKNOWN` | `/sites/$SITE_ID/user-products-families/$FAMILY_ID` | lectura/consulta | [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) |
| `UNKNOWN` | `/sites/MLA/search?q=ipod` | lectura/consulta | [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno) |
| `UNKNOWN` | `/user-products/$USER_PRODUCT_ID` | lectura/consulta | [User Products](https://developers.mercadolibre.com.co/es_co/user-products) |
| `UNKNOWN` | `/users` | lectura/consulta | [User Products](https://developers.mercadolibre.com.co/es_co/user-products) |
| `UNKNOWN` | `/users/$SELLER_ID/items/search?user_product_id=$USER_PRODUCT_ID` | lectura/consulta | [User Products](https://developers.mercadolibre.com.co/es_co/user-products) |
| `UNKNOWN` | `/users/$SELLER_ID/items/search?user_product_id=MLAU1234` | lectura/consulta | [User Products](https://developers.mercadolibre.com.co/es_co/user-products) |
| `DELETE` | `/items/$ITEM_ID/address_line_by_reference` | destructivo | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| `DELETE` | `/items/$ITEM_ID/variations/$VARIATION_ID` | destructivo | [Actualiza las variaciones de tu inmueble](https://developers.mercadolibre.com.co/es_co/actualizacion-variacion-inmuebles) |
| `GET` | `/categories/${ID}` | lectura/consulta | [Categorías](https://developers.mercadolibre.com.co/es_co/categorias-inmuebles) |
| `GET` | `/categories/$CATEGORY_ID/attributes` | lectura/consulta | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) |
| `GET` | `/categories/$CATEGORY_ID/classifieds_promotion_packs` | lectura/consulta | [Gestionar paquetes de inmuebles](https://developers.mercadolibre.com.co/es_co/gestionar-paquetes-de-inmuebles) |
| `GET` | `/categories/MLA1459` | lectura/consulta | [Categorías](https://developers.mercadolibre.com.co/es_co/categorias-inmuebles) |
| `GET` | `/categories/MLA1466` | lectura/consulta | [Categorías](https://developers.mercadolibre.com.co/es_co/categorias-inmuebles) |
| `GET` | `/categories/MLA1468` | lectura/consulta | [Categorías](https://developers.mercadolibre.com.co/es_co/categorias-inmuebles) |
| `GET` | `/categories/MLA401685/attributes` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos-inmuebles) |
| `GET` | `/categories/MLA401806` | lectura/consulta | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) |
| `GET` | `/categories/MLA401806/attributes` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones-para-inmuebles) |
| `GET` | `/classified_locations/cities/$CITY_ID` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| `GET` | `/classified_locations/cities/TUxBQ1LNTzc4N2Fm` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| `GET` | `/classified_locations/countries` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| `GET` | `/classified_locations/countries/$COUNTRY_ID` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| `GET` | `/classified_locations/countries/AR` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| `GET` | `/classified_locations/neighborhoods/$NEIGHBORHOOD_ID` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| `GET` | `/classified_locations/neighborhoods/TUxBQlLNTzM2NDg2OA` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| `GET` | `/classified_locations/states/$STATE_ID` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| `GET` | `/classified_locations/states/TUxBUENPUmFkZGIw` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| `GET` | `/items/$ITEM_ID?attributes=variations` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones-para-inmuebles) |
| `GET` | `/items/$ITEM_ID/address_line_by_reference` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| `GET` | `/items/$ITEM_ID/contacts/phone_views?date_from=$DATE_FROM&date_to=$DATE_TO` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| `GET` | `/items/$ITEM_ID/contacts/phone_views/time_window?last=$LAST&unit=$UNIT&ending=$ENDING` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| `GET` | `/items/$ITEM_ID/contacts/questions?date_from=$DATE_FROM&date_to=$DATE_TO` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| `GET` | `/items/$ITEM_ID/contacts/whatsapp?date_from=$DATE_FROM&date_to=$DATE_TO` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| `GET` | `/items/$ITEM_ID/contacts/whatsapp/time_window?unit=$UNIT&last=$LAST&ending=$ENDING` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| `GET` | `/items/$ITEM_ID/description` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos-inmuebles) |
| `GET` | `/items/$ITEM_ID/health` | lectura/consulta | [Calidad de las Publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-las-publicaciones-inmuebles) |
| `GET` | `/items/$ITEM_ID/health/actions` | lectura/consulta | [Calidad de las Publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-las-publicaciones-inmuebles) |
| `GET` | `/items/$ITEM_ID/variations/$Variation_id` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones-para-inmuebles) |
| `GET` | `/items/contacts/phone_views/time_window?ids=$ID1,ID2&last=$LAST&unit=$UNIT&ending=$ENDING_NOTE` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| `GET` | `/items/contacts/whatsapp/time_window?ids=$ID1,$ID2&unit=$UNIT&last=$LAST&ending=$ENDING` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| `GET` | `/items/tags` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| `GET` | `/items/visits?ids=$ITEM_ID&date_from=$DATE_FROM&date_to=$DATE_TO` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| `GET` | `/questions/$QUESTION_ID?api_version=4` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `GET` | `/quotations/$QUOTATION_ID?caller.type=seller` | lectura/consulta | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) |
| `GET` | `/quotations/items_ids?query=$ITEMID&caller.type=seller` | lectura/consulta | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) |
| `GET` | `/quotations/items_ids?query=ItemId1,Itemid2,Itemid3&caller.type=seller` | lectura/consulta | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) |
| `GET` | `/quotations/report?seller.id=$SELLER.ID` | lectura/consulta | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) |
| `GET` | `/sites/$COUNTRY_ID/search?item_location=lat:$LATITUDE1_LATITUDE2,lon:$LONGITUDE1_LONGITUDE2&category=$CATEGORY_ID` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| `GET` | `/sites/$SITE_ID/categories` | lectura/consulta | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) |
| `GET` | `/sites/$SITE_ID/health_levels` | lectura/consulta | [Calidad de las Publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-las-publicaciones-inmuebles) |
| `GET` | `/sites/MLA/categories` | lectura/consulta | [Categorías](https://developers.mercadolibre.com.co/es_co/categorias-inmuebles) |
| `GET` | `/sites/MLA/search?item_location=lat:-37.987148_-30.987148,lon:-57.5483864_-50.5483864&category=MLA1459&limit=1` | lectura/consulta | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| `GET` | `/users/$USER_ID/classifieds_promotion_packs?package_content=$PACKAGE_CONTENT&status=$STATUS` | lectura/consulta | [Gestionar paquetes de inmuebles](https://developers.mercadolibre.com.co/es_co/gestionar-paquetes-de-inmuebles) |
| `GET` | `/users/$USER_ID/classifieds_promotion_packs/$LISTING_TYPE?categoryId=$CATEGORY_ID` | lectura/consulta | [Gestionar paquetes de inmuebles](https://developers.mercadolibre.com.co/es_co/gestionar-paquetes-de-inmuebles) |
| `GET` | `/users/$USER_ID/contacts/phone_views?date_from=$DATE_FROM&date_to=$DATE_TO` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| `GET` | `/users/$USER_ID/contacts/phone_views/time_window?last=$LAST&unit=$UNIT&ending=$ENDING` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| `GET` | `/users/$USER_ID/contacts/questions?date_from=$DATE_FROM&date_to=$DATE_TO` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| `GET` | `/users/$USER_ID/contacts/questions/time_window?last=$LAST&unit=$UNIT&ending=$ENDING` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| `GET` | `/users/$USER_ID/contacts/whatsapp/time_window?unit=$UNIT&last=$LAST&ending=$ENDING` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| `GET` | `/users/$USER_ID/items_visits?date_from=$DATE_FROM&date_to=$DATE_TO` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| `GET` | `/users/$USER_ID/items_visits/time_window?last=$LAST&unit=$UNIT&ending=$ENDING` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| `GET` | `/users/806525693/leads/buyers?scope=test-public` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `GET` | `/vis-transactions-hub/{providerId` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| `GET` | `/vis-transactions-hub/configurations/provider` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| `GET` | `/vis-transactions-hub/configurations/provider/{pro` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| `GET` | `/vis-transactions-hub/configurations/provider/$provider_Id` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| `GET` | `/vis-transactions-hub/configurations/seller/$seller_Id` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| `GET` | `/vis/users/$USER_ID/leads/buyers` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `GET` | `/vis/users/$USER_ID/leads/buyers?contact_types=question` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `GET` | `/vis/users/$USER_ID/leads/buyers?contact_types=whatsapp` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `GET` | `/vis/users/$USER_ID/leads/buyers?item_id=MLX1234` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `GET` | `/vis/users/$USER_ID/leads/buyers?offset=$OFFSET&limit=$LIMIT&date_from=$DATE_FROM&date_to=$DATE_TO&contact_types=$CONTACT_TYPES&item_id=$ITEM_ID&buyer_ids=$BUYER_IDS` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `GET` | `/vis/users/3052668868/leads/buyers?offset=0&limit=10&date_from=2026-01-15&date_to=2026-01-22&contact_types=credit,question,whatsapp&include_guest=true` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `GET` | `/visits/items?ids=$ITEM_ID` | lectura/consulta | [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) |
| `POST` | `/items/$ITEM_ID/variations/$VARIATION_ID` | mutación | [Actualiza las variaciones de tu inmueble](https://developers.mercadolibre.com.co/es_co/actualizacion-variacion-inmuebles) |
| `POST` | `/items/MLA658778048/variations` | mutación | [Actualiza las variaciones de tu inmueble](https://developers.mercadolibre.com.co/es_co/actualizacion-variacion-inmuebles) |
| `POST` | `/items/MLC2913388294` | mutación | [Actualiza las variaciones de tu inmueble](https://developers.mercadolibre.com.co/es_co/actualizacion-variacion-inmuebles) |
| `PUT` | `/items/$ITEM_ID/address_line_by_reference` | mutación | [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) |
| `PUT` | `/items/MLC2913388294` | mutación | [Actualiza las variaciones de tu inmueble](https://developers.mercadolibre.com.co/es_co/actualizacion-variacion-inmuebles) |
| `PUT` | `/quotations/$QUOTATION_ID?caller.type=seller` | mutación | [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) |
| `UNKNOWN` | `/categories/MLA401806` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones-para-inmuebles) |
| `UNKNOWN` | `/categories/MLA401806/attributes` | lectura/consulta | [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones-para-inmuebles) |
| `UNKNOWN` | `/items/$ITEM_ID/description` | lectura/consulta | [Atributos](https://developers.mercadolibre.com.co/es_co/atributos-inmuebles) |
| `UNKNOWN` | `/items/tags` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| `UNKNOWN` | `/users/$USER_ID/classifieds_promotion_packs?package_content=$PACKAGE_CONTENT&status=$STATUS` | lectura/consulta | [Gestionar paquetes de inmuebles](https://developers.mercadolibre.com.co/es_co/gestionar-paquetes-de-inmuebles) |
| `UNKNOWN` | `/users/806525693/leads/buyers?scope=test-public` | lectura/consulta | [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) |
| `UNKNOWN` | `/vis-transactions-hub/{providerId` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| `UNKNOWN` | `/vis-transactions-hub/configurations/provider` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| `UNKNOWN` | `/vis-transactions-hub/configurations/provider/{pro` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| `UNKNOWN` | `/vis-transactions-hub/configurations/provider/$provider_Id` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| `UNKNOWN` | `/vis-transactions-hub/configurations/seller/$seller_Id` | lectura/consulta | [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) |
| `GET` | `/claims/$CLAIM_ID` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| `GET` | `/claims/$CLAIM_ID/detail` | lectura/consulta | [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) |
| `GET` | `/claims/$CLAIM_ID/returns` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| `GET` | `/claims/$CLAIM_ID/returns/attachments` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |
| `GET` | `/claims/$CLAIMS` | lectura/consulta | [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) |

_El índice JSON contiene 1440 endpoints/rutas adicionales no listados en esta tabla para mantener legible el Markdown._

## Autenticación y aplicaciones

Cobertura: 12 páginas, 52 endpoints/rutas detectadas. Integraciones detectadas en contenido: Catálogo, Facturación, Mensajería, Mercado Ads, Mercado Envíos, Mercado Pago, Notificaciones, OAuth.

### Páginas fuente

- [Autenticación segura](https://developers.mercadolibre.com.co/es_co/autenticacion-segura) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Autenticación segura; Política de contraseñas seguras; Ejemplo de contraseñas:; Almacenamiento de contraseñas; Flujo de recordar contraseña; ¿Cómo verificar?; Autenticación multifactor (MFA); Acceso administrativo y gestión de cuentas:
- [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) — estado: `ok`; endpoints: 7; fechas visibles: —; secciones: Autenticación y Autorización; Enviar access token por header; Autenticación; Autorización; ¿Cómo logramos la autorización?; Server side; Paso a paso:; 1. Realizando la autorización
- [Bloqueo de aplicaciones](https://developers.mercadolibre.com.co/es_co/bloqueo-de-aplicaciones) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Bloqueo de aplicaciones; ¿Por qué se ha bloqueado mi aplicación?; Motivo de Bloqueo; ¿Cómo afecta el bloqueo de mi aplicación a mi negocio?; ¿Afectará el bloqueo de mi aplicación a mis vendedores?; ¿Cómo puedo desbloquear mi aplicación?; Acciones a seguir
- [Crea una aplicación en Mercado Libre](https://developers.mercadolibre.com.co/es_co/crea-una-aplicacion-en-mercado-libre-es) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Crea una aplicación en Mercado Libre; Información básica de la aplicación; Gestionar mis aplicaciones; Configurar; Configuración de la aplicación; Consideraciones sobre scopes; Aplicaciones de solo lectura; Aplicaciones online de lectura/escritura
- [Error 403](https://developers.mercadolibre.com.co/es_co/error-403) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Error 403; Validaciones
- [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) — estado: `ok`; endpoints: 4; fechas visibles: 2026-01-15; secciones: Gestión de Identidades y Accesos; OAuth y gestión de Tokens; Credenciales de la aplicación; Protección de tokens y credenciales; Almacenamiento seguro; En la base de datos; En el código; En los logs
- [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) — estado: `ok`; endpoints: 8; fechas visibles: 2025-08-01, 2025-08-20; secciones: Gestiona tus aplicaciones; Detalles de las aplicaciones; Datos privados de tu aplicación; Aplicaciones autorizadas por usuario; Usuarios que le dieron permisos a tu aplicación; Descripción de los campos; Consideraciones; Revoca la autorización del usuario
- [Gestionar IPs de una aplicación](https://developers.mercadolibre.com.co/es_co/gestionar-ips-de-una-aplicacion) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Gestionar IPs de una aplicación; Configuración IP; Listado de rangos; Agregar nuevas IPs; Consideraciones; Agregando la IP de forma individual; Carga masiva de IPs; Consideraciones
- [Obtención del Access Token](https://developers.mercadolibre.com.co/es_co/obtencion-del-access-token) — estado: `ok`; endpoints: 3; fechas visibles: —; secciones: Obtención del Access Token; ¿Qué es un Access Token y para qué sirve?; 1. Preparación de Credenciales; 2. Diagrama de Autenticación; 3. Obtención del Código de Autorización; 4. Obtener el Access Token; Problemas Comunes; Conclusión
- [Permisos funcionales](https://developers.mercadolibre.com.co/es_co/permisos-funcionales) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Permisos funcionales; Scopes; Usuarios (default); Recursos relacionados; Publicación y sincronización; Recursos relacionados; Comunicación pre y postventa; Recursos relacionados
- [Seguridad de aplicaciones](https://developers.mercadolibre.com.co/es_co/seguridad-apps) — estado: `ok`; endpoints: 2; fechas visibles: 2026-01-15; secciones: Seguridad de aplicaciones; Protección de datos personales; Principios de protección de datos; Datos sensibles de Mercado Libre:; Prácticas requeridas:; En base de datos:; En logs:; Referencias
- [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) — estado: `ok`; endpoints: 29; fechas visibles: —; secciones: Usuarios y Aplicaciones; Devuelve información del usuario.; Respuesta; Información actualizada de los usuarios.; Devuelve información sobre la autenticación del usuario.; Respuesta; Devuelve la dirección del usuario; Respuesta

### Rutas del módulo

- `DELETE /applications/{app_id}`
- `DELETE /users/{cust_Id`
- `DELETE /users/$USER_ID/applications/$APP_ID`
- `GET /applications/{app_id}`
- `GET /applications/$APP_ID`
- `GET /applications/$APP_ID/grants`
- `GET /applications/$APPLICATION_ID`
- `GET /applications/12345`
- `GET /applications/3022782903258037`
- `GET /applications/v1/$APP_ID/consumed-applications?date_start=2025-08-01&date_end=2025-08-20`
- `GET /missed_feeds?app_id=$APP_ID`
- `GET /orders`
- `GET /orders?access_token=APP-1234567890`
- `GET /orders/123`
- `GET /users/{cust_Id`
- `GET /users/{User_id`
- `GET /users/{User_id}/classifieds_promotion_packs`
- `GET /users/$USER_ID`
- `GET /users/$USER_ID/accepted_payment_methods`
- `GET /users/$USER_ID/addresses`
- `GET /users/$USER_ID/applications`
- `GET /users/$USER_ID/applications/$APP_ID`
- `GET /users/$USER_ID/applications/$APPLICATION_ID`
- `GET /users/$USER_ID/available_listing_type/$LISTING_TYPE_ID?category_id=$CATEGORY_ID`
- `GET /users/$USER_ID/available_listing_types?category_id=$CATEGORY_ID`
- `GET /users/$USER_ID/brands`
- `GET /users/$USER_ID/classifieds_promotion_packs`
- `GET /users/$USER_ID/classifieds_promotion_packs/$LISTING_TYPE&categoryId=$CATEGORY_ID`
- `GET /users/12345678/brands`
- `GET /users/123456789`
- `GET /users/135146148/classifieds_promotion_packs`
- `GET /users/206946886/accepted_payment_methods`
- `GET /users/206946886/addresses`
- `GET /users/206946886/available_listing_type/gold_special?category_id=MLA6602`
- `GET /users/206946886/available_listing_types`
- `GET /users/206946886/classifieds_promotion_packs/silver?categoryId=MLA1459`
- `GET /users/26317316/applications`
- `GET /users/me`
- `POST /oauth/token`
- `POST /users/me`
- `POST auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=$REDIRECT_URL`
- `POST auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=$YOUR_URL&code_challenge=$CODE_CHALLENGE&code_challenge_method=$CODE_METHOD`
- `POST auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=https:/mercadolibre.com.ar`
- `PUT /users/123456789`
- `UNKNOWN /orders`
- `UNKNOWN /orders?access_token=APP-1234567890`
- `UNKNOWN /orders/123`
- `UNKNOWN /users/{User_id}/classifieds_promotion_packs`
- `UNKNOWN /users/me`
- `UNKNOWN auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=$REDIRECT_URL`
- `UNKNOWN auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=$YOUR_URL&code_challenge=$CODE_CHALLENGE&code_challenge_method=$CODE_METHOD`
- `UNKNOWN auth:/authorization?response_type=code&client_id=$APP_ID&redirect_uri=https:/mercadolibre.com.ar`

## FAQs, límites y soporte

Cobertura: 2 páginas, 1 endpoints/rutas detectadas. Integraciones detectadas en contenido: MCP, OAuth.

### Páginas fuente

- [MCP Server de Mercado Libre](https://developers.mercadolibre.com.co/es_co/mcp-server) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: MCP Server de Mercado Libre; Requisitos previos; Autenticación; Instalación y configuración; 3.1 Cursor; 3.2 Windsurf; 3.3 Otros IDEs; Conexión al MCP Server
- [Rate limit / Error 429 y pedidos de aumento de RL](https://developers.mercadolibre.com.co/es_co/rate-limit-error-429) — estado: `ok`; endpoints: 1; fechas visibles: —; secciones: Rate limit / Error 429 y pedidos de aumento de RL

### Rutas del módulo

- `UNKNOWN /visits`

## General

Cobertura: 17 páginas, 118 endpoints/rutas detectadas. Integraciones detectadas en contenido: Catálogo, Facturación, Mensajería, Mercado Envíos, Mercado Pago, Notificaciones, OAuth.

### Páginas fuente

- [¿Qué es Brand Protection Program?](https://developers.mercadolibre.com.co/es_co/que-es-brand-protection-program) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: ¿Qué es Brand Protection Program?; Titulares de derechos y vendedores; Flujo de denuncia
- [Buenas prácticas para uso de la plataforma](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-uso-de-la-plataforma) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Buenas prácticas para uso de la plataforma; Moderaciones o suspensión de cuentas por:; Envío de mensajes automáticos; Modificación en template de etiquetas; Clonación de publicación; Uso de las funcionalidades para los tipos de productos; Web Crawler:
- [Configuración o requisitos previos](https://developers.mercadolibre.com.co/es_co/configuracion-o-requisitos-previos) — estado: `ok`; endpoints: 1; fechas visibles: —; secciones: Configuración o requisitos previos; 1. Crea tu cuenta de MercadoLibre; 2. Configura tu aplicación y obtén tus credenciales; Sigue estos pasos:; 3. Consigue tu Access Token: La llave para acceder a la API; 4. Realiza tu primera petición a la API; Sigue este paso:; ¿Qué esperar?
- [Consideraciones de diseño](https://developers.mercadolibre.com.co/es_co/consideraciones-de-diseno) — estado: `ok`; endpoints: 7; fechas visibles: —; secciones: Consideraciones de diseño; Formato JSON; Uso de JSONP; Manejo de errores; Reducción de respuestas; Uso de OPCIONES; Paginación de resultados; Valores por defecto
- [Contratación de paquetes de publicación](https://developers.mercadolibre.com.co/es_co/contratacion-de-paquetes-de-publicacion) — estado: `ok`; endpoints: 0; fechas visibles: 05/11/2025; secciones: Contratación de paquetes de publicación; Contratación de paquetes de destaques; Siguientes Pasos; Lecturas Recomendadas; Actualizaciones de versión; Historial de cambios
- [Developer Partner Program](https://developers.mercadolibre.com.co/es_co/developer-partner-program) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Developer Partner Program; Índice; 1. Cómo funciona el Programa; 2. Requisitos para postularse al programa; 3. Aplicaciones certificadas; 3.1 Mantenimiento de medallas; 3.2 Proceso de asignación de iniciativas; 3.3 Cambios en la medalla asignada
- [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) — estado: `ok`; endpoints: 6; fechas visibles: 2022-09-26, 2022-09-27, 2022-10-17; secciones: Envíos en feriados opcionales; Listar días no laborables; Actualizar día no laboral; Buscar por día no laboral
- [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) — estado: `ok`; endpoints: 9; fechas visibles: 2023-09-01; secciones: Gestionar Moderaciones; Consultar moderaciones; Flujo recomendado; Campos de la respuesta; Filtrar items moderados de un usuario; Status, substatus y tags de moderaciones; Histórico de moderaciones; Flujo recomendado
- [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) — estado: `ok`; endpoints: 10; fechas visibles: —; secciones: Miembros del Programa; Consultar motivos habilitados; Realizar denuncia; Derecho de autor para imágenes; Consultar estado de denuncia; Responder vendedor
- [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) — estado: `ok`; endpoints: 14; fechas visibles: 2022-10-25, 2024-10-07, 2025-05-01; secciones: Moderaciones con pausado; Consultar moderaciones con item pausados; Moderación por cambio inusual de precio; Moderación de ítem sin ventas; Carga de imágenes por URL; Casos en los que el ítem será moderado; Moderación de inmueble no disponible; Obtener información sobre este tipo de moderación
- [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) — estado: `ok`; endpoints: 12; fechas visibles: 20 de enero de 2025; secciones: Motivos para comunicarse; Consultar motivos disponibles de comunicación; Templates disponibles según el país; Campos de la respuesta; Consultar cantidad de mensajes disponibles a enviar; Enviar mensaje según opción; Campos de la respuesta; Ejemplos de mensajes de error
- [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) — estado: `ok`; endpoints: 23; fechas visibles: —; secciones: Precio por variación; Activación de sellers; Estructura de ítems; Lógica de atributos para Família y User Products; Publicar un ítem; Modificación de items; Editor de familia; Consideraciones
- [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) — estado: `ok`; endpoints: 13; fechas visibles: —; secciones: Primeros pasos; Definir dominio de moda para publicar; Dominios disponibles para guía de talles; Consultar ficha técnica del dominio; Consulta ficha técnica de la guía de talles; Búsqueda de guía de talles; Si en el body del POST se envía:; Dominios de Marca y Estándar
- [Realiza pruebas](https://developers.mercadolibre.com.co/es_co/realiza-pruebas) — estado: `ok`; endpoints: 3; fechas visibles: —; secciones: Realiza pruebas; Enviar access token por header; Crea un usuario de test; Consideraciones; Compra y vende entre usuarios de test
- [Solicitud de visita](https://developers.mercadolibre.com.co/es_co/solicitud-de-visita) — estado: `ok`; endpoints: 1; fechas visibles: 06/11/2025; secciones: Solicitud de visita; Trabajando con Solicitudes de Visita; Actualizaciones en Tiempo Real; Pasos para iniciar la integración; 1. La cuenta del vendedor profesional o inmobiliaria debe estar registrada; 2. Registrar la aplicación para la obtención del token; 3. Publicar ítems en la plataforma.; Activar Solicitud de Visita en Publicaciones
- [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) — estado: `ok`; endpoints: 24; fechas visibles: —; secciones: Tipos de publicación; Ahora tus publicaciones se diferencian por las cuotas que agregues; Tipos de publicación por site; Especificación del tipo de publicación; Tipos de publicación disponibles; Exposiciones de las publicaciones; Transacciones disponibles para una publicación; Upgrades disponibles para una publicación
- [User Products](https://developers.mercadolibre.com.co/es_co/user-products) — estado: `ok`; endpoints: 8; fechas visibles: —; secciones: User Products; Conceptos importantes; FAQs; Precio por variación; UPtin; Stock distribuido y multiorigen

### Rutas del módulo

- `GET /catalog/charts/$SITE_ID/configurations/active_domains`
- `GET /catalog/charts/domains/search`
- `GET /catalog/charts/search`
- `GET /catalog/charts/search?offset=1&limit=100`
- `GET /currencies`
- `GET /currencies?attributes=id`
- `GET /currencies/ARS`
- `GET /currencies/ARS?callback=foo`
- `GET /domains/$DOMAIN_ID/technical_specs`
- `GET /domains/$DOMAIN_ID/technical_specs?section=grids`
- `GET /domains/MLA-SNEAKERS/technical_specs`
- `GET /domains/MLA-SNEAKERS/technical_specs?section=grids`
- `GET /items`
- `GET /items/$ITEM_ID`
- `GET /items/$ITEM_ID/available_downgrades`
- `GET /items/$ITEM_ID/available_listing_types`
- `GET /items/$ITEM_ID/available_upgrades`
- `GET /items/$ITEM_ORIGINAL/migration_live_listing?`
- `GET /items/$ITEM_ORIGINAL/user_product_listings/validate`
- `GET /items/$TIEM_ID?attributes=stop_time`
- `GET /items/$TIEM_ID/listing_type`
- `GET /items/MLA123456/migration_live_listing?`
- `GET /items/MLA12345678/user_product_listings/validate`
- `GET /items/MLA1389403099?attributes=stop_time`
- `GET /items/MLA1389403099/available_downgrades`
- `GET /items/MLA1389403099/available_listing_types`
- `GET /items/MLA1389403099/available_upgrades`
- `GET /items/MLA1389403099/listing_type`
- `GET /items/MLC1234567890`
- `GET /messages`
- `GET /messages/action_guide/packs/$PACK_ID?tag=post_sale`
- `GET /messages/action_guide/packs/$PACK_ID/caps_available?tag=post_sale`
- `GET /messages/action_guide/packs/$PACK_ID/option?tag=post_sale`
- `GET /messages/action_guide/packs/20000000000?tag=post_sale`
- `GET /messages/action_guide/packs/200000000000/caps_available?tag=post_sale`
- `GET /messages/action_guide/packs/2000000000000000/option`
- `GET /messages/action_guide/packs/2000000000000000/option?tag=post_sale`
- `GET /messages/action_guide/packs/2000000000000012?tag=post_sale`
- `GET /moderations/infractions/$USER_ID?date_created_since=YYYY-MM-DD&limit=2`
- `GET /moderations/infractions/288230000?date_created_since=2023-09-01&limit=2`
- `GET /moderations/last_moderation`
- `GET /moderations/last_moderation/$ITEM_ID-ITM`
- `GET /moderations/last_moderation/$MODERATION_REFERENCE_ID`
- `GET /moderations/last_moderation/MLA123444123-ITM`
- `GET /moderations/last_moderation/MLA926647862-ITM`
- `GET /moderations/pppi/case/$DENOUNCE_ID`
- `GET /moderations/pppi/case/123`
- `GET /moderations/pppi/denounces/$SITE_ID/ITM/options`
- `GET /moderations/pppi/denounces/items/$ITEM_ID`
- `GET /moderations/pppi/denounces/items/MLA123`
- `GET /moderations/pppi/denounces/MLA/ITM/options`
- `GET /shipping/seller/$SELLER_ID/working_day_middleend`
- `GET /shipping/seller/$SELLER_ID/working_day_middleend/optout`
- `GET /shipping/seller/$SELLER_ID/working_day_middleend/optout?date=2022-10-17`
- `GET /shipping/seller/$SELLER_ID/working_day_middleend/optout?date=AAAA-MM-DD`
- `GET /shipping/seller/12345678/working_day_middleend`
- `GET /sites/$SITE_ID/listing_exposures`
- `GET /sites/$SITE_ID/listing_exposures/$EXPOSURE_LEVEL`
- `GET /sites/$SITE_ID/listing_types`
- `GET /sites/$SITE_ID/listing_types/$LISTING_TYPE_ID`
- `GET /sites/$SITE_ID/user-products-families/$FAMILY_ID`
- `GET /sites/MLA/listing_exposures`
- `GET /sites/MLA/listing_exposures/high`
- `GET /sites/MLA/listing_types`
- `GET /sites/MLA/listing_types/gold_special`
- `GET /sites/MLA/search?q=ipod`
- `GET /sites/MLA/user-products-families/9871232123`
- `GET /sites/MLM/items/user_product_listings`
- `GET /user-products-families/{family_id`
- `GET /user-products-families/tasks/{task_id`
- `GET /user-products/$USER_PRODUCT_ID`
- `GET /user-products/$USER_PRODUCT_ID/items`
- `GET /user-products/MLBU22012`
- `GET /user-products/MLMU3691277914/items`
- `GET /users/$SELLER_ID/items/search?user_product_id=$USER_PRODUCT_ID`
- `GET /users/$SELLER_ID/items/search?user_product_id=MLAU1234,MLAU12345`
- `GET /users/$USER_ID/available_listing_type/free?category_id=$CATEGORY_ID`
- `GET /users/$USER_ID/items/search?status=pending`
- `GET /users/0123456789/items/search?tags=moderation_penalty&status=paused`
- `GET /users/1234/available_listing_types?category_id=MLA1055`
- `GET /users/1234/items/search?user_product_id=MLBU206642488`
- `GET /users/123456/items/search?status=pending`
- `GET /vis/leads/$LEAD_ID`
- `POST /catalog/charts/domains/search`
- `POST /catalog/charts/search`
- `POST /catalog/charts/search?offset=1&limit=100`
- `POST /domains/$DOMAIN_ID/technical_specs?section=grids`
- `POST /domains/MLA-SNEAKERS/technical_specs?section=grids`
- `POST /items`
- `POST /items/$TIEM_ID/listing_type`
- `POST /items/MLA1389403099/listing_type`
- `POST /messages/action_guide/packs/$PACK_ID/option?tag=post_sale`
- `POST /messages/action_guide/packs/2000000000000000/option`
- `POST /messages/action_guide/packs/2000000000000000/option?tag=post_sale`
- `POST /moderations/pppi/case/$DENOUNCE_ID`
- `POST /moderations/pppi/denounces/items/$ITEM_ID`
- `POST /moderations/pppi/denounces/items/MLA123`
- `POST /sites/MLM/items/user_product_listings`
- `POST /user-products-families/{family_id`
- `POST /user-products/$USER_PRODUCT_ID/items`
- `POST /user-products/MLMU3691277914/items`
- `POST /users/test_user`
- `PUT /items/$ITEM_ID`
- `PUT /shipping/seller/$SELLER_ID/working_day_middleend`
- `UNKNOWN /categories`
- `UNKNOWN /currencies`
- `UNKNOWN /items`
- `UNKNOWN /items/MLC1234567890`
- `UNKNOWN /moderations/last_moderation/$ITEM_ID-ITM`
- `UNKNOWN /moderations/last_moderation/$MODERATION_REFERENCE_ID`
- `UNKNOWN /moderations/last_moderation/MLA123444123-ITM`
- `UNKNOWN /moderations/last_moderation/MLA926647862-ITM`
- `UNKNOWN /sites/$SITE_ID/user-products-families/$FAMILY_ID`
- `UNKNOWN /sites/MLA/search?q=ipod`
- `UNKNOWN /user-products/$USER_PRODUCT_ID`
- `UNKNOWN /users`
- `UNKNOWN /users/$SELLER_ID/items/search?user_product_id=$USER_PRODUCT_ID`
- `UNKNOWN /users/$SELLER_ID/items/search?user_product_id=MLAU1234`

## Inmuebles

Cobertura: 20 páginas, 84 endpoints/rutas detectadas. Integraciones detectadas en contenido: Catálogo, Mercado Envíos, Notificaciones, OAuth.

### Páginas fuente

- [Actualiza las variaciones de tu inmueble](https://developers.mercadolibre.com.co/es_co/actualizacion-variacion-inmuebles) — estado: `ok`; endpoints: 6; fechas visibles: 06/11/2025; secciones: Actualiza las variaciones de tu inmueble; Agregar nuevas variaciones; Modifica variaciones; Parámetros; Elimina una variación; Parámetros; Lecturas recomendadas; Actualizaciones de versión
- [Atributos](https://developers.mercadolibre.com.co/es_co/atributos-inmuebles) — estado: `ok`; endpoints: 4; fechas visibles: 20 de enero de 2026, 05/11/2025; secciones: Atributos; Attributes API: descubre los atributos clave que necesitas para publicar; Uso del endpoint /attributes; Atributos Importantes a tener en Cuenta; Atributos adicionales; 1. Título de la publicación; 2. Descripción del Inmueble; 3. Ubicación del inmueble
- [Calidad de las Publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-las-publicaciones-inmuebles) — estado: `ok`; endpoints: 3; fechas visibles: 06/11/2025; secciones: Calidad de las Publicaciones; Niveles de Calidad por País; Parámetros de la solicitud; Campos de la respuesta; Niveles de Calidad por Ítem; Parámetros de la solicitud; Campos de la respuesta; Acciones para mejorar la calidad de una publicación
- [Categorías](https://developers.mercadolibre.com.co/es_co/categorias-inmuebles) — estado: `ok`; endpoints: 5; fechas visibles: 06/11/2025; secciones: Categorías; Categories API: descubre el tipo de propiedad y operación que necesitas para publicar; 1. Identifica la categoría de inmuebles por país; 2. Identifica el tipo de propiedad a publicar; 3. Identifica el tipo de operación a publicar; 4. Identifica si el inmueble es nuevo o usado (subtipo de operación); 5. Selección de categoría; Siguientes Pasos
- [Categorías y Atributos](https://developers.mercadolibre.com.co/es_co/categorias-atributos-inmuebles) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Categorías y Atributos; Siguientes Pasos:
- [Ciclo de vida de las publicaciones de Inmuebles](https://developers.mercadolibre.com.co/es_co/ciclo-de-vida-de-las-publicaciones-de-inmuebles) — estado: `ok`; endpoints: 0; fechas visibles: 1 de enero de 2022, 28 de septiembre de 2022, 06/11/2025; secciones: Ciclo de vida de las publicaciones de Inmuebles; Duración máxima y condiciones de publicación; Parámetros de tiempo del ciclo de vida; Comportamiento del ciclo de vida; Estados del ciclo de vida; Lecturas recomendadas; Actualizaciones de versión
- [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) — estado: `ok`; endpoints: 11; fechas visibles: 31 de julio de 2026, 06/11/2025; secciones: Desarrollos Inmobiliarios; ¿Cómo se publica un Desarrollo Inmobiliario?; Categorías de un Desarrollo Inmobiliario; Parámetros; Fotografías de una publicación para un desarrollo inmobiliario; Ejemplo de la categoría de desarrollo inmobiliario para Argentina específicamente “Departamentos”; Publicación de desarrollos inmobiliarios; Publicación
- [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) — estado: `ok`; endpoints: 16; fechas visibles: 2021-01-01, 2021-02-01, 06/11/2025; secciones: Estadísticas de interacciones en Inmuebles; Tipo de interacciones; Visitas; Visitas por vendedor - total de visitas entre rangos de fechas; Parámetros; Cantidad de Visitas Recientes por Usuario; Parámetros; Visitas por Publicación
- [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) — estado: `ok`; endpoints: 15; fechas visibles: 2022-03-30; secciones: Experiencia para inmuebles; Objetivos; Pasos para iniciar la integración; Actualizaciones en Tiempo Real; Configuraciones; Crear configuración; Actualizar configuración; Obtención de configuración
- [Gestionar paquetes de inmuebles](https://developers.mercadolibre.com.co/es_co/gestionar-paquetes-de-inmuebles) — estado: `ok`; endpoints: 5; fechas visibles: 20 de enero de 2026, 05/11/2025; secciones: Gestionar paquetes de inmuebles; Tipos de Paquetes; Cupos y límite de publicaciones; Consideraciones Adicionales sobre Paquetes:; Consejos adicionales.; 1. Consulta qué paquetes de publicación están disponibles para contratar:; Estructura de Respuesta Esperada:; 2. Contrata paquetes de publicación
- [Glosario](https://developers.mercadolibre.com.co/es_co/glosario-inmuebles) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Glosario
- [Guía de Integración de Inmuebles](https://developers.mercadolibre.com.co/es_co/introduccion-guia-de-inmuebles) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Guía de Integración de Inmuebles; ¿Qué puedes hacer con esta API?; Casos de Uso; Primeros Pasos; Documentación Completa; Soporte y Comunidad; ¡Comienza ahora!
- [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) — estado: `ok`; endpoints: 10; fechas visibles: 2025-04-18, 2025-06-18, 2026-01-15, 2026-01-22, 2024-05-14, 2024-05-24, 06/11/2025, 26/01/2026; secciones: Leads; Consultar interesados en los ítems del vendedor; Parámetros de la consulta; Descripción de la respuesta; Ejemplo de llamada con el parámetro opcional include_guest=true; Campos adicionales al utilizar el parámetro include_guest=true; Consultar Leads; Consultar todos los leads de un usuario
- [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) — estado: `ok`; endpoints: 14; fechas visibles: 05/11/2025; secciones: Localizar Inmuebles; Introducción: Potenciando la búsqueda y publicación de inmuebles con nuestra API; Explorar Países; Estructura de respuesta esperada; Explorar información del País; Estructura de respuesta esperada; Manejo de Errores; Explorar información de Estados
- [Paquetes y permisos para proyectos, desarrollos o emprendimientos inmobiliarios](https://developers.mercadolibre.com.co/es_co/paquetes-y-permisos-para-proyectos-desarrollos-o-emprendimientos-inmobiliarios) — estado: `ok`; endpoints: 0; fechas visibles: 06/11/2025; secciones: Paquetes y permisos para proyectos, desarrollos o emprendimientos inmobiliarios; Siguientes Pasos:; Lecturas Recomendadas; Actualizaciones de versión; Historial de cambios
- [Pasos Rápidos para Publicar un Inmueble de Prueba](https://developers.mercadolibre.com.co/es_co/pasos-rapidos-para-publicar-un-inmueble-de-prueba) — estado: `ok`; endpoints: 5; fechas visibles: —; secciones: Pasos Rápidos para Publicar un Inmueble de Prueba; 1. Crea un usuario de prueba: experimenta sin costo; 2.Configura tu usuario de prueba como inmobiliaria; 3.Contrata un paquete de publicaciones (promotion pack); 4. Obtener access token del usuario de test; 5.Publica tu inmueble; Parámetros de publicación de inmuebles; 6.Verificar el estado de la publicación
- [Primeros pasos: Publicación de Inmuebles en la API de MercadoLibre](https://developers.mercadolibre.com.co/es_co/primeros-pasos-inmuebles) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Primeros pasos: Publicación de Inmuebles en la API de MercadoLibre; Diagrama de pasos esenciales; Próximo paso
- [Publica Inmuebles](https://developers.mercadolibre.com.co/es_co/publica-inmueble) — estado: `ok`; endpoints: 1; fechas visibles: 23 de febrero de 2026, 06/11/2025; secciones: Publica Inmuebles; Preparación para la Publicación; Publica tu inmueble; Parámetros de respuesta; Publica inmuebles en Portal Inmobiliario (Chile); Lecturas recomendadas; Actualizaciones de versión; Historial de cambios
- [Publicaciones de tiendas oficiales para inmuebles](https://developers.mercadolibre.com.co/es_co/publicaciones-de-tiendas-oficiales-para-inmuebles) — estado: `ok`; endpoints: 1; fechas visibles: 06/11/2025; secciones: Publicaciones de tiendas oficiales para inmuebles; Verificación de tienda oficial del vendedor; Inclusión del official_store_id; Errores comunes al publicar en Tiendas Oficiales; Lecturas recomendadas; Actualizaciones de versión; Historial de cambios
- [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones-para-inmuebles) — estado: `ok`; endpoints: 6; fechas visibles: 06/11/2025; secciones: Variaciones; Publica inmuebles con variaciones; Errores comunes al publicar con variaciones.; Consulta tu publicación con variaciones; Lecturas recomendadas; Actualizaciones de versión

### Rutas del módulo

- `DELETE /items/$ITEM_ID/address_line_by_reference`
- `DELETE /items/$ITEM_ID/variations/$VARIATION_ID`
- `GET /categories/${ID}`
- `GET /categories/$CATEGORY_ID/attributes`
- `GET /categories/$CATEGORY_ID/classifieds_promotion_packs`
- `GET /categories/MLA1459`
- `GET /categories/MLA1466`
- `GET /categories/MLA1468`
- `GET /categories/MLA401685/attributes`
- `GET /categories/MLA401806`
- `GET /categories/MLA401806/attributes`
- `GET /classified_locations/cities/$CITY_ID`
- `GET /classified_locations/cities/TUxBQ1LNTzc4N2Fm`
- `GET /classified_locations/countries`
- `GET /classified_locations/countries/$COUNTRY_ID`
- `GET /classified_locations/countries/AR`
- `GET /classified_locations/neighborhoods/$NEIGHBORHOOD_ID`
- `GET /classified_locations/neighborhoods/TUxBQlLNTzM2NDg2OA`
- `GET /classified_locations/states/$STATE_ID`
- `GET /classified_locations/states/TUxBUENPUmFkZGIw`
- `GET /items/$ITEM_ID?attributes=variations`
- `GET /items/$ITEM_ID/address_line_by_reference`
- `GET /items/$ITEM_ID/contacts/phone_views?date_from=$DATE_FROM&date_to=$DATE_TO`
- `GET /items/$ITEM_ID/contacts/phone_views/time_window?last=$LAST&unit=$UNIT&ending=$ENDING`
- `GET /items/$ITEM_ID/contacts/questions?date_from=$DATE_FROM&date_to=$DATE_TO`
- `GET /items/$ITEM_ID/contacts/whatsapp?date_from=$DATE_FROM&date_to=$DATE_TO`
- `GET /items/$ITEM_ID/contacts/whatsapp/time_window?unit=$UNIT&last=$LAST&ending=$ENDING`
- `GET /items/$ITEM_ID/description`
- `GET /items/$ITEM_ID/health`
- `GET /items/$ITEM_ID/health/actions`
- `GET /items/$ITEM_ID/variations/$Variation_id`
- `GET /items/contacts/phone_views/time_window?ids=$ID1,ID2&last=$LAST&unit=$UNIT&ending=$ENDING_NOTE`
- `GET /items/contacts/whatsapp/time_window?ids=$ID1,$ID2&unit=$UNIT&last=$LAST&ending=$ENDING`
- `GET /items/tags`
- `GET /items/visits?ids=$ITEM_ID&date_from=$DATE_FROM&date_to=$DATE_TO`
- `GET /questions/$QUESTION_ID?api_version=4`
- `GET /quotations/$QUOTATION_ID?caller.type=seller`
- `GET /quotations/items_ids?query=$ITEMID&caller.type=seller`
- `GET /quotations/items_ids?query=ItemId1,Itemid2,Itemid3&caller.type=seller`
- `GET /quotations/report?seller.id=$SELLER.ID`
- `GET /sites/$COUNTRY_ID/search?item_location=lat:$LATITUDE1_LATITUDE2,lon:$LONGITUDE1_LONGITUDE2&category=$CATEGORY_ID`
- `GET /sites/$SITE_ID/categories`
- `GET /sites/$SITE_ID/health_levels`
- `GET /sites/MLA/categories`
- `GET /sites/MLA/search?item_location=lat:-37.987148_-30.987148,lon:-57.5483864_-50.5483864&category=MLA1459&limit=1`
- `GET /users/$USER_ID/classifieds_promotion_packs?package_content=$PACKAGE_CONTENT&status=$STATUS`
- `GET /users/$USER_ID/classifieds_promotion_packs/$LISTING_TYPE?categoryId=$CATEGORY_ID`
- `GET /users/$USER_ID/contacts/phone_views?date_from=$DATE_FROM&date_to=$DATE_TO`
- `GET /users/$USER_ID/contacts/phone_views/time_window?last=$LAST&unit=$UNIT&ending=$ENDING`
- `GET /users/$USER_ID/contacts/questions?date_from=$DATE_FROM&date_to=$DATE_TO`
- `GET /users/$USER_ID/contacts/questions/time_window?last=$LAST&unit=$UNIT&ending=$ENDING`
- `GET /users/$USER_ID/contacts/whatsapp/time_window?unit=$UNIT&last=$LAST&ending=$ENDING`
- `GET /users/$USER_ID/items_visits?date_from=$DATE_FROM&date_to=$DATE_TO`
- `GET /users/$USER_ID/items_visits/time_window?last=$LAST&unit=$UNIT&ending=$ENDING`
- `GET /users/806525693/leads/buyers?scope=test-public`
- `GET /vis-transactions-hub/{providerId`
- `GET /vis-transactions-hub/configurations/provider`
- `GET /vis-transactions-hub/configurations/provider/{pro`
- `GET /vis-transactions-hub/configurations/provider/$provider_Id`
- `GET /vis-transactions-hub/configurations/seller/$seller_Id`
- `GET /vis/users/$USER_ID/leads/buyers`
- `GET /vis/users/$USER_ID/leads/buyers?contact_types=question`
- `GET /vis/users/$USER_ID/leads/buyers?contact_types=whatsapp`
- `GET /vis/users/$USER_ID/leads/buyers?item_id=MLX1234`
- `GET /vis/users/$USER_ID/leads/buyers?offset=$OFFSET&limit=$LIMIT&date_from=$DATE_FROM&date_to=$DATE_TO&contact_types=$CONTACT_TYPES&item_id=$ITEM_ID&buyer_ids=$BUYER_IDS`
- `GET /vis/users/3052668868/leads/buyers?offset=0&limit=10&date_from=2026-01-15&date_to=2026-01-22&contact_types=credit,question,whatsapp&include_guest=true`
- `GET /visits/items?ids=$ITEM_ID`
- `POST /items/$ITEM_ID/variations/$VARIATION_ID`
- `POST /items/MLA658778048/variations`
- `POST /items/MLC2913388294`
- `PUT /items/$ITEM_ID/address_line_by_reference`
- `PUT /items/MLC2913388294`
- `PUT /quotations/$QUOTATION_ID?caller.type=seller`
- `UNKNOWN /categories/MLA401806`
- `UNKNOWN /categories/MLA401806/attributes`
- `UNKNOWN /items/$ITEM_ID/description`
- `UNKNOWN /items/tags`
- `UNKNOWN /users/$USER_ID/classifieds_promotion_packs?package_content=$PACKAGE_CONTENT&status=$STATUS`
- `UNKNOWN /users/806525693/leads/buyers?scope=test-public`
- `UNKNOWN /vis-transactions-hub/{providerId`
- `UNKNOWN /vis-transactions-hub/configurations/provider`
- `UNKNOWN /vis-transactions-hub/configurations/provider/{pro`
- `UNKNOWN /vis-transactions-hub/configurations/provider/$provider_Id`
- `UNKNOWN /vis-transactions-hub/configurations/seller/$seller_Id`

## Mensajería, reclamos y devoluciones

Cobertura: 11 páginas, 147 endpoints/rutas detectadas. Integraciones detectadas en contenido: Mensajería, Mercado Envíos, Mercado Pago, Notificaciones, OAuth.

### Páginas fuente

- [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) — estado: `ok`; endpoints: 21; fechas visibles: 2019-08-06, 2019-07-30, 2024-06-13; secciones: ¿Qué es gestionar la evidencia de reclamos?; Obtener evidencia del reclamo; Upload del archivo adjunto; Obtener informaciones del adjunto enviado; Descargar el archivo adjunto enviado; Cargar evidencias de envíos; Campos de la respuesta; Entrega por correo
- [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) — estado: `ok`; endpoints: 23; fechas visibles: —; secciones: ¿Qué es gestionar la resolución de un reclamo?; Solicitar mediación; Opciones de resolución de reclamos; Tipos de expected-resolutions; Consultar resoluciones esperadas; Campos de la respuesta; Consultar resoluciones esperadas; Parámetros de Respuesta
- [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo) — estado: `ok`; endpoints: 12; fechas visibles: —; secciones: ¿Qué es un mensaje de un reclamo?; Obtener todos los mensajes de un reclamo; Campos de la respuesta; Responder mensajes y adjuntar archivos; Carga de archivos; Crear mensaje con el archivo cargado; Descarga el archivo; Obtener información del archivo
- [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) — estado: `ok`; endpoints: 31; fechas visibles: —; secciones: ¿Qué es un reclamo?; Notificaciones de reclamos; Consultar un reclamo; Campos de la respuesta:; Detalles de un reclamo; Campos de la respuesta; Buscar reclamos; Parámetros:
- [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) — estado: `ok`; endpoints: 28; fechas visibles: —; secciones: ¿Qué es una devolución?; Gestionar una devolución; Consultar una devolución; Campos de la respuesta; Obtener detalles de las revisiones de una devolución; ¿Cómo identificar la posibilidad de consultar la API de GET Reviews?; ¿Qué son las apelaciones?; ¿Cuál es la diferencia entre apelaciones y devolución con falla?
- [Cambios - Changes & Allow Replace](https://developers.mercadolibre.com.co/es_co/changes) — estado: `ok`; endpoints: 6; fechas visibles: —; secciones: Cambios - Changes & Allow Replace; Cambios; Consultar un cambio:; Campos de la Respuesta; Campos de Respuesta: Estados; Estado Pending y Descripciones Detalladas; Estado de Procesamiento y Entrega; Definición de Estados - Fallo
- [Errores](https://developers.mercadolibre.com.co/es_co/errores) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Errores; Posibles errores al trabajar con reclamos; Api Errors; Metadata
- [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) — estado: `ok`; endpoints: 17; fechas visibles: 02 de febrero de 2026, 02/02/2026; secciones: Gestión de mensajes; Nueva arquitectura de mensajería; IDs de los Agentes por país; Consideraciones; Parámetros; Obtener mensajes de un paquete; Obtener los detalles del mensaje por ID; Enviar mensaje al comprador
- [Mensajes bloqueados](https://developers.mercadolibre.com.co/es_co/mensajes-bloqueados) — estado: `ok`; endpoints: 4; fechas visibles: —; secciones: Mensajes bloqueados; Consultar mensajes bloqueados; Campos de la respuesta; status; substatus; status_date
- [Mensajes pendientes](https://developers.mercadolibre.com.co/es_co/mensajes-pendientes) — estado: `ok`; endpoints: 14; fechas visibles: —; secciones: Mensajes pendientes; Flujo desde notificaciones; Mensajes pendientes de leer filtrado por resource; Modos de uso; Parámetros; Marcar mensajes como leídos; Mensajes pendientes de leer; Errores
- [Qué es mensajería](https://developers.mercadolibre.com.co/es_co/que-es-mensajeria) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Qué es mensajería

### Rutas del módulo

- `GET /claims/$CLAIM_ID`
- `GET /claims/$CLAIM_ID/detail`
- `GET /claims/$CLAIM_ID/returns`
- `GET /claims/$CLAIM_ID/returns/attachments`
- `GET /claims/$CLAIMS`
- `GET /claims/$CLAIMS_ID/messages`
- `GET /claims/actions-history`
- `GET /claims/actions/evidences`
- `GET /claims/affects-reputation`
- `GET /claims/detail`
- `GET /claims/reasons/$REASON_ID`
- `GET /claims/search`
- `GET /claims/search?offset=0&limit=30`
- `GET /claims/search?resource_id=123`
- `GET /claims/search?status=opened`
- `GET /messages/$MESSAGE_ID?tag=post_sale`
- `GET /messages/attachments?tag=post_sale&site_id=MLB`
- `GET /messages/attachments?tag=post_sale&site_id=SITE_ID`
- `GET /messages/attachments/$ATTACHMENT_ID?tag=post_sale&site_id=SITE_ID`
- `GET /messages/packs/$PACK_ID/sellers/$SELLER_ID?tag=post_sale`
- `GET /messages/packs/$PACK_ID/sellers/$USER_ID?tag=post_sale`
- `GET /messages/packs/2000000089077943/sellers/415458330?limit=2&offset=1&tag=post_sale`
- `GET /messages/packs/2000000089077943/sellers/415458330?tag=post_sale`
- `GET /messages/packs/22175467/sellers/32086568493?tag=post_sale`
- `GET /messages/packs/pack_id/sellers/seller_id`
- `GET /messages/packs/pack_id/sellers/seller_id?mark_as_read=false`
- `GET /messages/unread?role=$ROLE&tag=post_sale`
- `GET /messages/unread?tag=post_sale`
- `GET /messages/unread/$RESOURCE?tag=post_sale`
- `GET /messages/unread/packs/1234/sellers/2345?tag=post_sale`
- `GET /packs`
- `GET /packs/{pack_id}/sellers/{seller_id}/conversations/{type}`
- `GET /packs/1234/sellers/2345`
- `GET /packs/1977056109/sellers/378136913`
- `GET /packs/2000000089077943/seller/415458330`
- `GET /packs/22175467/sellers/32086568493`
- `GET /post-purchase/v1/claims/:CLAIM_ID/expected-resolutions/allow-replace`
- `GET /post-purchase/v1/claims/$CLAIM_ID`
- `GET /post-purchase/v1/claims/$CLAIM_ID/actions-history`
- `GET /post-purchase/v1/claims/$CLAIM_ID/actions/evidences`
- `GET /post-purchase/v1/claims/$CLAIM_ID/actions/send-message`
- `GET /post-purchase/v1/claims/$CLAIM_ID/affects-reputation`
- `GET /post-purchase/v1/claims/$CLAIM_ID/attachments`
- `GET /post-purchase/v1/claims/$CLAIM_ID/attachments-evidences`
- `GET /post-purchase/v1/claims/$CLAIM_ID/attachments-evidences/$ATTACHMENT_ID`
- `GET /post-purchase/v1/claims/$CLAIM_ID/attachments-evidences/$ATTACHMENTS_ID/download`
- `GET /post-purchase/v1/claims/$CLAIM_ID/attachments/$ATTACHMENTS_ID`
- `GET /post-purchase/v1/claims/$CLAIM_ID/attachments/$ATTACHMENTS_ID/download`
- `GET /post-purchase/v1/claims/$CLAIM_ID/changes`
- `GET /post-purchase/v1/claims/$CLAIM_ID/charges/return-cost`
- `GET /post-purchase/v1/claims/$CLAIM_ID/charges/return-cost?calculate_amount_usd=true`
- `GET /post-purchase/v1/claims/$CLAIM_ID/detail`
- `GET /post-purchase/v1/claims/$CLAIM_ID/evidences`
- `GET /post-purchase/v1/claims/$CLAIM_ID/expected-resolutions`
- `GET /post-purchase/v1/claims/$CLAIM_ID/messages`
- `GET /post-purchase/v1/claims/$CLAIM_ID/partial-refund/available-offers`
- `GET /post-purchase/v1/claims/$CLAIM_ID/returns/attachments`
- `GET /post-purchase/v1/claims/$CLAIM_ID/status-history`
- `GET /post-purchase/v1/claims/5123456/attachments-evidences`
- `GET /post-purchase/v1/claims/5123456/attachments-evidences/$ATTACHMENT_ID`
- `GET /post-purchase/v1/claims/5123456/attachments-evidences/$ATTACHMENTS_ID/download`
- `GET /post-purchase/v1/claims/5175748308/actions-history`
- `GET /post-purchase/v1/claims/5175748308/status-history`
- `GET /post-purchase/v1/claims/5204934310/actions/evidences`
- `GET /post-purchase/v1/claims/5204934310/actions/send-message`
- `GET /post-purchase/v1/claims/5204934310/detail`
- `GET /post-purchase/v1/claims/5204934310/evidences`
- `GET /post-purchase/v1/claims/5204934310/expected-resolutions`
- `GET /post-purchase/v1/claims/5204934310/messages`
- `GET /post-purchase/v1/claims/5204934310/partial-refund/available-offers`
- `GET /post-purchase/v1/claims/5224172034/affects-reputation`
- `GET /post-purchase/v1/claims/5255026166/returns/attachments`
- `GET /post-purchase/v1/claims/5255498215/changes`
- `GET /post-purchase/v1/claims/5281510459`
- `GET /post-purchase/v1/claims/555555555/attachments/1325224382_181a6330-d9f6-410c-a2c9-d03f8323bd16.jpg/download`
- `GET /post-purchase/v1/claims/949903015/actions/evidences`
- `GET /post-purchase/v1/claims/949903019/evidences`
- `GET /post-purchase/v1/claims/reasons/$REASON_ID`
- `GET /post-purchase/v1/claims/reasons/PDD9939`
- `GET /post-purchase/v1/claims/search?players.user_id=123456789&players.role=respondent&status=opened&limit=30`
- `GET /post-purchase/v1/returns/{return_id}/return-review`
- `GET /post-purchase/v1/returns/$RETURN_ID/return-review`
- `GET /post-purchase/v1/returns/$RETURN_ID/reviews`
- `GET /post-purchase/v1/returns/267582953/return-review`
- `GET /post-purchase/v1/returns/54640533964/reviews`
- `GET /post-purchase/v1/returns/reasons?flow=$FLOW&claim_id=$CLAIM_ID`
- `GET /post-purchase/v1/returns/reasons?flow=seller_return_failed&claim_id=5555555`
- `GET /post-purchase/v2/claims/$CLAIM_ID/returns`
- `GET /returns/attachments`
- `GET /returns/reasons`
- `GET /shipments/$SHIPMENT_ID/costs`
- `GET /v1/claims/search`
- `GET /v1/claims/search?offset=0&limit=30`
- `GET /v1/claims/search?resource_id=123`
- `GET /v1/claims/search?status=opened`
- `POST /claims/{claim_id}/partial-refund/available-offers`
- `POST /claims/expected-resolutions`
- `POST /claims/partial-refund/available-offers-resolutions`
- `POST /messages/attachments?tag=post_sale&site_id=MLB`
- `POST /messages/attachments?tag=post_sale&site_id=SITE_ID`
- `POST /messages/packs/$PACK_ID/sellers/$USER_ID?tag=post_sale`
- `POST /messages/packs/2000000089077943/sellers/415458330?tag=post_sale`
- `POST /post-purchase/v1/claims/$CLAIM_ID/actions/evidences`
- `POST /post-purchase/v1/claims/$CLAIM_ID/actions/open-dispute`
- `POST /post-purchase/v1/claims/$CLAIM_ID/actions/send-message`
- `POST /post-purchase/v1/claims/$CLAIM_ID/attachments`
- `POST /post-purchase/v1/claims/$CLAIM_ID/attachments-evidences`
- `POST /post-purchase/v1/claims/$CLAIM_ID/evidences`
- `POST /post-purchase/v1/claims/$CLAIM_ID/expected-resolutions`
- `POST /post-purchase/v1/claims/$CLAIM_ID/expected-resolutions/allow-return`
- `POST /post-purchase/v1/claims/$CLAIM_ID/expected-resolutions/partial-refund`
- `POST /post-purchase/v1/claims/$CLAIM_ID/expected-resolutions/refund`
- `POST /post-purchase/v1/claims/$CLAIM_ID/partial-refund/available-offers`
- `POST /post-purchase/v1/claims/$CLAIM_ID/returns/attachments`
- `POST /post-purchase/v1/claims/5123456/attachments-evidences`
- `POST /post-purchase/v1/claims/5204934310/actions/evidences`
- `POST /post-purchase/v1/claims/5204934310/actions/open-dispute`
- `POST /post-purchase/v1/claims/5204934310/actions/send-message`
- `POST /post-purchase/v1/claims/5204934310/expected-resolutions`
- `POST /post-purchase/v1/claims/5204934310/expected-resolutions/allow-return`
- `POST /post-purchase/v1/claims/5204934310/expected-resolutions/partial-refund`
- `POST /post-purchase/v1/claims/5204934310/expected-resolutions/refund`
- `POST /post-purchase/v1/claims/5204934310/partial-refund/available-offers`
- `POST /post-purchase/v1/claims/5255026166/returns/attachments`
- `POST /post-purchase/v1/claims/5341941616/partial-refund/available-offers`
- `POST /post-purchase/v1/claims/949903015/actions/evidences`
- `POST /post-purchase/v1/claims/949903019/evidences`
- `POST /post-purchase/v1/returns/$RETURN_ID/return-review`
- `POST /post-purchase/v1/returns/267582953/return-review`
- `UNKNOWN /claims/{claim_id}/partial-refund/available-offers`
- `UNKNOWN /claims/$CLAIM_ID`
- `UNKNOWN /claims/$CLAIM_ID/returns/attachments`
- `UNKNOWN /claims/actions/evidences`
- `UNKNOWN /claims/search`
- `UNKNOWN /claims/search?offset=0&limit=30`
- `UNKNOWN /claims/search?resource_id=123`
- `UNKNOWN /claims/search?status=opened`
- `UNKNOWN /packs/1234/sellers/2345`
- `UNKNOWN /packs/1977056109/sellers/378136913`
- `UNKNOWN /packs/2000000089077943/seller/415458330`
- _...7 endpoints adicionales en el JSON._

## Mercado Ads

Cobertura: 5 páginas, 55 endpoints/rutas detectadas. Integraciones detectadas en contenido: Catálogo, Mercado Ads, Mercado Envíos, Mercado Pago, OAuth.

### Páginas fuente

- [Bonificaciones para Product Ads](https://developers.mercadolibre.com.co/es_co/bonificaciones-para-product-ads) — estado: `ok`; endpoints: 1; fechas visibles: —; secciones: Bonificaciones para Product Ads; Tipos de bonificaciones; Consultar bonificación; Campos de respuesta; Respuesta cuando no hay bonificaciones; Posibles errores al consultar bonificaciones
- [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) — estado: `ok`; endpoints: 14; fechas visibles: 17 de junio de 2026, 2023-02-09, 2025-04-01, 2025-04-07, 2025-04-02, 2025-04-03, 2025-04-04, 2025-04-05, 2025-04-06, 2025-05-01, 2025-05-05, 2025-05-02, 2025-05-03, 2025-05-04, 2024-07-01, 2024-07-10, 2025-02-13; secciones: Brand Ads; Flujo técnico recomendado; Consultar anunciante; Campos de respuesta; Tipos de campañas; Buscar campañas; Campos de respuesta; Detalle de campaña
- [Display Ads](https://developers.mercadolibre.com.co/es_co/display) — estado: `ok`; endpoints: 14; fechas visibles: 1 de septiembre de 2022, 2024-04-01, 2024-04-15, 2024-02-01, 2024-09-19, 2024-09-20; secciones: Display Ads; Consultar anunciante; Campos de respuesta; Consultar campañas de un anunciante; Parámetros opcionales; Campos de respuesta; Tipos de campañas y objetivos; Métricas de una campaña
- [Mercado Ads](https://developers.mercadolibre.com.co/es_co/introduccion-a-mercado-ads) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Mercado Ads
- [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) — estado: `ok`; endpoints: 28; fechas visibles: 26 de febrero de 2026, 30 de marzo de 2026, 2025-12-01, 2025-12-30, 2024-01-01, 2024-02-28, 2023-01-01; secciones: Product Ads; Consultar anunciante; Detalle de un anuncio; Métricas de campañas; Filtros disponibles; Search y métricas de campañas; Métricas diarias de campañas; Métricas sumarizadas de campañas

### Rutas del módulo

- `GET /advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ads/search?limit=1&offset=0&date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount`
- `GET /advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ads/search?limit=1&offset=0&date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount&aggregation_type=DAILY`
- `GET /advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ads/search?limit=1&offset=0&date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount&metrics_summary=true`
- `GET /advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/campaigns/search`
- `GET /advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/campaigns/search??limit=1&offset=0&date_from=2025-12-01&date_to=2025-12-30&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount&metrics_summary=true`
- `GET /advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/campaigns/search?limit=2&offset=0&date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount&aggregation_type=DAILY`
- `GET /advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/items/search`
- `GET /advertising/$ADVERTISER_SITE_ID/product_ads/ads/$ITEM_ID`
- `GET /advertising/$ADVERTISER_SITE_ID/product_ads/ads/$ITEM_ID?date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount`
- `GET /advertising/$ADVERTISER_SITE_ID/product_ads/ads/$ITEM_ID?date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount&aggregation_type=DAILY`
- `GET /advertising/$ADVERTISER_SITE_ID/product_ads/campaigns/$CAMPAIGN_ID??date_from=2025-12-01&date_to=2025-12-30&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount,impression_share,top_impression_share,lost_impression_share_by_budget,lost_impression_share_by_ad_rank,acos_benchmark`
- `GET /advertising/$ADVERTISER_SITE_ID/product_ads/campaigns/$CAMPAIGN_ID?date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount,impression_share,top_impression_share,lost_impression_share_by_budget,lost_impression_share_by_ad_rank,acos_benchmark&aggregation_type=DAILY`
- `GET /advertising/$ADVERTISER_SITE_ID/product_ads/items/$ITEM_ID`
- `GET /advertising/advertisers?product_id=$PRODUCT_ID`
- `GET /advertising/advertisers?product_id=BADS`
- `GET /advertising/advertisers?product_id=DISPLAY`
- `GET /advertising/advertisers?product_id=PADS`
- `GET /advertising/advertisers/$ADVERTISER_ID/brand_ads/campaigns`
- `GET /advertising/advertisers/$ADVERTISER_ID/brand_ads/campaigns/$CAMPAIGN_ID`
- `GET /advertising/advertisers/$ADVERTISER_ID/brand_ads/campaigns/$CAMPAIGN_ID/items`
- `GET /advertising/advertisers/$ADVERTISER_ID/brand_ads/campaigns/$CAMPAIGN_ID/keywords`
- `GET /advertising/advertisers/$ADVERTISER_ID/brand_ads/campaigns/$CAMPAIGN_ID/keywords/metrics?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`
- `GET /advertising/advertisers/$ADVERTISER_ID/brand_ads/campaigns/$CAMPAIGN_ID/metrics?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`
- `GET /advertising/advertisers/$ADVERTISER_ID/brand_ads/campaigns/full_summary?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`
- `GET /advertising/advertisers/$ADVERTISER_ID/brand_ads/campaigns/metrics?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&aggregation_type=daily`
- `GET /advertising/advertisers/$ADVERTISER_ID/display/campaigns`
- `GET /advertising/advertisers/$ADVERTISER_ID/display/campaigns/$CAMPAIGN_ID/line_items?sort_by=start_date&sort_order=asc`
- `GET /advertising/advertisers/$ADVERTISER_ID/display/campaigns/$CAMPAIGN_ID/line_items/$LINE_ITEM_ID/creatives?sort_by=start_date&sort_order=asc`
- `GET /advertising/advertisers/$ADVERTISER_ID/display/campaigns/$CAMPAIGN_ID/metrics?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`
- `GET /advertising/advertisers/$ADVERTISER_ID/display/campaigns/999999/line_items/0000001/creatives?sort_by=start_date&sort_order=asc`
- `GET /advertising/advertisers/$ADVERTISER_ID/display/metrics?dimension=creatives&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&line_item_id=$LINE_ITEM_ID`
- `GET /advertising/advertisers/$ADVERTISER_ID/display/metrics?dimension=line_items&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&campaign_id=$CAMPAIGN_ID`
- `GET /advertising/advertisers/$ADVERTISER_ID/product_ads/campaigns`
- `GET /advertising/advertisers/$ADVERTISER_ID/product_ads/items`
- `GET /advertising/advertisers/0000/display/metrics?dimension=creatives&date_from=2024-09-19&date_to=2024-09-20&line_item_id=4321`
- `GET /advertising/advertisers/0000/display/metrics?dimension=line_items&date_from=2024-09-19&date_to=2024-09-19&campaign_id=1111`
- `GET /advertising/advertisers/101010/brand_ads/campaigns/123456/keywords/metrics?date_from=2024-07-01&date_to=2024-07-10`
- `GET /advertising/advertisers/101010/brand_ads/campaigns/full_summary?date_from=2024-07-01&date_to=2024-07-10`
- `GET /advertising/advertisers/10101010/brand_ads/campaigns/123456/metrics?date_from=2025-05-01&date_to=2025-05-05`
- `GET /advertising/advertisers/10101010/brand_ads/campaigns/metrics?date_from=2025-04-01&date_to=2025-04-07&aggregation_type=daily`
- `GET /advertising/advertisers/11111/display/campaigns/80/metrics?date_from=2024-04-01&date_to=2024-04-15`
- `GET /advertising/advertisers/123456/display/campaigns/987654/line_items?sort_by=start_date&sort_order=asc`
- `GET /advertising/advertisers/61/display/campaigns?sort_by=start_date&sort_order=desc`
- `GET /advertising/MLA/advertisers/882927/product_ads/campaigns/search?limit=1&offset=0&date_from=2025-12-01&date_to=2025-12-30&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount`
- `GET /advertising/MLM/advertisers/35300/product_ads/ads/search?limit=1&offset=0&date_from=2024-01-01&date_to=2024-02-28&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount`
- `GET /advertising/MLM/product_ads/ads/MLM12345678`
- `GET /advertising/product_ads_2/campaigns/$CAMPAIGN_ID/ads/metrics`
- `GET /advertising/product_ads_2/campaigns/$CAMPAIGN_ID/metrics`
- `GET /advertising/product_ads/ads/search`
- `GET /advertising/product_ads/campaigns/$CAMPAIGN_ID`
- `GET /advertising/product_ads/campaigns/$CAMPAIGN_ID/ads/metrics`
- `GET /advertising/product_ads/campaigns/$CAMPAIGN_ID/metrics`
- `GET /advertising/product_ads/items/$ITEM_ID`
- `UNKNOWN /advertising/$ADVERTISER_SITE_ID/product_ads/campaigns/$CAMPAIGN_ID??date_from=2025-12-01&date_to=2025-12-30&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount,impression_share,top_impression_share,lost_impression_share_by_budget,lost_impression_share_by_ad_rank,acos_benchmark`
- `UNKNOWN /advertising/advertisers/bonifications`

## Mercado Envíos

Cobertura: 14 páginas, 181 endpoints/rutas detectadas. Integraciones detectadas en contenido: Catálogo, Facturación, Mensajería, Mercado Envíos, Mercado Pago, Notificaciones, OAuth.

### Páginas fuente

- [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) — estado: `ok`; endpoints: 20; fechas visibles: —; secciones: Agrupación de paquetes para la Colecta; 1. Validar usuario; Llamada:; Ejemplo:; Campos de la respuesta; Errores; 2. Crear Bundle; Llamada:
- [Costos de envío](https://developers.mercadolibre.com.co/es_co/costos-de-envios) — estado: `ok`; endpoints: 8; fechas visibles: —; secciones: Costos de envío; Consultar productos con envíos gratis; Parámetros de respuesta:; Consultar costos de envíos de un ítem; Parámetros de consulta aceptables:; Parámetros de respuesta:; Códigos de estado de respuesta:; Consultar costos de envíos al comprar un artículo
- [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) — estado: `ok`; endpoints: 23; fechas visibles: 2025-09-30, 2025-10-10; secciones: Envíos Colecta y Places; Configurar un usuario de test; Capacidad de envíos; Vista del vendedor:; Consultar la capacidad de envíos; Consulta por USER_ID; Consulta por NODE_ID; Restricciones de capacidad
- [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) — estado: `ok`; endpoints: 28; fechas visibles: 2021-12-25; secciones: Envíos Flex; Vista del vendedor:; Áreas de cobertura por países; Configurar un usuario de test; Consultar suscripciones de un usuario; Consultar zonas de cobertura; Consideraciones; Códigos de respuesta
- [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) — estado: `ok`; endpoints: 13; fechas visibles: 2020-06-01, 2020-06-30, 2020-06-29, 2020-07-28; secciones: Envíos Fulfillment; Obtener el inventory_id; Consultar el stock del vendedor; Campos de la respuesta; Manejo de errores; Posibles errores; Consultar detalle del stock no disponible; Consultar operaciones
- [Envíos Personalizados](https://developers.mercadolibre.com.co/es_co/envios-personalizados) — estado: `ok`; endpoints: 9; fechas visibles: —; secciones: Envíos Personalizados; Ofrecer envío personalizado para tus productos; Envío gratis en envíos personalizados; Agregar envío personalizado en productos; Agregar número de seguimiento; Estados de envío y transiciones; Informar estado de entrega
- [Envíos Turbo](https://developers.mercadolibre.com.co/es_co/envios-turbo) — estado: `ok`; endpoints: 12; fechas visibles: —; secciones: Envíos Turbo; Áreas de cobertura por países; Configurar un usuario de test; Consultar suscripciones de un usuario; Parámetros; Parámetros de respuesta; Códigos de respuesta   200 OK: Consulta exitosa.  400 Bad Request: Algún parámetro es inválido.  401 Unauthorized: No tienes credenciales válidas.  403 Forbidden: No tienes permisos suficientes para acceder a este recurso.  404 Not Found: No se encontró la configuración.  500 Internal Server Error: Error al obtener la configuración.; Consultar radio de cobertura
- [Flete Dinámico](https://developers.mercadolibre.com.co/es_co/flete-dinamico) — estado: `ok`; endpoints: 12; fechas visibles: —; secciones: Flete Dinámico; Dinámica de Homologación; Requisitos de Homologación; Cumplimiento del Contrato; Tiempo de Respuesta Óptimo; Ubicación de Infraestructura; Origen y Destino de Datos; Especificaciones de la fuente de datos (origen)
- [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) — estado: `ok`; endpoints: 20; fechas visibles: —; secciones: Gestión Mercado Envíos; Modalidades de Envíos; Preferencias de envío de un ítem; Servicios de Envíos disponibles por país; Preferencias de envío de un usuario; Consultar modos de envíos de una categoría; Consultar atributos de shipping por dominio; Consultar Servicios de Logística de un User Product
- [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) — estado: `ok`; endpoints: 20; fechas visibles: —; secciones: Gestionar envíos; Consultar envíos; Ítems asociados a un envío; Costos del envío; Parámetros; Pagos de un envío; Plazo máximo de despacho (SLA); Parámetros de respuesta:
- [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) — estado: `ok`; endpoints: 31; fechas visibles: —; secciones: Gestionar Mercado Envíos 2; Tipos de logísticas; Agregar ME2 a un ítem; Atributos requeridos por dominio; Los campos indicarán:; Consultar fecha de envío del producto; Imprimir etiquetas de envío; Validaciones de tipos de logística:
- [ME1 / ME2 y envío gratis](https://developers.mercadolibre.com.co/es_co/me1-me2-y-envio-gratis) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: ME1 / ME2 y envío gratis
- [Mercado Envíos - Costos y cotizaciones](https://developers.mercadolibre.com.co/es_co/mercado-envios-costos-y-cotizaciones) — estado: `ok`; endpoints: 3; fechas visibles: —; secciones: Mercado Envíos - Costos y cotizaciones
- [Mercado Envíos 1](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-1) — estado: `ok`; endpoints: 9; fechas visibles: —; secciones: Mercado Envíos 1; Activar ME1 a un vendedor; Publicar un ítem con ME1; Activar ME1 en un ítem; Códigos de estado de respuesta:; Consultar envíos con ME1; Alertas de fraude

### Rutas del módulo

- `DELETE /flex/sites/$SITE_ID/items/$ITEM_ID/v2`
- `DELETE /flex/sites/MLB/items/MLB1493119403/v2`
- `DELETE /soe/bundles/{bundle_id`
- `DELETE /soe/bundles/12345/volumes`
- `GET /catalog_domains/$DOMAIN_ID/shipping_attributes`
- `GET /catalog_domains/MLA-AUTOMOTIVE_TIRES/shipping_attributes`
- `GET /catalog_domains/MLB-AUTOMOTIVE_TIRES/shipping_attributes`
- `GET /categories`
- `GET /categories/$CATEGORY_ID/shipping_preferences`
- `GET /categories/MCO7159/shipping_preferences`
- `GET /categories/MLA418448/shipping_preferences`
- `GET /categories/MLA45502/shipping_preferences`
- `GET /categories/MLB438794/shipping_preferences`
- `GET /customers/marketplace/sites/{SITE_ID`
- `GET /customers/marketplace/sites/$SITE_ID/user-products/$USER_PRODUCT_ID/contracts/shippability/services`
- `GET /customers/marketplace/sites/MLA/user-products/MLAU1234567890/contracts/shippability/services?legacy_attributes=true`
- `GET /flex/sites/{SITE_ID`
- `GET /flex/sites/$SITE_ID/items/$ITEM_ID/v2`
- `GET /flex/sites/$SITE_ID/shipments/$SHIPMENT_ID/assignment/v2`
- `GET /flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/coverage/radius/v1`
- `GET /flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/coverage/radius/v1?show_availables=boolean`
- `GET /flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/coverage/zones/v1`
- `GET /flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/coverage/zones/v1?show_availables=$boolean`
- `GET /flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/delivery-ranges/v1`
- `GET /flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/delivery-ranges/v1?show_availables=boolean`
- `GET /flex/sites/$SITE_ID/users/$USER_ID/services/$SERVICE_ID/configurations/holidays/v1`
- `GET /flex/sites/$SITE_ID/users/$USER_ID/subscriptions/v1`
- `GET /flex/sites/MLA/shipments/40070866801/assignment/v2`
- `GET /flex/sites/MLA/users/1438865529/subscriptions/v1`
- `GET /flex/sites/MLA/users/1444885522/courier-shipment/v1`
- `GET /flex/sites/MLB/items/MLB1493119403/v2`
- `GET /inventories/$INVENTORY_ID/stock/fulfillment`
- `GET /inventories/$INVENTORY_ID/stock/fulfillment?include_attributes=conditions`
- `GET /inventories/LCQI05831/stock/fulfillment`
- `GET /inventories/YLXH33638/stock/fulfillment?include_attributes=conditions`
- `GET /items/$ITEM_ID/shipping_options?city_to=$CITY_TO`
- `GET /items/$ITEM_ID/shipping_options?zip_code=$ZIP_CODE`
- `GET /items/MLA1122334488`
- `GET /items/MLA1398714241/shipping_options?city_to=Q08tRENCb2dvdA`
- `GET /items/MLA1398714241/shipping_options?zip_code=1675`
- `GET /items/MLA1718222111`
- `GET /items/MLA803066380/shipping_options?zip_code=$1234`
- `GET /items/MLB1557246024`
- `GET /nodes/$NETWORK_NODE_ID/capacity_middleend`
- `GET /nodes/$NETWORK_NODE_ID/schedule/$LOGISTIC_TYPE`
- `GET /nodes/$NETWORK_NODE_ID/service/$SERVICE_TYPE/processing_time_tool`
- `GET /nodes/$NODE_ID/capacity_middleend`
- `GET /nodes/MXP20157465171/schedule/xd_drop_off`
- `GET /nodes/MXP20157465171/service/carrier_pickup/processing_time_tool`
- `GET /nodes/MXP20214899242/capacity_middleend`
- `GET /orders/$ORDER_ID`
- `GET /orders/$ORDER_ID?options`
- `GET /orders/$ORDER_ID/shipments`
- `GET /orders/2053577644`
- `GET /shipment_labels?shipment_ids=$SHIPPING_ID1,$SHIPPING_ID2&response_type=pdf`
- `GET /shipment_labels?shipment_ids=$SHIPPING_ID1,$SHIPPING_ID2&response_type=zpl2`
- `GET /shipment_labels?shipment_ids=43308302844&response_type=pdf`
- `GET /shipment_labels?shipment_ids=43308302844&response_type=zpl2`
- `GET /shipment_statuses`
- `GET /shipments`
- `GET /shipments/$SHIPMENT_ID`
- `GET /shipments/$SHIPMENT_ID/carrier`
- `GET /shipments/$SHIPMENT_ID/delays`
- `GET /shipments/$SHIPMENT_ID/items`
- `GET /shipments/$SHIPMENT_ID/lead_time`
- `GET /shipments/$SHIPMENT_ID/payments`
- `GET /shipments/$SHIPMENT_ID/sla`
- `GET /shipments/$SHIPMENT_ID/split`
- `GET /shipments/1111111111/payments`
- `GET /shipments/12345678`
- `GET /shipments/1234567899/history`
- `GET /shipments/27691621451/carrier`
- `GET /shipments/30143583389/delays`
- `GET /shipments/40173236996`
- `GET /shipments/42469883906`
- `GET /shipments/43308302844`
- `GET /shipments/43319685225`
- `GET /shipments/43416180080/sla`
- `GET /shipments/shipment_id/`
- `GET /shipments/shipment_id/costs`
- `GET /shipments/shipment_id/payments`
- `GET /shipping/me1/sites/{site_id}/metrics`
- `GET /shipping/me1/sites/MLB/metrics?seller_id=123456789&ts_from=2023-10-01T00:00:00Z&ts_to=2023-10-31T23:59:59Z`
- `GET /shipping/me1/sites/MLB/metrics?ts_from=2023-10-01T00:00:00Z&ts_to=2023-10-31T23:59:59Z`
- `GET /shipping/me1/v1/quotation/simulate`
- `GET /shipping/me1/v1/tariff/{resource_id}`
- `GET /shipping/me1/v1/tariff/550e8400-e29b-41d4-a716-446655440000`
- `GET /shipping/me1/v1/tariff/template`
- `GET /shipping/me1/v1/tariff/template?site=MLB`
- `GET /shipping/me1/v1/tariff/update`
- `GET /sites/{site_id}/metrics`
- `GET /sites/$SITE_ID/shipping_methods`
- `GET /soe/bundles`
- `GET /soe/bundles/{bundle_id`
- `GET /soe/bundles/{bundleId`
- `GET /soe/bundles/12345/summary`
- `GET /soe/bundles/12345/volumes`
- `GET /soe/bundles/12345/volumes/search?volume_reference=451235132`
- `GET /soe/bundles/789`
- `GET /soe/bundles/789/file`
- `GET /soe/bundles/label`
- `GET /soe/bundles/search`
- `GET /soe/bundles/search?bundle_reference=ABCD1234`
- `GET /soe/bundles/users/validate`
- `GET /stock/fulfillment/operations/$OPERATION_ID`
- `GET /stock/fulfillment/operations/329663159`
- `GET /stock/fulfillment/operations/search?seller_id=$SELLER_ID&inventory_id=$INVENTORY_ID&date_from=$aaammdd&date_to=$aaammdd`
- `GET /stock/fulfillment/operations/search?seller_id=$SELLER_ID&inventory_id=$INVENTORY_ID&date_from=$aaammdd&date_to=$aaammdd&scroll=YXBpY29yZS1pdGVtcw==:ZHMtYXBpY29yZS1pdGVtcy0wMQ==:DXF1ZXJ5QW5kRmV0Y2gBAAAAABIu7AgWMXl6anF3SU5SMVNaQXFxTkZubHBqQQ==`
- `GET /stock/fulfillment/operations/search?seller_id=384324657&inventory_id=DEHW09303&date_from=2020-06-01&date_to=2020-06-30`
- `GET /stock/fulfillment/operations/search?seller_id=384741716&inventory_id=NFWV18668&date_from=2020-06-29&date_to=2020-07-28&type=SALE_CONFIRMATION&external_references.shipment_id=1111?`
- `GET /user-products/{USER_PRODUCT_ID}/contracts/shippability/services`
- `GET /users`
- `GET /users/{COURIER_USER_ID}/courier-shipment/v1`
- `GET /users/$USER_ID/capacity_middleend/$LOGISTIC_TYPE`
- `GET /users/$USER_ID/service/$SERVICE_TYPE/processing_time_tool`
- `GET /users/$USER_ID/shipping_modes`
- `GET /users/$USER_ID/shipping_options/free?dimensions=$DIMENSIONES&verbose=$VERBOSE&item_price=$ITEM_PRICE&listing_type_id=$LISTING_TYPE&mode=$MODE&condition=$CONDITION&logistic_type=$LOGISTIC_TYPE&free_shipping=$FREE_SHIPPING`
- `GET /users/$USER_ID/shipping_preferences`
- `GET /users/$USER_ID/shipping/schedule/$LOGISTIC_TYPE`
- `GET /users/12345678/shipping_preferences`
- `GET /users/123456789/capacity_middleend/cross_docking`
- `GET /users/123456789/service/carrier_pickup/processing_time_tool`
- `GET /users/123456789/shipping_modes`
- `GET /users/123456789/shipping/schedule/cross_docking`
- `GET /users/244878077/shipping_options/free?dimensions=9x17x22,462&verbose=true&item_price=300&listing_type_id=gold_pro&mode=me2&condition=new&logistic_type=drop_off&free_shipping=True`
- `POST /catalog_domains/$DOMAIN_ID/shipping_attributes`
- `POST /catalog_domains/MLB-AUTOMOTIVE_TIRES/shipping_attributes`
- `POST /categories/MCO7159/shipping_preferences`
- `POST /categories/MLB278114/attributes`
- `POST /flex/sites/{SITE_ID`
- `POST /flex/sites/$SITE_ID/items/$ITEM_ID/v2`
- `POST /flex/sites/MLA/users/1444885522/courier-shipment/v1`
- `POST /flex/sites/MLB/items/MLB1493119403/v2`
- `POST /items/$ITEM_ID`
- `POST /items/$ITEM_ID/shipping_options?zip_code=$ZIP_CODE`
- `POST /items/MLA1644124644`
- `POST /items/MLA803066380/shipping_options?zip_code=$1234`
- `POST /orders/$ORDER_ID`
- `POST /orders/$ORDER_ID?options`
- `POST /orders/2053577644`
- _...41 endpoints adicionales en el JSON._

## Preguntas, ventas y postventa

Cobertura: 19 páginas, 182 endpoints/rutas detectadas. Integraciones detectadas en contenido: Catálogo, Facturación, Mensajería, Mercado Ads, Mercado Envíos, Mercado Pago, Notificaciones, OAuth.

### Páginas fuente

- [Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/buenas-practicas-para-el-consumo-de-las-apis-de-reportes-de-facturacion) — estado: `ok`; endpoints: 28; fechas visibles: 2024-01-01; secciones: Buenas Prácticas para el Consumo de las APIs de Reportes de Facturación; Información General; Propósito de los Recursos; Lista de Endpoints; 1. Obtener Períodos de Facturación; 2. Obtener Documentos de un Período; 3. Resumen de Facturación; 4. Obtener Detalles de Facturación
- [Cargar y Obtener Facturas](https://developers.mercadolibre.com.co/es_co/cargar-factura) — estado: `ok`; endpoints: 12; fechas visibles: —; secciones: Cargar y Obtener Facturas; Cargar factura en detalle de venta; Adjuntar archivo XML; Posibles errores en la carga de factura; Obtener IDs de las facturas; Posibles errores obteniendo IDs de facturas; Obtener factura; Posibles errores por obtener facturas
- [Crear nota informativa](https://developers.mercadolibre.com.co/es_co/notas-de-packs) — estado: `ok`; endpoints: 5; fechas visibles: —; secciones: Crear nota informativa; Actualizar nota informativa; Visualizar nota informativa; Buscar nota informativa
- [Datos de Facturación](https://developers.mercadolibre.com.co/es_co/facturacion) — estado: `ok`; endpoints: 4; fechas visibles: —; secciones: Datos de Facturación; Obteniendo o BILLING_INFO_ID; Consultar los datos para facturación; Respuesta con los ejemplos de persona física y jurídica; Descripción de los campos de la API
- [Descarga de documento legal](https://developers.mercadolibre.com.co/es_co/reportes-descargas) — estado: `ok`; endpoints: 10; fechas visibles: 2021-08-01, 2021-08-04; secciones: Descarga de documento legal; Descarga reporte de conciliación
- [Estados de órdenes y seguimiento](https://developers.mercadolibre.com.co/es_co/estados-de-ordenes-me1) — estado: `ok`; endpoints: 6; fechas visibles: —; secciones: Estados de órdenes y seguimiento; Estados y sub estados de envío; Actualizar el estado de un envío ME1; Marcar compra como despachada; Marcar compra como salió para entrega; Marcar compra como no entregada; Marcar compra como entregada; Marcar entrega como fallida por comprador ausente
- [Facturación / Billing info](https://developers.mercadolibre.com.co/es_co/facturacion-billing-info) — estado: `ok`; endpoints: 5; fechas visibles: —; secciones: Facturación / Billing info
- [Feedback sobre venta](https://developers.mercadolibre.com.co/es_co/feedback-sobre-venta) — estado: `ok`; endpoints: 11; fechas visibles: —; secciones: Feedback sobre venta; Descripción de recursos; Valores aceptados para enviar como "reason"; Publicar feedback; Responder al feedback; Consultar feedbacks de una venta; Consultar el feedback; Modificar el feedback
- [Gestión de packs](https://developers.mercadolibre.com.co/es_co/gestion-packs) — estado: `ok`; endpoints: 12; fechas visibles: —; secciones: Gestión de packs; Relación de entidades; Consultar órdenes de un pack; Consultar órdenes; Ya tengo el producto
- [Gestiona pagos](https://developers.mercadolibre.com.co/es_co/pagos) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Gestiona pagos; Recibir una notificación; Flujo de devolución de dinero en cuenta por ventas canceladas
- [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) — estado: `ok`; endpoints: 36; fechas visibles: —; secciones: Gestiona preguntas y respuestas; Buscar preguntas; Preguntas recibidas por un vendedor; Preguntas recibidas respecto de un artículo; ¿Cómo ordenar?; Preguntas realizadas por un usuario respecto de un artículo; Preguntas por ID; Descripción de atributos
- [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) — estado: `ok`; endpoints: 21; fechas visibles: 01/01/2001; secciones: Gestionar órdenes; Obtener una orden; Campos de respuesta:; ¿Cómo se calcula?; Componentes de la fórmula; Características importantes; Ejemplo de respuesta con gross_price; Desglose del cálculo en el ejemplo
- [Notas en órdenes](https://developers.mercadolibre.com.co/es_co/notas-en-ordenes) — estado: `ok`; endpoints: 6; fechas visibles: —; secciones: Notas en órdenes; Agregar una nota a una orden; Ver notas de órdenes; Modificar una nota; Eliminar notas; Bloquear ofertas; Bloquear ofertas de un usuario específico
- [Preguntas Frecuentes (FAQs)](https://developers.mercadolibre.com.co/es_co/faq-preguntas-frecuentes) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Preguntas Frecuentes (FAQs)
- [Preguntas y Respuestas](https://developers.mercadolibre.com.co/es_co/preguntas-y-respuestas) — estado: `ok`; endpoints: 13; fechas visibles: —; secciones: Preguntas y Respuestas; Obtener preguntas por ID de ítem; Respuesta; Realizar una pregunta.; Respuesta; Responder una pregunta.; Respuesta; Obtener detalles de la pregunta.
- [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) — estado: `ok`; endpoints: 24; fechas visibles: 2024-11-01, 2021-06-01, 2024-05-01, 2023-03-01, 2022-10-01; secciones: Provisiones; Parámetros de paginación; Filtros opcionales; Ejemplo de paginación: Detalles de Mercado Pago; Consideraciones; Mercado Libre; Llamada:; Ejemplo:
- [Reporte de pagos](https://developers.mercadolibre.com.co/es_co/reportes-pagos) — estado: `ok`; endpoints: 4; fechas visibles: 2023-08-01; secciones: Reporte de pagos; Parámetros; Campos de respuesta; Detalle de pagos; Parámetros opcionales; Campos de respuesta
- [Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/reportes-de-facturacion) — estado: `ok`; endpoints: 7; fechas visibles: 2023-06-01, 2020-02-19, 2020-03-18, 2020-03-01, 2020-03-24, 2021-06-01, 2021-06-02, 2021-05-03, 2023-10-01, 2023-06-19, 2023-07-18, 2023-07-24, 2023-07-01; secciones: Reportes de Facturación; Obtener período; Parámetro obligatorio; Parámetros de respuesta; Obtener Documentos de un Período; Parámetros opcionales; Resumen de Facturación; Parámetros de respuesta
- [Resumen de Percepciones](https://developers.mercadolibre.com.co/es_co/resumen-percepciones) — estado: `ok`; endpoints: 6; fechas visibles: 2021-08-01, 2021-11-29, 2021-10-30; secciones: Resumen de Percepciones; Campos de la respuesta; Detalle de percepciones; Mercado Libre; Mercado Pago; Campos de la respuesta

### Rutas del módulo

- `DELETE /orders/$ORDER_ID/notes/$NOTE_ID`
- `DELETE /packs/$PACK_ID/fiscal_documents`
- `DELETE /packs/2000000089077943/fiscal_documents`
- `DELETE /questions/:id:`
- `DELETE /questions/$QUESTION_ID`
- `DELETE /users/$SELLER_ID/questions_blacklist/$USER_ID`
- `GET /answers`
- `GET /answers/:`
- `GET /billing/integration/group/ML/order/details?order_ids={order`
- `GET /billing/integration/group/ML/order/details?order_ids=$ORDER_ID`
- `GET /billing/integration/group/ML/order/details?order_ids=1234567890000`
- `GET /billing/integration/group/ML/perceptions/details`
- `GET /billing/integration/group/ML/perceptions/details?document_id=333555777&tax_type=CIVA&offset=1&limit=2&currency=USD`
- `GET /billing/integration/group/MP/perceptions/details`
- `GET /billing/integration/group/MP/perceptions/details?document_id=333555777&tax_type=CIVAMP&tax_id=12345&offset=1&limit=2&currency=USD`
- `GET /billing/integration/legal_document/$FILE_ID`
- `GET /billing/integration/legal_document/1234_FE_MEPF00869625_pdf`
- `GET /billing/integration/monthly/periods`
- `GET /billing/integration/monthly/periods?group=MP&document_type=BILL&offset=1&limit=2`
- `GET /billing/integration/payment/$PAYMENT_ID/charges`
- `GET /billing/integration/payment/111111abcde/charges`
- `GET /billing/integration/periods/key/{key}/documents`
- `GET /billing/integration/periods/key/{KEY}/group/ML/details`
- `GET /billing/integration/periods/key/{key}/group/ML/details?limit=1000&from_id=0`
- `GET /billing/integration/periods/key/{KEY}/group/MP/details`
- `GET /billing/integration/periods/key/{key}/summary/details`
- `GET /billing/integration/periods/key/$KEY/documents`
- `GET /billing/integration/periods/key/$KEY/group/ML/details`
- `GET /billing/integration/periods/key/$KEY/group/ML/flex/details`
- `GET /billing/integration/periods/key/$KEY/group/ML/full/details`
- `GET /billing/integration/periods/key/$KEY/group/ML/insurtech/details`
- `GET /billing/integration/periods/key/$KEY/group/ML/payment/details`
- `GET /billing/integration/periods/key/$KEY/group/MP/details`
- `GET /billing/integration/periods/key/$KEY/perceptions/summary`
- `GET /billing/integration/periods/key/$KEY/reports`
- `GET /billing/integration/periods/key/$KEY/summary/details`
- `GET /billing/integration/periods/key/2021-06-01/documents?group=MP&document_type=BILL&limit=1`
- `GET /billing/integration/periods/key/2021-06-01/group/ML/details?document_type=BILL&limit=1`
- `GET /billing/integration/periods/key/2021-08-01/perceptions/summary?group=MP`
- `GET /billing/integration/periods/key/2021-08-01/reports`
- `GET /billing/integration/periods/key/2022-10-01/group/ML/insurtech/details?document_type=BILL&limit=1`
- `GET /billing/integration/periods/key/2023-03-01/group/ML/flex/details?document_type=BILL&limit=1`
- `GET /billing/integration/periods/key/2023-03-01/group/ML/full/details?document_type=BILL&limit=1`
- `GET /billing/integration/periods/key/2023-08-01/group/ML/payment/details?limit=1`
- `GET /billing/integration/periods/key/2023-10-01/summary/details`
- `GET /billing/integration/periods/key/2024-05-01/group/MP/details?document_type=BILL&limit=1`
- `GET /billing/integration/periods/key/2024-11-01/group/MP/details?document_type=BILL&limit=1000&from_id=0`
- `GET /billing/integration/periods/key/2024-11-01/group/MP/details?document_type=BILL&limit=1000&from_id=12345678`
- `GET /billing/integration/reports/$FILE_ID`
- `GET /billing/integration/reports/$FILE_ID/status`
- `GET /billing/integration/reports/ML-report-BILL-2021-08-04-11119999-CSV-v2?document_type=BILL`
- `GET /billing/integration/reports/ML-report-BILL-2021-08-04-11119999-CSV-v2/status?document_type=BILL`
- `GET /billing/monthly/periods`
- `GET /block-api/search/users/72641919?type=blocked_by_questions`
- `GET /currency_conversions/search?from=$CURRENCY_ID&to=$CURRENCY_ID`
- `GET /currency_conversions/search?from=ARS&to=BRL`
- `GET /feedback/$FEEDBACK_ID`
- `GET /feedback/9041207884458`
- `GET /feedbacks/$feedback_id`
- `GET /items/:id`
- `GET /items/{item_id}/sale_price`
- `GET /items/{item_id}/sale_price:`
- `GET /moderations/infractions`
- `GET /my/received_questions/search`
- `GET /orders:`
- `GET /orders/:id`
- `GET /orders/{id}/discounts`
- `GET /orders/{order_id}/discounts:`
- `GET /orders/$id/discounts`
- `GET /orders/$ORDER_ID/discounts`
- `GET /orders/$order_id/feedback`
- `GET /orders/$ORDER_ID/feedback`
- `GET /orders/$ORDER_ID/notes`
- `GET /orders/$ORDER_ID/product`
- `GET /orders/2000003508419013`
- `GET /orders/2000003508419013/discounts`
- `GET /orders/2000003508419013/feedback`
- `GET /orders/2000003508419013/shipments`
- `GET /orders/2000008779458474`
- `GET /orders/2000010733434062`
- `GET /orders/billing-info/$SITE_ID/$BILLING_INFO.ID`
- `GET /orders/billing-info/MLB/677487519924852462`
- `GET /orders/search`
- `GET /orders/search?seller=$SELLER_ID&order.date_created.from=2015-07-01T00:00:00.000-00:00&order.date_created.to=2015-07-31T00:00:00.000-00:00`
- `GET /orders/search?seller=$SELLER_ID&order.status=paid`
- `GET /orders/search?seller=$SELLER_ID&order.status=paid&sort=date_desc`
- `GET /orders/search?seller=89660613&q=2032217210`
- `GET /packs:`
- `GET /packs/$PACK_ID`
- `GET /packs/$PACK_ID/fiscal_documents`
- `GET /packs/$PACK_ID/fiscal_documents/$FISCAL_DOCUMENT_ID`
- `GET /packs/2000000089077943/fiscal_documents`
- `GET /packs/2000000089077943/fiscal_documents/415460047_a96d8dea-38cd-4402-938e-80a1c134fc5d`
- `GET /packs/2000006181551917`
- `GET /payments/{id}`
- `GET /questions`
- `GET /questions:`
- `GET /questions/:id`
- `GET /questions/:id:`
- `GET /questions/$QUESTION_ID`
- `GET /questions/11751825075?api_version=4`
- `GET /questions/3957150025`
- `GET /questions/hidden:`
- `GET /questions/search`
- `GET /questions/search?item_id=$ITEM_ID&api_version=4`
- `GET /questions/search?item_id=MLA608007087`
- `GET /questions/search?item=$ITEM_ID`
- `GET /questions/search?item=$ITEM_ID&api_version=4`
- `GET /questions/search?item=$ITEM_ID&from=$CUST_ID&api_version=4`
- `GET /questions/search?item=MLB1623490410&api_version=4`
- `GET /questions/search?seller_id=$SELLER_ID&api_version=4`
- `GET /questions/search?seller_id=$SELLER_ID&sort_fields=item_id,date_created&api_version=4`
- `GET /questions/search?seller_id=$SELLER_ID&sort_fields=item_id,date_created&sort_types=ASC&api_version=4`
- `GET /questions/search?seller_id=419059118&api_version=4`
- `GET /seller-promotions/offers/{offer_id}:`
- `GET /shipments:`
- `GET /shipments/:shipping_id`
- `GET /shipments/$SHIPMENT_ID/process/ready_to_ship`
- `GET /shipments/$SHIPMENT_ID/seller_notifications`
- `GET /shipments/28264263908/seller_notifications`
- `GET /shipments/43664723386/process/ready_to_ship`
- `GET /shipments/shipping.id`
- `GET /users/:id`
- `GET /users/$SELLER_ID/questions_blacklist/$USER_ID`
- `GET /users/$USER_ID/questions/response_time`
- `GET /users/1111111/questions/response_time`
- `POST /answers`
- `POST /answers/:`
- `POST /billing/integration/periods/key/$KEY/reports`
- `POST /billing/integration/periods/key/2021-08-01/reports`
- `POST /feedback/$FEEDBACK_ID`
- `POST /feedback/$FEEDBACK_ID/reply`
- `POST /feedback/9041207884458`
- `POST /my/questions/hidden:`
- `POST /orders/`
- `POST /orders/$order_id/feedback`
- `POST /orders/$ORDER_ID/feedback`
- `POST /orders/$ORDER_ID/notes`
- `POST /orders/$ORDER_ID/notes/$NOTE_ID`
- `POST /orders/2000003508419013/feedback`
- _...42 endpoints adicionales en el JSON._

## Productos, ítems y catálogo

Cobertura: 45 páginas, 561 endpoints/rutas detectadas. Integraciones detectadas en contenido: Catálogo, Facturación, Mensajería, Mercado Ads, Mercado Envíos, Mercado Pago, Notificaciones, OAuth.

### Páginas fuente

- [Actualiza tus publicaciones](https://developers.mercadolibre.com.co/es_co/actualiza-tus-publicaciones) — estado: `ok`; endpoints: 5; fechas visibles: 12 de marzo de 2026, 06/11/2025; secciones: Actualiza tus publicaciones; Destacar una publicación; Modifica la localización de tu inmueble.; Actualiza la tienda oficial del Inmueble; Cambia el estado de tus publicaciones.; Guía para algunos campos; Elimina Publicaciones; Proceso para eliminar un ítem
- [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) — estado: `ok`; endpoints: 17; fechas visibles: —; secciones: Buscador de productos; Parámetros; Buscar por Part Number o Product ID; Producto de catálogo; Detalles de productos; Comportamientos especiales; Productos padres y hijos; Elegir el producto para publicar
- [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) — estado: `ok`; endpoints: 35; fechas visibles: —; secciones: Búsqueda de ítems; Resumen de los recursos disponibles; Valores en el campo available_quantity; available_quantity; Buscar ítems por vendedor; Obtener ítems de los listados por vendedor; Por ID de vendedor; Por nickname
- [Calidad de publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones) — estado: `ok`; endpoints: 8; fechas visibles: —; secciones: Calidad de publicaciones; Niveles de calidad por sitio; Detalle de la calidad por ítem; Detalle de la calidad por User Product; Campos de la respuesta; Errores
- [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) — estado: `ok`; endpoints: 22; fechas visibles: —; secciones: Campañas co-fondeada automatizada y campañas de precios competitivos; Consultar detalle de campaña; Campos de la respuesta; Estados; Consultar ítems en una campaña; Estado de los ítems; Indicar ítems para una campaña; Parámetros
- [Categorización de productos](https://developers.mercadolibre.com.co/es_co/categoriza-productos) — estado: `ok`; endpoints: 9; fechas visibles: 14 de diciembre de 2022; secciones: Categorización de productos; Predictor de categorías; Parámetros obligatorios; Parámetros opcionales; Campos de respuesta; Convertir de Domínio a Categoría; Categorías por site; Detalle de una categoría
- [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) — estado: `ok`; endpoints: 75; fechas visibles: 15/07/2026; secciones: Compatibilidades entre ítems y productos de Autopartes; Resumen de cambios del catálogo de compatibilidades; 1. Nueva distinción en las respuestas: source: SELLER vs source: CATALOGO; 2. Parámetro extended=true ignorado para compatibilidades de catálogo; 3. Detalle por ID no disponible para compatibilidades de catálogo; 4. Eliminación restringida a compatibilidades del vendedor; 5. Copiar y pegar no incluirá compatibilidades de catálogo; Verificar compatibilidad entre dominios
- [Competencia](https://developers.mercadolibre.com.co/es_co/competencia-en-catalogo) — estado: `ok`; endpoints: 7; fechas visibles: —; secciones: Competencia; Notificaciones por cambio de estado; Detalle de la competencia; Campos de la respuesta; Razones; Publicación ganadora
- [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) — estado: `ok`; endpoints: 19; fechas visibles: —; secciones: Costos por vender; Descripción de atributos; Obtener el costo de envío según nueva estructura; Parámetros:; Lógica de cálculo; Filtrar por precio; Filtrar por precio y listing type; Filtrar por precio y cantidad
- [Descripción de productos](https://developers.mercadolibre.com.co/es_co/descripcion-de-articulos) — estado: `ok`; endpoints: 5; fechas visibles: —; secciones: Descripción de productos; Consejos para describir una publicación; Consultar descripción de un ítem; Crear descripción en un ítem; Beneficios del texto plano; Editar descripción existente; Errores; Modificando una descripción existente
- [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item) — estado: `ok`; endpoints: 17; fechas visibles: —; secciones: Descuento pre-acordado por ítem y Campaña de liquidación stock Full; Consultar detalles de una campaña; Estados de las campañas; Consultar ítems en una campaña; Estado de los ítems; Aceptar descuento; Parámetros; Eliminar descuento
- [Diagnóstico de imágenes](https://developers.mercadolibre.com.co/es_co/diagnostico-imagenes) — estado: `ok`; endpoints: 1; fechas visibles: —; secciones: Diagnóstico de imágenes; Cuándo y cómo usarla; Qué es el picture_type y para qué sirve?; Respuesta; Campos de respuesta; Buenas prácticas y recomendaciones
- [Elegibilidad de catálogo](https://developers.mercadolibre.com.co/es_co/elegibilidad-catalogo) — estado: `ok`; endpoints: 11; fechas visibles: —; secciones: Elegibilidad de catálogo; Filtrar por vendedor; Elegibilidad por vendedor; Elegibilidad por item; Consideraciones; Descripción de campos; Elegibilidad en varios items
- [Gestión de stock en convivencia Full/Flex (MLA y MLC)](https://developers.mercadolibre.com.co/es_co/convivencia-full-y-flex) — estado: `ok`; endpoints: 8; fechas visibles: —; secciones: Gestión de stock en convivencia Full/Flex (MLA y MLC); Notificaciones; Obtener el stock de un ítem; Campos de la repuesta:; Modificar el stock de un ítem
- [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) — estado: `ok`; endpoints: 38; fechas visibles: —; secciones: Gestión de stock multiorigen / User Products
- [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) — estado: `ok`; endpoints: 32; fechas visibles: 18 de marzo de 2026; secciones: Gestionar automatizaciones; Qué cambia?; Identificación Previa; Obtener reglas disponibles para un ítem; Reglas; Pre condiciones para obtener las reglas disponibles para un ítem; Campos de la respuesta; Posibles errores al obtener las reglas disponibles
- [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) — estado: `ok`; endpoints: 23; fechas visibles: —; secciones: Gestionar guía de talles; Crear guía de talles personalizadas; Crear guía en dominios TOPS and BOTTOMS; Crear guías con medidas de prenda; Consultar una guía de talles; Agregar filas en guía de talles; Modificar fila en guía de talles; Modificar guía de talles
- [Gestionar precios por cantidad](https://developers.mercadolibre.com.co/es_co/precio-por-cantidad) — estado: `ok`; endpoints: 33; fechas visibles: 21 de julio de 2026; secciones: Gestionar precios por cantidad; Precios por cantidad B2B; Consideraciones; Identificar usuarios habilitados; Agregar, modificar y eliminar precio por cantidad; Posibles errores; Identificar publicaciones con precio por cantidad; Obtener precios del ítem con precio por cantidad
- [Gestionar referencias de precios](https://developers.mercadolibre.com.co/es_co/referencias-de-precios) — estado: `ok`; endpoints: 7; fechas visibles: 2024-06-16, 2024-07-20; secciones: Gestionar referencias de precios; Obtener items con referencias de precios por vendedor; Pre condiciones para obtener referencias de precios por vendedor; Obtener detalle de la referencia de precios por item_id; Pre condiciones para obtener referencias de precios; Campos de la respuesta; Posibles errores al consultar referencias de precios de un ítem
- [Guía para productos](https://developers.mercadolibre.com.co/es_co/guia-para-producto) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Guía para productos; Introducción
- [Identificadores de productos](https://developers.mercadolibre.com.co/es_co/identificadores-de-productos) — estado: `ok`; endpoints: 8; fechas visibles: —; secciones: Identificadores de productos; Tipos de GTIN; Lógica para el uso del atributo GTIN; Publicar con identificadores; Agregar identificadores; Razones de no enviar GTIN; Consultar identificadores en publicaciones; Consideraciones
- [Imágenes en publicaciones](https://developers.mercadolibre.com.co/es_co/trabajar-con-imagenes) — estado: `ok`; endpoints: 10; fechas visibles: —; secciones: Imágenes en publicaciones; Recomendaciones para subir imágenes; Validar y cargar una imagen; Vincular una imagen a tu artículo; Reemplazar imágenes; Revisa posibles errores; Formato de la imagen; Errores
- [Imágenes y moderaciones](https://developers.mercadolibre.com.co/es_co/imagenes-y-moderaciones) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Imágenes y moderaciones
- [Kits virtuales](https://developers.mercadolibre.com.co/es_co/kits-virtuales) — estado: `ok`; endpoints: 21; fechas visibles: —; secciones: Kits virtuales; Consideraciones especiales; Buscador de productos componentes; Filtros disponibles; Crear Kit virtual; Estructura de bundle; Body - Sin sincronización de precios; Body - Sincronización de precios
- [Moderaciones de imágenes](https://developers.mercadolibre.com.co/es_co/moderaciones-de-imagenes) — estado: `ok`; endpoints: 2; fechas visibles: 2022-03-22, 2025-04-30, 2022-06-18; secciones: Moderaciones de imágenes; Flujo recomendado para carga sin moderaciones; Consultar items con moderación de imágenes; Ejemplos de respuesta:
- [Notificaciones](https://developers.mercadolibre.com.co/es_co/productos-recibe-notificaciones) — estado: `ok`; endpoints: 62; fechas visibles: 31 de julio de 2026; secciones: Notificaciones; Configuración de notificaciones; URL de Retorno de Llamada (Callback URL):; Tópicos:; Tópicos; Estructura Modelo con Subtópicos:; Flujo de filtros/subtópicos; Tópicos disponibles
- [Opiniones de productos](https://developers.mercadolibre.com.co/es_co/opiniones-sobre-producto) — estado: `ok`; endpoints: 3; fechas visibles: —; secciones: Opiniones de productos; Campos de la respuesta; Consultar opiniones de ítem de catálogo
- [Prácticas, validaciones y requerimientos de seguridad para integradores](https://developers.mercadolibre.com.co/es_co/introduccion-seguridad) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Prácticas, validaciones y requerimientos de seguridad para integradores; Cómo leer esta documentación; Gobierno y cumplimiento; Introducción; Responsabilidad de Seguridad
- [Precios de productos](https://developers.mercadolibre.com.co/es_co/api-de-precios) — estado: `ok`; endpoints: 8; fechas visibles: 18 de marzo de 2026; secciones: Precios de productos; Notificaciones sobre precios; Obtener precio de venta actual; Descripción de los campos; Cuando la promoción está activa:; Cuando la promoción está programada:; Obtener precios del producto; Descripción de los campos
- [Precios netos por cantidad](https://developers.mercadolibre.com.co/es_co/precios-netos) — estado: `ok`; endpoints: 19; fechas visibles: —; secciones: Precios netos por cantidad; Identificar usuarios e ítems elegibles; Campos de la respuesta; Agregar, modificar y eliminar precios netos por cantidad; Parámetros obligatorios; Campos de la respuesta; Identificar publicaciones con precio neto por cantidad; Obtener precios del ítem con precio neto por cantidad
- [Product Ads para Catálogo y User Products](https://developers.mercadolibre.com.co/es_co/product-ads-para-catalogo-y-user-products-lectura) — estado: `ok`; endpoints: 44; fechas visibles: 27 de mayo de 2026, 2025-08-31, 2025-09-30, 30 de marzo de 2026, 2025-12-01, 2025-12-30, 2024-01-01, 2024-02-28, 30 de mayo de 2026, 2025-10-28, 2025-10-29, 2026-04-01, 2025-09-20, 2025-10-08, 2025-08-01; secciones: Product Ads para Catálogo y User Products; Tipos de agrupamiento en anuncios; Nuevo flujo de Product Ads con variantes unificadas; ¿Qué cambió en el flujo?; Antes:; Ahora:; Flujo técnico recomendado; Consultar anunciante
- [Productos reacondicionados](https://developers.mercadolibre.com.co/es_co/catalogo-reacondicionados) — estado: `ok`; endpoints: 4; fechas visibles: —; secciones: Productos reacondicionados; Catálogo de reacondicionados; Publicar reacondicionados; Publicar directo a catálogo refurbished; Publicar desde tradicional
- [Publicaciones denunciadas](https://developers.mercadolibre.com.co/es_co/publicaciones-denunciadas) — estado: `ok`; endpoints: 7; fechas visibles: 2022-04-01; secciones: Publicaciones denunciadas; Consultar ítems denunciados; Parámetros obligatorios; Campos de respuesta; Conoce los diferentes status:; Consultar detalle del ítem denunciado; Campos de respuesta; Conoce los diferentes tipos de denuncias:
- [Publicaciones requeridas](https://developers.mercadolibre.com.co/es_co/publicaciones-requeridas-en-catalogo) — estado: `ok`; endpoints: 12; fechas visibles: —; secciones: Publicaciones requeridas; Reconocer dominios previamente (DUMP); Campos de respuesta; Reconocer productos previamente; Dominios de venta exclusiva en catálogo; Moderaciones; Tag de preaviso para pruebas
- [Publicar en catálogo](https://developers.mercadolibre.com.co/es_co/publicacion-en-catalogo) — estado: `ok`; endpoints: 10; fechas visibles: —; secciones: Publicar en catálogo; Publicar directo; Optin desde un item tradicional; Variaciones en catálogo; Sincroniza condiciones de venta; Corrección de la sincronización de ítems; Consulta de sincronización de ítems; Sincronización de ítems
- [Publicar productos](https://developers.mercadolibre.com.co/es_co/publica-productos) — estado: `ok`; endpoints: 13; fechas visibles: 9 de septiembre de 2024; secciones: Publicar productos; Detalle de las publicaciones; Consultar productos; Atributos; Título; Descripción; Estado; Cantidad disponible
- [Qué es catálogo](https://developers.mercadolibre.com.co/es_co/que-es-catalogo) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Qué es catálogo
- [Republicar ítems](https://developers.mercadolibre.com.co/es_co/re-publica) — estado: `ok`; endpoints: 7; fechas visibles: —; secciones: Republicar ítems; Consultar estado y fecha de vencimiento de la publicación; Cerrar item; Republicar ítem; Republicar ítem con variaciones
- [Sincroniza y modifica publicaciones](https://developers.mercadolibre.com.co/es_co/producto-sincroniza-modifica-publicaciones) — estado: `ok`; endpoints: 12; fechas visibles: 18 de marzo de 2026; secciones: Sincroniza y modifica publicaciones; Consideraciones para actualizar ítems; Actualizar ítems; Descripciones; Imágenes; Tipos de publicación; Flujo y estados de las publicaciones; Los ítems pueden tener estado:
- [Stock distribuido](https://developers.mercadolibre.com.co/es_co/stock-distribuido) — estado: `ok`; endpoints: 12; fechas visibles: —; secciones: Stock distribuido; Tipos de stock; Obtener detalle de stock; Gestionar stock
- [Stock Multi Origen](https://developers.mercadolibre.com.co/es_co/stock-multi-origen) — estado: `ok`; endpoints: 14; fechas visibles: —; secciones: Stock Multi Origen; Identificar vendedor Multi-Warehouse; Gestión de depósitos; Búsqueda de depósitos (stores) de un usuario; Creación de ítems Multi-Warehouse; Consultar detalle de stock depósitos (User Products); Gestión de stock por ubicación
- [Validación de guía de talles](https://developers.mercadolibre.com.co/es_co/validacion-de-guia-de-talles) — estado: `ok`; endpoints: 2; fechas visibles: —; secciones: Validación de guía de talles; Recomendaciones para publicaciones de moda; Validaciones al crear una guía de talles; Validaciones al asociar una guía a una publicación; Moderaciones
- [Validaciones](https://developers.mercadolibre.com.co/es_co/validaciones) — estado: `ok`; endpoints: 3; fechas visibles: —; secciones: Validaciones; Descripción de los campos; Detalle
- [Validador de publicaciones](https://developers.mercadolibre.com.co/es_co/validador-de-publicaciones) — estado: `ok`; endpoints: 1; fechas visibles: —; secciones: Validador de publicaciones; Ejemplos de validación; Valida tu artículo; Valida un artículo con variaciones; Valida tu artículo inmueble; Referencia de códigos de error; Consideraciones
- [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones) — estado: `ok`; endpoints: 23; fechas visibles: 14 de diciembre de 2022; secciones: Variaciones; Beneficios; Consideraciones; Publicar ítems con variaciones; Atributos requeridos; Consultar variaciones; Agregar nuevas variaciones; Modificar variaciones

### Rutas del módulo

- `DELETE /catalog/charts/$CHART_ID`
- `DELETE /catalog/charts/124125`
- `DELETE /items/{item_id}/compatibilities`
- `DELETE /items/{item_id}/compatibilities/{compatibility_id}`
- `DELETE /items/MLA599099879/variations/10449631060`
- `DELETE /items/MLA658778048/variations/15092589430`
- `DELETE /pricing-automation/items/$ITEM_ID/automation`
- `DELETE /pricing-automation/items/MLA12345678/automation`
- `DELETE /seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&promotion_id=$PROMOTION_ID&offer_id=$OFFER_ID`
- `DELETE /seller-promotions/items/MLA1387793467?promotion_type=PRICE_MATCHING_MELI_ALL&promotion_id=P-MLA2072013&offer_id=OFFER-MLA1387793467-1000000151&app_version=v2`
- `DELETE /seller-promotions/items/MLB10203040?promotion_type=UNHEALTHY_STOCK&promotion_id=P-MLB12345&offer_id=MLB10203040-f588cf87-e298-498e-82ad-285b16dd11d5`
- `DELETE /seller-promotions/items/MLB1834747833?promotion_type=PRE_NEGOTIATED&promotion_id=P-MLM394001&offer_id=MLM1834747833-9eafadd4-16d2-49ae-b272-9a7a34585cb8`
- `DELETE /seller-promotions/items/MLB3538191898?promotion_type=SMART&promotion_id=P-MLB1812010&offer_id=OFFER-MLB3538191898-177685&app_version=v2`
- `DELETE /seller-promotions/items/MLB4048719074?promotion_type=PRICE_MATCHING&promotion_id=P-MLB2087012&offer_id=OFFER-MLB4048719074-10000001972&app_version=v2`
- `GET /$resource`
- `GET /$RESOURCE`
- `GET /advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ad_groups/search`
- `GET /advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ad_groups/search?date_to=2025-09-30&date_from=2025-08-01&limit=800&sort=desc&sort_by=clicks&metrics=CLICKS,PRINTS,COST,CPC,CTR,DIRECT_AMOUNT,INDIRECT_AMOUNT,TOTAL_AMOUNT,DIRECT_UNITS_QUANTITY,INDIRECT_UNITS_QUANTITY,UNITS_QUANTITY,DIRECT_ITEMS_QUANTITY,INDIRECT_ITEMS_QUANTITY,ADVERTISING_ITEMS_QUANTITY,ORGANIC_UNITS_QUANTITY,ORGANIC_UNITS_AMOUNT,ORGANIC_ITEMS_QUANTITY,ACOS,TACOS,SOV,CVR,ROAS&metrics_summary=true&filters[ad_group_id`
- `GET /advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ad_groups/search?filters[item_ids`
- `GET /advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/ads/search?filters[item_id`
- `GET /advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/campaigns/$CAMPAIGN_ID/ads/metrics?date_from=2025-10-28&date_to=2025-10-29&filters[item_ids`
- `GET /advertising/$ADVERTISER_SITE_ID/advertisers/$ADVERTISER_ID/product_ads/campaigns/search?limit=1&offset=0&date_from=2025-12-01&date_to=2025-12-30&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount&metrics_summary=true`
- `GET /advertising/$ADVERTISER_SITE_ID/product_ads/ad_groups/$AD_GROUP_ID`
- `GET /advertising/$ADVERTISER_SITE_ID/product_ads/ad_groups/$AD_GROUP_ID/ads?date_from=2025-09-20&date_to=2025-10-08&metrics=clicks,prints,cost,cpc,ctr,direct_amount,indirect_amount,total_amount,direct_units_quantity,indirect_units_quantity,units_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,organic_units_quantity,organic_units_amount,organic_items_quantity,acos,tacos,sov,cvr,roas`
- `GET /advertising/$ADVERTISER_SITE_ID/product_ads/campaigns/$CAMPAIGN_ID?date_from=2025-12-01&date_to=2025-12-30&metrics=clicks,prints,ctr,cost,cpc,acos,organic_units_quantity,organic_units_amount,organic_items_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,cvr,roas,sov,direct_units_quantity,indirect_units_quantity,units_quantity,direct_amount,indirect_amount,total_amount,impression_share,top_impression_share,lost_impression_share_by_budget,lost_impression_share_by_ad_rank,acos_benchmark`
- `GET /advertising/$ADVERTISER_SITE_ID/product_ads/campaigns/$CAMPAIGN_ID/ad_groups/metrics?date_from=2026-04-01&date_to=2026-04-01&metrics=clicks,prints,cost,cpc,ctr,direct_amount,indirect_amount,total_amount,direct_units_quantity,indirect_units_quantity,units_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,organic_units_quantity,organic_units_amount,organic_items_quantity,acos,sov,roas,cvr,tacos`
- `GET /advertising/MCO/product_ads/campaigns/355771832/ad_groups/metrics?date_from=2026-04-01&date_to=2026-04-01&metrics=clicks,prints,cost,cpc,ctr,direct_amount,indirect_amount,total_amount,direct_units_quantity,indirect_units_quantity,units_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,organic_units_quantity,organic_units_amount,organic_items_quantity,acos,sov,roas,cvr,tacos`
- `GET /advertising/MLA/advertisers/882927/product_ads/ad_groups/search?filters[item_ids`
- `GET /advertising/MLM/advertisers/12345/product_ads/campaigns/search?filters[status`
- `GET /advertising/MLM/advertisers/4622/product_ads/ad_groups/search?date_to=2025-09-30&date_from=2025-08-01&limit=800&sort=desc&sort_by=clicks&metrics=CLICKS,PRINTS,COST,CPC,CTR,DIRECT_AMOUNT,INDIRECT_AMOUNT,TOTAL_AMOUNT,DIRECT_UNITS_QUANTITY,INDIRECT_UNITS_QUANTITY,UNITS_QUANTITY,DIRECT_ITEMS_QUANTITY,INDIRECT_ITEMS_QUANTITY,ADVERTISING_ITEMS_QUANTITY,ORGANIC_UNITS_QUANTITY,ORGANIC_UNITS_AMOUNT,ORGANIC_ITEMS_QUANTITY,ACOS,TACOS,SOV,CVR,ROAS&metrics_summary=true&filters[ad_group_id`
- `GET /advertising/MLM/product_ads/ad_groups/1142185192/ads?date_from=2025-09-20&date_to=2025-10-08&metrics=clicks,prints,cost,cpc,ctr,direct_amount,indirect_amount,total_amount,direct_units_quantity,indirect_units_quantity,units_quantity,direct_items_quantity,indirect_items_quantity,advertising_items_quantity,organic_units_quantity,organic_units_amount,organic_items_quantity,acos,tacos,sov,cvr,roas`
- `GET /advertising/MLM/product_ads/ad_groups/65867?date_from=2025-08-31&date_to=2025-09-30&metrics=CLICKS,PRINTS,COST,CPC,CTR,DIRECT_AMOUNT,INDIRECT_AMOUNT,TOTAL_AMOUNT,DIRECT_UNITS_QUANTITY,INDIRECT_UNITS_QUANTITY,UNITS_QUANTITY,DIRECT_ITEMS_QUANTITY,INDIRECT_ITEMS_QUANTITY,ADVERTISING_ITEMS_QUANTITY,ORGANIC_UNITS_QUANTITY,ORGANIC_UNITS_AMOUNT,ORGANIC_ITEMS_QUANTITY,ACOS`
- `GET /catalog_compatibilities/products_search/count_family_products`
- `GET /catalog_compatibilities/products_search/new?categoryId=$CATEGORY_ID`
- `GET /catalog_compatibilities/restrictions/values?main_domain_id=MLA-CARS_AND_VANS&secondary_domain_id=MLA-VEHICLE_ENGINE_MOUNTS`
- `GET /catalog_compatibilities/restrictions/values?main_domain_id=MLA-CARS_AND_VANS&secondary_domain_id=MLA-VEHICLE_SHOCK_ABSORBERS`
- `GET /catalog_domains/DOMAIN_ID/categories`
- `GET /catalog_domains/MLA-CELLPHONES/categories`
- `GET /catalog_domains/MLB-CARS_AND_VANS/compatibilities/cards`
- `GET /catalog_forewarning/date`
- `GET /catalog_suggestions/$SUGGESTION_ID`
- `GET /catalog_suggestions/MLA123456`
- `GET /catalog/charts/$CHART_ID`
- `GET /catalog/charts/232382`
- `GET /catalog/dumps/domains/$SITE_ID/compatibilities`
- `GET /catalog/dumps/domains/MLB/catalog_only`
- `GET /catalog/dumps/domains/MLB/catalog_required`
- `GET /catalog/dumps/domains/MLB/compatibilities`
- `GET /categories/$CATEGORY_ID`
- `GET /categories/$CATEGORY_ID/sale_terms`
- `GET /categories/MLA126186/attributes`
- `GET /categories/MLA1577`
- `GET /categories/MLA1577/sale_terms`
- `GET /categories/MLA1642/sale_terms`
- `GET /categories/MLA30835/attributes`
- `GET /categories/MLA3530`
- `GET /categories/MLM167991/sale_terms`
- `GET /claims/5108684499`
- `GET /claims/search?reason_id=$reason_id`
- `GET /collections/$PAYMENT_ID`
- `GET /collections/3043111111`
- `GET /compats-snapshots/orders/$ORDER_ID`
- `GET /compats-snapshots/orders/2000006372967416`
- `GET /compats-snapshots/orders/2000006372967424`
- `GET /compats-snapshots/orders/2000006372967684`
- `GET /flex/sites/$SITE_ID/shipments/$SHIPMENT_ID/assignment/v1`
- `GET /item/$ITEM_ID/performance`
- `GET /item/MLA1435540505/performance`
- `GET /items?ids=$ITEM_ID1`
- `GET /items?ids=$ITEM_ID1,$ITEM_ID2`
- `GET /items?ids=$ITEM_ID1,$ITEM_ID2&attributes=$ATTRIBUTE1,$ATTRIBUTE2,$ATTRIBUTE3`
- `GET /items?ids=MLA599260060,MLA594239600`
- `GET /items?ids=MLA599260060,MLA594239600&attributes=id,price,category_id,title`
- `GET /items/{item_id`
- `GET /items/{Item_id`
- `GET /items/{item_id}`
- `GET /items/{item_id}:`
- `GET /items/{item_id}/compatibilities`
- `GET /items/{item_id}/compatibilities?extended=true`
- `GET /items/{item_id}/compatibilities/{compatibility_id}`
- `GET /items/{itemId}/details`
- `GET /items/{itemId}/sale_price`
- `GET /items/$ITEM_ID/automation`
- `GET /items/$ITEM_ID/bundle/prices_configuration`
- `GET /items/$ITEM_ID/catalog_forewarning/date`
- `GET /items/$ITEM_ID/catalog_listing_eligibility`
- `GET /items/$ITEM_ID/compatibilities`
- `GET /items/$ITEM_ID/compatibilities?extended=true`
- `GET /items/$ITEM_ID/compatibilities/$COMPATIBILITY_ID`
- `GET /items/$ITEM_ID/compatibilities/$COMPATIBILITY_ID/note`
- `GET /items/$item_id/compatibilities/exception`
- `GET /items/$ITEM_ID/description?api_version=2`
- `GET /items/$ITEM_ID/details`
- `GET /items/$ITEM_ID/price_to_win`
- `GET /items/$ITEM_ID/price_to_win?SITE_ID&version=v2`
- `GET /items/$ITEM_ID/price/history`
- `GET /items/$ITEM_ID/prices`
- `GET /items/$ITEM_ID/prices?display_version=true`
- `GET /items/$ITEM_ID/prices/price-per-quantity`
- `GET /items/$ITEM_ID/prices/standard`
- `GET /items/$ITEM_ID/prices/standard/quantity`
- `GET /items/$ITEM_ID/rules`
- `GET /items/$ITEM_ID/sale_price?context=$CHANNEL,LOYALTY_LEVEL`
- `GET /items/$ITEM_ID/sale_price?context=$CONTEXT`
- `GET /items/$ITEM_ID/sale_price?context=channel_marketplace`
- `GET /items/$ITEM_ID/sale_price?quantity=5`
- `GET /items/$ITEM_ID/variations`
- `GET /items/$ITEMS_ID/prices`
- `GET /items/$ITEMS_ID/sale_price?context=$CONTEXTS&quantity`
- `GET /items/$ITEMS_ID/sale_price?context=$CONTEXTS&quantity=$CANTIDAD`
- `GET /items/catalog_domains/$DOMAIN_ID/compatibilities/cards`
- `GET /items/compatibilities_summary`
- `GET /items/ITEM_ID/price_to_win`
- `GET /items/kits`
- `GET /items/MLA000000?include_attributes=all`
- `GET /items/MLA1136716168`
- `GET /items/MLA1150086340`
- `GET /items/MLA1234567/price_to_win?version=v2`
- `GET /items/MLA123456789/catalog_listing_eligibility`
- `GET /items/MLA1417560910`
- `GET /items/MLA3191390879/sale_price?context=channel_marketplace,buyer_loyalty_3`
- `GET /items/MLA456789/price_to_win?version=v2`
- `GET /items/MLA599099879/variations/10449631060`
- `GET /items/MLA640992661?include_attributes=all`
- `GET /items/MLA658778048`
- `GET /items/MLA658778048?attributes=variations`
- `GET /items/MLA658778048/variations`
- `GET /items/MLA658778048/variations/15092589430`
- `GET /items/MLA686791111`
- `GET /items/MLA765432/price_to_win?version=v2`
- `GET /items/MLA794706391/compatibilities`
- `GET /items/MLA820048955`
- `GET /items/MLA821614634/relist`
- `GET /items/MLA830570458/catalog_forewarning/date`
- `GET /items/MLA832998780`
- `GET /items/MLA832998780/relist`
- `GET /items/MLA935110000/description`
- `GET /items/MLA9876543/price_to_win?version=v2`
- `GET /items/MLB1234/catalog_listing_eligibility`
- `GET /items/MLB123450000/prices/standard/quantity`
- _...421 endpoints adicionales en el JSON._

## Promociones y pricing

Cobertura: 11 páginas, 109 endpoints/rutas detectadas. Integraciones detectadas en contenido: Mercado Pago, OAuth.

### Páginas fuente

- [Campaña co-fondeada para PIX](https://developers.mercadolibre.com.co/es_co/pix) — estado: `ok`; endpoints: 8; fechas visibles: —; secciones: Campaña co-fondeada para PIX; Consultar detalle de la campaña; Consultar ítems de una campaña; Indicar ítems para una campaña; Modificar ítems; Eliminar Campaña; Errores
- [Campañas co-fondeadas](https://developers.mercadolibre.com.co/es_co/campanas-co-fondeadas) — estado: `ok`; endpoints: 10; fechas visibles: —; secciones: Campañas co-fondeadas; Consultar detalle de la campaña; Campos específicos de esta campaña; Estados; Consultar ítems en una campaña; Estado de los ítems; Indicar ítems para una campaña; Parámetros
- [Campañas con descuento por cantidad](https://developers.mercadolibre.com.co/es_co/campanas-con-descuento-por-cantidad) — estado: `ok`; endpoints: 18; fechas visibles: —; secciones: Campañas con descuento por cantidad; Vista del vendedor; Crear campaña; Actualizar la campaña; Eliminar campaña; Consultar detalles de una campaña; Campos específicos de esta campaña; Estados
- [Campañas de cupones del vendedor](https://developers.mercadolibre.com.co/es_co/cupones-del-vendedor) — estado: `ok`; endpoints: 16; fechas visibles: —; secciones: Campañas de cupones del vendedor; Para ofrecer este descuento es necesario:; Crear campaña; Campos de la llamada; Campos de la respuesta; Actualizar campaña; Campos que se puede actualizar:; Eliminar campaña
- [Campañas del vendedor](https://developers.mercadolibre.com.co/es_co/campanas-del-vendedor) — estado: `ok`; endpoints: 17; fechas visibles: —; secciones: Campañas del vendedor; Crear campaña; Campos de la llamada; Actualizar campaña; Eliminar campaña; Consultar detalle de campaña; Campos de la respuesta; Estados
- [Campañas tradicionales](https://developers.mercadolibre.com.co/es_co/deals) — estado: `ok`; endpoints: 14; fechas visibles: —; secciones: Campañas tradicionales; Consultar detalles de una campaña; Estados; Consultar ítems de una campaña; Campos de la respuesta; Estado de los ítems; Sugerencia de descuentos para promociones; Indicar ítems para una campaña
- [Descuento individual](https://developers.mercadolibre.com.co/es_co/descuento-individual) — estado: `ok`; endpoints: 8; fechas visibles: —; secciones: Descuento individual; Para ofrecer este descuento es necesario:; Ofrecer descuento; Parámetros; Consideraciones; Estado del ítem; Eliminar descuento individual a un ítem; Errores
- [Gestionar promociones](https://developers.mercadolibre.com.co/es_co/central-de-promociones) — estado: `ok`; endpoints: 26; fechas visibles: —; secciones: Gestionar promociones; Características de las promociones; Disponibilidad por país; Promociones del vendedor; Campos de la respuesta; Consultar ítems candidatos; Consultar ofertas; Campos de la respuesta
- [Ofertas del día](https://developers.mercadolibre.com.co/es_co/ofertas-del-dia) — estado: `ok`; endpoints: 10; fechas visibles: —; secciones: Ofertas del día; Consultar ítems de la campaña; Estado del ítem; Indicar ítems; Eliminar ítems
- [Ofertas relámpago](https://developers.mercadolibre.com.co/es_co/ofertas-relampago) — estado: `ok`; endpoints: 11; fechas visibles: —; secciones: Ofertas relámpago; Consultar ítems; Estado del ítem; Indicar ítems; Eliminar ítems
- [Promotions / Pricing](https://developers.mercadolibre.com.co/es_co/promotions-pricing) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Promotions / Pricing

### Rutas del módulo

- `DELETE /seller-promotions/items/$ITEM_ID?app_version=v2`
- `DELETE /seller-promotions/items/$ITEM_ID?app_version=v2&promotion_type=$PROMOTION_TYPE`
- `DELETE /seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&app_version=v2`
- `DELETE /seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&promotion_id=$PROMOTION_ID&app_version=v2`
- `DELETE /seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&promotion_id=$PROMOTION&app_version=v2`
- `DELETE /seller-promotions/items/$ITEM_ID?promotion_type=BANK&promotion_id=$PROMOTION_ID&offer_id=$OFFER_ID&app_version=v2`
- `DELETE /seller-promotions/items/$ITEM_ID?promotion_type=SELLER_COUPON_CAMPAIGN&promotion_id=$PROMOTION_ID&app_version=v2`
- `DELETE /seller-promotions/items/MLA1399846831?app_version=v2`
- `DELETE /seller-promotions/items/MLA632979587??app_version=v2&promotion_type=DOD`
- `DELETE /seller-promotions/items/MLA632979587?app_version=v2&promotion_type=LIGHTNING`
- `DELETE /seller-promotions/items/MLA632979587?promotion_type=MARKETPLACE_CAMPAIGN&promotion_id=1804&offer_id=MLA876618673-9eafadd4-16d2-49ae-b272-9a7a34585cb8&app_version=v2`
- `DELETE /seller-promotions/items/MLA632979587?promotion_type=VOLUME&promotion_id=1804&offer_id=MLA876618673-9eafadd4-16d2-49ae-b272-9a7a34585cb8&app_version=v2`
- `DELETE /seller-promotions/items/MLA876768946?promotion_type=PRICE_DISCOUNT&app_version=v2`
- `DELETE /seller-promotions/items/MLB123456789?promotion_type=SELLER_COUPON_CAMPAIGN&promotion_id=C-MLB1081&app_version=v2`
- `DELETE /seller-promotions/items/MLB3295112047?promotion_type=DEAL&promotion_id=P-MLB1806019&app_version=v2`
- `DELETE /seller-promotions/items/MLB3538191898?promotion_type=SELLER_CAMPAIGN&promotion_id=C-MLB302`
- `DELETE /seller-promotions/promotions/{{Promo-ID`
- `DELETE /seller-promotions/promotions/$PROMOTION_ID?promotion_type=SELLER_CAMPAIGN&app_version=v2`
- `DELETE /seller-promotions/promotions/$PROMOTION_ID?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2`
- `DELETE /seller-promotions/promotions/C-MLB1234?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2`
- `DELETE /seller-promotions/promotions/C-MLB360923?promotion_type=SELLER_CAMPAIGN&app_version=v2`
- `GET /seller-promotions`
- `GET /seller-promotions/candidates`
- `GET /seller-promotions/candidates/$CANDIDATE_ID?app_version=v2`
- `GET /seller-promotions/candidates/CANDIDATE-MLB1254949426-803130663?app_version=v2`
- `GET /seller-promotions/exclusion-list/item?app_version=v2`
- `GET /seller-promotions/exclusion-list/seller?app_version=v2`
- `GET /seller-promotions/exclusion-list/seller/{item_id`
- `GET /seller-promotions/items/$ITEM_ID?app_version=v2&promotion_type=$PROMOTION_TYPE`
- `GET /seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&promotion_id=$PROMOTION_ID&app_version=v2`
- `GET /seller-promotions/items/$ITEM_ID?promotion_type=BANK&promotion_id=$PROMOTION_ID&offer_id=$OFFER_ID&app_version=v2`
- `GET /seller-promotions/items/MLA1399846831?app_version=v2`
- `GET /seller-promotions/items/MLA1658866847?app_version=v2`
- `GET /seller-promotions/items/MLA632979587??app_version=v2&promotion_type=DOD`
- `GET /seller-promotions/items/MLA632979587?app_version=v2&promotion_type=LIGHTNING`
- `GET /seller-promotions/items/MLA632979587?promotion_type=MARKETPLACE_CAMPAIGN&promotion_id=1804&offer_id=MLA876618673-9eafadd4-16d2-49ae-b272-9a7a34585cb8&app_version=v2`
- `GET /seller-promotions/items/MLA876768946?app_version=v2`
- `GET /seller-promotions/items/MLB3293401659?app_version=v2`
- `GET /seller-promotions/items/MLB3293401743?app_version=v2`
- `GET /seller-promotions/items/MLB3293481659?app_version=v2`
- `GET /seller-promotions/items/MLB3295112047?app_version=v2`
- `GET /seller-promotions/items/MLB3295112047?promotion_type=DEAL&promotion_id=P-MLB1806019&app_version=v2`
- `GET /seller-promotions/offers`
- `GET /seller-promotions/offers/$OFFERS_ID?app_version=v2`
- `GET /seller-promotions/offers/OFFER-MLB1970246686-42701792?app_version=v2`
- `GET /seller-promotions/promotions/$PROMOTION_ID?promotion_type=$PROMOTION_TYPE&app_version=v2`
- `GET /seller-promotions/promotions/$PROMOTION_ID?promotion_type=BANK&app_version=v2`
- `GET /seller-promotions/promotions/$PROMOTION_ID/items?app_version=v2&promotion_type=LIGHTNING`
- `GET /seller-promotions/promotions/$PROMOTION_ID/items?promotion_type=$PROMOTION_TYPE&app_version=v2`
- `GET /seller-promotions/promotions/$PROMOTION_ID/items?promotion_type=$PROMOTION_TYPE&app_version=v2&limit=50&search_after={$SEARCH_AFTER`
- `GET /seller-promotions/promotions/$PROMOTION_ID/items?promotion_type=$PROMOTION_TYPE&status=$STATUS&item_id=$ITEM_ID&app_version=v2`
- `GET /seller-promotions/promotions/$PROMOTION_ID/items?promotion_type=BANK&app_version=v2`
- `GET /seller-promotions/promotions/$PROMOTION_ID/items?promotion_type=DOD&app_version=v2`
- `GET /seller-promotions/promotions/$PROMOTION_ID/items?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2`
- `GET /seller-promotions/promotions/C-MLB300?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2`
- `GET /seller-promotions/promotions/C-MLB300/items?promotion_type=SELLER_CAMPAIGN&app_version=v2`
- `GET /seller-promotions/promotions/C-MLB302?promotion_type=SELLER_CAMPAIGN&app_version=v2`
- `GET /seller-promotions/promotions/DOD-MLB1000/items?promotion_type=DOD&app_version=v2`
- `GET /seller-promotions/promotions/LGH-MLB1000/items?app_version=v2&promotion_type=LIGHTNING`
- `GET /seller-promotions/promotions/MLA1111/items?promotion_type=DEAL&item_id=MLA604400000&app_version=v2`
- `GET /seller-promotions/promotions/MLA1111/items?promotion_type=DEAL&status_item=active&app_version=v2`
- `GET /seller-promotions/promotions/MLA1111/items?promotion_type=DEAL&status=started&app_version=v2`
- `GET /seller-promotions/promotions/P-MLB1806015?promotion_type=MARKETPLACE_CAMPAIGN&app_version=v2`
- `GET /seller-promotions/promotions/P-MLB1806015/items?promotion_type=MARKETPLACE_CAMPAIGN&app_version=v2`
- `GET /seller-promotions/promotions/P-MLB1806017?promotion_type=VOLUME&app_version=v2`
- `GET /seller-promotions/promotions/P-MLB1806017/items?promotion_type=VOLUME&app_version=v2`
- `GET /seller-promotions/promotions/P-MLB1806019?promotion_type=DEAL&app_version=v2`
- `GET /seller-promotions/promotions/P-MLB1806019/items?promotion_type=DEAL&app_version=v2`
- `GET /seller-promotions/users/$USER_ID?app_version=v2`
- `GET /seller-promotions/users/1356551933?app_version=v2`
- `POST /seller-promotions/exclusion-list/item?app_version=v2`
- `POST /seller-promotions/exclusion-list/seller?app_version=v2`
- `POST /seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&app_version=v2`
- `POST /seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&promotion_id=$PROMOTION_ID&offer_id=$OFFER_ID`
- `POST /seller-promotions/items/$ITEM_ID?promotion_type=$PROMOTION_TYPE&promotion_id=$PROMOTION&app_version=v2`
- `POST /seller-promotions/items/$ITEM_ID?promotion_type=SELLER_COUPON_CAMPAIGN&promotion_id=$PROMOTION_ID&app_version=v2`
- `POST /seller-promotions/items/MLA632979587?promotion_type=VOLUME&promotion_id=1804&offer_id=MLA876618673-9eafadd4-16d2-49ae-b272-9a7a34585cb8&app_version=v2`
- `POST /seller-promotions/items/MLA876768946?app_version=v2`
- `POST /seller-promotions/items/MLA876768946?promotion_type=PRICE_DISCOUNT&app_version=v2`
- `POST /seller-promotions/items/MLB123456789?app_version=v2`
- `POST /seller-promotions/items/MLB123456789?promotion_type=SELLER_COUPON_CAMPAIGN&promotion_id=C-MLB1081&app_version=v2`
- `POST /seller-promotions/items/MLB1834747833&app_version=v2`
- `POST /seller-promotions/items/MLB3293401659?app_version=v2`
- `POST /seller-promotions/items/MLB3293401743?app_version=v2`
- `POST /seller-promotions/items/MLB3293481659?app_version=v2`
- `POST /seller-promotions/items/MLB3295112047?app_version=v2`
- `POST /seller-promotions/items/MLB3538191898?promotion_type=SELLER_CAMPAIGN&promotion_id=C-MLB302`
- `POST /seller-promotions/promotions?app_version=v2`
- `POST /seller-promotions/promotions?app_version=v2&version=test`
- `POST /seller-promotions/promotions/{{Promo-ID`
- `POST /seller-promotions/promotions/$PROMOTION_ID?app_version=v2`
- `POST /seller-promotions/promotions/$PROMOTION_ID?promotion_type=SELLER_CAMPAIGN&app_version=v2`
- `POST /seller-promotions/promotions/$PROMOTION_ID?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2`
- `POST /seller-promotions/promotions/$PROMOTION_ID/items?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2`
- `POST /seller-promotions/promotions/C-MLB1234?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2`
- `POST /seller-promotions/promotions/C-MLB300?promotion_type=SELLER_COUPON_CAMPAIGN&app_version=v2`
- `POST /seller-promotions/promotions/C-MLB300/items?promotion_type=SELLER_CAMPAIGN&app_version=v2`
- `POST /seller-promotions/promotions/C-MLB302?promotion_type=SELLER_CAMPAIGN&app_version=v2`
- `POST /seller-promotions/promotions/C-MLB360923?promotion_type=SELLER_CAMPAIGN&app_version=v2`
- `POST /seller-promotions/promotions/C-MLB5783?app_version=v2&version=test`
- `POST /seller-promotions/promotions/P-MLB1806017?promotion_type=VOLUME&app_version=v2`
- `POST /seller-promotions/promotions/P-MLB1806017/items?promotion_type=VOLUME&app_version=v2`
- `PUT /seller-promotions/items/$ITEM_ID?app_version=v2`
- `PUT /seller-promotions/items/MLB3295112047?app_version=v2`
- `PUT /seller-promotions/items/MLB3538191898?app_version=v2`
- `PUT /seller-promotions/promotions/$PROMOTION_ID?app_version=v2`
- `PUT /seller-promotions/promotions/C-MLB5783?app_version=v2&version=test`
- `UNKNOWN /seller-promotions/promotions/{{Promo-ID`
- `UNKNOWN /seller-promotions/promotions/C-MLB5783?app_version=v2&version=test`

## Reputación, métricas y calidad

Cobertura: 6 páginas, 47 endpoints/rutas detectadas. Integraciones detectadas en contenido: Catálogo, Facturación, Mensajería, Mercado Ads, Mercado Envíos, Mercado Pago, Notificaciones, OAuth.

### Páginas fuente

- [Experiencia de compra](https://developers.mercadolibre.com.co/es_co/experiencia-de-compra) — estado: `ok`; endpoints: 4; fechas visibles: —; secciones: Experiencia de compra; Parámetro requeridos; Campos de la respuesta; Campos y componentes de la respuesta; Posibles errores; Ejemplos de casos de uso; Detalle de problemas; Distribución con tooltip
- [Pedidos y opiniones](https://developers.mercadolibre.com.co/es_co/pedidos-y-opiniones) — estado: `ok`; endpoints: 24; fechas visibles: 01/01/2001; secciones: Pedidos y opiniones; Buscar los pedidos por vendedor; Respuesta; Buscar pedido por vendedor; Respuesta; Buscar pedidos por comprador; Respuesta; Devuelve datos de un pago
- [Programa de Despegue y Beneficio de Reputación](https://developers.mercadolibre.com.co/es_co/recuperacion-reputacion) — estado: `ok`; endpoints: 7; fechas visibles: —; secciones: Programa de Despegue y Beneficio de Reputación; Flujo técnico; Notificaciones; Consultar reputación; Conocer detalle del Programa; Campos de respuesta; Activar programa; Desactivar programa
- [Tendencias](https://developers.mercadolibre.com.co/es_co/tendencias) — estado: `ok`; endpoints: 5; fechas visibles: —; secciones: Tendencias; Consultar tendencias por país; Consultar tendencias por país y categoría
- [Tiendas Oficiales](https://developers.mercadolibre.com.co/es_co/tienda-oficial) — estado: `ok`; endpoints: 7; fechas visibles: —; secciones: Tiendas Oficiales; Acceso a los IDs de sus marcas; Campos de respuesta; Accede a toda la información sobre una marca específica; Errores comunes en la respuesta de la API al publicar en Tiendas Oficiales multimarca
- [Visitas](https://developers.mercadolibre.com.co/es_co/recurso-de-visitas) — estado: `ok`; endpoints: 10; fechas visibles: 2021-01-01, 2021-02-01, 2021-08-06; secciones: Visitas; Descripción de parámetros; Campos de respuesta; Visitas totales por usuario; Visitas totales por artículo; Visitas por artículo entre rangos de fecha; Visitas con fecha por usuario; Visitas con fecha por artículo

### Rutas del módulo

- `GET /block-api/search/users/123456?type=blocked_by_order`
- `GET /feedback/$FEEDBACK_ID/reply`
- `GET /feedback/9040351529869`
- `GET /feedback/9040351529869/reply`
- `GET /items/$ITEM_ID/visits/time_window?last=$LAST&unit=$UNIT&ending=$ENDING`
- `GET /items/MCO471870973/visits/time_window?last=2&unit=day&ending=2021-08-06`
- `GET /items/visits?ids=MCO473861358&date_from=2021-01-01&date_to=2021-02-01`
- `GET /orders/1068825849/feedback`
- `GET /orders/search?buyer=$BUYER_ID`
- `GET /orders/search?buyer=207040551`
- `GET /orders/search?seller=$SELLER_ID`
- `GET /orders/search?seller=$SELLER_ID&q=$ORDER_ID`
- `GET /orders/search?seller=207035636`
- `GET /orders/search?seller=207035636&q=`
- `GET /payments/$PAYMENT_ID`
- `GET /payments/28382111111`
- `GET /reputation/items/$ITEM_ID/purchase_experience/integrators`
- `GET /reputation/items/MLA1391786841/purchase_experience/integrators?locale=es_AR`
- `GET /reputation/user_products/{UP_ID`
- `GET /reputation/user_products/MLAU1391786841/purchase_experience/integrators?locale=es_AR`
- `GET /sites/$SITE_ID/payment_methods`
- `GET /sites/MLA/payment_methods`
- `GET /sites/MLA/payment_methods/amex`
- `GET /trends`
- `GET /trends/$SITE_ID`
- `GET /trends/$SITE_ID/$CATEGORY_ID`
- `GET /trends/MLA`
- `GET /trends/MLA/MLA1246`
- `GET /users/:userID/order_blacklist?offset=100&limit=50`
- `GET /users/$USER_ID/brands/$BRAND`
- `GET /users/1000011398/items_visits?date_from=2021-01-01&date_to=2021-02-01`
- `GET /users/1000011398/items_visits/time_window?last=2&unit=day`
- `GET /users/1477536226/brands/14501111111`
- `GET /users/1477536226/brands/aaaaa`
- `GET /users/14775362261111111/brands`
- `GET /users/2275117700/brands`
- `GET /users/2275117700/brands/294894`
- `GET /users/reputation/seller_recovery/activate`
- `GET /users/reputation/seller_recovery/cancel_guarantee`
- `GET /users/reputation/seller_recovery/legal-document?type=(PREVIEW|COMPLETE`
- `GET /users/reputation/seller_recovery/status`
- `GET /visits/items?ids=MLB9992242141`
- `POST /feedback/9040351529869/reply`
- `POST /orders/1068825849/feedback`
- `POST /users/reputation/seller_recovery/activate`
- `PUT /feedback/9040351529869`
- `PUT /users/reputation/seller_recovery/cancel_guarantee`

## Seguridad

Cobertura: 5 páginas, 3 endpoints/rutas detectadas. Integraciones detectadas en contenido: Mercado Pago, Notificaciones, OAuth.

### Páginas fuente

- [Control de acceso y autorización](https://developers.mercadolibre.com.co/es_co/control-de-acceso-y-autorizacion) — estado: `ok`; endpoints: 4; fechas visibles: —; secciones: Control de acceso y autorización; Principios de autorización; Mínimo privilegio:; Denegación por defecto:; Verificación en backend:; Verificación en cada petición:; Segregación por seller; Gestión de permisos
- [Gestión de incidentes](https://developers.mercadolibre.com.co/es_co/gestion-de-incidentes) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Gestión de incidentes; El impacto de una respuesta lenta:; Clasificación de incidentes por severidad; El ciclo de respuesta a incidentes (Framework NIST); Roles y responsabilidad durante un incidente; Comunicación durante incidentes; Comunicación interna:; Comunicación externa: sellers / usuarios afectados:
- [Infraestructura: Cifrado y seguridad de transporte](https://developers.mercadolibre.com.co/es_co/infraestructura) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Infraestructura: Cifrado y seguridad de transporte; ¿Qué protegemos con TLS?; Una implementación incorrecta de TLS puede resultar en:; Requisitos de TLS; Cipher suites recomendadas; Para TLS 1.3 (idealmente); Para TLS 1.2 (aceptadas); Cipher suites no aceptadas
- [Monitoreo](https://developers.mercadolibre.com.co/es_co/monitoreo) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Monitoreo; Registro de eventos y auditoría; Eventos recomendados a registrar; Información importante a incluir:; Información a NO incluir:; Referencias; Glosario; Gestión de anomalías
- [Seguridad en Integraciones](https://developers.mercadolibre.com.co/es_co/seguridad-desarrollo-seguro) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Seguridad en Integraciones; Resumen; 1.1: Limpieza, Rotación y Quick Wins Técnicos; Eliminación de Secretos y Rotación (Crítico); MFA Obligatorio e Inmediato; Quick Win: Análisis de Dependencias (SCA); Quick Win: Headers de Seguridad; 2.2: Autenticación, Fuerza Bruta y PII

### Rutas del módulo

- `UNKNOWN /orders/{order_id}`
- `UNKNOWN /orders/12345`
- `UNKNOWN /orders/123456`

## Servicios

Cobertura: 7 páginas, 20 endpoints/rutas detectadas. Integraciones detectadas en contenido: Catálogo, Mercado Pago, OAuth.

### Páginas fuente

- [Administra áreas de cobertura](https://developers.mercadolibre.com.co/es_co/administra-areas-de-cobertura) — estado: `ok`; endpoints: 4; fechas visibles: —; secciones: Administra áreas de cobertura; Áreas de cobertura por país; Áreas de cobertura por ID; Agrega áreas de cobertura
- [Consultas avanzadas](https://developers.mercadolibre.com.co/es_co/consultas-avanzadas-2) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Consultas avanzadas
- [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/servicios-consulta-usuarios) — estado: `ok`; endpoints: 12; fechas visibles: —; secciones: Consultas sobre el usuario; Consultar mis datos personales; Consultar información pública de un usuario; Consultar información privada de un usuario que ha aceptado el uso de mi aplicación; Actualizar datos de usuario; Endpoint block-api/search/users: Consultar usuários bloqueados para orders y question:; Ejemplo de request: blocked_by_questions; Ejemplo de request: blocked_by_order
- [Elige tipo de servicio](https://developers.mercadolibre.com.co/es_co/elige-tipo-de-servicio) — estado: `ok`; endpoints: 6; fechas visibles: —; secciones: Elige tipo de servicio; Categorías por Site; Categorías JSON; Atributos específicos de las categorías; Nombre; Ruta de la raíz; Elige la mejor categoría para tu producto
- [Guía para Servicios](https://developers.mercadolibre.com.co/es_co/guia-para-servicios) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Guía para Servicios
- [Publica servicios](https://developers.mercadolibre.com.co/es_co/publica-servicios-vis) — estado: `ok`; endpoints: 7; fechas visibles: —; secciones: Publica servicios; Puntos básicos; Resultados de publicaciones; Página detalles del artículo; Campos del artículo; Definición de atributos; Título; Descripción
- [Sincroniza publicaciones](https://developers.mercadolibre.com.co/es_co/servicio-sincroniza-publicaciones) — estado: `ok`; endpoints: 1; fechas visibles: —; secciones: Sincroniza publicaciones; Consideraciones; Actualiza tu artículo; Descripciones; Imágenes; Tipos de publicación; Cambia el estado de las publicaciones; Elimina publicaciones

### Rutas del módulo

- `GET /block-api/search/users/{user_id`
- `GET /block-api/search/users/123456?type=blocked_by_questions`
- `GET /categories/MLA1071`
- `GET /categories/MLA24272/attributes`
- `GET /categories/MLA58257`
- `GET /coverage_areas/TUxBUEpVSnk3YmUz`
- `GET /items/Item_id`
- `GET /items/ITEM_ID`
- `GET /items/MLA599074368`
- `GET /items/MLA612001263`
- `GET /sites/MLA/coverage_areas`
- `GET /sites/MLA/search?category=MLA5726`
- `GET /users/202593498`
- `GET /users/202593498/address`
- `GET /users/202593498/private`
- `PUT /items/ITEM_ID`
- `PUT /items/MLA599074368`
- `PUT /users/{User_id`
- `PUT /users/202593498/address`
- `UNKNOWN /items/Item_id`

## Usuarios y recursos cross

Cobertura: 16 páginas, 88 endpoints/rutas detectadas. Integraciones detectadas en contenido: Catálogo, Facturación, Mercado Envíos, Mercado Pago, OAuth.

### Páginas fuente

- [Atributos](https://developers.mercadolibre.com.co/es_co/atributos) — estado: `ok`; endpoints: 31; fechas visibles: —; secciones: Atributos; Consulta atributos; Tipos de atributos posibles; Comportamientos especiales; Atributos obligatorios; Atributos obligatorios por condición; Atributos de Dimensiones de Paquete; Errores
- [Carga de atributos](https://developers.mercadolibre.com.co/es_co/conoce-como-estan-los-vendedores-frente-la-carga-de-atributos) — estado: `ok`; endpoints: 8; fechas visibles: —; secciones: Carga de atributos; Glosario; Conocer el estado de un vendedor; Conocer el estado de completitud y calidad de una publicación; Parámetros; Descripción de campos; Especificaciones
- [Categorías y Atributos](https://developers.mercadolibre.com.co/es_co/categorias-y-atributos) — estado: `ok`; endpoints: 17; fechas visibles: —; secciones: Categorías y Atributos; Categorías; Predictor de categorías; Atributos específicos de las categorías; Atributos obligatorios; Valores más utilizados (top values); Descarga de categorías; Convertir de Domínio a Categoría
- [Comunicaciones](https://developers.mercadolibre.com.co/es_co/conoce-las-novedades-que-reciben-los-vendedores) — estado: `ok`; endpoints: 1; fechas visibles: —; secciones: Comunicaciones; Consultar comunicaciones; Parámetros; Campos de la respuesta
- [Consulta de Usuarios](https://developers.mercadolibre.com.co/es_co/consulta-de-usuarios) — estado: `ok`; endpoints: 9; fechas visibles: 05/11/2025; secciones: Consulta de Usuarios; Registrarte como inmobiliaria (opcional); Consultar mis datos personales; Campos de la respuesta; Consultar información pública de un usuario; Usuario vendedor sell equal pay (S = P); Consultar usuarios bloqueados para órdenes.; Parámetros
- [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/consulta-usuarios) — estado: `ok`; endpoints: 15; fechas visibles: 6 de enero de 2016; secciones: Consultas sobre el usuario; Consultar mis datos personales; Consultar información pública de un usuario; Consultar información privada de un usuario que ha aceptado el uso de mi  aplicación; Usuario Vendedor S = P (sell equal pay); Códigos de error comunes; Endpoint block-api/search/users: Consultar usuários bloqueados para orders y question:; Ejemplo de request: blocked_by_questions
- [Consultas sobre el usuario](https://developers.mercadolibre.com.co/es_co/producto-consulta-usuarios) — estado: `ok`; endpoints: 3; fechas visibles: —; secciones: Consultas sobre el usuario; Consultar mis datos personales; Consultar información pública de un usuario; Consultar información privada de un usuario que ha aceptado el  uso de mi aplicación; Códigos de error comunes
- [Direcciones del usuario](https://developers.mercadolibre.com.co/es_co/direcciones-del-usuario) — estado: `ok`; endpoints: 2; fechas visibles: —; secciones: Direcciones del usuario; Consultar direcciones del usuario; Campos de la respuesta
- [Dominios y Categorías](https://developers.mercadolibre.com.co/es_co/dominios-y-categorias) — estado: `ok`; endpoints: 17; fechas visibles: 29/10/2025; secciones: Dominios y Categorías; Obtener todos los sitios.; Respuesta; Obtiene listado de exposiciones por sitio.; Respuesta; Obtiene listado de precios.; Respuesta; Obtiene el árbol de categorías por sitio.
- [Items - Atributos de envío y dimensiones](https://developers.mercadolibre.com.co/es_co/items-atributos-de-envio-y-dimensiones) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Items - Atributos de envío y dimensiones
- [Marcadores](https://developers.mercadolibre.com.co/es_co/marcadores) — estado: `ok`; endpoints: 4; fechas visibles: —; secciones: Marcadores; Accede a tus marcadores; Marca un producto; Elimina un marcador
- [Preguntas frecuentes sobre validación de datos](https://developers.mercadolibre.com.co/es_co/validacion-de-datos) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Preguntas frecuentes sobre validación de datos; ¿Por qué debo realizar el flujo de validación de datos?; ¿Cómo saber quién es titular de la cuenta?; ¿Cómo saber que hay datos pendientes en la cuenta?; ¿El flujo de validación aplica a Empresas?; ¿Por qué pedimos validación de identidad para empresas?; Tengo representante legal en el país pero los beneficiarios finales son extranjeros, ¿es necesario enviar la documentación de los beneficiarios finales?; Una vez cargados los datos, ¿Hasta cuanto puede demorar la validación?
- [Referencias de dominios, productos y atributos para Autopartes](https://developers.mercadolibre.com.co/es_co/referencias-de-dominios-productos-y-atributos-para-autopartes) — estado: `ok`; endpoints: 16; fechas visibles: 15/07/2026; secciones: Referencias de dominios, productos y atributos para Autopartes; Atributos principales; Atributos secundarios; Atributos opcionales; Atributos por dominio; Atributos por categoría; Búsqueda de vehículos; Top values
- [Reputación de vendedores](https://developers.mercadolibre.com.co/es_co/reputacion-de-vendedores) — estado: `ok`; endpoints: 2; fechas visibles: —; secciones: Reputación de vendedores; Campos de la respuesta; Métricas de calidad; Reclamos (Claims); Tiempo de entrega con retraso (Handling Time); Cancelaciones (Cancellations); Límites para cada variable
- [Ubicación y Monedas](https://developers.mercadolibre.com.co/es_co/ubicacion-y-monedas) — estado: `ok`; endpoints: 13; fechas visibles: —; secciones: Ubicación y Monedas; Obtiene información sobre países.; Respuesta:; Obtiene detalle de país; Respuesta; Obtiene estado de la información.; Respuesta; Obtiene información de la ciudad.
- [Validar datos de vendedores](https://developers.mercadolibre.com.co/es_co/validar-datos-de-vendedores) — estado: `ok`; endpoints: 3; fechas visibles: —; secciones: Validar datos de vendedores; Consideraciones

### Rutas del módulo

- `DELETE /users/{user_id`
- `DELETE /users/$USER_ID/immediate_payment/by_user`
- `DELETE /users/$YOUR_CUST_ID/order_blacklist/$SELLER_ID`
- `DELETE /users/me/bookmarks/MLA5529`
- `GET /catalog_compatibilities/products_search/chunks`
- `GET /catalog_domains/$DOAMAIN_ID/attributes/$ATTRIBUTE_ID/top_values`
- `GET /catalog_domains/$DOMAIN_ID`
- `GET /catalog_domains/$DOMAIN_ID/attributes/$ATTRIBUTE_ID/top_values`
- `GET /catalog_domains/MLA-CARS_AND_VANS`
- `GET /catalog_domains/MLA-CARS_AND_VANS/attributes/BRAND/top_values`
- `GET /catalog_domains/MLA-CARS_AND_VANS/attributes/MODEL/top_values`
- `GET /catalog_domains/MLA-CARS_AND_VANS/attributes/VEHICLE_YEAR/top_values`
- `GET /catalog_domains/MLA-CELLPHONES/attributes/BRAND/top_values`
- `GET /catalog_domains/MLA-CELLPHONES/attributes/MODEL/top_values`
- `GET /catalog_domains/MLB-CARS_AND_VANS/categories`
- `GET /catalog_quality/status?item_id=$ITEM_ID&v=3`
- `GET /catalog_quality/status?item_id=MLA123456789&v=3`
- `GET /catalog_quality/status?seller_id=$SELLER_ID&include_items=$BOOL&v=$VERSION`
- `GET /catalog_quality/status?seller_id=321654987&include_items=true&v=32`
- `GET /categories/$CATEGORY_ID/attributes/conditional`
- `GET /categories/$CATEGORY_ID/technical_specs/input`
- `GET /categories/$CATEGORY_ID/technical_specs/output`
- `GET /categories/MLA1002/technical_specs/input`
- `GET /categories/MLA1002/technical_specs/output`
- `GET /categories/MLA109291/attributes`
- `GET /categories/MLA1234/attributes`
- `GET /categories/MLA12345/attributes`
- `GET /categories/MLA125703/attributes`
- `GET /categories/MLA1743`
- `GET /categories/MLA1743/classifieds_promotion_packs`
- `GET /categories/MLA1744`
- `GET /categories/MLA1744/attributes`
- `GET /categories/MLA403656/attributes/conditional`
- `GET /categories/MLA5725`
- `GET /classified_locations/cities/TUxVQ0NBQjY1MmQ1`
- `GET /classified_locations/countries/UY`
- `GET /classified_locations/states/UY-RO`
- `GET /communications/notices?limit=$LIMIT&offset=$OFFSET`
- `GET /countries/AR/zip_codes/5000`
- `GET /country/AR/zip_codes/search_between?zip_code_from=5000&zip_code_to=5100`
- `GET /currencies/`
- `GET /currencies/CLP`
- `GET /currency_conversions/search?from=ARS&to=CLP`
- `GET /domains/MLA-JACKETS_AND_COATS/technical_specs`
- `GET /items/{item_id?attributes=attributes&include_internal_attributes=true`
- `GET /items/MLA0000000`
- `GET /items/MLA0000000?attributes=attributes&include_internal_attributes=true`
- `GET /items/MLA20805195516`
- `GET /items/MLA621092868`
- `GET /sites/$SITE_ID/listing_prices?price=$PRICE`
- `GET /sites/MLA/categories/all`
- `GET /sites/MLA/domain_discovery/search?limit=1&q=fiat%20uno`
- `GET /sites/MLA/listing_prices?price=1`
- `GET /users/{user_id`
- `GET /users/$SELLER_ID/questions_blacklist`
- `GET /users/$USER_ID?attributes=status`
- `GET /users/$USER_ID/immediate_payment`
- `GET /users/$USER_ID/immediate_payment/by_user`
- `GET /users/$USER_ID/items/search?tags=incomplete_technical_specs`
- `GET /users/$YOUR_CUST_ID/order_blacklist/$SELLER_ID`
- `GET /users/123456789?attributes=status`
- `GET /users/128885`
- `GET /users/145834937/addresses`
- `GET /users/205159033`
- `GET /users/465432224/items/search?tags=incomplete_technical_specs`
- `GET /users/me/bookmarks`
- `GET /users/me/bookmarks/MLA5529`
- `POST /catalog_compatibilities/products_search/chunks`
- `POST /catalog_domains/$DOAMAIN_ID/attributes/$ATTRIBUTE_ID/top_values`
- `POST /catalog_domains/$DOMAIN_ID/attributes/$ATTRIBUTE_ID/top_values`
- `POST /catalog_domains/MLA-CARS_AND_VANS/attributes/BRAND/top_values`
- `POST /catalog_domains/MLA-CARS_AND_VANS/attributes/MODEL/top_values`
- `POST /catalog_domains/MLA-CARS_AND_VANS/attributes/VEHICLE_YEAR/top_values`
- `POST /catalog_domains/MLA-CELLPHONES/attributes/BRAND/top_values`
- `POST /catalog_domains/MLA-CELLPHONES/attributes/MODEL/top_values`
- `POST /categories/$CATEGORY_ID/attributes/conditional`
- `POST /categories/MLA403656/attributes/conditional`
- `POST /users/me/bookmarks`
- `PUT /items/MLA621092868`
- `PUT /users/$USER_ID/immediate_payment`
- `UNKNOWN /catalog_quality/status?item_id=$ITEM_ID&v=3`
- `UNKNOWN /catalog_quality/status?item_id=MLA123456789&v=3`
- `UNKNOWN /catalog_quality/status?seller_id=$SELLER_ID&include_items=$BOOL&v=$VERSION`
- `UNKNOWN /catalog_quality/status?seller_id=321654987&include_items=true&v=32`
- `UNKNOWN /currency_conversions/search?from=ARS&to=CLP`
- `UNKNOWN /domains/MLA-JACKETS_AND_COATS/technical_specs`
- `UNKNOWN /items/MLA20805195516`
- `UNKNOWN /users/{user_id`

## Vehículos

Cobertura: 9 páginas, 52 endpoints/rutas detectadas. Integraciones detectadas en contenido: Catálogo, Mercado Envíos, Notificaciones, OAuth.

### Páginas fuente

- [Calidad de publicaciones (vehículos)](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones-vehiculos) — estado: `ok`; endpoints: 6; fechas visibles: —; secciones: Calidad de publicaciones (vehículos); Niveles de calidad por sitio; Campos de la respuesta; Detalle de la calidad por ítem; Campos de la respuesta; Acciones necesarias para mejorar la calidad de un ítem; Descripción de las acciones; Acciones para Vehículos
- [Créditos pre aprobados](https://developers.mercadolibre.com.co/es_co/credits-motors) — estado: `ok`; endpoints: 4; fechas visibles: —; secciones: Créditos pre aprobados; Notificaciones de nuevos leads de Créditos; Consultar créditos disponibilizados para los ítems del vendedor; Detalles del crédito disponibilizado; Posibles errores
- [Gestión de Paquetes de Vehículos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-paquetes) — estado: `ok`; endpoints: 11; fechas visibles: 02/01/2025, 20 de enero de 2026, 01/01/2022, 01/06/2022; secciones: Gestión de Paquetes de Vehículos; Tipos de Paquete; Nuevo Modelo de Paquetes (MLM y MCO); Nuevos listing_type; FAQs Nuevo Modelo de Paquetes; Consultar paquetes por categoría; Consultar paquetes de publicaciones contratados por un usuario; Descripción de recursos
- [Gestiona preguntas y contactos](https://developers.mercadolibre.com.co/es_co/vehiculos-gestiona-preguntas-y-contactos) — estado: `ok`; endpoints: 21; fechas visibles: —; secciones: Gestiona preguntas y contactos; Descripción de parámetros; Consulta del total de preguntas; Por publicación:; Por usuario:; Contactos con fecha; Por publicación; Por usuario
- [Guía para vehículos](https://developers.mercadolibre.com.co/es_co/introduccion-vehiculos) — estado: `ok`; endpoints: 0; fechas visibles: —; secciones: Guía para vehículos; Introducción
- [Localiza vehículos](https://developers.mercadolibre.com.co/es_co/localizacion-de-vehiculos) — estado: `ok`; endpoints: 9; fechas visibles: —; secciones: Localiza vehículos; Parametrización de ubicación; Explorar todos los países; Explorar información de un país; Explorar información de un estado; Explorar información de una ciudad; Explorar información de un barrio
- [Personas Interesadas](https://developers.mercadolibre.com.co/es_co/persona-interesadas) — estado: `ok`; endpoints: 9; fechas visibles: 2026-01-15, 2026-01-22, 2024-05-14, 2024-05-24; secciones: Personas Interesadas; Consultar interesados en los ítems del vendedor; Parámetros de la consulta; Descripción de la respuesta; Ejemplo de llamada con el parámetro opcional include_guest=true; Campos adicionales al utilizar el parámetro include_guest=true; Tipos de Leads Disponibles (contact_types); Posibles errores
- [Publica vehículos](https://developers.mercadolibre.com.co/es_co/publica-vehiculos) — estado: `ok`; endpoints: 13; fechas visibles: 23 de febrero de 2026; secciones: Publica vehículos; Consultar vehículo; Atributos; Título; Placa; Chasis; Descripción; Imágenes
- [Sincroniza publicaciones (vehículos)](https://developers.mercadolibre.com.co/es_co/vehiculos-sincroniza-publicaciones) — estado: `ok`; endpoints: 7; fechas visibles: 12 de marzo de 2026; secciones: Sincroniza publicaciones (vehículos); Consideraciones; Actualiza tu artículo; Descripciones; Imágenes; Tipos de publicación; Cambia el estado de las publicaciones; Actualización con atributo WITH_FINANCING_OPTIONS (Opciones de financiamiento)

### Rutas del módulo

- `GET /categories/$CATETGORY_ID/classifieds_promotion_packs`
- `GET /categories/MLB1743/classifieds_promotion_packs`
- `GET /classified_locations/cities/{City_id`
- `GET /classified_locations/cities/TUxBQ0NBUGZlZG1sYQ`
- `GET /classified_locations/countries/{Country_Id`
- `GET /classified_locations/neighborhoods/{Neighborhood_Id`
- `GET /classified_locations/neighborhoods/TUxBQkNBQjM4MDda`
- `GET /classified_locations/states/{State_id`
- `GET /classified_locations/states/TUxBUENBUGw3M2E1`
- `GET /items/$ITEM_ID/contacts/phone_views/time_window?last=$LAST&unit=$UNIT`
- `GET /items/$ITEM_ID/contacts/questions/time_window?ids=$ID1,ID2&last=$LAST&unit=$UNIT&ending=$ENDING_DATE`
- `GET /items/$ITEM_ID/contacts/questions/time_window?last=$LAST&unit=$UNIT`
- `GET /items/$ITEM_ID/contacts/whatsapp/time_window?unit=$UNIT&last=$LAST`
- `GET /items/contacts/phone_views/time_window?ids=MLA510272257,MLA489747739&last=2&unit=hour&ending=2014-05-28T00:00:00.000-03:00`
- `GET /items/contacts/whatsapp/time_window?ids=$IDS&unit=$UNIT&last=$LAST&ending=$ENDING`
- `GET /items/MLA111111111/listing_type`
- `GET /items/MLA1116194549/contacts/whatsapp?date_from=2014-05-28T00:00:00.000-03:00&date_to=2014-05-29T23:59:59.999`
- `GET /items/MLA510272257/contacts/questions/time_window?last=2&unit=hour`
- `GET /items/MLA932485344/description`
- `GET /items/MLB4277151191`
- `GET /items/MLM735814032/health`
- `GET /items/MLM735814032/health/actions`
- `GET /items/MLV421672596/contacts/questions?date_from=2014-08-01T00:00:00.000-03:00&date_to=2014-08-02T23:59:59.999`
- `GET /leads/$LEAD_ID/details`
- `GET /leads/3f2dedf2-dfbd-4981-a726-40b13aa172ff/details`
- `GET /sites/MLB/health_levels`
- `GET /users/$USER_ID/classifieds_promotion_packs/$LISTING_TYPE/available?categoryId=$CATEGORY_ID&upgrades=true`
- `GET /users/$USER_ID/contacts/phone_views/time_window?last=$LAST&unit=$UNIT`
- `GET /users/$USER_ID/contacts/questions/time_window?last=$LAST&unit=$UNIT`
- `GET /users/$USER_ID/contacts/whatsapp/time_window?unit=$UNIT&last=$LAST`
- `GET /users/$USER_ID/items/search?tags=$TAG`
- `GET /users/$USER_ID/leads/buyers`
- `GET /users/123456789/classifieds_promotion_packs/gold_premium/available?categoryId=MLM1744&upgrades=true`
- `GET /users/127232529/contacts/phone_views?date_from=2014-05-28T00:00:00.000-03:00&date_to=2014-05-29T23:59:59.999`
- `GET /users/127232529/contacts/whatsapp/time_window?unit=$UNIT&last=$LAST`
- `GET /users/135146148/classifieds_promotion_packs?package_content=ALL`
- `GET /users/52366166/contacts/phone_views?date_from=2014-05-28T00:00:00.000-03:00&date_to=2014-05-29T23:59:59.999`
- `GET /users/705332753/items/search?tags=misplaced_personal_data`
- `GET /vis/leads/3f2dedf2-dfbd-4981-a726-40b13aa172ff`
- `GET /vis/loans/14b52fd8-85dc-11eb-8436-2753cb1f9665?seller_id=707775316`
- `GET /vis/loans/search?seller_id=$SELLER_ID&date_from=AAAA-MM-DDTHH:MM:SS&date_to=AAAA-MM-DDTHH:MM:SS`
- `GET /vis/loans/search?seller_id=707775316&date_from=2020-12-10T00:00:00&date_to=2021-01-01T00:00:00`
- `GET /vis/users/$USER_ID/leads/buyers?offset=$OFFSET&limit=$LIMIT&date_from=$DATE_FROM&date_to=$DATE_TO&contact_types=$CONTACT_TYPES`
- `GET /vis/users/3052668868/leads/buyers?offset=0&limit=10&date_from=2026-01-15&date_to=2026-01-22&contac_types=credit,question,whatsapp`
- `POST /items/MLA111111111/listing_type`
- `PUT /items/{ItemID}`
- `PUT /items/MLA1568702067`
- `PUT /items/MLA2736093652`
- `UNKNOWN /items/MLA1568702067`
- `UNKNOWN /items/MLA2736093652`
- `UNKNOWN /users/$USER_ID/items/search?tags=$TAG`
- `UNKNOWN /users/705332753/items/search?tags=misplaced_personal_data`

## Autenticación, permisos y headers

La documentación capturada menciona tokens, OAuth, scopes/permiso o headers en 155 páginas. El patrón central para recursos privados o escritura es enviar `Authorization: Bearer <ACCESS_TOKEN>`; los endpoints de OAuth/autorización deben tratarse como infraestructura crítica.

- [Autenticación y Autorización](https://developers.mercadolibre.com.co/es_co/autenticacion-y-autorizacion) — módulo: Autenticación y aplicaciones; headers/hints: Authorization, X-12345678, authorization, accept, content-type, x-www-form-urlencoded, Bearer, redirect_uri, client_id, client_secret, access_token, scope
- [Crea una aplicación en Mercado Libre](https://developers.mercadolibre.com.co/es_co/crea-una-aplicacion-en-mercado-libre-es) — módulo: Autenticación y aplicaciones; headers/hints: scopes, scope
- [Error 403](https://developers.mercadolibre.com.co/es_co/error-403) — módulo: Autenticación y aplicaciones; headers/hints: scopes
- [Gestión de Identidades y Accesos](https://developers.mercadolibre.com.co/es_co/gestion-de-identidades-y-accesos-oauth-y-tokens) — módulo: Autenticación y aplicaciones; headers/hints: Authorization, refresh_token, access_token, Bearer, client_secret
- [Gestiona tus aplicaciones](https://developers.mercadolibre.com.co/es_co/gestiona-tus-aplicaciones) — módulo: Autenticación y aplicaciones; headers/hints: Authorization, Bearer, ACCESS_TOKEN, scopes, read, write
- [Obtención del Access Token](https://developers.mercadolibre.com.co/es_co/obtencion-del-access-token) — módulo: Autenticación y aplicaciones; headers/hints: authorization, accept, content-type, x-www-form-urlencoded, client_id, redirect_uri, client_secret, access_token, Bearer, scope, refresh_token
- [Seguridad de aplicaciones](https://developers.mercadolibre.com.co/es_co/seguridad-apps) — módulo: Autenticación y aplicaciones; headers/hints: access_token
- [Usuarios y Aplicaciones](https://developers.mercadolibre.com.co/es_co/usuarios-y-aplicaciones) — módulo: Autenticación y aplicaciones; headers/hints: Authorization, Content-Type, Content-type, Bearer, ACCESS_TOKEN, scopes
- [Configuración o requisitos previos](https://developers.mercadolibre.com.co/es_co/configuracion-o-requisitos-previos) — módulo: General; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Envíos en feriados opcionales](https://developers.mercadolibre.com.co/es_co/dias-no-laborables) — módulo: General; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Gestionar Moderaciones](https://developers.mercadolibre.com.co/es_co/gestionar-moderaciones) — módulo: General; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Miembros del Programa](https://developers.mercadolibre.com.co/es_co/miembros-del-programa) — módulo: General; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Moderaciones con pausado](https://developers.mercadolibre.com.co/es_co/moderaciones-con-pausado) — módulo: General; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Motivos para comunicarse](https://developers.mercadolibre.com.co/es_co/motivos-para-comunicarse) — módulo: General; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN, read
- [Precio por variación](https://developers.mercadolibre.com.co/es_co/precio-variacion) — módulo: General; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Primeros pasos](https://developers.mercadolibre.com.co/es_co/primeros-pasos-es) — módulo: General; headers/hints: Authorization, Content-Type, x-caller-id, Bearer, ACCESS_TOKEN
- [Realiza pruebas](https://developers.mercadolibre.com.co/es_co/realiza-pruebas) — módulo: General; headers/hints: Authorization, X-12345678, Content-type, Bearer, ACCESS_TOKEN
- [Solicitud de visita](https://developers.mercadolibre.com.co/es_co/solicitud-de-visita) — módulo: General; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Tipos de publicación](https://developers.mercadolibre.com.co/es_co/tipos-de-publicacion-y-actualizaciones-de-articulos) — módulo: General; headers/hints: Authorization, Content-Type, Accept, Bearer, ACCESS_TOKEN
- [Actualiza las variaciones de tu inmueble](https://developers.mercadolibre.com.co/es_co/actualizacion-variacion-inmuebles) — módulo: Inmuebles; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Atributos](https://developers.mercadolibre.com.co/es_co/atributos-inmuebles) — módulo: Inmuebles; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Calidad de las Publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-las-publicaciones-inmuebles) — módulo: Inmuebles; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Categorías](https://developers.mercadolibre.com.co/es_co/categorias-inmuebles) — módulo: Inmuebles; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Desarrollos Inmobiliarios](https://developers.mercadolibre.com.co/es_co/desarrollos-inmobiliarios) — módulo: Inmuebles; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Estadísticas de interacciones en Inmuebles](https://developers.mercadolibre.com.co/es_co/estadisticas-de-interacciones-en-inmuebles) — módulo: Inmuebles; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Experiencia para inmuebles](https://developers.mercadolibre.com.co/es_co/experiencia-para-inmuebles) — módulo: Inmuebles; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Gestionar paquetes de inmuebles](https://developers.mercadolibre.com.co/es_co/gestionar-paquetes-de-inmuebles) — módulo: Inmuebles; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Leads](https://developers.mercadolibre.com.co/es_co/leads-inmuebles) — módulo: Inmuebles; headers/hints: Authorization, Bearer, ACCESS_TOKEN, scope
- [Localizar Inmuebles](https://developers.mercadolibre.com.co/es_co/localizar-inmuebles) — módulo: Inmuebles; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Pasos Rápidos para Publicar un Inmueble de Prueba](https://developers.mercadolibre.com.co/es_co/pasos-rapidos-para-publicar-un-inmueble-de-prueba) — módulo: Inmuebles; headers/hints: Authorization, Content-Type, client_id, client_secret, redirect_uri, Bearer, ACCESS_TOKEN
- [Publica Inmuebles](https://developers.mercadolibre.com.co/es_co/publica-inmueble) — módulo: Inmuebles; headers/hints: Authorization, Content-Type, access_token, Bearer, ACCESS_TOKEN
- [Publicaciones de tiendas oficiales para inmuebles](https://developers.mercadolibre.com.co/es_co/publicaciones-de-tiendas-oficiales-para-inmuebles) — módulo: Inmuebles; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Variaciones](https://developers.mercadolibre.com.co/es_co/variaciones-para-inmuebles) — módulo: Inmuebles; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [¿Qué es gestionar la evidencia de reclamos?](https://developers.mercadolibre.com.co/es_co/gestionar-evidencia-de-reclamos) — módulo: Mensajería, reclamos y devoluciones; headers/hints: Authorization, x-public, Bearer, ACCESS_TOKEN
- [¿Qué es gestionar la resolución de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-resolucion-de-reclamos) — módulo: Mensajería, reclamos y devoluciones; headers/hints: Authorization, Bearer, ACCESS_TOKEN, client_id
- [¿Qué es un mensaje de un reclamo?](https://developers.mercadolibre.com.co/es_co/gestionar-mensaje-de-un-reclamo) — módulo: Mensajería, reclamos y devoluciones; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [¿Qué es un reclamo?](https://developers.mercadolibre.com.co/es_co/que-es-un-reclamo) — módulo: Mensajería, reclamos y devoluciones; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [¿Qué es una devolución?](https://developers.mercadolibre.com.co/es_co/gestionar-devoluciones) — módulo: Mensajería, reclamos y devoluciones; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN, access_token
- [Cambios - Changes & Allow Replace](https://developers.mercadolibre.com.co/es_co/changes) — módulo: Mensajería, reclamos y devoluciones; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Gestión de mensajes](https://developers.mercadolibre.com.co/es_co/mensajeria-post-venta) — módulo: Mensajería, reclamos y devoluciones; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN, client_id, read
- [Mensajes bloqueados](https://developers.mercadolibre.com.co/es_co/mensajes-bloqueados) — módulo: Mensajería, reclamos y devoluciones; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Mensajes pendientes](https://developers.mercadolibre.com.co/es_co/mensajes-pendientes) — módulo: Mensajería, reclamos y devoluciones; headers/hints: Authorization, Bearer, ACCESS_TOKEN, access_token, client_id, read
- [Bonificaciones para Product Ads](https://developers.mercadolibre.com.co/es_co/bonificaciones-para-product-ads) — módulo: Mercado Ads; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Brand Ads](https://developers.mercadolibre.com.co/es_co/ads-bads) — módulo: Mercado Ads; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Display Ads](https://developers.mercadolibre.com.co/es_co/display) — módulo: Mercado Ads; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Product Ads](https://developers.mercadolibre.com.co/es_co/pads-read) — módulo: Mercado Ads; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Agrupación de paquetes para la Colecta](https://developers.mercadolibre.com.co/es_co/agrupacion-de-paquetes-para-la-colecta) — módulo: Mercado Envíos; headers/hints: x-scope, Content-Type, Authorization, scope, Bearer, ACCESS_TOKEN, read, write, client_id
- [Costos de envío](https://developers.mercadolibre.com.co/es_co/costos-de-envios) — módulo: Mercado Envíos; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Envíos Colecta y Places](https://developers.mercadolibre.com.co/es_co/envios-colectas-places) — módulo: Mercado Envíos; headers/hints: Authorization, X-Version, x-version, Bearer, ACCESS_TOKEN
- [Envíos Flex](https://developers.mercadolibre.com.co/es_co/envios-flex) — módulo: Mercado Envíos; headers/hints: Authorization, Bearer, ACCESS_TOKEN, scope, access_token
- [Envíos Fulfillment](https://developers.mercadolibre.com.co/es_co/envios-fulfillment) — módulo: Mercado Envíos; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Envíos Personalizados](https://developers.mercadolibre.com.co/es_co/envios-personalizados) — módulo: Mercado Envíos; headers/hints: Authorization, Content-Type, Accept, Bearer, ACCESS_TOKEN
- [Envíos Turbo](https://developers.mercadolibre.com.co/es_co/envios-turbo) — módulo: Mercado Envíos; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Flete Dinámico](https://developers.mercadolibre.com.co/es_co/flete-dinamico) — módulo: Mercado Envíos; headers/hints: x-format-new, Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Gestión Mercado Envíos](https://developers.mercadolibre.com.co/es_co/mercado-envios) — módulo: Mercado Envíos; headers/hints: Authorization, authorization, Content-Type, Accept, x-multichannel, X-Format-New, Bearer, ACCESS_TOKEN, access_token, client_id
- [Gestionar envíos](https://developers.mercadolibre.com.co/es_co/envios) — módulo: Mercado Envíos; headers/hints: x-format-new, Authorization, authorization, Bearer, ACCESS_TOKEN, access_token
- [Gestionar Mercado Envíos 2](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-2) — módulo: Mercado Envíos; headers/hints: Authorization, Content-Type, X-Format-New, x-format-new, Bearer, ACCESS_TOKEN, client_id, access_token
- [Mercado Envíos 1](https://developers.mercadolibre.com.co/es_co/mercadoenvios-modo-1) — módulo: Mercado Envíos; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Cargar y Obtener Facturas](https://developers.mercadolibre.com.co/es_co/cargar-factura) — módulo: Preguntas, ventas y postventa; headers/hints: Content-Type, Authorization, content-type, access_token, Bearer, ACCESS_TOKEN
- [Crear nota informativa](https://developers.mercadolibre.com.co/es_co/notas-de-packs) — módulo: Preguntas, ventas y postventa; headers/hints: Authorization, Content-Type, X-Public, Bearer, ACCESS_TOKEN, access_token
- [Datos de Facturación](https://developers.mercadolibre.com.co/es_co/facturacion) — módulo: Preguntas, ventas y postventa; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Descarga de documento legal](https://developers.mercadolibre.com.co/es_co/reportes-descargas) — módulo: Preguntas, ventas y postventa; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Estados de órdenes y seguimiento](https://developers.mercadolibre.com.co/es_co/estados-de-ordenes-me1) — módulo: Preguntas, ventas y postventa; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Feedback sobre venta](https://developers.mercadolibre.com.co/es_co/feedback-sobre-venta) — módulo: Preguntas, ventas y postventa; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Gestión de packs](https://developers.mercadolibre.com.co/es_co/gestion-packs) — módulo: Preguntas, ventas y postventa; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Gestiona preguntas y respuestas](https://developers.mercadolibre.com.co/es_co/gestiona-preguntas-respuestas) — módulo: Preguntas, ventas y postventa; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Gestionar órdenes](https://developers.mercadolibre.com.co/es_co/gestiona-ventas) — módulo: Preguntas, ventas y postventa; headers/hints: Authorization, X-Content-Missing, Bearer, ACCESS_TOKEN, access_token
- [Notas en órdenes](https://developers.mercadolibre.com.co/es_co/notas-en-ordenes) — módulo: Preguntas, ventas y postventa; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Preguntas y Respuestas](https://developers.mercadolibre.com.co/es_co/preguntas-y-respuestas) — módulo: Preguntas, ventas y postventa; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Provisiones](https://developers.mercadolibre.com.co/es_co/provisiones) — módulo: Preguntas, ventas y postventa; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Reporte de pagos](https://developers.mercadolibre.com.co/es_co/reportes-pagos) — módulo: Preguntas, ventas y postventa; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Reportes de Facturación](https://developers.mercadolibre.com.co/es_co/reportes-de-facturacion) — módulo: Preguntas, ventas y postventa; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Resumen de Percepciones](https://developers.mercadolibre.com.co/es_co/resumen-percepciones) — módulo: Preguntas, ventas y postventa; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Actualiza tus publicaciones](https://developers.mercadolibre.com.co/es_co/actualiza-tus-publicaciones) — módulo: Productos, ítems y catálogo; headers/hints: Authorization, Content-Type, Accept, access_token, Bearer, ACCESS_TOKEN
- [Buscador de productos](https://developers.mercadolibre.com.co/es_co/buscador-de-productos) — módulo: Productos, ítems y catálogo; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Búsqueda de ítems](https://developers.mercadolibre.com.co/es_co/items-y-busquedas) — módulo: Productos, ítems y catálogo; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Calidad de publicaciones](https://developers.mercadolibre.com.co/es_co/calidad-de-publicaciones) — módulo: Productos, ítems y catálogo; headers/hints: Authorization, Bearer, ACCESS_TOKEN, access_token
- [Campañas co-fondeada automatizada y campañas de precios competitivos](https://developers.mercadolibre.com.co/es_co/campanas-smart-price-matching) — módulo: Productos, ítems y catálogo; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Categorización de productos](https://developers.mercadolibre.com.co/es_co/categoriza-productos) — módulo: Productos, ítems y catálogo; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Compatibilidades entre ítems y productos de Autopartes](https://developers.mercadolibre.com.co/es_co/compatibilidades-entre-items-y-productos) — módulo: Productos, ítems y catálogo; headers/hints: Authorization, Content-Type, content-type, Bearer, ACCESS_TOKEN
- [Competencia](https://developers.mercadolibre.com.co/es_co/competencia-en-catalogo) — módulo: Productos, ítems y catálogo; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Costos por vender](https://developers.mercadolibre.com.co/es_co/comision-por-vender) — módulo: Productos, ítems y catálogo; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Descripción de productos](https://developers.mercadolibre.com.co/es_co/descripcion-de-articulos) — módulo: Productos, ítems y catálogo; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Descuento pre-acordado por ítem y Campaña de liquidación stock Full](https://developers.mercadolibre.com.co/es_co/descuento-pre-acordado-por-item) — módulo: Productos, ítems y catálogo; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Diagnóstico de imágenes](https://developers.mercadolibre.com.co/es_co/diagnostico-imagenes) — módulo: Productos, ítems y catálogo; headers/hints: Content-Type, Authorization, Bearer, ACCESS_TOKEN
- [Elegibilidad de catálogo](https://developers.mercadolibre.com.co/es_co/elegibilidad-catalogo) — módulo: Productos, ítems y catálogo; headers/hints: Authorization, Bearer, ACCESS_TOKEN
- [Gestión de stock en convivencia Full/Flex (MLA y MLC)](https://developers.mercadolibre.com.co/es_co/convivencia-full-y-flex) — módulo: Productos, ítems y catálogo; headers/hints: Authorization, x-version, Content-Type, X-Version, Bearer, ACCESS_TOKEN
- [Gestión de stock multiorigen / User Products](https://developers.mercadolibre.com.co/es_co/stock-multiwarehouse) — módulo: Productos, ítems y catálogo; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Gestionar automatizaciones](https://developers.mercadolibre.com.co/es_co/automatizaciones-de-precios) — módulo: Productos, ítems y catálogo; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- [Gestionar guía de talles](https://developers.mercadolibre.com.co/es_co/guias-de-talles) — módulo: Productos, ítems y catálogo; headers/hints: Authorization, Content-Type, Bearer, ACCESS_TOKEN
- _65 referencias adicionales en el índice JSON._

## Auditoría y actualización futura

Cada página del snapshot conserva hashes normalizados de contenido, encabezados y endpoints. En una revisión futura, vuelve a capturar la documentación y compara snapshots con:

```bash
node scripts/compare-snapshots.mjs mercadolibre-api-snapshot.json nuevo-snapshot.json mercadolibre-api-changelog.md
```

Los cambios se clasifican como páginas agregadas/removidas/modificadas y endpoints agregados/removidos/modificados. Cualquier cambio en autenticación, parámetros, respuestas o errores debe revisarse manualmente usando las páginas fuente enlazadas.
