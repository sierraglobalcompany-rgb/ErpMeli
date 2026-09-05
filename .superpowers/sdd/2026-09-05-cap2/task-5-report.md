# Task 5 — one-shot manual admission and truthful outcomes

Worktree: `C:/codex/ERP-BDM/WORK/h8fix`; branch `codex/capacity-config`.
Task baseline: `3b8becd`. PHP used: `C:/xampphp/php/php.exe` (8.5.8 CLI).
Implementation was limited to Task 5 and directly affected regression fixtures.

## Implemented

- `ManualCampaignPreviewService::consume()` now performs one atomic ready-to-consumed UPDATE qualified by token, creating user, ready state and database UTC expiry; exactly one affected row is required. It never restores ready state.
- Exact launcher accepts one admission callback. All selection structural checks, global exclusion, FIFO busy checks and deadline/policy checks precede it. Callback rechecks current user company/account access, source eligibility/authority and physical capacity, then consumes the token. Recovery and enqueue occur only afterwards.
- Busy/invalid/expired/reduced-capacity prechecks leave ready. A consumed preview remains terminal after failure, lost response, SQL failure after a saved checkpoint or replay. A new preview can continue the durable checkpoint.
- Available FIFO consumes directly before its real worker under the same global lease. Worker failures do not restore preview readiness.
- Uncertified legacy selections no longer derive physical budget from block size. Certified compatible lower budgets stay bounded and do not increase when current changes.
- Normal successful pagination has an explicit local-envelope completion reason (`manual_checkpoint_deferred`) and returns deferred, not completed/error, to the browser. It can continue the rest of the bounded selection.
- Protected outcomes retain explicit reason and optional HTTP status. Persisted attempt/job HTTP 401/403/429 outranks a domain status and stops selection. Pause/policy protection closes the manual envelope for review with no background continuation.
- Summary and controller/view distinguish completed, deferred, review and not started. Slow enqueue followed by zero claimed work is not reported as processed. Empty results do not imply that all selected resources completed.
- Manual item notification uses the confirmed single GET capability, mapped to the existing physical `item_detail` profile. Its strict review path propagates failures; an existing usable review performs zero physical HTTP. Independent tolerant review behavior is unchanged.

## Ownership exception: why it remains fenced

No engine control or transport-source policy was relaxed. The existing `QueueCoreOwnershipGuard`, `QueueCoreDispatchFence`, `QueueCoreRepository` physical markers, global execution lease and Task 4 V4 fences remain intact.

`NotificationWorkItemService::processExact` accepts the bounded Queue Core manual path only with its server execution context and a read-only durable certification. The SQL checks company/account, manual_exact job/domain, notification source ID, expected source-authority version, running job, exact attempt, matching owner/generation, unfinished attempt and live global manual lease owner/generation. Missing, spoofed and stale contexts return protected before source leasing. The existing physical Core fence still repeats method/path/profile and both leases immediately before HTTP. The adapter invokes this path with continuation disabled. No new child jobs/engine/table/migration were introduced.

## TDD evidence

Commands below were invoked as `C:/xampphp/php/php.exe tests/<name>.php` in the worktree, with `TEMP` and `TMP` set to `D:/Codex/tmp/erp-meli/cap2-20260905/tmp`.

Observed expected RED failures before the corresponding production corrections:

| Test | Observed RED |
| --- | --- |
| `cap2_manual_admission` | `atomic_admission_requires_exactly_one_winner`: second consume silently succeeded. |
| `cap2_manual_budget` | `uncertified_or_reduced_preview_requires_recalculation`: legacy block size was accepted as HTTP capacity. |
| `cap2_manual_launcher` | `launcher_admission_exactly_once_before_effect`: callback was not invoked; effect admission was absent. |
| `cap2_manual_admission` | Real notification returned completed with zero physical calls under V4 ownership instead of executing its certified bounded manual path. |
| `cap2_manual_outcomes` | `normal_200_checkpoint_truthful_deferred`: two successfully paginated partial domain resources were presented as completed. |
| `cap2_manual_item_contract` | No item_exact manual capability; subsequent exact profile check identified that the physical Core profile must be item_detail. |
| `cap2_manual_presentation` | Controller returned processed=3, waiting=0, review=1 for one deferred and two not-started selections. |
| `cap2_manual_busy` | NULL expiry was excluded by SQL three-valued logic, so admission occurred before the later busy rejection. |
| `cap2_manual_busy` | Slow SQL enqueue left runner claimed=0 but summary processed=1, review=1, not-started=0. |
| `cap2_manual_safety` | A real AFTER INSERT trigger made the source nonterminal/ineligible; the handler reported it completed and started the second selection. |

Harness-only errors were not counted as product RED: one concurrent full-schema setup hit the global migration advisory lock; one initial projection fixture lacked required columns and was replaced by the real projection refresh; one minimal fixture lacked factory feature-flag schema and was changed to compose the real repository/leases/runner/handler directly. All subsequent full-schema test setup is serialized. No handler, adapter, transport fence or domain service is replaced in the main integration proofs.

The initial combined orders-search scenarios hit the existing hard three-calls-per-15-minute endpoint protection. The test was corrected by making the second resource of the successful-pagination selection a real question notification: only three orders-search calls are now needed for page + post-checkpoint failure + new-preview continuation. No rate limit was raised or bypassed. Independent HTTP error cases clear only disposable penalty rows between cases, after each protected scenario has been asserted.

## Final focused verification

The final 13-suite run completed with exit 0 for every command:

