# Auditoría forense Cron V3 - ERP Meli 2.29.0

Fecha: 2026-08-02. Base inmutable: 2.28.59. Implementación: copia de trabajo 2.29.0.

## Veredicto

Cron V3 está implementado en modo deshabilitado y listo para instalación de
esquema, shadow y canario. No está autorizado para producción hasta completar
las pruebas MariaDB concurrentes, 60 ciclos shadow y 24 horas de canario.

## Evidencia de producción

Lectura autenticada y sin mutaciones mediante navegador interno:

- Centro Cron: 14.280 pendientes; último ciclo reclamó 2 funciones, inició 2,
  finalizó 6 recursos, inició 5 transportes y dejó 4 funciones fuera de ventana.
- Diagnóstico: 9.723 elegibles y 64 errores activos.
- Spool: 3.464; notificaciones: 2.029; enriquecimiento: 1.066; packs: 5.742;
  conciliación de ventas: 843; recálculo: 1.072.
- Trabajo `order_enrichment/19`: error, recurso shipment, alcance cuenta 2 y
  resultado remoto no confirmado.
- Venta `2000017635284992`: comercial completa, logística incompleta, neto
  oficial pendiente y un formulario legacy para completar finanzas.
- Procesar ahora todavía comunicaba una campaña que continuaba al cerrar la
  pestaña. La versión 2.29.0 elimina esa conducta.
- Ads, PostSale y Logistics aparecen próximos/no instalados; Growth e Insights
  no deben crear trabajo V3 hasta cumplir gate de proveedor, migración, tópico
  y endpoint.

## Diez pasadas

1. Inventario: se trazaron WorkQueueRegistry, productores web/webhook,
   servicios financieros, módulos, launchers y endpoints con graphify y rg.
2. Scoping: enqueue, claim, finish, OAuth, reparaciones y backfill exigen
   empresa y cuenta. OAuth rechaza traslado de usuario entre empresas.
3. Concurrencia: work y attempts usan token más lease_generation; un owner
   vencido no puede finalizar. El fence legacy de task state también exige token.
4. Rate/429: bucket global transaccional fail-closed; 10 rpm iniciales; una
   llamada lógica y un despacho físico por intento; 429, 5xx e incertidumbre
   tienen estados distintos.
5. Webhooks: tamaño, JSON, application_id, cuenta, tópico y recurso se validan
   antes de spool vivo; inválidos se cuarentenan una vez.
6. Ventas/logística: páginas de 20 solo descubren IDs; orden, pack y shipment
   son unidades exactas. Pack puede encolar shipment, no consultarlo en el mismo
   intento.
7. Finanzas: estado y evidencia canónicos; proyección local inmediata; una
   captura Billing por venta/input_version; máximo 60 order_ids; gap scan 50/20;
   sin recaptura diaria.
8. Productos/módulos: descubrimiento 20, item exacto y descripción exacta.
   Logistics tiene gate; Ads, Growth, Insights y PostSale no crean jobs no
   confirmados.
9. Navegador: se revisaron Cron, diagnóstico, detalle 19, venta objetivo,
   reclamos, módulos, actualización y procesamiento manual en varias pestañas.
10. Release/rollback: V3 inicia deshabilitado, ownership por familia, V2 se
    conserva; ninguna familia V2 se retira parcialmente.

## Arquitectura implementada

- `jobs/cron_v3_local.php --runtime=45 --max-items=50`
- `jobs/cron_v3_remote.php --delay=10 --runtime=35 --max-http=12`
- Tablas: work, attempts, ownership, rate buckets, circuit states y snapshots.
- Estados: ready, leased, completed, deferred, review y dead.
- Dedupe: tipo, empresa, cuenta, recurso e input_version.
- Fairness: máximo dos trabajos seguidos por cuenta y tres por familia cuando
  existe alternativa.
- Adaptador legacy: lee máximo 50, materializa máximo 20 y guarda checkpoint.
- Ownership certificado inicialmente solo para order_enrichment,
  financial_recalc y sale_financial_reconciliation. Las demás familias
  continúan en V2 hasta tener productor y adaptador completos.
