# Task 4 — packs and exact notifications

## Scope and implementation

Base: `442c8413c0d7aaf02445a92609f3687ff62ec426`, worktree `C:/codex/ERP-BDM/WORK/h8fix`, branch `codex/capacity-config`.

Status: DONE_WITH_CONCERNS — owned implementation and focused physical/regression tests pass; legacy aggregate remains 115/51, and the Task 6/7 integrated gates remain explicitly open.

- Worker wraps pack and notification domain calls in `QUEUE_V4_DOMAIN_EXACT`. Company/account, resource type and remote ID come from the tenant-bound durable source SELECT; queue job, attempt, owner and generation come from the claimed durable queue row. Inner legacy `cron`/`webhook_worker` arguments cannot override this context.
- One shared closed GET predicate serves source policy and the physical V4 fence. Supported exact resources are pack, order, shipment, question, claim and item; endpoint journal keys are resource-specific. Numeric IDs and item IDs have closed syntax and must match the server-bound metadata. Existing finance billing capability remains unchanged.
- An active V4 cycle rejects transport sources that have neither the V4 read fence nor the V4 OAuth fence, before legacy transport can escape the cycle counter.
- Queue exact notification dispatch uses the existing clean order adapter and single-read/local-relationships shipment adapter. Question and claim retain their exact services. No replacement notification child jobs, legacy order fanout or secondary endpoint fallback was added.
- Queue exact shipment inspection may read a shipment without a pre-existing local order. It persists an unlinked snapshot when the order is unknown, without fetching the order. The manual inspection prerequisite is unchanged.
- Items are classified as `item_exact`, API-capable with conservative one-call estimate. Actual existing review and cached pack branches execute with zero physical calls.
- Strict item review propagates errors, including real 429, and cannot reuse a failed item snapshot as success. Queue exact notification deferral/failure is persisted before errors are rethrown to the worker. The original `ApiRhythmDeferredException` and its `reachedRemote` are retained. Independent review/bulk scanning remains tolerant; manual exact admission/outcome changes remain Task 5.
- Existing notification leases, processing/latest event IDs, acknowledgements and rerun behavior are retained and exercised.

No engine ownership guard was relaxed. No Task 5 admission, Task 6 deadline/compensation/metric query, schema, migration, version, deployment, external messaging or real HTTP changes were made. Pre-existing untracked `qa/` was not staged or modified.

## Test architecture and environment

The main test uses real `Migrator::run(301)` on a uniquely named `erp_meli_k1d_test_cap2_domains_*` database guarded by `K1dSafeTestDatabase`. It runs real worker, repository, domain services, MeliApiClient, ownership guard, rhythm permits, cURL preparation, V4 dispatch/response fences and transport journal. Only namespaced `curl_exec`, `curl_getinfo` response fields, and `curl_error` are replaced at the actual wire boundary. Real `CurlHandle` options provide the requested URL. Unexpected wire paths fail; there are no handler, service or `MeliHttpTransportInterface` doubles in the main test.

The reusable test-only boundary is `tests/cap2_domains_wire_fixture.php`. It supports exact-path response bodies/status and an at-wire callback (used to enqueue a newer event while the first GET is in flight). It does not simulate response headers, network timing or TCP behavior; those are not claimed by this task.

Every PHP command below ran from the task worktree after:

```powershell
$env:TEMP='D:/Codex/tmp/erp-meli/cap2-20260905/tmp'
$env:TMP=$env:TEMP
```

PHP: `C:/xampphp/php/php.exe` 8.5.8. Main fixture sets `APP_ENV=test`, `ML_WRITE_ENABLED=false`, `DB_HOST=127.0.0.1`, `DB_PORT=33079`, `DB_USER=root`, empty local disposable DB password, unique safe DB name, test-only encryption key, `MELI_API_BASE=https://cap2-wire.invalid`, and owned `PRIVATE_STORAGE_PATH=D:/Codex/tmp/erp-meli/cap2-20260905/qa/domains-private`. It never loads real OAuth secrets. MariaDB is the parent's owned 512 MiB `meli-cap2-qa` container. Each fixture database is dropped in `finally` under the same safety guard.

## TDD evidence

Required TDD, writing-good-tests, implementer-prompt and verification-before-completion instructions were read and followed. Expected failures were observed before the corresponding production change. Fixture construction errors (wrong event column assertion and a missing required shipment `synced_at` fixture field) were corrected as fixture errors, not counted as RED proof.

### RED 1 — closed identity

Command: `& C:/xampphp/php/php.exe tests/cap2_domains_policy.php`

Exit 1; expected assertion:

```text
Fatal error: Uncaught RuntimeException: pack_mismatched_resource_or_secondary_denied
Stack trace:
#0 tests/cap2_domains_policy.php(25): k1b_assert(false, 'pack_mismatched...')
#1 app/Services/ApiExecutionMetadataContext.php(51): closure()
#2 tests/cap2_domains_policy.php(16): ApiExecutionMetadataContext::run(...)
```

