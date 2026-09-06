# Task 6 — Deadline and physical evidence consistency

Status: DONE — final owned verification passed; parent scoped runtime review has no blocking finding.

## Scope and implementation

- Preserve the admitted manual 45-second total / 43-second acceptance window established by Task 5. Nested `CronDeadlineContext::within()` cannot extend an outer deadline; scoped-only deadlines constrain physical timeouts too.
- Recompute cURL timeouts after physical fences. Progress checks the effective deadline and retains the existing Core heartbeat. Real cURL setup, source policy, SQL fences, leases and journals remain enabled in the main integration proof.
- Retain a process-local capability only for the exact provisional request. On certified NOT SENT cancellation, atomically undo only its own journal marker and source preparation, scoped by company/account/request/work/attempt/owner/generation. Actual execution consumes that capability before `curl_exec`; even a lost response cannot refund it. No new states, tables, migrations or frameworks.
- Core cancellation is tenant-bound and records cancellation diagnostics without modifying the previously reviewed Task 5 orphan methods. V4 queue, OAuth, sales-audit and sales-repair share certified compensation. Repeated repair cancellations use request-bound diagnostics. Lost COMMIT acknowledgements require certified cancellation; uncertifiable durable events and cycle reservations remain intact.
- MeliApiClient retains reservations when physical authority is unknown. Failed reservation/rhythm compensation becomes a protected uncertain outcome, not an automatic retry.
- Worker physical metrics use the canonical schema-301 journal with queue source, matching work/job, attempt and tenant joins. Read errors cannot become certified zero; unavailable receipts report null physical count / UNKNOWN dispatch.
- Real remote 429 deferrals retain reached-remote metadata and 429 receipts/stop reason. Uncertain work goes to review and stops the batch. Scheduler skips repair after a protected worker stop.
- Controller-authorized ownership expansion: existing V4 uncertain recovery, repository expiry/duplicate-enqueue and narrow scheduler stop logic. An uncertain reviewed/expired request cannot be automatically reopened. Confirmed NOT_DISPATCHED expiry remains eligible for normal deferral. Parent separately owns corresponding sales-audit, sales-repair and enrichment fixes and their tests.
- Historical ready/waiting rows also respect exact tenant/source/work unresolved physical evidence. One shared repository predicate governs claim, preview, count, coalesced finance extras and financial wakeup. It uses the existing journal index and does not add a per-transport query. Known RESPONSE_KNOWN 200 and genuinely NOT SENT rows remain eligible; wakeup cannot clear uncertain diagnostics.
- Existing legacy worker-only tests now install the canonical journal table and mirror each simulated handler call with real journal rows. They remain explicitly isolated control-flow tests, not physical-wire integration evidence.

## TDD evidence

Runtime: `C:/xampphp/php/php.exe` (PHP 8.5.8). Every MySQL run used guarded disposable test databases on the owned `127.0.0.1:33079` MariaDB, root/empty test-only credentials, APP_ENV=test, ML_WRITE_ENABLED=false. Full-schema Migrator runs were serialized with parent/browser ownership. TEMP/TMP/private storage were under `D:/Codex/tmp/erp-meli/cap2-20260905`.

Commands run from `C:/codex/ERP-BDM/WORK/h8fix`:

1. `php tests/cap2_transport_deadline.php`: RED `nested_deadline_cannot_extend_outer_window`; GREEN `CAP2_TRANSPORT_DEADLINE_OK` after effective-deadline corrections.
2. `php tests/cap2_transport_mysql.php`: initial RED `queue_after_deadline_blocked:no_error` demonstrated that a completed physical fence could outlive the deadline and still send. The four-source matrix then passed deadline-before-marker, deadline-after-marker, timeout reduction after a slow fence, progress abort, sent/no-response, and failed compensation.
3. Subsequent focused RED assertions caught mixed source/work IDs in physical metrics; genuine uncertain GET returning waiting/autoretry; genuine ApiRhythmDeferredException 429 reporting a generic deferred reason; Core sent markers being refundable; a foreign-tenant Core journal being cancelled; repeated repair cancellation misclassified as owner loss; unknown physical authority being refunded.
4. `php tests/cap2_transport_mysql.php` with log `qa/task6-extension-red.log`: four explicit failures — failed rhythm compensation did not throw protected uncertainty, reviewed uncertain work was automatically recovered, expired physical attempts were retried, and a failed journal/metadata probe fell back to zero. GREEN in `qa/task6-extension-green.log` after minimal fixes.
5. `php tests/cap2_transport_scheduler.php`: RED `scheduler_does_not_enter_repair_after_remote_result_uncertain`; GREEN `CAP2_TRANSPORT_SCHEDULER_OK`. This is a dedicated orchestration test with isolated stage outcomes and real scheduler, policy, SQL lease, and cycle budget.
6. `php tests/k1d_rc1_worker_429_regression.php`: legacy fixture failed because it lacked the required canonical journal, not because the production metric should fall back. Fixture upgraded without relaxing runtime authority. GREEN: one 429, no later call, remaining budget 99.
7. `php tests/k1d_rc1_worker_budget_cycles.php`: GREEN 180 actual worker cycles, levels 1/2/3/15/55/100, max 100 transports per cycle, zero budget violations. Real journal rows mirror simulated handler calls; equality with the physical metric is asserted.
8. `php tests/cap2_transport_mysql.php` with `qa/task6-commit-red.log`: eight intentional lost-COMMIT-acknowledgement failures across queue/OAuth/audit/repair, plus duplicate enqueue reopening an uncertain review. Corrected using process-local preparation authority and existing row identities.
9. Parent review identified INSERT acknowledgement loss before the capability setter plus a failed rollback. `qa/task6-rollback-red.log` contains four exact `*_unknown_insert_and_rollback_never_refunded` failures; `qa/task6-rollback-green.log` passed after tracking preparation before SQL and accepting only certified rollback/cancellation.
10. Following that failure through the real MeliApiClient produced `client_cannot_downgrade_transport_uncertainty_from_later_not_dispatched_read` in `qa/task6-client-red.log`. `qa/task6-client-green.log` passed after preserving protected transport uncertainty before any later state read/refund. This models rollback completing but its acknowledgement being lost, not a fabricated physical response.
11. Parent-owned commit `8779fcb` separately fixes and tests historical audit/enrichment/repair uncertainty and a repair completion owner/generation race. Its real-service RED evidence is `qa/task6b-uncertain-red.log` (six expected failures) and `qa/task6b-historical-red2.log` (five expected failures); `qa/task6b-historical-green.log` passed fourteen checks. I independently reviewed those three services and their test without editing/staging them. Historical physical uncertainty is intentionally quarantined until explicit reconciliation/authorization, not automatically retried.
12. Historical queue quarantine RED in `qa/task6-quarantine-red2.log`: `historical_uncertain_not_preview_eligible`, `historical_uncertain_not_counted_eligible`, `historical_uncertain_not_claimed_known200_and_not_sent_still_claimed`, `financial_wakeup_excludes_uncertain_preserves_known_and_not_sent`, `financial_wakeup_preserves_uncertain_diagnostics`. GREEN in `qa/task6-quarantine-green.log` after the shared journal predicate. The first quarantine log is a missing-fixture-input_version setup failure, not behavioral RED.

