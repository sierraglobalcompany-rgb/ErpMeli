LAST_UPDATED_AT=2026-08-27T00:00:00-05:00 [PROVEN_LOCAL]

## PRODUCTION
VERSION=2.40.0 [OBSERVED_ACCEPTED]
SCHEMA=300 [OBSERVED_ACCEPTED]
SIGNED_MARKER=VALID [OBSERVED_ACCEPTED]
LAST_VERIFIED_AT=2026-08-27T02:22:00Z [PROVEN_READ_ONLY_GATE_A]

## CANDIDATE
VERSION=2.40.1 [PROVEN_LOCAL]
SCHEMA=300 [OBSERVED_ACCEPTED]
BRANCH=codex/2400-final-release-gate [PROVEN_LOCAL]
HEAD=8e102e2711905a3dc9c9c4e62a4afce41adbfa1f [PROVEN_LOCAL]
TREE=ccfa4de7f7a8efa46f7036fd724c311bc8caa457 [PROVEN_LOCAL]
DEPLOY_AUTHORIZED=NO [PROVEN_PROCESS]

## EXECUTION
CANONICAL_FIFO=queue_v4_clean_jobs available_at ASC,id ASC [PROVEN_PREVIOUS]
DRAIN_AUTHORITY=QueueExecutionLeaseService[global] [PROVEN_PREVIOUS]
DRAIN_KERNEL_ALIAS=single_execution_kernel/manual_drain_authority is an internal key/alias related to the proven lease authority, not a replacement authority [PROVEN_LOCAL]
AUTOMATIC_DRAINER=jobs/queue_v4_clean.php --runtime=45 --max-jobs=3 [PROVEN_PREVIOUS]
MANUAL_DRAINER=Smart Manual Drain one authenticated idempotent step [PROVEN_PREVIOUS]
CRON_REAL=Queue V4 physical cron unchanged unless new contradictory evidence [OBSERVED_ACCEPTED]
REMOTE_EXECUTION_AUTHORITY=ApiRhythmPolicyService + ApiGuardService telemetry [PROVEN_PREVIOUS]

## REAL_BUGS
- financial_reconciliation / policy_delay retry churn [PROVEN_GATE_A_CLOSED_LOCAL]

## TOOLING_BUGS
- PowerShell/SSH/BOM/UTF-16/link packaging failures are tooling, not ERP runtime [PROVEN_PROCESS]

## OBSERVABILITY_NOISE
- API Health must separate REMOTE_HTTP_429 from LOCAL_POLICY_DELAY [PROVEN_PREVIOUS]

## SAFETY_BLOCKS_OK
- updater/migration gates may block on checksum/state drift; treat as safety until disproven [PROVEN_PREVIOUS]

## EXPECTED_BEHAVIOR
- Billing 429 protection remains 30m/2h/6h/12h with Retry-After authority [PROVEN_PREVIOUS]
- historical recovery is separate from release [PROVEN_PROCESS]

## HISTORICAL_DEBT
- legacy V2/V3/Queue Core names and historical backlog remain debt unless a real caller is proven [OBSERVED_ACCEPTED]

## DO_NOT_TOUCH
- production data/files [PROVEN_PROCESS]
- historical migrations already applied in production [PROVEN_PROCESS]
- Cron max-jobs/FIFO/B429/Billing 900s unless current bug directly proves need [PROVEN_PROCESS]

## CURRENT_EVIDENCE
- Gate A read-only output: LOCAL_POLICY_DELAY_30D=5522, REPEAT_EVENT_RATIO=0.6796, REPEATS_UNDER_5M=706 [PROVEN_GATE_A]
- Gate B browser/settings/API Health local fixture PASS [PROVEN_PREVIOUS]
- Candidate fix: financial_reconciliation stale/missing next_run_at uses 900s, valid future next_run_at is preserved exactly, financial_recalc does not inherit the 900s floor [PROVEN_LOCAL_TESTS]
- Candidate fix: global policy delays still stop the cycle; endpoint/capability/job policy delays defer only the affected pointer and allow independent eligible work to continue [PROVEN_LOCAL_TESTS]
- P1 external re-audit fix: ApiRhythmDeferredException scopes are classified at one decision point; unknown scope fails closed as break; OAuthRefreshRequiredException is account-scoped and independent account work continues [PROVEN_LOCAL_TESTS]
- P1 continuation fix: remote_backoff authority is account+endpoint scoped through ApiGuardService::afterFailure/openCircuit, so it defers only affected work and does not starve independent account work [PROVEN_LOCAL_TESTS]
- Worker Cycle Control closeout: ApiBudgetExhaustedException now preserves structured budget scopes; Queue V4 uses one deferred-cycle decision policy. app/unknown budget stops the cycle; account/endpoint/job_type budget continues independent work. ApiBudgetInfrastructureException and app ApiManualPauseException now defer without attempt penalty and fail closed as global stops. The old MeliApiException(429) worker fallback was deleted after census: the real Queue V4 MeliApiClient converts remote 429 into ApiRhythmDeferredException before the generic MeliApiException path. QueueV4PreTransportDeferredException remains fail-closed; explicit authority exceptions, including ApiRhythmDeferredException, ApiBudgetExhaustedException, ApiManualPauseException and CronDeadlineDeferredException, are rethrown before that wrapper. Endpoint-budget fidelity is now proven with endpoint X always blocked and endpoint Y independently completed, including clustered X work ahead of Y [PROVEN_LOCAL_TESTS_145].

## NEXT_ACTION_ALLOWED
- External ChatGPT re-audit of candidate 2.40.1 package before any install/deploy [PROVEN_PROCESS]
