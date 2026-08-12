# Queue V4 Clean

Queue V4 Clean is a greenfield automation engine. It deliberately does not
repair, import, validate, or consult the operational state of Queue Core, V2,
or V3.

## REUSE

- `App\Core\Database`: PDO MySQL/MariaDB connections.
- `App\Core\Auth`, CSRF, same-origin, and permanent-admin reauthentication.
- `MeliApiClient`, OAuth token encryption, endpoint registry, pacing, and API
  guards for documented read-only calls.
- `OrderSyncService` business persistence through a Queue V4-specific entry
  point.
- Existing companies, Mercado Libre accounts, OAuth tokens, business tables,
  installer, and updater.

## REPLACE

- Operational queue: `queue_v4_clean_jobs` with a strict `available_at,id`
  FIFO over `ready` rows only.
- Worker attempts, runs, scheduler lease, checkpoints, control, health, and
  readiness use only `queue_v4_clean_*` tables.
- Readiness is a direct three-account `GET /users/me` test outside the queue.
- The administrative surface exposes only readiness, OAuth count, queue
  counts, scheduler status, Activate, and Stop.

## LEGACY_IGNORE

- All `queue_core_*` tables and their jobs, attempts, dispatch journals,
  runs, leases, generations, contexts, receipts, and uncertain executions.
- Cron V2/V3 operational state and historical backlog.
- Historical importer and downgrade/recovery bridges.

Legacy tables may remain physically present. Queue V4 Clean never reads them.
The only compatibility condition is that V3 and V3 Shadow configuration stay
disabled so legacy launchers cannot execute accidentally.

## State model

Readiness has exactly `NOT_READY`, `READY_TO_TEST`, `TESTING`, `CERTIFIED`, and
`FAILED`. Certification does not activate the engine and never creates a
Hostinger scheduler. Activation is a separate administrative action.

Operational jobs have `ready`, `running`, `waiting`, `review`, `dead`, and
`completed`. Only `ready` participates in FIFO. Lease expiry returns a safe
read-only job to `ready`; exhausted or unsafe work is moved out of FIFO.

## Safety boundaries

- Every operational row and business lookup is scoped by both `company_id`
  and `meli_account_id`.
- Readiness and the implemented worker perform GET requests only.
- `ML_WRITE_ENABLED=false` is mandatory for readiness and activation.
- No migration reads, copies, or deletes legacy work or business data.
- No code in this module reads or writes `storage/raw`.