- Panel independiente V3: local/remoto, ready, leased, cooldown, review, dead,
  antigüedad, throughput, HTTP físico y señal. Nunca declara salud sin ambos
  snapshots.

## Inventario remoto

| Familia | Unidad V3 | Endpoint | Estado |
|---|---|---|---|
| OAuth | oauth_refresh | POST /oauth/token | Excepción técnica confirmada |
| Órdenes | orders_search_page | GET /orders/search | Handler, corte V2 pendiente |
| Órdenes | order_exact | GET /orders/{id} | Handler |
| Packs | pack_exact | GET /packs/{id} | Handler y adaptador |
| Envíos | shipment_exact | GET /shipments/{id} | Handler y adaptador |
| Preguntas | search/exact | GET /questions/search, /questions/{id} | Handler |
| Reclamos | search/exact | GET /post-purchase/v1/claims/... | Handler y productor |
| Finanzas | sale_billing_capture | GET /billing/integration/group/ML/order/details | Handler, productor y adaptador |
| Fiscal | sales_fiscal_exact | GET /orders/billing-info/... | Bloqueado hasta servicio exacto |
| Productos | search/exact/description | GET /users/{id}/items/search, /items/{id}, /description | Handler |
| Logistics | module_logistics_exact | GET /shipments/{id} | Gate instalado/confirmado |
| Billing histórico | period details | GET /billing/integration/periods/key/{key}/group/ML/details | Confirmado, productor diferido |

## Riesgo residual y gates

- P1: no hay `ERP_MIGRATOR_TEST_DSN` ni cliente MariaDB local; la prueba de dos
  workers y 100 reservas concurrentes quedó omitida en esta máquina.
- P1: no se ejecutaron 60 ciclos shadow contra snapshot real porque el esquema
  V3 no está instalado en una base de pruebas accesible.
- P1: canario de 24 horas no ejecutado; es un gate operacional, no una prueba
  sustituible por unit tests.
- P1: fiscal exacto y familias V2 no certificadas permanecen deliberadamente en
  V2. El registro de cutover impide retirarlas por error.
- P2: el endpoint histórico por período está documentado, pero no se habilita
  antes de estabilizar captura exacta.

## Corte y rollback

1. Instalar migraciones 240-243 con V3 apagado.
2. Ejecutar 60 ciclos shadow local/remoto; exigir cero HTTP y cero mutación de
   fuentes.
3. Habilitar launchers con ownership todavía deshabilitado.
4. Transferir una familia certificada por vez. Nunca activar tipos parciales.
5. Iniciar remoto a 10 rpm. Subir 15, 20, 25 y 30 cada 24 horas solo con cero
   429, cero lease_lost, cero duplicados y antigüedad decreciente.
6. Conservar V2 14 días. Rollback: deshabilitar launchers V3 y devolver
   ownership de la familia a V2; las filas V3 e intentos se conservan.
7. No retirar V2 ni campañas históricas hasta certificar backlog decreciente y
   resolver los 64 errores activos.

## Verificación y entrega

- `composer test`: aprobado, 154 pruebas base, 393 rutas y todos los contratos
  V3/2.29.0. La rama MariaDB informa `mysql=skipped` por falta de DSN local.
- `composer lint`, `composer compat`, `composer analyse` y
  `composer validate --no-check-publish`: aprobados.
- Navegador local: `http://127.0.0.1:8101/login`, assets y accesibilidad básica
  cargados, sin errores de consola. Las rutas autenticadas requieren una sesión
  local; la auditoría autenticada se hizo contra producción en modo lectura.
- Release: `ERP Meli 2.29.0 Cron V3 Shadow`, 917 archivos y 1.847.922 bytes.
- ZIP SHA-256:
  `6AE679BEA53E9E355B83717BDFFE543B9943F83038618A0D8A2F69354C2A30E9`.
