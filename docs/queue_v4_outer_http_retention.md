# Outer HTTP receipt retention — PR33 correction candidate

Status: BLOCKED, not release/install ready. The existing pipeline is reused;
the two throughput/runtime blockers below must be resolved before approval.

## Scope and preservation

`TechnicalRetentionCliService` appends `outer_http_attempts` followed by
`outer_http_runs`. Existing dataset positions retain their meaning. No new
Cron, queue, engine, setting, business journal or HTTP operation is introduced.

Only `queue_v4_outer_http`, no manual campaign, terminal status with a valid
closed JSON receipt and `finished_at` can enter archival. Running, malformed
and incomplete receipts stay hot indefinitely; unknown dispatches are never
resolved by retention. Recent 429 and unknown evidence remains intact.

Both datasets are partitioned by the parent's actual **finish month**. The
child archive contains the original full child row, including its original
reservation timestamp. Partition membership is not inferred from that
timestamp. A late close belongs to a later archive, not to an already-ready
reservation-month archive.

Hot deletion reuses `retention.incident_days` (default 90), with a floor of
30 days and no new setting. Archive verification, membership verification,
rollup and exact row checksum precede deletion. Children are removed first;
the parent's predicate requires absence of **all** children. The existing
FK cascade therefore does not remove unverified child rows. Changed rows
remain hot with stale membership. Closed receipt reads use their persisted
aggregate, never zero inferred from archived detail. Incomplete receipt
reads continue to census available children.

The archive registry currently uses an ENUM, so migration303 necessarily
appends exactly these two keys while retaining all previous values. Its
column and unique-key DDL is canonical, exact-once, without `IF NOT EXISTS`.
Migrator checksum drift blocks; partial DDL remains failed, not silently
adopted. Production compatibility requires a later release preflight.

## Bounds that this candidate does NOT yet establish

The scheduler maintenance call passes at most 100 rows to retention. Each
detail row needs one archival pass and one deletion pass. Even with every
cycle dedicated to those two passes, the upper bound is 50 attempts/minute;
other datasets, parents, daily rollups and archive finalization reduce it.
At 55 known HTTP/minute, hot backlog grows by at least 7,200 attempts/day.
Cancelled-before-transport rows and unresolved holds add to that growth.
No finite steady-state hot-row maximum can honestly be certified.

Archive batch writes/deletes are bounded, but finalization/encryption and
verification still scan a whole monthly archive in the existing pipeline.
Lease renewal is not a 45-second wall-clock bound. A tiny fixture passing
R10 does not prove sustained scheduler capacity or finalization runtime.

Required next correction: within the same retention pipeline, demonstrate
service rate above 55/minute **including** both passes and other work, and
bounded/resumable archive finalization/verification that cannot monopolize
the scheduler. Merely increasing a batch number would not resolve both
findings. Do not deploy this candidate or label these findings Minor.
