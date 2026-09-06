# Calls: physical transport and Billing implementation

Date: 2026-09-06. Worktree: `C:/codex/ERP-BDM/WORK/h8fix`, branch `codex/capacity-config`. Assignment baseline `432c39e58af9f860600e30cae5c5b89026f05229`; independent root/manual/technical commits intervened without being staged by this worker. This report concerns the new calls plan, not the previous CAP2 completion.

## Delivered scope

- Extended the existing `QueueV4CleanCycleBudget`, not a second authority: automatic/manual owner, one outer lifecycle, finite deadline, exact per-attempt reserve/refund, no refund after the physical boundary, protected stop on real 429/uncertainty. Core remains Core; its fences/journal are not relabeled as V4.
- Client attempts generate a fresh server-owned 40-hex ID inside the retry loop, before rhythm reservation. Header, request log, permit and V4 transport event share it. Incoming request IDs cannot override it; owner/generation remain separate fencing identities.
- Physical cURL admission requires the outer context and recomputes deadline/timeout after slow SQL fences. Existing progress deadline and Core heartbeat remain. Certified pre-send cancellation is zero once; uncertain compensation is retained conservatively.
- Snapshot separates charged `used` from certified physical calls. Unresolved reservations yield `physical_http_calls=null`, certainty UNKNOWN and a separate known-sent count. Sent-without-response certifies one physical call, not a known response. Scheduler's top-level receipt also preserves this distinction.
- Exact private technical capabilities authorize only their source/method/path; the narrowly approved OAuth profile GET is allowed through the existing ownership guard. Generic web/cron metadata does not confer that capability. Technical launchers are owned and verified separately by the technical agent.
- Billing correlates only exact company/account/attempt identity, exactly one physical log and one dispatched permit with agreeing status. Legacy work IDs/time proximity cannot certify duplicates. Missing, partial, duplicate-source, tenant-conflicting or status-conflicting evidence remains UNKNOWN. A reserved permit alone is not proof of HTTP.
- UNKNOWN pause is finite and read-stable: configured conservative maximum anchored to the real 429, each Retry-After's own absolute deadline, and longer persisted pauses are respected. Expiration permits conservative operation without rewriting history as certain. Retained ambiguity older than 72 hours remains UNKNOWN; increases require the existing configured minimum of recent, fully correlated successes after the latest ambiguous evidence. Database authority failures block.
- A known 429 writes its exact fenced permit status while it remains dispatched, before computing the pause. A failure before the pause transaction leaves it blocking and preserves any longer pause; no transient incomplete pair invents a 12-hour pause for a provable first 429.

## TDD and verification authority

Superpowers test-driven-development and verification-before-completion were followed. PHP was `C:/xampphp/php/php.exe` (8.5.8); all DB fixtures were guarded, disposable databases on owned local MariaDB `127.0.0.1:33079`. Full schema fixtures use existing migration set 301 and were serialized with other workers. There were no runtime schema changes, remote Meli requests, emails, production accesses or deployments.

Evidence root: `D:/Codex/tmp/erp-meli/calls-20260906/transport`.

Meaningful RED → GREEN pairs include:

| Contract | RED | GREEN |
|---|---|---|
| Absent/nested budget, exact refund identity, no parallel metadata count, finite deadline | `budget-red.log`, `client-budget-red.log`, `scheduler-owner-red.log`, `refund-identity-red.log`, `metadata-red.log`, `finite-deadline-red.log` | corresponding `*-green.log`; final budget suite |
| Exact Billing pairs and fail-closed authority | `billing-identity-red.log`, `authority-red.log` | corresponding GREEN; final identity suite |
| Private technical source/path and profile capability | `sources-red.log`, `profile-red.log` | corresponding GREEN; final source suite |
| Real retry IDs/profile guard, physical loss stop, exact first429 pause, absolute Retry-After | `retry-absolute-red.log` (five expected failures) | `transport-integrated-green.log`; final transport suite |
| Historical73h UNKNOWN with zero new evidence | `history-red.log` | `history-green.log`, `history-measure-green.log` |
| Charged reservation is not certified HTTP | `receipt-certainty-red.log`, `scheduler-certainty-red.log` | corresponding GREEN and final suites |
| Existing fixtures adopt exact attempt IDs / Core outer context | `cap2_automatic_scheduler_mysql-contract-red.log`, `cap2_transport_scheduler-contract-red.log`, `cap2-transport-contract-red.log` | corresponding GREEN and final suites |

