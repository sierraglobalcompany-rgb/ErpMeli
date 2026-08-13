# ERP MELI 2.38.4 — Queue V4 OAuth real-path containment

## Incident authority

Production 2.38.3 proved the local OAuth pretransport path, private recovery storage,
cryptography, database identity and refresh-version fence. Two expired operations were
left `RUNNING/NOT_DISPATCHED` and a third remained `SCHEDULED`. The persisted fence and
request logs certify zero remote HTTP, but the former launcher discarded the Throwable,
so its exact local class and stage are not recoverable retrospectively.

## Correction

2.38.4 keeps schema 296 and adds a CLI capability preflight before scheduling or claiming
OAuth work. Missing cURL, PDO MySQL, JSON, OpenSSL/randomness or fsync blocks the whole
scheduler cycle with zero claim and zero remote HTTP. The cURL adapter repeats that guard.

The real path now publishes a bounded stage authority from operation-profile resolution
through the dispatch fence and token CAS. The launcher records only a random diagnostic id,
normalized class, enumerated stage, file basename and line. Messages, traces, payloads,
tokens, ciphertext and connection secrets are never emitted.

The supervisor has a final `Throwable` containment policy driven exclusively by the fresh
persisted fence. `NOT_DISPATCHED` returns to `WAITING`; `MAY_HAVE_DISPATCHED` becomes
`REMOTE_UNCERTAIN`; known 429 waits; known 2xx is reconciled from refresh generation or
durable escrow without another POST. If the fence cannot be read or transitioned, the
cycle aborts as `OAUTH_CONTAINMENT_FAILED` and does not guess that a retry is safe.

An unexpected OAuth fault aborts the same scheduler process before Producer and Worker.
The existing stale-operation repair remains the only repair authority and idempotently
returns expired `RUNNING/NOT_DISPATCHED` operations to `WAITING`.

## QA

The focal integration test enters through `QueueV4CleanOAuthSupervisor`, constructs a real
`MeliApiClient`, runs `OAuthTokenRefreshService`, operation profile, ownership, metadata,
API guard, rhythm, budget and dispatch fence, and replaces only the final physical transport.
It covers missing cURL, pre-dispatch TypeErrors, cURL preparation, budget infrastructure,
fence persistence, uncertain dispatch, 429, 5xx, malformed 2xx, escrow/CAS recovery,
containment failure, stale production preimage and scheduler abort. No real Mercado Libre
HTTP or business write is performed.

## Post-install safety

Cron remains disabled. The first production action is the zero-HTTP command
`jobs/queue_v4_runtime_self_check.php`. Only a complete PASS may authorize a later,
separately controlled Queue V4 run. The build does not execute Cron, OAuth, readiness or
any production mutation.

## Ten-pass final re-audit

A second adversarial audit was performed after the first build and before publication.
It covered release authority, the cross-layer call graph, zero-HTTP CLI preflight, stale
repair, persisted dispatch fences, 429/rate policy, tenant isolation and secret handling,
leases/CAS/escrow concurrency, global regressions, updater/runtime authorities and
reproducible artifacts.

The re-audit found and closed the following additional defects:

1. A local Throwable immediately after the committed operation claim but before the first
   counter read could leave the operation `RUNNING`. Containment now begins at the new
   `OAUTH_OPERATION_CLAIMED` stage and returns the operation to a fence-derived state.
2. A Throwable raised by repair, capability inspection, scheduling or claim preparation
   could escape through the generic launcher path. All pre-claim failures now return a
   sanitized `oauth_control_plane_blocked` result with zero claimed operations.
3. The final physical-POST counter read was outside the final Throwable boundary. It is now
   inside containment, with the persisted dispatch fence as the independent fallback.
4. Generic known-429 handling used a fixed 60-second delay. It now uses the shared rhythm
   authority's conservative canonical backoff and never shortens Retry-After.
5. Durable escrow cleanup occurred before, or could block after, the committed MariaDB
   generation/operation state. The database postimage is now made terminal first; cleanup
   is best-effort and emits only a sanitized deferred-cleanup diagnostic.
6. OAuth stage provenance could remain set while Producer/Worker ran in the same PHP
   process. The stage context is reset after the OAuth abort gate and before Producer.

The expanded real-path test contains 38 checks. It includes dynamic pre-claim and
post-claim TypeErrors, the complete fault matrix, canonical 429 delay, stale repair,
escrow/CAS adoption, containment failure and scheduler abort. It still reports
`real_meli_http=0`, `business_writes=0` and `raw_storage_touched=false`.

The exact historical Hostinger Throwable remains unrecoverable because 2.38.3 discarded
its class and stage. This is not represented as a guessed root cause. The production
boundary classes that can reproduce the observed `NOT_DISPATCHED` postimage are now
identified safely by the sanitized stage receipt and contained without an orphaned claim.
