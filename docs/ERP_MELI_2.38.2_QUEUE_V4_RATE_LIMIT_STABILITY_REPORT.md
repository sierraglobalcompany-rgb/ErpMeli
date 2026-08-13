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
