# ERP MELI 2.38.3 — Automatic OAuth Control Plane

## Resultado

La release 2.38.3 incorpora una autoridad OAuth automática dentro del único
`QueueV4CleanScheduler`, antes del Producer y del Worker, pero fuera del FIFO
comercial. No crea otro Cron, no introduce prioridades y no devuelve autoridad
operativa a Queue Core ni a V3.

## Contrato de seguridad

- Una sola operación OAuth física por ejecución del scheduler.
- `POST /oauth/token` es la única capacidad remota de la fuente exacta
  `queue_v4_clean_oauth`.
- La barrera durable distingue `NOT_DISPATCHED`, `MAY_HAVE_DISPATCHED` y
  `RESPONSE_KNOWN`.
- Sólo un `429` conocido puede esperar y reintentarse en otra ejecución.
- Un 5xx, transporte incierto o 2xx malformado después de la barrera termina
  `REMOTE_UNCERTAIN`, sin segundo POST automático.
- Un 2xx válido conservado en escrow cifrado se recupera localmente, sin repetir
  el transporte.
- Los trabajos comerciales aplazados por OAuth no consumen intento ni crean
  Review o Dead.
- La reserva preventiva OAuth es 3 solicitudes globales y 1 por cuenta en cada
  ventana de 15 minutos; también limita `orders_event_sync`.

## Persistencia

La migración 296 crea `oauth_refresh_operations`. La tabla sólo conserva
identidad, estado, leases, generación y diagnóstico seguro; nunca tokens. Las
credenciales rotadas pendientes de MariaDB permanecen cifradas en
`QueueOAuthDurableRecoveryStore`, fuera de la aplicación servida.

## Compatibilidad y operación

La actualización soportada es 2.38.2 → 2.38.3. Queue V4 conserva su estado,
el único Cron físico conserva el mismo comando y el instalador no ejecuta
readiness ni llamadas Mercado Libre. Schema final: 296; migración 297 ausente.

## QA local

La certificación final exige:

- matriz adversarial OAuth y recuperación durable;
- contrato estático Queue V4 e Inventario;
- `composer test` de la release actual;
- ensayo MariaDB 11.8.8 2.38.2 → 2.38.3;
- rebuild Git con `core.autocrlf=false` y `core.autocrlf=true`;
- artefactos A/B/físicos byte-exactos;
- cero HTTP real a Mercado Libre y cero acceso a `storage/raw`.

Los hashes, HEAD/TREE finales y conteos de artefactos se registran en el
manifiesto externo generado desde la autoridad Git congelada.
