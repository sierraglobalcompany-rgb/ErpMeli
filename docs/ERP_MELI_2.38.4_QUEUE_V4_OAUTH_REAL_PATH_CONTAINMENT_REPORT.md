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