The original prefix regex accepted another pack ID. The new policy test also covers all six literal paths, wrong ID, subresource, method, search, and incompatible active-cycle sources.

### RED 2 — actual worker/domain/cURL path

Command: `& C:/xampphp/php/php.exe tests/cap2_domains_mysql.php`

Exit 1; expected assertion before wrapping the domain dispatch:

```text
Fatal error: Uncaught RuntimeException: question_exact_reaches_one_real_fenced_wire:{"run_id":1,"claimed":2,"completed":0,"deferred":2,"control_unit":"PHYSICAL_API_CALL","max_calls":1,"physical_http_calls":0,"call_budget":{"limit":1,"used":0,"remaining":1},"stop_reason":"no_claimable_job","cycle_used":0}
Stack trace:
#0 tests/cap2_domains_mysql.php(48): k1b_assert(false, 'question_exact_...')
#1 {main}
```

The legacy source failed before wire; the initial fixture also inserted a redundant canonical pointer, subsequently corrected to reuse the real canonical admission from notification enqueue.

### RED 3 — item 429 falsely completed

Command: `& C:/xampphp/php/php.exe tests/cap2_domains_mysql.php`

Exit 1 after all success-path resources and cache branches passed:

```text
PASS=order_exact
PASS=shipment_exact
PASS=claim_exact
PASS=item_exact
PASS=pack_exact
ITEM_API_CAPABLE=NO
Fatal error: Uncaught RuntimeException: item_429_not_complete
Stack trace:
#0 tests/cap2_domains_mysql.php(92): k1b_assert(false, 'item_429_not_co...')
#1 {main}
```

The test delivered a real-client 429 at the wire boundary. The existing scan swallowed it and completed the notification. Strict mode now preserves failure and lets the worker stop without claiming the next ready item.

### RED 4 — unknown local shipment order

Command: `& C:/xampphp/php/php.exe tests/cap2_domains_mysql.php`

Exit 1 before the queue-only inspection correction:

```text
PASS=order_exact
PASS=shipment_exact
Fatal error: Uncaught RuntimeException: shipment_exact_one_wire_ledger_budget:{"run_id":4,"claimed":1,"completed":0,"deferred":1,"control_unit":"PHYSICAL_API_CALL","max_calls":1,"physical_http_calls":0,"call_budget":{"limit":1,"used":0,"remaining":1},"stop_reason":"no_claimable_job","cycle_used":0}
Stack trace:
#0 tests/cap2_domains_mysql.php(187): k1b_assert(false, 'shipment_exact_...')
#1 tests/cap2_domains_mysql.php(142): cap2_domains_assert_run(..., '/shipments/8112', 'shipment_exact')
#2 tests/cap2_domains_mysql.php(60): cap2_domains_case(...)
#3 {main}
```

The manual prerequisite prevented an automatic one-read shipment snapshot. The new queue-only branch leaves the unknown order unlinked and never requests it.

## GREEN and regressions

Final main command: `& C:/xampphp/php/php.exe tests/cap2_domains_mysql.php`

Final output and exit code are retained at `D:/Codex/tmp/erp-meli/cap2-20260905/qa/task4-domains-green.log`.

```text
PASS=order_exact
PASS=shipment_exact
PASS=shipment_exact
PASS=claim_exact
PASS=item_exact
PASS=pack_exact
PASS=negative_source_path_id_tenant_owner_generation_zero
PASS=question_exact
ITEM_API_CAPABLE=YES
STATUS=PASS CAP2_DOMAINS_MYSQL
REAL_MELI_HTTP=0
REAL_EMAIL_SENT=0
```

Exit 0. A final re-lint of NotificationWorkItemService and cap2_domains_mysql, rerun of cap2_domains_policy, and `git diff --check` also exit 0 after self-review.

The main matrix covers nine total wire GETs: six resource types, a second shipment with an unknown local order, an in-flight notification rerun, and the terminal 429 scenario. Each successful GET is checked against literal endpoint classification, tenant/job/attempt/owner/generation metadata, worker physical counter, shared cycle counter and one durable RESPONSE_KNOWN journal event. Cache branches consume zero. Six negative physical calls (wrong source, path/ID, subresource, tenant, owner, generation) retain NOT_DISPATCHED and zero budget/ledger/wire. A real 429 has one durable item_exact event, cannot complete the notification, and leaves the later queue job unclaimed. Independent tolerant review and strict failed-cache rejection are separately exercised with zero pre-transport calls.

Regression command (same TEMP/TMP, with APP_ENV=test, ML_WRITE_ENABLED=false, DB_HOST=127.0.0.1, DB_PORT=33079, DB_USER=root, DB_PASS empty, PRIVATE_STORAGE_PATH pointing to the owned D fixture directory):