`known429-red.log` was a migration-lock failure, not behavioral proof. Earlier `known429-red2.log`/`known429-green.log` used an incorrect PHP timezone interpretation and are superseded by the UTC-correct `retry-absolute-red.log` and integrated GREEN. The first `physical-red.log` also exposed a test cleanup API typo; the exact owned disposable DB was safely verified and dropped, and subsequent runs clean up automatically. No misleading intermediate log is treated as final authority.

Final commands use PowerShell with TEMP/TMP set to the evidence root:

```powershell
& C:/xampphp/php/php.exe tests/calls_transport_budget.php
& C:/xampphp/php/php.exe tests/calls_billing_identity.php
& C:/xampphp/php/php.exe tests/calls_transport_sources.php
& C:/xampphp/php/php.exe tests/calls_billing_history_mysql.php
& C:/xampphp/php/php.exe tests/calls_transport_mysql.php
& C:/xampphp/php/php.exe tests/cap2_automatic_budget_mysql.php
& C:/xampphp/php/php.exe tests/cap2_automatic_scheduler_mysql.php
& C:/xampphp/php/php.exe tests/cap2_automatic_worker_context_mysql.php
& C:/xampphp/php/php.exe tests/cap2_transport_scheduler.php
& C:/xampphp/php/php.exe tests/cap2_transport_deadline.php
& C:/xampphp/php/php.exe tests/cap2_transport_mysql.php
```

The full transport suites use real client/services/SQL fences/journals with only the cURL wire boundary faked. They cover the four V4 physical sources, Core cancellation/tenant mismatch, slow-fence deadlines, actual sent loss, first429 with 99 remaining, compensation/commit/rollback failures, unresolved recovery/eligibility and exact worker metric joins. Scheduler suites use explicit stage doubles and are orchestration evidence, not physical-wire proof. Their fixture-only adaptations use 40-hex IDs and explicit Core outer ownership without relaxing assertions.

## Measured cost and limitations

The historical anchor adds one SELECT returning one aggregate row; no per-attempt correlated query or historical PHP fetchAll is introduced. With 20,121 retained physical rows the focused local measurement was 55.8292 ms and +3,032 bytes PHP memory. EXPLAIN showed linear table/derived scans and filesort, no dependent subquery. Existing schema301 account/tenant-prefixed indexes do not make a global Billing history scan constant-cost. This is a correctness/performance tradeoff, not a production scale guarantee. Root's admission health query budget is consequently 18 instead of the prior calls value17 (baseline before calls12). No extra index, cache or persistence architecture was added.

Historical certainty is supported by retained database evidence; this task does not change retention policy or fabricate certainty after evidence deletion. Broader release certification, RAW/browser recertification, graph/registry/package/deploy decisions remain with the parent. The local commit is not deployment authorization.

## Ownership and review

Own changes: nine runtime files (Core ownership guard; V4 cycle budget, scheduler, transport journal; execution metadata, rhythm, cURL, client, source policy), six new calls transport/Billing test files, and three existing scheduler/transport fixtures. Parent/manual/technical service files, their tests and pre-existing `qa/` are excluded from staging. Independent review was requested from the technical agent; the charged-versus-certified receipt finding was closed with dedicated RED/GREEN and coordinated manual/UI propagation.

Final verification: all 11 listed suites passed (eight fresh pure/minimal suites, the measured history suite, and both fresh full-schema suites). `calls_transport_mysql-final-green.log` and `cap2_transport_mysql-final-green.log` include the real cancelled0/sent-loss1/unresolved-preparationUNKNOWN receipt assertions. Eighteen owned PHP files passed syntax checks in `owned-lint-final.log`; `git diff --check` passed. Both final full-schema databases were cleaned up and the Migrator slot released. The independent reviewer reported no additional P1 in the nine runtime files after the receipt correction. No package or deployment is claimed here.
