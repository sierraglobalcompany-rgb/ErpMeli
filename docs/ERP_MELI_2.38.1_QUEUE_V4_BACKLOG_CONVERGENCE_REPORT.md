# ERP MELI 2.38.1 — Queue V4 backlog convergence

## Scope

This release changes only Queue V4 scheduling capacity and producer pressure.
It adds no migration, does not change FIFO identity, does not touch review/dead
records, and performs no production or Mercado Libre operation during build.

## Confirmed cause in 2.38.0

The production Cron runs once per minute with `--max-jobs=3`. The producer can
open one fresh-discovery job for each of the three certified accounts each
minute. Thus the zero-order base arrival is 3 jobs/min and the service ceiling
is also 3 jobs/min. Inventory lifecycle refresh adds up to 3 jobs per 15
minutes. The base net slope is therefore 0 to +0.2 jobs/min, before any real
orders or pagination.

One discovery page can return 0, 1, 5 or 20 exact orders. A full non-terminal
page adds 20 exact jobs plus one pagination job after consuming the discovery
job: a bounded net burst of +20. Three simultaneous full pages can therefore
add a bounded net burst of +60. Production aggregate evidence showed Ready
increasing from 528 to 540 in approximately three minutes while Running stayed
0 and Waiting converged to 0. The administrative surface does not expose job
type or age, so those two production dimensions are intentionally reported as
not observable rather than inferred.

## Correction

1. Worker ceiling increases from 3 to 15 jobs per minute. The existing
   per-application/per-account API budget, retry policy, remote-write guard and
   shared 45-second deadline continue to fail closed.
2. Fresh discovery is backpressured per company/account while any prior
   operational work (`ready`, `running`, `waiting`) exists for that tenant.
3. Discovery catch-up uses contiguous persisted watermark windows; delayed
   execution cannot skip time by clamping the lower edge forward.
4. Scheduler lease, FIFO `available_at,id`, OAuth, Queue V4 readiness and
   Inventory behavior are unchanged.

## Capacity certification

MariaDB 11.8.8 tests cover fan-out 0/1/5/20, a 25-order paginated frontier,
pending-child backpressure, FIFO at the 15-job ceiling, a shared deadline, and
backlog 500 simulations.

- Zero business traffic: `500 → 350` after 10 simulated minutes.
- Moderate traffic (5 exact arrivals/min): `500 → 402` after 10 minutes
  (including two bounded inventory-refresh jobs).
- Normal bounded load (5 exact arrivals/min total): the certified simulation
  drains 98 jobs in 10 minutes, an observed net drain of 9.8 jobs/min. While a
  tenant has pending operational work, fresh discovery remains backpressured
  and therefore does not add another three root jobs per minute.
- Worst bounded page burst: queue can temporarily grow by 20 per account, but
  the same tenant cannot open a new frontier until those children drain.
- Sustained input above the protected API/service ceiling is correctly
  classified as non-convergent; the release does not bypass Mercado Libre
  budgets to claim otherwise.

## Deployment note

Installing the files does not edit Hostinger tasks. The existing single Cron
must be edited manually from `--max-jobs=3` to `--max-jobs=15`; no second task
may be created.
