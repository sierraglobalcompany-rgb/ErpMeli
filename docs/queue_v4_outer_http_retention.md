# Outer HTTP receipt retention — parent-only PR33

Outer telemetry uses one parent row per invocation and zero children.
Refunded boundaries update the same parent; they are not physical calls.
No outer-specific cold datasets, encryption, whole-month finalization,
child archival/rollup/membership or round-robin dataset is used.

## Existing maintenance lane

QueueV4CleanMaintenanceService calls OuterCronHttpReceipt::retainStep on
each maintenance invocation, independently of technical dataset rotation.
No new Cron/worker/queue/setting. Existing canonical API log/permit retention
is unchanged.

TTL is retention.incident_days, default90 and minimum30. The step selects
at most100 raw parent IDs through the component/id index, before validating
age/status/JSON, and performs at most10 exact-ID/identity/JSON-byte deletes.
It reuses one namespaced checkpoint row (outer_http_receipts) in the existing
system_retention_cli_state table. A monotonic ID cursor visits held evidence
without letting a malformed or unknown prefix permanently starve later IDs.
An empty window resets the cursor for the next maintenance invocation.
This is constant progress metadata, not a per-request ledger, new dataset,
rotation engine, table or setting. Existing technical lane checkpoints
are separate and unchanged.
No loop over months or unbounded delete. It checks canonical accept-work
and effective remaining deadline before selection and each deletion, using
a2-second reserve. Near-deadline and externally-owned transactions skip
without mutation. Indexed/limited logical work is bounded, not a promise
about wall-clock database outage latency.

Only exact component, terminal and finished, valid consistent closed JSON,
no pending, unknown0 and known total are eligible. Known ID list/count/
uniqueness must agree; running/interrupted/incomplete/malformed evidence
remains held. Existing FK children also hold a parent, avoiding cascading
evidence deletion. DELETE revalidates exact bytes/component/status/TTL.

With one natural Cron invocation/min, eligible service capacity is10/min
and intake1/min: margin9/min. Tests drain100 eligible parents in10 bounded
steps. Held evidence is explicitly outside this purge service model:
it may remain indefinitely and must not be deleted to fabricate a bound.
Mixed/invalid candidates can reduce actual deletion rate:10/min is eligible
service capacity, not guaranteed throughput in every mixed window. Tests
also preserve3000 held parents while ANALYZE reports100 examined rows, and
advance past a held prefix to delete eligible parents behind it. Unknown
backlog is intentionally not globally bounded or automatically resolved.

The previous cold-archive and boundary-ingress blockers are removed by
changing row cardinality, not by increasing physical budget or altering
refunds. HTTP history remains in existing logs/journals/permits/captures
and their existing retention, not in this receipt forever.
