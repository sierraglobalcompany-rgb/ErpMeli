# Cron V3 - arquitectura operativa

## Objetivo

Cron V3 reemplaza la seleccion monolitica por trabajo unitario, idempotente,
aislado por empresa y cuenta, con fencing de propietario y generacion. V2 se
mantiene durante catorce dias como rollback, pero nunca puede reclamar una cola
cuya propiedad ya fue transferida a V3.

## Lanzadores

    php jobs/cron_v3_local.php --runtime=45 --max-items=50
    php jobs/cron_v3_remote.php --delay=10 --runtime=35 --max-http=12

El lanzador local no puede construir ni usar un transporte Mercado Libre. El
lanzador remoto consume como maximo un HTTP por intento y no ejecuta llamadas
en paralelo. OAuth es un trabajo remoto independiente.

## Estados y fencing

Los unicos estados de cron_v3_work son:

    ready -> leased -> completed
                    -> deferred -> ready
                    -> review
                    -> dead

Cada claim incrementa lease_generation y crea un owner_token. Heartbeat,
finalizacion, aplazamiento y error exigen simultaneamente id, company_id,
meli_account_id, owner_token y lease_generation. Un rowCount distinto de uno
invalida el resultado del worker.

La identidad idempotente es:

    work_type + company_id + meli_account_id + resource_key + input_version

Un reintento crea una fila en cron_v3_attempts, no otro trabajo activo.

## Registro de trabajos

### Local

| Work type | Productor | Resultado |
| --- | --- | --- |
| notification_spool | archivo webhook validado | evento durable |
| notification_normalize | evento durable | recurso normalizado |
| notification_backfill | scanner con checkpoint | normalizacion faltante |
| recurring_schedule | reglas recurrentes | paginas de busqueda |
| financial_projection | order.persisted | neto provisional |
| financial_recalc | evidencia nueva | lectura canonica recalculada |
| financial_gap_scan | diagnostico por cuenta | hasta 20 trabajos faltantes |
| order_date_repair | diagnostico local | fecha reparada o review |
| operational_maintenance | agenda local | mantenimiento acotado |
| monthly_maintenance | agenda local | evidencia; nunca reabre cierre |

### Remoto

| Work type | Endpoint confirmado | Limite por intento |
| --- | --- | --- |
| oauth_refresh | POST /oauth/token | 1 |
| orders_search_page | GET /orders/search | 1 pagina de 20 |
| order_exact | GET /orders/{id} | 1 orden |
| pack_exact | GET /packs/{id} | 1 pack |
| shipment_exact | GET /shipments/{id} | 1 envio |
| questions_search_page | GET /questions/search | 1 pagina |
| question_exact | GET /questions/{id} | 1 pregunta |
| claims_search_page | GET /post-purchase/v1/claims/search | 1 pagina de 20 |
| claim_exact | GET /post-purchase/v1/claims/{id} | 1 reclamo |
| sale_billing_capture | GET /billing/integration/group/ML/order/details | 1 venta, maximo 60 ordenes |
| sale_fiscal_capture | GET /orders/billing-info/{site}/{id} | 1 venta |
| items_discovery_page | GET /users/{id}/items/search | 20 IDs |
| item_exact | GET /items/{id} | 1 publicacion |
| item_description | GET /items/{id}/description | 1 descripcion |

Los trabajos de auditoria y reparacion reutilizan order_exact, pack_exact y
shipment_exact; no crean una segunda consulta para el mismo input_version.

## Productores

| Entrada | Trabajo |
| --- | --- |
| Webhook validado orders_v2 | order_exact |
| Webhook validado de envio | shipment_exact |
| Webhook validado de pregunta | question_exact |
| Webhook validado de reclamo | claim_exact |
| Webhook validado de item | item_exact |
| Regla recurrente de ventas | orders_search_page |
| Persistencia de orden | financial_projection, enriquecimientos exactos y una captura Billing |
| Pagina de reclamos | claims_search_page; cada ID produce claim_exact |
| Diagnostico financiero | solo recursos faltantes, maximo 20 por checkpoint |
| Procesar ahora | reclama y ejecuta un unico trabajo; no crea campana |
| Adaptador legacy | lee 50, materializa 20 y guarda checkpoint |

Todo productor debe aparecer tambien en el registro ejecutable de Cron V3. Las
pruebas de contrato fallan ante un productor, consumidor o endpoint huerfano.

## Finanzas

sale_financial_state es la lectura canonica. sale_financial_evidence conserva
evidencia inmutable por fuente y captura. Al guardar una orden se proyecta de
inmediato con orden, items, pagos embebidos y logistica disponible. El neto
oficial aparece solo despues de sale_billing_capture.

Una captura Billing agrupa exclusivamente ordenes de la misma venta. Si una
venta supera 60 IDs pasa a review. No existe recaptura diaria: una nueva
input_version se crea solo por cambio de entradas, evidencia faltante o
reparacion explicita.

Los cierres mensuales no se reescriben. Evidencia tardia crea discrepancia o
revision.

## Backpressure y ritmo

Cada productor tiene high y low watermark. Un adaptador inspecciona hasta 50
filas y crea hasta 20 trabajos. El selector usa round-robin por empresa y
cuenta, con maximo tres trabajos consecutivos de una familia y dos de una
cuenta.

El rate gate es transaccional y fail-closed. Sin tablas de ritmo no hay HTTP.
Retry-After prevalece sobre cualquier calculo local; un 429 cierra el circuito
por endpoint, cuenta y aplicacion. Un 5xx se aplaza como otro intento. Un envio
remoto de resultado incierto pasa a review.

## Web y seguridad

- El webhook valida tamano, JSON, application_id, cuenta, topic y recurso antes
  de entrar a la cola viva.
- Un rechazo se escribe una sola vez en cuarentena con retencion limitada.
- ML_WRITE_ENABLED=false bloquea todo metodo comercial distinto de GET.
- Procesar ahora ejecuta un paso sincronico mientras la peticion esta abierta.
- Retencion, backup, restore y saneamiento usan lanzadores locales y adquieren
  lock, pausa y freeze antes de mutar.
- Un meli_user_id vinculado a otra empresa produce conflicto; nunca mueve la
  cuenta ni sus tokens.

## Corte y rollback

1. Aplicar migraciones de seguridad, motor y finanzas.
2. Mantener V3 deshabilitado y ejecutar 60 ciclos shadow sin HTTP.
3. Transferir ownership de colas locales.
4. Transferir notificaciones, ordenes, packs, envios y paginas.
5. Transferir finanzas, fiscal, preguntas, reclamos y productos.
6. Activar modulos solo con migracion, proveedor, topic y endpoint confirmados.
7. Conservar V2 catorce dias, sin ownership de colas transferidas.
8. Retirar V2 solo con backlog y antiguedad descendentes.

Rollback significa deshabilitar los launchers V3, esperar el vencimiento de
leases, devolver ownership a V2 y ejecutar el probe local. Nunca se borran
trabajos, intentos, evidencia ni snapshots para retroceder.

## Canario

Iniciar en 10 RPM globales y subir a 15, 20, 25 y 30 cada 24 horas solamente con:

- cero 429;
- cero finalizaciones tardias;
- cero trabajos activos duplicados;
- backlog y antiguedad en descenso;
- circuitos cerrados y snapshots cargados.

Un panel no puede mostrarse saludable con p95 critico, backlog creciente o
metricas ausentes.
