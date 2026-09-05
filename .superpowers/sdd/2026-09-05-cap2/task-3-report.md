# Task 3 report: ERP authority for CLI and worker

## Status

DONE_WITH_CONCERNS. Task-owned implementation and focused regressions are complete. The full aggregate/release suite was deliberately not run after the controller reported less than 1 GB free on `C:` and requested an immediate durable checkpoint. The known aggregate baseline remains 115/51; this report does not claim that aggregate is green.

## Implemented

- `AutomationCallBudgetService` now keeps `requested_max_calls` distinct from the effective `max_calls` and calculates the effective value as the minimum of requested, saved current, saved ceiling, and technical maximum 100. With no CLI argument, requested/effective capacity comes from the saved current.
- Both `--max-calls` and the `--max-jobs` compatibility alias can reduce capacity but cannot raise the ERP current. Existing source metadata remains available.
- `QueueV4CleanScheduler` resolves capacity when entered and resolves it again after both leases are acquired, immediately before starting `QueueV4CleanCycleBudget`. This makes a concurrent saved reduction authoritative. All scheduler terminal results report original requested capacity and final configured/current, ceiling, and effective capacity.
- `jobs/queue_v4_clean.php` passes the preserved requested capacity into the scheduler and no longer overwrites the scheduler's final capacity metadata with the earlier CLI snapshot.
- `QueueV4CleanWorker` normalizes `maxCalls` to 0..100 before the existing zero-work parameter exits. For actual work, it fails before counts, control reads, `beginRun`, or claims when the outer cycle budget is absent or its remaining capacity exceeds the worker's authorized argument. It does not start or reset the outer budget.
- Pointer traversal stays local with floor 15 and multiplier 20; the shared cycle fuse remains 1000.
- Updated the pre-existing capacity-policy expectations from ceiling-wins to the approved ERP-current-wins behavior.

## TDD evidence

### RED

Command:

`C:\xampphp\php\php.exe -d error_reporting=-1 -d display_errors=1 tests\cap2_automatic_budget_mysql.php`

Relevant expected failure before implementation:

`RuntimeException: no_argument_preserves_current_as_request`

Reason: the resolver did not expose the requested value separately and still allowed CLI values to replace the ERP current.

Command:

`C:\xampphp\php\php.exe -d error_reporting=-1 -d display_errors=1 tests\cap2_automatic_worker_context_mysql.php`

Relevant expected failure before implementation:

`RuntimeException: max_calls_normalized_before_runtime_early_exit`

Reason: the worker reported 150 rather than normalized 100 on its existing runtime-zero early return; the same regression file then covers missing/mismatched outer contexts before SQL reads.

Command:

`C:\xampphp\php\php.exe -d error_reporting=-1 -d display_errors=1 tests\cap2_automatic_scheduler_mysql.php`

Relevant expected failure before implementation:

`RuntimeException: scheduler_preserves_original_requested_capacity`

Reason: the scheduler did not distinguish the originally requested 50 calls from final capacity and did not apply saved current 3 as the final limit.

### GREEN

Commands and results:

- `...php tests\cap2_automatic_budget_mysql.php` -> `STATUS=PASS CAP2_AUTOMATIC_BUDGET_MYSQL`
- `...php tests\cap2_automatic_worker_context_mysql.php` -> `STATUS=PASS CAP2_AUTOMATIC_WORKER_CONTEXT_MYSQL`
- `...php tests\cap2_automatic_scheduler_mysql.php` -> `STATUS=PASS CAP2_AUTOMATIC_SCHEDULER_MYSQL`, with `STAGE_DOUBLES=NOT_FINAL_PHYSICAL_PROOF`
- `...php tests\capacity_policy_mysql.php` -> `STATUS=PASS CAPACITY_POLICY_MYSQL`
- `...php tests\capacity_active_scheduler_mysql.php` -> `STATUS=PASS CAPACITY_ACTIVE_SCHEDULER_MYSQL`; levels `1,2,3,15,55,100`; shared stages `oauth,audit,worker,repair`
- `$env:DB_PORT='33079'; ...php tests\k1d_rc1_worker_budget_cycles.php` -> `STATUS=PASS K1D_RC1_WORKER_BUDGET_CYCLES`; 180/180 cycles, maximum 100 transports, 0 violations
- `$env:DB_PORT='33079'; ...php tests\k1d_rc1_worker_429_regression.php` -> `STATUS=PASS K1D_RC1_WORKER_429_REGRESSION`; first remote 429 stopped the cycle and remaining budget was 99
- `...php tests\k1d_fix1_pr11_gates.php` -> `STATUS=PASS K1D_FIX1_PR11_GATES`
- `...php tests\k1d_static_contract.php` -> `STATUS=PASS K1D_STATIC_CONTRACT`
- `...php tests\k1b_no_batch_or_job_authority.php` -> `K1B_NO_BATCH_OR_JOB_AUTHORITY=PASS`
- `php -l` on every changed PHP source/test -> no syntax errors
- `git diff --check` -> exit 0, no output

The first attempt to run the two older worker DB tests used their default port 3306 and failed to connect. They were rerun with the owned MariaDB port 33079 and passed as recorded above; this was an environment invocation issue, not a product/test assertion failure.

## Test boundaries

The scheduler regression replaces only OAuth/audit/producer/maintenance/worker/repair stage boundaries so the real scheduler, capacity policy/service, SQL scheduler lease, and real shared `QueueV4CleanCycleBudget` can be exercised deterministically. It is explicitly labeled as stage-mocked evidence, not final physical-wire proof. The worker budget-cycle regression uses the real worker/repository and a handler at the physical-call boundary; no real Mercado Libre HTTP or email was executed.

## Files changed

- `app/Services/AutomationCallBudgetService.php`
- `app/QueueV4Clean/QueueV4CleanScheduler.php`
- `app/QueueV4Clean/QueueV4CleanWorker.php` (budget entry only)
- `jobs/queue_v4_clean.php`
- `tests/capacity_policy_mysql.php` (approved old expectation corrections)
- `tests/cap2_automatic_budget_mysql.php`
- `tests/cap2_automatic_scheduler_mysql.php`
- `tests/cap2_automatic_worker_context_mysql.php`
- `.superpowers/sdd/2026-09-05-cap2/task-3-report.md`

No domain handler, metric query, migration, schema, version, production configuration, `qa/`, network, SSH, FTP, Cron, deployment, push, or merge changes were made.

## Self-review

- Completeness: checked no-argument behavior, both CLI aliases, legacy 60/100 attempts against saved 1/15, ceiling-only behavior, current 3/ceiling 100/request 50, concurrent reduction, boundaries 1/2/3/15/55/100, shared OAuth/stage consumption, and missing/mismatched worker budget contexts.
- Mutation check: removing the requested field, choosing requested instead of current, skipping the scheduler re-read, overwriting scheduler metadata in the job, moving context validation below repository counts, accepting missing context, or accepting outer remaining greater than the worker argument is covered by a focused assertion.
- Scope: worker edits stop after budget entry; domain handling and metric SQL were not touched.
- Quality: no new counter/reset path was added. Existing local pointer limits and physical fuse are unchanged.
- Concern: aggregate/release suites remain unexecuted in this task due the controller's urgent disk-space boundary. Existing scheduler tests use declared stage doubles and must not be described as final physical transport proof.
