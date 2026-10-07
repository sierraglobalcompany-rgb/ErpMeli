# Phase 2 — multi-order Billing design, NOT implemented

## Scope and decision gate

Phase 1 adds an outer physical HTTP receipt. It does not establish the cause of the historical 429 and does not change Billing cardinality. The supplied API ceiling of 60 IDs is a design constraint, not a production choice. Before implementation, confirm the exact response mapping against `docs/mercadolibre_api_map.md` and the approved external Billing contract. No new endpoint is proposed.

## Reuse first

Keep `BillingCaptureTransportAuthority`, `SaleFinancialService`, `completedBillingOrderCheckpoints` and `findUnconsumedDurableResult` as the authorities. Do not add a queue, recovery engine, cache or parallel transport. A request parent must represent one physical request and its exact ordered membership; children must represent existing Financial jobs and their independent claim generations. Extending existing capture authority is preferred over a separate service. Any parent/child schema extension belongs to a separately approved migration after Phase 1, not migration 303.

## Parent and child permissions

Parent identity: company, MELI account, seller/auth scope, request ID, documented endpoint, immutable membership hash, reserved/dispatch/known-or-unknown result state and durable raw response. Never combine tenants or incompatible auth contexts. Physical budget reserves once for the parent, releases once if no send and charges once at cURL; N children never consume N HTTP units.

Child identity: company, account, external order ID, Financial source ID, input version, lock owner and claim generation. Reserve compatible children through the existing claim contract. Recheck every child before the parent's final dispatch permission. If membership becomes invalid before dispatch, abort the untouched reservation; do not silently replace a member or reuse a request ID for different bytes.

After dispatch, preserve `PHYSICAL_RESULT_PERSIST_PERMISSION != FINANCIAL_FINALIZATION_PERMISSION`. A known parent response must persist even if a child's lease has changed. Financial finalization independently rechecks that child's input and generation. Stale children can reuse durable results only through the existing exact result-consumption contract; they do not grant other children permission.

## Response matrix

| Response | Parent | Children / subsequent work |
|---|---|---|
| Complete known 200 | Persist once; one physical HTTP | Validate and persist each matching child, then independently finalize |
| 200 with missing child | Persist raw known response | Known valid children are not resent; missing child is unresolved, not successful |
| 206 | Persist known partial response | Same exact per-child validation; HTTP status alone grants no success |
| Duplicate requested child | Known parent, contradictory child mapping | Hold affected ambiguous mapping; never choose first/last arbitrarily |
| Unknown or wrong-tenant child | Preserve response as evidence | Reject mapping; do not import into any tenant |
| Malformed child | Preserve known parent | Hold malformed child; preserve independent valid children only if contract permits exact separation |
| 429 | One known physical HTTP | All children inherit transport deferral; none are reconciled; preserve existing pause/backoff |
| Known 5xx | One known physical HTTP | Existing certainty-aware deferral; no fabricated child result |
| Unknown response / crash after boundary | Parent remains unresolved | No automatic resend of the parent or its children while uncertainty fence applies |

For 60 requested IDs with 59 durable valid results and one omission, the 59 become reusable checkpoints. Only the omitted child may become retry-eligible after an explicitly defined safe contract permits it. A known parent 200 is not proof that the omitted order was processed successfully. No blanket retry of all 60. Replays are idempotent by exact parent and child identities, not approximate payload matches.

## Crash and concurrency

Before final dispatch, abortable reservations remain local and uncharged. After the final fence, no new rejecting gate may be inserted before cURL. Persist raw known parent independently of later child locks. Crash after durable parent result but before child completion must resume local consumption without HTTP. Parent membership, physical request ID and known response bytes remain immutable. Competing claims cannot finalize the same child generation twice.

## Future verification and rollout gate (not executed)

Test cardinalities 1, 5, 20 and 60 with MariaDB and fake transport. For each, prove one physical identity, one shared budget charge, tenant isolation, final dispatch fence, complete/partial/429/5xx/uncertain response handling, child lease and input drift, replay, crash recovery and zero resend of known children. Test 59+1 explicitly, duplicate and unknown child IDs, and stale generation races.

Do not choose production N yet. Separate approval must select a small canary based on those tests and durable Phase 1 endpoint/429/unknown measurements. Keep existing rhythm, backoffs, global budget and emergency stop; no parallel parents. Installation of Phase 1 does not authorize Phase 2 implementation, migration or canary.

## Project direction

Use the new receipt to measure the actual effect before changing traffic. Resolve a demonstrated bottleneck, verify legitimate progress, then return to the roadmap. The goal remains a simpler, maintainable ERP with one execution authority, durable work and no loss, duplicate effects or tenant crossings—not an additional architecture.
