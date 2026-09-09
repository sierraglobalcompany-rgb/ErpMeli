# Task 7 — Independent cumulative branch review

Status: **APPROVED FOR FINAL PACKAGING, WITH EXPLICIT LIMITS**. No unresolved blocker or important runtime finding remains in the reviewed CAP2 source. Final RAW artifact construction, reopen/hash/application verification and operator handoff are still pending and are not certified by this review.

## Reviewed authority

- Product patch base: `191c5ee708d154471a442dfb6cca3324f8609b01`.
- Cumulative runtime review target: `7550160` (Tasks 1–6, including the protected-stop regression). Later commits `52c59dc`, `4be5551`, `f327fd0` and `bea6af1` contain browser proof, evidence retention, bounded performance tests and a supplementary shared-budget test; their relevant changes were also reviewed.
- The final uncommitted metadata correction at review time adds the exact `CapacityChangeGuard.php` dependency plus a focused publication regression. Its paired RED demonstrated the missing registry entry; the GREEN run exited 0 with `STATUS=PASS CAP2_PUBLICATION`.
- Reviewed inputs included the approved plan, `D:/Codex/tmp/erp-meli/cap2-20260905/qa/MATRIX.md`, Task 1–6 reports and independent review/rereview evidence, production diffs, current tests, browser evidence and bounded performance logs.

## Verdict by requirement

### Capacity authority and administration

- The ERP policy is the effective authority: strict `1 <= current <= ceiling <= 100`, default ceiling 55, read-only snapshots, revision-bound writes and module-scoped transactional locking. CLI values can only reduce the stored current value.
- Generic settings paths no longer write managed capacity fields. Legacy-derived manual capacity requires explicit pair adoption before unrelated rhythm changes can alter it.
- Settings preparation and confirmation preserve permanent-admin, same-origin and CSRF checks and re-run global company/account authorization. Actual current increases alone invoke the fail-closed health gate; ceiling-only changes do not.
- `CapacityChangeGuard` covers active companies/accounts plus runnable job/OAuth/audit/repair scope, account ACL configuration, complete readiness blockers and reached-remote 429 evidence. Unknown schema/read failures deny an increase without exposing foreign-tenant detail.

### Automatic execution and exact domain work

- Scheduler and worker retain one outer cycle budget. No-argument execution uses current policy; requested aliases are capped by current, ceiling and technical maximum. A saved reduction is re-read before work and wins over an earlier request.
- The worker rejects an absent or oversized outer budget before run/count/claim side effects and does not reset that budget. OAuth, queue, audit, repair and exact domain reads share the same physical-call counter.
- Exact pack/order/shipment/question/claim/item reads use tenant-bound durable source identity plus job/attempt/owner/generation metadata. The source policy and physical fence use a closed GET path map; mismatched method, type, ID or source fails closed.
- Item exact review normalizes the canonical item ID and propagates exact-path failures instead of swallowing them. Independent real-service tests use the real handlers, fences, journal and SQL; only the final wire is replaced.

### Manual one-shot browser execution

- Preview admission is an atomic ready-to-consumed transition bound to token, user and expiry. Scope, current policy, deadline, busy state, exact source identity and remote contract are revalidated before admission and effects.
- Exact work runs under one global manual lease, processes only the browser-confirmed rows and never falls back to unrelated FIFO work. Old POST replay remains idempotent and produces no new wire call; continuation requires an actual new preview.
- Successful pagination/checkpoint outcomes remain ordinary deferred results. Persisted 401/403/429, uncertainty and lease loss close locally as protected/review outcomes and stop the remaining selection. Result counts distinguish completed, deferred, review and not-started work; there is no background continuation.
- Expired or abandoned physical uncertainty is quarantined for review. A pending pre-claim orphan is recoverable only after a fresh admitted preview proves the consumed-preview provenance; recovery does not fabricate source status or cursor progress.

### Deadline, transport evidence and replay safety

- The browser request owns one 45-second total window with 43-second work acceptance. Nested deadlines can only shorten it. cURL timeout values are recomputed after the physical fence, and progress checks preserve the effective deadline and heartbeat.
- Physical budget is claimed at the source-specific durable fence. Queue, OAuth, audit and repair cancellation can compensate only a request-bound, tenant-bound, owner/generation-bound preparation certified NOT SENT. Actual or uncertain sends are never refunded or retried automatically.
- Lost INSERT/COMMIT/rollback acknowledgement becomes protected uncertainty unless exact compensation is certified. Response-known evidence is durable. Historical uncertain rows remain excluded from preview, count, claim and financial wakeup; known 200 and genuine NOT SENT rows retain their supported paths.
- Worker physical metrics require the canonical queue journal row with matching source kind, work/job, attempt and tenant. A metric read failure cannot become a certified zero. Real 429 metadata and the global stop reason survive through the worker/scheduler boundary.

### Publication and metadata