```powershell
$failed=0
foreach($file in @('tests/cap2_domains_policy.php','tests/k1d_rc1_worker_429_regression.php','tests/k1d_rc1_worker_budget_cycles.php','tests/k1d_static_contract.php','tests/cap2_automatic_worker_context_mysql.php')) {
    & C:/xampphp/php/php.exe $file
    if($LASTEXITCODE -ne 0) { $failed++ }
}
exit $failed
```

Exit 0, full output retained in `D:/Codex/tmp/erp-meli/cap2-20260905/qa/task4-regressions.log`:

```text
STATUS=PASS CAP2_DOMAINS_POLICY
REAL_MELI_HTTP=0
STATUS=PASS K1D_RC1_WORKER_429_REGRESSION
WORKER_JOBS_SEEDED=2
WORKER_CONFIGURED_BUDGET=100
REMAINING_BUDGET_AT_STOP=99
FIRST_REMOTE_429_STOPS_CYCLE=YES
CALLS_AFTER_429_SAME_CYCLE=0
STOP_REASON=remote_429_global_pause
REAL_MELI_HTTP=0
REAL_EMAIL_SENT=0
STATUS=PASS K1D_RC1_WORKER_BUDGET_CYCLES
ACTUAL_WORKER_CYCLES=180
WORKER_BUDGET_LEVELS=1,2,3,15,55,100
WORKER_CYCLES_PER_LEVEL=30
MAX_TRANSPORTS_PER_CYCLE=100
MAX_CALLS_VIOLATIONS=0
PRODUCTION_CHANGED_BY_CODEX=NO
REAL_MELI_HTTP=0
REAL_EMAIL_SENT=0
STATUS=PASS K1D_STATIC_CONTRACT
STATUS=PASS CAP2_AUTOMATIC_WORKER_CONTEXT_MYSQL
REAL_MELI_HTTP=0
REAL_EMAIL_SENT=0
```

Those pre-existing worker budget/429 fixtures use stage/handler seams and are supporting regressions only; the main new matrix is the physical-fence proof.

Lint command: run `C:/xampphp/php/php.exe -l` for the five changed runtime files and the three new test files. All eight print `No syntax errors detected`. `git diff --check` exits 0 without output.

## Aggregate suite and limits

`& C:/xampphp/php/php.exe tests/run.php` was run once with the safe environment above and `DB_NAME=erp_meli_k1d_test_cap2_static_no_db`. Result: **115 tests, 51 failures**, exit 1. This is not green. Failures include historic removed legacy entrypoints and version/source-string assertions. The parent confirmed its immutable archived baseline has the same 115/51 totals at `D:/Codex/tmp/erp-meli/cap2-20260905/qa/baseline-legacy.log`. Exact normalized FAIL-line comparison remains Task 7; the aggregate's tool output was truncated, and it was not rerun after the parent instructed not to rerun it.

Task 6 still owns the worker metric join and real-429 metric classification/compensation/deadline changes. This report does not claim the future full integrated release gate, production sustainability at 55/100, actual network timing, response headers, or physical production HTTP certification.

Task 5 must explicitly opt its manual item path into `createReviewForItem(..., true)` where strict exact propagation is required; this task did not change that manual path. Disposable databases were cleaned by their guarded fixture `finally`. An attempted cleanup of the owned D `domains-private` scratch folder was rejected by the execution policy before running; it remains an explicitly owned test-only output folder, not a production artifact.

## Self-review

- Read the entire owned diff. Context restoration uses the existing finally-based helper. Domain resource identity is read from tenant-bound source SQL, not payload hints.
- Exact source policy and fence share the same predicate, eliminating mismatched allowlists. Durable lease/attempt matching and journal transaction semantics are unchanged.
- No finance billing contract or independent bulk review behavior was broadened. Failed review snapshots are not accepted by strict exact mode. Manual admission remains outside this patch.
- Success, local cache, active-cycle incompatible source, stale/mismatched physical fences, unknown shipment relationship, non-429 strict errors, genuine 429, and concurrent new notification event are covered.
- Existing large services remain large; no structural rewrite or new runtime framework was introduced.
- No nested agents were spawned. Independent review belongs to the parent.

## Owned files

- `app/QueueV4Clean/QueueV4CleanWorker.php`
- `app/QueueV4Clean/QueueV4CleanDispatchFence.php`
- `app/Services/MeliTransportSourcePolicy.php`
- `app/Services/NotificationWorkItemService.php`
- `app/Services/MeliProductUpdateReviewService.php`
- `tests/cap2_domains_policy.php`
- `tests/cap2_domains_mysql.php`
- `tests/cap2_domains_wire_fixture.php`
- This report.
