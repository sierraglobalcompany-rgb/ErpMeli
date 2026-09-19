# K1B Canonical Work Contract

K1B establishes a stable boundary for ERP work without creating another queue, Cron, worker, service, or transport path.

The principle is:

```text
ERP does not depend on V4.
V4 depends on the ERP canonical work contract.
```

## Runtime shape

```text
business producers
  -> WorkAdmissionContract
  -> CanonicalWorkStore
  -> current physical adapter: QueueV4CanonicalWorkStore
  -> current durable FIFO: queue_v4_clean_jobs
  -> DrainAuthorityContract
  -> current drain authority: queue_core_execution_leases
  -> current drainer adapter: QueueV4CurrentDrainer
  -> QueueV4CleanScheduler / QueueV4CleanWorker
  -> domain services
  -> Mercado Libre remote contract
```

## KISS invariants

```text
ONE_DURABLE_FIFO=YES
ONE_ACTIVE_DRAINER=YES
CONTROL_UNIT=PHYSICAL_API_CALL
NO_QUEUE_V5=YES
NO_NEW_CRON=YES
NO_BATCH_AUTHORITY=YES
NO_JOB_COUNT_AUTHORITY=YES
```

The table name `queue_v4_clean_jobs` remains the physical store for compatibility. New business admission should target `WorkAdmissionContract` / `CanonicalWorkStore`; the V4 table and repository are implementation details inside `app/Work/Adapters` and existing `app/QueueV4Clean`.

## Current adapters

- `QueueV4CanonicalWorkStore` maps canonical `WorkEnvelope` objects to the current Queue V4 physical row shape.
- `QueueCoreDrainAuthority` wraps the existing `QueueExecutionLeaseService` and `queue_core_execution_leases`.
- `QueueV4CurrentDrainer` wraps the current scheduler as the only active drainer adapter.
- `CronAdmissionService` remains backward compatible and now delegates admission to the canonical V4 store for exact domain/order work.

## Remaining permitted legacy coupling

Some references remain intentionally outside `app/Work/Adapters`:

- operator UI labels and limits;
- read-only diagnostic bundle/reporting services;
- legacy `CronAdmissionService` pointer-alignment SQL;
- historical docs and release metadata.

These are documented in `docs/architecture/K1B_V4_COUPLING_AUDIT.csv`. They are not new drainer authority and are not removed in K1B.

## Fail-safe drainer rule

K1B does not implement a second active drainer. If a future/manual drainer tries to use the current V4 drainer adapter directly, it is denied fail-closed; FIFO is not skipped and no remote call is made.

## K2 notification admission

Notifications keep `meli_notification_events` as the durable receipt and
`meli_notification_work_items` as the coalesced domain source. Neither table is
a second executable queue. An actionable source is admitted through
`CronAdmissionService` into the canonical store in the same PDO transaction.

The executable identity is one active pointer per tenant and source. A later
event received after a terminal pointer uses the source event/attempt generation
to create a new pointer; a later event received while processing leaves the
current pointer waiting through `rerun_requested`. Old out-of-order events stay
durable but cannot reopen or downgrade the source. Retry, resume, and the two
existing selective notification recoveries restore canonical admission in the
same transaction as their source transition.

Simultaneous webhook transactions may deadlock while coalescing the same source.
The receiver retries that fully rolled-back local transaction once. It does not
perform business HTTP during reception, create an outbox, or add another
drainer. `ML_WRITE_ENABLED=false` continues to deny commercial remote mutations;
it does not disable local event persistence or authorized GET processing.