Initial fixture setup errors (missing required seeded fields, invalid fixture cleanup method, wrong worker argument order) were corrected before treating those runs as RED evidence. They are not feature-failure evidence.

## Evidence files

All exportable logs live in `D:/Codex/tmp/erp-meli/cap2-20260905/qa/` with `task6-*` names. Initial extension logs were copied from the task root and hash-verified:

- task6-extension-red.log: SHA256 C2FC9B5C718C500D4BBA98C2A6943681BA1CF81FFA03C948EBA35108971BD283
- task6-extension-green.log: SHA256 1B67266E3BF6DF753290F97641685BC7F755B270BFEE6C7221E4A86FA272FB21
- task6-scheduler-red.log / task6-scheduler-green.log
- task6-legacy-red.log
- task6-commit-red.log
- task6-commit-green.log
- task6-rollback-red.log / task6-rollback-green.log
- task6-client-red.log / task6-client-green.log
- task6-domains-green.log / task6-manual-green.log
- task6-legacy429-green.log / task6-legacycycles-green.log
- task6-quarantine-red2.log / task6-quarantine-green.log
- task6-lint-final.log
- task6-domains-final.log / task6-manual-final.log / task6-scheduler-final.log
- task6-legacy429-final.log / task6-legacycycles-final.log

Fresh final verification after the last runtime change: main transport matrix `CAP2_TRANSPORT_MYSQL_OK` (28 printed source/Core matrix passes plus the authority, client, historical queue and finance-wakeup assertions); domains/default-hybrid and genuine 429 with 99 remaining; manual launcher; scheduler stop; 21 owned PHP files lint-clean; deadline, manual budget and presentation tests passed. The final legacy 429 proof passed. Final legacy budget rerun passed all 180 cycles (30 each at 1/2/3/15/55/100), max 100 transports per cycle, zero violations, canonical journal metric equality. Every listed final command exited 0; `git diff --check` passed. No owned database suite remained active at commit time.

The initial malformed fixture left one database; it was identified by its exact owned name and CAP2-only contents, guarded again with K1dSafeTestDatabase::assertGuard, and removed. All normal harness runs clean their own database.

## Self-review and remaining cross-task obligations

- No real Mercado Libre HTTP, email, SSH/FTP, production configuration, Cron, push, merge or deployment occurred. The main tests replace only the wire boundary and virtual time; the journal/read-failure and lost-commit tests inject narrowly scoped PDO faults while all other SQL remains real.
- Steady successful transport adds no SQL scans: timeout/progress work uses the clock, and compensation SQL runs only on NOT SENT preparation failures. Worker metrics remove the old metadata probes/fallback. Parent owns final measured baseline/target performance comparison.
- Parent owns independent review, the three related source-service uncertainty paths, browser coverage, full branch QA/registry/integrity and RAW package/rollback generation. Their edits and preexisting qa/ were not staged by this task.
- Parent also owns rerunning affected automatic-context/manual-runtime/selection suites after the final eligibility predicate, with honest canonical-journal fixture upgrades where required. The earlier parent passes are superseded for those affected paths. I reviewed the parent's updated K1D static protected-stop assertion; real transport/429 tests remain the behavioral authority.
- Source schema remains 301 and runtime version remains 2.40.1. No claim of sustainable production throughput is made.
- Parent's final scoped review found no blocking issue in preparation/rollback authority, early uncertain propagation, tenant-bound shared quarantine, deadline/heartbeat, physical metric joins or protected scheduler stops. No further runtime change is planned.

## Owned commit boundary

Explicit allowlist: twelve runtime files (Core fence/repository; V4 read/OAuth fences, journal, repository, recovery, worker, scheduler; Curl transport, deadline context, MeliApiClient), seven new `tests/cap2_transport*.php` files, the two legacy worker fixtures/tests, and this report. No parent source/test, browser/package file, migration, registry or preexisting `qa/` is included. The report is force-added by exact path because `.superpowers` is normally ignored. Starting Task 6 base was `d0df1c0`; intervening independently reviewed parent commits are preserved.