1. `cap2_manual_budget`: uncertified legacy/malformed/reduced rejection and compatible certified lower budget.
2. `cap2_manual_item_contract`: confirmed one-GET path and matching physical profile; no description suffix.
3. `cap2_manual_busy`: real SQL NULL-lease busy protection, admission-before-recovery ordering and slow-enqueue not-started truthfulness.
4. `cap2_manual_presentation`: real controller maps deferred/not-started accurately at its isolated result boundary.
5. `cap2_manual_admission`: full schema 301; two PDO connections, one SQL winner; wrong user; expiry changed after load; real service + adapter + handler + physical fence; Cron busy retains ready; token consumed before wire; lost-response replay zero HTTP.
6. `cap2_manual_launcher`: real launcher/core; callback once before enqueue; callback failure zero effect; invalid second selection prevents first effect; expiry after load prevents enqueue.
7. `cap2_manual_outcomes`: successful HTTP200 page persists checkpoint and allows second selection; SQL-trigger failure on second enqueue leaves first checkpoint; old-preview replay zero HTTP; new preview continues; real protected 401/403/429 each stop remainder and do not complete source.
8. `cap2_manual_safety`: scope/reduction rejection; missing/spoofed/stale owner zero HTTP; genuine durable manual owner reaches one GET; a source becoming nonterminal/ineligible after enqueue stops selection without a false completion; available busy retains ready; real worker beginRun SQL failure consumes permanently; real API pause stops selection.
9. `cap2_manual_items`: real strict item GET; cached review zero HTTP; 429 does not complete source or dispatch second item; old-preview replay zero HTTP.
10. `capacity_manual_selection`: saved resource count remains distinct from HTTP cap.
11. `capacity_manual_runtime`: full existing capacities 1/2/3/15/55/100, local-resource batches, scope/FIFO, reductions, HTTP protections and total deadline cases.
12. `capacity_manual_controller`: existing controller resource-count forwarding regression.
13. `f5_manual_kiss_contract`: existing KISS surface/route smoke contract.

Final logs are in `D:/Codex/tmp/erp-meli/cap2-20260905/qa/task5-<test-name>.log` (one per command).
The command loop fails immediately on a nonzero PHP exit:

```powershell
$env:TEMP='D:/Codex/tmp/erp-meli/cap2-20260905/tmp'; $env:TMP=$env:TEMP
$tests=@('cap2_manual_budget','cap2_manual_item_contract','cap2_manual_busy','cap2_manual_presentation','cap2_manual_admission','cap2_manual_launcher','cap2_manual_outcomes','cap2_manual_safety','cap2_manual_items','capacity_manual_selection','capacity_manual_runtime','capacity_manual_controller','f5_manual_kiss_contract')
foreach($test in $tests) {
  & C:/xampphp/php/php.exe ('tests/'+$test+'.php') 2>&1 | Tee-Object -FilePath ('D:/Codex/tmp/erp-meli/cap2-20260905/qa/task5-'+$test+'.log')
  if($LASTEXITCODE -ne 0) { throw ('Focused verification failed: '+$test) }
}
```

All 25 changed/new PHP files were individually linted; no syntax errors. `git diff --check` passed. Runtime assertions certify `REAL_MELI_HTTP=0`; the only fake physical boundary is `cap2_domains_wire_fixture.php` (curl_exec/getinfo/error), with real cURL options, source policies, permissions, leases, persistence and counters. No real email was sent.

## Directly affected legacy regression expectations

- `capacity_manual_selection`: fixture now supplies a certified capacity revision and its isolated launcher boundary calls the provided admission callback. Previously it relied on missing capacity evidence and post-launch consumption.
- `capacity_manual_runtime`: expired-before-launch now expects a pre-admission exception with callback=0/HTTP=0 instead of a returned empty batch. All other capacity matrix cases remain present.
- `f5_manual_kiss_contract`: reflects the fourth admission argument and continued preview/deadline binding. This remains a textual smoke contract; real behavioral authority is the new integration suites, not its string assertions.

## Files and self-review

Runtime: `ManualCampaignPreviewService`, `ManualSingleStepService`, `ManualPhysicalCallBudget`, `ManualQueueLauncher`, `ManualExactHandler`, `ManualRemoteCapabilityRegistry`, `QueueResult`, `CampaignItemResult`, `RegisteredManualCampaignAdapter`, the manual exact path of `NotificationWorkItemService`, the result-only block of `SettingsController`, and `Views/settings/manual_processing.php`.

Tests: ten new `tests/cap2_manual*.php` files (including the fixture), and the three legacy fixtures listed above. This report is the only planning artifact owned by this task.

Self-review preserved all unrelated tracked/untracked changes, including existing `qa/`. No Task 6 cURL/deadline compensation/worker metric-join file was edited. No table, migration, version or schema change. No background continuation, remote writes, SSH/FTP, push, merge, deployment, production configuration or Cron invocation.

The 45-second browser window still starts before preview loading/locks/source preparation; the launcher and each existing 25/20-second step only reduce it. Final physical deadline tightening/compensation is explicitly Task 6 and was not claimed here. Full aggregate comparison, browser/UAT, integrated branch review and packaging remain Task 7.

Independent specification/quality review is the parent/controller's next gate. The source-ineligibility race correction changes only the handler's initial stale-source branch: terminal existing resources may be completed; missing or nonterminal/ineligible resources require review and stop selection.

Final post-review correction verification: reran `cap2_manual_safety.php` with its SQL source-race trigger and refreshed its D log; output `STATUS=PASS CAP2_MANUAL_SAFETY`, `REAL_MELI_HTTP=0`, exit 0. Re-linted `ManualExactHandler.php`, `cap2_manual_safety.php` and `cap2_manual_busy.php`; all passed. Re-ran `git diff --check` and staged diff check; both clean. The added recovery-order assertion in `cap2_manual_busy` also passed and its log was refreshed. No outstanding self-review defect remains known; independent review is still required.
