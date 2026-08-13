# ERP MELI 2.38.2 — Queue V4 rate-limit stability

## Incidente y causa raíz

La contención productiva de partida dejó el Cron físico ausente, Queue V4
internamente activo y una preimagen de `READY=58`, `REVIEW=279`, `DEAD=0`.
Se observaron 10 respuestas HTTP 429 antes de retirar el Cron y ninguna nueva
después. Los 13 trabajos de auditoría de ventas permanecieron `pending` y no
iniciaron transporte HTTP.

El análisis del código 2.38.1 confirmó dos causas:

1. La penalización del endpoint estaba particionada por cuenta. Un 429 de
   `/orders/search` en una cuenta no impedía que las otras cuentas, bajo la
   misma aplicación, iniciaran el mismo endpoint.
2. Queue V4 reclamaba primero el trabajo y trataba ritmo, presupuesto, deadline
   y 429 como fallos ordinarios. Cada aplazamiento consumía `attempt_count` y
   podía enviar trabajo sano a Review.

## Autoridad 2.38.2

- `ApiRhythmPolicyService` es la única autoridad de 429. Mantiene el breaker
  adicional por cuenta y agrega un breaker compartido por aplicación/endpoint
  normalizado.
- `/orders/search` tiene techo local configurable de hasta 30 transportes en
  una ventana rodante real de 900 segundos, compartida por las tres cuentas y
  por auditoría de ventas.
- `Retry-After` nunca se reduce. Sin cabecera se usa una espera conservadora de
  300 segundos más jitter determinista acotado.
- `ApiBudgetService` conserva presupuestos preventivos, pero no crea otra
  autoridad de 429. `ApiGuardService` conserva circuitos no relacionados con
  capacidad y tampoco duplica el breaker 429.
- Queue V4 compensa con CAS el incremento de `attempt_count` cuando la causa es
  ritmo, presupuesto o deadline. `available_at` conserva la próxima oportunidad
  segura y la ejecución no crea Review.
- Fallos funcionales, payload inválido y respuestas ambiguas conservan la
  política anterior de intentos, Review o Dead.

## Forense y operación

El snapshot administrativo expone Review por clase, tipo, cuenta y antigüedad.
La recuperación es una herramienta CLI explícita, exacta y de un solo job; sólo
acepta evidencia completa de aplazamientos técnicos conocidos. No se instala ni
ejecuta automáticamente y rechaza `MeliApiException` genérica o evidencia mixta.

La UI distingue configuración interna del scheduler de evidencia observada:
`RECENT`, `STALE` o `UNKNOWN`. Sólo hPanel puede certificar `ABSENT`. Ventas
distingue `pending` como “Preparado” y `running` como “En ejecución”.

## Seguridad del despliegue

La actualización 2.38.1 → 2.38.2 es metadata-only, mantiene schema 295 y no
añade migraciones. El rehearsal local preserva Queue V4, scheduler interno, 279
Review y 13 auditorías de ventas pendientes. No crea Cron, no ejecuta Review,
no llama Mercado Libre, no cambia OAuth, no habilita escrituras remotas y no
toca `storage/raw`.

Después de instalar se exige una auditoría read-only. Sólo con OAuth 3/3,
`RUNNING=0`, `DEAD=0` y clasificación Review conocida puede autorizarse un Cron
único cada minuto con `--runtime=45 --max-jobs=5`, seguido por observación de 60
minutos y 24 horas. Auditoría de ventas y recuperación Review permanecen
dormidas durante esa certificación primaria.

## Trazabilidad del contrato auditado

- `QueueV4CleanRepository::claim()` es el único punto que incrementa
  `attempt_count`. `deferWithoutAttemptPenalty()` revierte exactamente ese
  incremento dentro del CAS tenant-scoped y cierra el intento como `waiting`.
- `QueueV4CleanWorker` trata Rhythm, Budget, deadline y 429 como non-failure
  antes de los fallos funcionales. Payload inválido conserva `dead`; errores
  funcionales y HTTP no recuperables consumen `max_attempts` y terminan en
  `review`.
- `MeliApiClient` normaliza y reserva el transporte antes de cruzar la frontera
  HTTP. Un 429 conocido se transforma en `ApiRhythmDeferredException` con
  `reached_remote=true` y `next_safe_at`; el fallback genérico 429 usa el mismo
  backoff configurable de Rhythm.
- `ApiRhythmPolicyService` normaliza el endpoint, mantiene el breaker compartido
  `endpoint:shared:<sha256(endpoint)>`, conserva un límite adicional por cuenta
  y aplica el techo rodante de `/orders/search`. `ApiBudgetService` sólo decide
  cuotas preventivas y `ApiGuardService` excluye expresamente HTTP 429.
- `QueueV4CleanReviewService` clasifica evidencia histórica, recupera un único
  job exacto con CAS, rechaza evidencia mixta/ambigua y reconoce idempotentemente
  una postimagen `waiting` ya recuperada sin volver a mutarla.

## Matriz focal sin HTTP real

| Caso | Autoridad comprobada | Resultado |
|---|---|---|
| A | Primer 429 remoto; cuentas 2 y 3 bloqueadas antes del transporte fake | PASS |
| B | `Retry-After=7200` llega hasta `available_at` absoluto | PASS |
| C | 429 sin cabecera usa backoff conservador configurable | PASS |
| D | 10 aplazamientos Rhythm | PASS, intento final 0, Review 0 |
| E | 10 aplazamientos Budget | PASS, intento final 0, Review 0 |
| F | 10 aplazamientos deadline | PASS, intento final 0, Review 0 |
| G | Payload inválido | PASS, Dead |
| H | `RuntimeException` funcional repetida | PASS, Review al máximo |
| I | Fallback `MeliApiException(429)` | PASS, intento 0 |
| J | `MeliApiException(400)` repetida | PASS, Review al máximo |
| K | Salidas 1–30 y bloqueo local de la 31 | PASS |
| L | Ventana rodante libera sólo la salida realmente vencida | PASS |
| M | Discovery bloquea Sales Audit y Sales Audit bloquea Discovery | PASS |
| N | FIFO/leases del full-flow canónico | PASS |
| O | Packs siguen siendo contenedores, doble venta 0 | PASS |
| P | Escrituras remotas de negocio | PASS, 0 |

La prueba focal ejecuta 68 comprobaciones, un solo cruce al transporte fake que
responde 429 y cero llamadas HTTP reales. El rehearsal conserva exactamente 279
Review, 13 auditorías de ventas `pending`, el motor y la configuración interna
del scheduler; no crea Cron físico ni ejecuta recuperación.