- Runtime version remains 2.40.1 and schema remains 301. No migration, table or framework was added.
- Workflow/evidence files remain non-runtime. Runtime manifest coverage includes the added files.
- One pre-package metadata omission was found: `app/Services/CapacityChangeGuard.php` existed in the runtime manifest but was not an explicit `runtime_dependencies` entry. The reviewed correction follows the existing tracked-PHP-autoload pattern, names the real `SettingsController` consumer, has no duplicate ID/path, and requires runtime-manifest presence. The focused publication test now checks all four new CAP2 dependencies. This issue is resolved in the reviewed working diff and must be included in the final target.

## Independent validation performed

All commands below were run from `C:/codex/ERP-BDM/WORK/h8fix`, with no real Mercado Libre HTTP, SSH/FTP, email, Cron, push, merge or production mutation.

- Changed runtime PHP lint: 41 files, 0 failures.
- `git diff --check 191c5ee..7550160`: exit 0. Current working diff check also passed during review.
- Parent-owned supplementary files `tests/cap2_transport_mysql.php`, `tests/cap2_policy_performance.php`, `tests/cap2_queue_performance.php` and `tests/cap2_publication.php`: syntax clean and source-reviewed.
- Shared-budget supplement: one budget of 3 is retained across real queue, OAuth, audit and repair physical fences without a reset; the first three reach the fake wire and the fourth is denied with zero additional wire. This is valid supplementary fence integration evidence, not a claim of a complete production scheduler E2E.
- Browser configuration proof: real production Auth/controller/guard/SQL/templates with desktop/mobile coverage for automatic/manual save, cancel, reload, scalar and pair validation with storage unchanged, two-tab revision conflict, 429/unknown increase denial, ceiling-only allowance, anonymous/temporary/CSRF denial. Final log: `qa/browser-config/review-rerun-browser.log`.
- Browser manual proof: real production Auth/controller/preview/launcher/services/fences/SQL/templates, with the fake only at the cURL wire. It covers normal 200 checkpoint, protected 429 stop, post-checkpoint failure, old POST zero-wire replay and explicit new-preview continuation on desktop/mobile. Final log: `qa/browser-step/final-browser.log`; direct screenshots and wire/server logs are retained under the same bounded evidence directory.
- The final browser fixture did not write source statuses or cursors to manufacture continuation. It removed only its own trigger, advanced due time and let actual processing persist a saved order cursor of 2 and complete the question; total wire evidence remained truthful.

## Performance evidence and its boundary

The stable result is query-count parity, not a speedup:

- Policy absent settings: baseline median 249.689 ms versus target 262.526 ms for 2,000 reads/2,000 SELECTs, no writes (`+5.1%` in this run).
- Policy explicit settings: baseline median 258.922 ms versus target 308.619 ms for 2,000 reads/2,000 SELECTs, no writes (`+19.2%` in this run).
- Queue eligibility: baseline median 116.347 ms versus target 146.888 ms for 250 reads/250 SELECTs over 1,000 jobs/events, with no INSERT during measured queries (`+26.3%` in this run).

Other quiet repeats retained by the parent produced lower and opposite wall-clock deltas. The environment and short run length therefore show material timing variability. These measurements certify that the target did not add per-call incident scans or extra query round trips in the measured paths; they do **not** certify a wall-clock improvement, sustainable throughput, complete HTTP transport cost, or production capacity at 55/100.

## Non-blocking observation

The notification catalog accepts an item resource pattern case-insensitively, while the exact transport map requires the canonical uppercase item path. `createReviewForItem()` uppercases before issuing the request, but the durable metadata retains the source ID. A noncanonical lowercase webhook item ID would therefore fail the identity equality check and be quarantined rather than sent. This is fail-closed and cannot broaden a path or cross a tenant; Mercado Libre item IDs are canonical uppercase. It is a compatibility observation, not a release blocker.

## Explicit limits and final obligations

- The lowest physical HTTP boundary is fake in domain/manual/browser integration. There was no real Mercado Libre request, and no production load or sustainability claim is made.
- The legacy aggregate suite remains an exact-baseline comparison with 115 tests and 51 known failure identities, not a green suite. Final packaging must retain the normalized 51/51/0-difference authority rather than reporting only counts.
- Parent-reported final broad PHP lint (894 files, 0 failures), manual seven-suite run, transport suite, 429/drainer integration and K1D helpers are supporting evidence. This reviewer did not rerun those DB suites after the final parent sessions and does not relabel reported results as independently executed.
- Final packaging must commit the reviewed registry/test correction, freeze the exact HEAD, build the cumulative RAW delta from `191c5ee`, regenerate/check manifest and registry authority, reopen every ZIP entry, verify all hashes, apply the patch to an isolated base tree, and verify rollback replacements plus explicit removal of added files.
- The proposed isolated RAW verification is acceptable if its Git work tree is the exact archived final HEAD and the authoritative Git directory is used read-only for historical/canonical blob checks; package contents must additionally be verified independently of that Git directory by reopening hashes and applying the generated patch to the immutable base.
- No final deploy/QA ZIP, CONTROL, rollback guide or read-only operator handoff is approved by this review until those artifact-specific checks pass.

## Review ownership

This review was read-only for runtime and parent-owned tests. The only reviewer-owned output is this report. No runtime, shared fixture, migration, registry, package helper or parent test was edited or staged by the reviewer.
