<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Services\ApiExecutionMetadataContext;
use App\Services\ApiBudgetInfrastructureException;
use App\Services\ApiBudgetExhaustedException;
use App\Services\ApiManualPauseException;
use App\Services\ApiRhythmPolicyService;
use App\Services\ApiRhythmDeferredException;
use App\Services\CronDeadlineContext;
use App\Services\CronDeadlineDeferredException;
use App\Services\MeliApiClient;
use App\Services\MeliApiException;
use App\Services\MeliReadClientInterface;
use App\Services\MeliTransportSourcePolicy;
use App\Services\ManualRemoteCallLimitException;
use App\Services\OAuthRefreshRequiredException;
use App\Services\NotificationWorkItemService;
use App\Services\OrderEnrichmentService;
use App\Services\OrderFinancialRecalcJobService;
use App\Services\OrderSyncService;
use App\Services\QueueV4PreTransportDeferredException;
use App\Services\RemoteResultUncertainException;
use App\Services\SaleFinancialService;
use App\Services\SyncSettingsService;
use PDO;
use RuntimeException;
use Throwable;

final class QueueV4CleanWorker
{
    public const DEFAULT_MAX_CALLS = 1;
    public const HARD_MAX_CALLS = 15;
    /** @deprecated Temporary Hostinger/hPanel compatibility input alias. */
    public const DEFAULT_MAX_JOBS = self::DEFAULT_MAX_CALLS;
    /** @deprecated Temporary Hostinger/hPanel compatibility input alias. */
    public const HARD_MAX_JOBS = self::HARD_MAX_CALLS;
    private const POINTER_SAFETY_MULTIPLIER = 20;
    private const FINANCIAL_RECONCILIATION_STALE_RECHECK_SECONDS = 900;
    private const QUEUE_V4_AUDIT_DIR = 'storage/queue-v4-audit';

    /** @var \Closure(int):MeliReadClientInterface */
    private \Closure $clientFactory;
    /** @var \Closure(int):OrderSyncService */
    private \Closure $syncFactory;
    /** @var null|\Closure(array<string,mixed>):void */
    private ?\Closure $jobHandler;
    /** @var null|\Closure(string,int,int):void */
    private ?\Closure $domainHandler;
    /** @var \Closure():SaleFinancialService */
    private \Closure $financialFactory;

    /**
     * @param null|callable(int):MeliReadClientInterface $clientFactory
     * @param null|callable(int):OrderSyncService $syncFactory
     * @param null|callable(array<string,mixed>):void $jobHandler Test-only/local fixture seam.
     * @param null|callable(string,int,int):void $domainHandler Test-only domain source seam.
     * @param null|callable():SaleFinancialService $financialFactory Test-only billing transport seam.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly QueueV4CleanRepository $repository,
        ?callable $clientFactory = null,
        ?callable $syncFactory = null,
        ?callable $jobHandler = null,
        ?callable $domainHandler = null,
        ?callable $financialFactory = null,
    ) {
        $this->clientFactory = $clientFactory !== null
            ? \Closure::fromCallable($clientFactory)
            : static fn (int $accountId): MeliReadClientInterface => new MeliApiClient($accountId);
        $this->syncFactory = $syncFactory !== null
            ? \Closure::fromCallable($syncFactory)
            : static fn (int $accountId): OrderSyncService => new OrderSyncService($accountId);
        $this->jobHandler = $jobHandler !== null ? \Closure::fromCallable($jobHandler) : null;
        $this->domainHandler = $domainHandler !== null ? \Closure::fromCallable($domainHandler) : null;
        $this->financialFactory = $financialFactory !== null
            ? \Closure::fromCallable($financialFactory)
            : static fn (): SaleFinancialService => new SaleFinancialService();
    }

    /**
     * @param list<int>|null $authorizedAccountIds
     * @return array{run_id:int,claimed:int,completed:int,deferred:int}
     */
    public function run(
        string $launcher,
        int $maxCalls = self::DEFAULT_MAX_CALLS,
        int $runtimeSeconds = 45,
        ?array $authorizedAccountIds = null,
        ?int $accountId = null
    ): array
    {
        if ($maxCalls < 1 || $runtimeSeconds < 5) {
            return [
                'run_id' => 0,
                'claimed' => 0,
                'completed' => 0,
                'deferred' => 0,
                'control_unit' => 'PHYSICAL_API_CALL',
                'max_calls' => max(0, $maxCalls),
                'physical_http_calls' => 0,
            ];
        }
        $receiptStartedAt = microtime(true);
        $receiptStartedText = gmdate('Y-m-d H:i:s');
        $receiptJobs = [];
        $reviewed = 0;
        $endReason = 'completed';
        $readyBefore = $waitingBefore = $reviewBefore = $runningBefore = 0;
        $financeWakeupRuntime = [
            'ran' => false,
            'candidates' => 0,
            'released' => 0,
            'skipped_future' => 0,
            'skipped_blocked' => 0,
            'errors' => 0,
        ];
        try {
            $countsBefore = $this->repository->counts();
            $readyBefore = (int) ($countsBefore['ready'] ?? 0);
            $waitingBefore = (int) ($countsBefore['waiting'] ?? 0);
            $reviewBefore = (int) ($countsBefore['review'] ?? 0);
            $runningBefore = (int) ($countsBefore['running'] ?? 0);
        } catch (Throwable) {
            $endReason = 'pre_count_unavailable';
        }
        $control = $this->repository->control();
        if ((string) $control['engine_state'] !== 'ACTIVE') {
            $this->writeCycleAuditReceipt([
                'started_at' => $receiptStartedText,
                'ended_at' => gmdate('Y-m-d H:i:s'),
                'duration_ms' => (int) round((microtime(true) - $receiptStartedAt) * 1000),
                'launcher' => $launcher,
                'runtime_seconds' => $runtimeSeconds,
                'control_unit' => 'PHYSICAL_API_CALL',
                'max_calls' => $maxCalls,
                'max_jobs' => $maxCalls,
                'ready_before' => $readyBefore,
                'waiting_before' => $waitingBefore,
                'review_before' => $reviewBefore,
                'running_before' => $runningBefore,
                'claimed' => 0,
                'completed' => 0,
                'deferred' => 0,
                'reviewed' => 0,
                'remote_http' => 0,
                'remote_429' => 0,
                'remote_5xx' => 0,
                'local_policy_delay' => 0,
                'finance_wakeup_runtime' => $financeWakeupRuntime,
                'end_reason' => 'engine_not_active',
                'jobs' => [],
            ]);
            return [
                'run_id' => 0,
                'claimed' => 0,
                'completed' => 0,
                'deferred' => 0,
                'control_unit' => 'PHYSICAL_API_CALL',
                'max_calls' => $maxCalls,
                'physical_http_calls' => 0,
            ];
        }
        $maxCalls = min(self::HARD_MAX_CALLS, $maxCalls);
        $pointerSafetyLimit = max(self::HARD_MAX_CALLS, $maxCalls * self::POINTER_SAFETY_MULTIPLIER);
        $deadline = microtime(true) + max(5, min(45, $runtimeSeconds));
        $owner = bin2hex(random_bytes(16));
        $runId = $this->repository->beginRun($launcher, $owner);
        $claimed = $completed = $deferred = 0;
        try {
            $this->repository->expireLeases($authorizedAccountIds, $accountId);
            try {
                $this->repository->releaseDueWaiting($authorizedAccountIds, $accountId);
            } catch (Throwable $error) {
                if (method_exists($this->repository, 'lastFinanceWakeupRuntime')) {
                    $financeWakeupRuntime = $this->repository->lastFinanceWakeupRuntime();
                }
                throw $error;
            }
            if (method_exists($this->repository, 'lastFinanceWakeupRuntime')) {
                $financeWakeupRuntime = $this->repository->lastFinanceWakeupRuntime();
            }
            while ($claimed < $pointerSafetyLimit
                && !QueueV4CleanCycleBudget::exhausted()
                && microtime(true) < $deadline - 2.0
                && CronDeadlineContext::canAcceptWork(2)) {
                $job = $this->repository->claim($runId, $owner, 60, $authorizedAccountIds, $accountId);
                if ($job === null) {
                    $endReason = 'no_claimable_job';
                    break;
                }
                $claimed++;
                try {
                    $outcome = $this->handle($job);
                } catch (OAuthRefreshRequiredException $error) {
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        'oauth_refresh_required',
                        $this->repository->nextOAuthOpportunity(
                            (int) $job['company_id'],
                            (int) $job['meli_account_id'],
                        ),
                    );
                    $receiptJobs[] = $this->cycleJobReceipt($job, 'waiting', 'oauth_refresh_required', false, null, null, null);
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
                        $endReason = 'deferred_break:oauth_refresh_required';
                        break;
                    }
                    continue;
                } catch (ApiRhythmDeferredException $error) {
                    $classification = 'rate_limit_deferred:' . $this->safeToken($error->blockingScope);
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        $classification,
                        $error->nextSafeAt,
                    );
                    if ($this->shouldParkFinancialReconciliation($job, $error)) {
                        $this->repository->parkFinancialReconciliationUntil(
                            $error->nextSafeAt,
                            (int) ($job['id'] ?? 0),
                        );
                    }
                    $receiptJobs[] = $this->cycleJobReceipt($job, 'waiting', $classification, $error->reachedRemote, null, null, $error->nextSafeAt);
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
                        $endReason = 'deferred_break:' . $classification;
                        break;
                    }
                    continue;
                } catch (ApiBudgetExhaustedException $error) {
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        'capacity_deferred:budget',
                        $error->nextSafeAt,
                    );
                    $receiptJobs[] = $this->cycleJobReceipt($job, 'waiting', 'capacity_deferred:budget', false, null, null, $error->nextSafeAt);
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
                        $endReason = 'deferred_break:capacity_deferred:budget';
                        break;
                    }
                    continue;
                } catch (CronDeadlineDeferredException $error) {
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        'capacity_deferred:cron_deadline',
                        $error->nextSafeAt,
                    );
                    $receiptJobs[] = $this->cycleJobReceipt($job, 'waiting', 'capacity_deferred:cron_deadline', false, null, null, $error->nextSafeAt);
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
                        $endReason = 'deferred_break:capacity_deferred:cron_deadline';
                        break;
                    }
                    continue;
                } catch (ManualRemoteCallLimitException $error) {
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        'capacity_deferred:manual_burst',
                        $error->nextSafeAt
                    );
                    $receiptJobs[] = $this->cycleJobReceipt($job, 'waiting', 'capacity_deferred:manual_burst', false, null, null, $error->nextSafeAt);
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
                        $endReason = 'deferred_break:capacity_deferred:manual_burst';
                        break;
                    }
                    continue;
                } catch (QueueV4PreTransportDeferredException $error) {
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        'pre_transport_deferred',
                        $error->nextSafeAt,
                    );
                    $receiptJobs[] = $this->cycleJobReceipt($job, 'waiting', 'pre_transport_deferred', false, null, null, $error->nextSafeAt);
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
                        $endReason = 'deferred_break:pre_transport_deferred';
                        break;
                    }
                    continue;
                } catch (RemoteResultUncertainException $error) {
                    $nextSafeAt = gmdate('Y-m-d H:i:s', time() + 60);
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        'remote_result_uncertain_safe_get',
                        $nextSafeAt,
                    );
                    $receiptJobs[] = $this->cycleJobReceipt($job, 'waiting', 'remote_result_uncertain_safe_get', true, null, null, $nextSafeAt);
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
                        $endReason = 'deferred_break:remote_result_uncertain_safe_get';
                        break;
                    }
                    continue;
                } catch (ApiManualPauseException $error) {
                    $classification = 'manual_pause:' . $this->safeToken($error->scope);
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        $classification,
                        $error->resumeAt,
                    );
                    $receiptJobs[] = $this->cycleJobReceipt($job, 'waiting', $classification, false, null, null, $error->resumeAt);
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
                        $endReason = 'deferred_break:' . $classification;
                        break;
                    }
                    continue;
                } catch (ApiBudgetInfrastructureException $error) {
                    $nextSafeAt = gmdate('Y-m-d H:i:s', time() + 60);
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        'capacity_deferred:budget_infrastructure',
                        $nextSafeAt,
                    );
                    $receiptJobs[] = $this->cycleJobReceipt($job, 'waiting', 'capacity_deferred:budget_infrastructure', false, null, null, $nextSafeAt);
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
                        $endReason = 'deferred_break:capacity_deferred:budget_infrastructure';
                        break;
                    }
                    continue;
                } catch (MeliApiException $error) {
                    $this->functionalFailure($job, $runId, $error);
                    $nextSafeAt = null;
                    if ((int) ($error->httpStatus ?? 0) === 429) {
                        $nextSafeAt = (new ApiRhythmPolicyService())->openSharedRateLimitPause(
                            isset($error->response['retry_after']) ? (int) $error->response['retry_after'] : null
                        );
                    }
                    $receiptJobs[] = $this->cycleJobReceipt(
                        $job,
                        'waiting',
                        'meli_api_exception:' . ($error->httpStatus !== null ? 'http_' . (int) $error->httpStatus : 'unknown'),
                        $error->requestId !== null,
                        $error->httpStatus,
                        null,
                        $nextSafeAt
                    );
                    $deferred++;
                    if ((int) ($error->httpStatus ?? 0) === 429) {
                        $endReason = 'remote_429_global_pause';
                        break;
                    }
                    continue;
                } catch (RuntimeException $error) {
                    $this->functionalFailure($job, $runId, $error);
                    $receiptJobs[] = $this->cycleJobReceipt($job, 'waiting', $this->failureClass($error), false, null, null, null);
                    $deferred++;
                    continue;
                }
                if (($outcome['state'] ?? '') === 'waiting') {
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        (string) ($outcome['classification'] ?? 'domain_source_waiting'),
                        isset($outcome['next_safe_at']) ? (string) $outcome['next_safe_at'] : null,
                    );
                    $receiptJobs[] = $this->cycleJobReceipt(
                        $job,
                        'waiting',
                        (string) ($outcome['classification'] ?? 'domain_source_waiting'),
                        (bool) ($outcome['reached_remote'] ?? false),
                        isset($outcome['http_status']) ? (int) $outcome['http_status'] : null,
                        isset($outcome['retry_after']) ? (string) $outcome['retry_after'] : null,
                        isset($outcome['next_safe_at']) ? (string) $outcome['next_safe_at'] : null
                    );
                    $deferred++;
                    if (!empty($outcome['reached_remote']) && (int) ($outcome['http_status'] ?? 0) === 429) {
                        $endReason = 'remote_429_global_pause';
                        break;
                    }
                    continue;
                }
                if (($outcome['state'] ?? '') === 'review') {
                    $this->repository->review(
                        $job,
                        $runId,
                        (string) ($outcome['classification'] ?? 'domain_source_review'),
                    );
                    $receiptJobs[] = $this->cycleJobReceipt(
                        $job,
                        'review',
                        (string) ($outcome['classification'] ?? 'domain_source_review'),
                        (bool) ($outcome['reached_remote'] ?? false),
                        isset($outcome['http_status']) ? (int) $outcome['http_status'] : null,
                        isset($outcome['retry_after']) ? (string) $outcome['retry_after'] : null,
                        isset($outcome['next_safe_at']) ? (string) $outcome['next_safe_at'] : null
                    );
                    $reviewed++;
                    $deferred++;
                    if (!empty($outcome['reached_remote']) && (int) ($outcome['http_status'] ?? 0) === 429) {
                        $endReason = 'remote_429_global_pause';
                        break;
                    }
                    continue;
                }
                if (($outcome['state'] ?? '') !== 'completed') {
                    throw new RuntimeException('queue_v4_clean_worker_outcome_invalid');
                }
                $this->repository->complete($job, $runId);
                $receiptJobs[] = $this->cycleJobReceipt($job, 'completed', 'completed', (bool) ($outcome['reached_remote'] ?? false), null, null, null);
                $completed++;
            }
            if (QueueV4CleanCycleBudget::exhausted()) {
                $endReason = 'call_budget_exhausted';
            } elseif ($claimed >= $pointerSafetyLimit) {
                $endReason = 'pointer_safety_limit_reached';
            } elseif (microtime(true) >= $deadline - 2.0) {
                $endReason = 'runtime_deadline_reached';
            }
            $this->repository->finishRun($runId, 'completed');
            return [
                'run_id' => $runId,
                'claimed' => $claimed,
                'completed' => $completed,
                'deferred' => $deferred,
                'control_unit' => 'PHYSICAL_API_CALL',
                'max_calls' => $maxCalls,
                'physical_http_calls' => $this->physicalHttpCallsForRun($runId),
                'call_budget' => QueueV4CleanCycleBudget::snapshot(),
                'stop_reason' => $endReason,
            ];
        } catch (Throwable $error) {
            $this->repository->finishRun($runId, 'failed');
            $endReason = 'worker_exception:' . $this->failureClass($error);
            throw $error;
        } finally {
            $this->writeCycleAuditReceipt([
                'started_at' => $receiptStartedText,
                'ended_at' => gmdate('Y-m-d H:i:s'),
                'duration_ms' => (int) round((microtime(true) - $receiptStartedAt) * 1000),
                'launcher' => $launcher,
                'runtime_seconds' => $runtimeSeconds,
                'control_unit' => 'PHYSICAL_API_CALL',
                'max_calls' => $maxCalls,
                'max_jobs' => $maxCalls,
                'call_budget' => QueueV4CleanCycleBudget::snapshot(),
                'ready_before' => $readyBefore,
                'waiting_before' => $waitingBefore,
                'review_before' => $reviewBefore,
                'running_before' => $runningBefore,
                'claimed' => $claimed,
                'completed' => $completed,
                'deferred' => $deferred,
                'reviewed' => $reviewed,
                'remote_http' => $this->receiptCountRemoteHttp($receiptJobs),
                'remote_429' => $this->receiptCountHttpStatus($receiptJobs, 429),
                'remote_5xx' => $this->receiptCountHttp5xx($receiptJobs),
                'local_policy_delay' => $this->receiptCountLocalPolicyDelay($receiptJobs),
                'finance_wakeup_runtime' => $financeWakeupRuntime,
                'end_reason' => $endReason,
                'jobs' => $receiptJobs,
            ]);
        }
    }

    /**
     * Read-only canonical receipt for UI/manual orchestration. Automatic
     * callers may ignore it; K10 uses it to project state without inventing
     * retry/rhythm policy outside Queue V4.
     *
     * @return array<string,mixed>
     */
    public function receiptForRun(int $runId): array
    {
        if ($runId < 1) {
            return [
                'run_id' => 0,
                'claimed' => 0,
                'completed' => 0,
                'deferred' => 0,
                'physical_http_calls' => 0,
                'classification' => 'no_work',
                'http_status' => null,
                'next_safe_at' => null,
                'dispatch_state' => 'NOT_DISPATCHED',
            ];
        }

        try {
            $run = $this->pdo->prepare(
                'SELECT jobs_claimed,jobs_completed,jobs_deferred
                 FROM queue_v4_clean_runs WHERE id=? LIMIT 1'
            );
            $run->execute([$runId]);
            $runRow = $run->fetch(PDO::FETCH_ASSOC);
            $attempt = $this->pdo->prepare(
                'SELECT a.job_id,a.outcome,a.error_class,a.dispatch_state,a.physical_http_calls,a.http_status,
                        j.state AS job_state,j.available_at AS job_available_at,j.last_error_class AS job_error_class
                 FROM queue_v4_clean_attempts a
                 INNER JOIN queue_v4_clean_jobs j
                   ON j.id=a.job_id AND j.company_id=a.company_id AND j.meli_account_id=a.meli_account_id
                 WHERE a.run_id=?
                 ORDER BY a.id DESC LIMIT 1'
            );
            $attempt->execute([$runId]);
            $attemptRow = $attempt->fetch(PDO::FETCH_ASSOC);
            $classification = (string) (($attemptRow['error_class'] ?? '') ?: ($attemptRow['job_error_class'] ?? '') ?: ($attemptRow['outcome'] ?? 'no_work'));
            $nextSafeAt = null;
            if (($attemptRow['job_state'] ?? '') === 'waiting') {
                $nextSafeAt = $attemptRow['job_available_at'] ?? null;
            }

            return [
                'run_id' => $runId,
                'claimed' => (int) ($runRow['jobs_claimed'] ?? 0),
                'completed' => (int) ($runRow['jobs_completed'] ?? 0),
                'deferred' => (int) ($runRow['jobs_deferred'] ?? 0),
                'physical_http_calls' => $this->physicalHttpCallsForRun($runId),
                'classification' => $classification !== '' ? $classification : 'no_work',
                'http_status' => isset($attemptRow['http_status']) ? (int) $attemptRow['http_status'] : null,
                'next_safe_at' => $nextSafeAt,
                'dispatch_state' => (string) ($attemptRow['dispatch_state'] ?? 'NOT_DISPATCHED'),
                'outcome' => (string) ($attemptRow['outcome'] ?? 'none'),
                'job_state' => (string) ($attemptRow['job_state'] ?? ''),
            ];
        } catch (Throwable) {
            return [
                'run_id' => $runId,
                'claimed' => 0,
                'completed' => 0,
                'deferred' => 0,
                'physical_http_calls' => 0,
                'classification' => 'receipt_not_available',
                'http_status' => null,
                'next_safe_at' => null,
                'dispatch_state' => 'NOT_DISPATCHED',
            ];
        }
    }

    private function physicalHttpCallsForRun(int $runId): int
    {
        if ($runId < 1) {
            return 0;
        }
        try {
            if ($this->hasTable('queue_v4_clean_transport_events')
                && $this->hasColumn('queue_v4_clean_transport_events', 'attempt_id')) {
                $events = $this->pdo->prepare(
                    "SELECT COUNT(DISTINCT e.request_id)
                     FROM queue_v4_clean_transport_events e
                     INNER JOIN queue_v4_clean_attempts a
                       ON a.id=e.attempt_id
                      AND a.company_id=e.company_id
                      AND a.meli_account_id=e.meli_account_id
                     WHERE a.run_id=?
                       AND e.dispatch_state IN ('PHYSICAL_STARTED','RESPONSE_KNOWN')"
                );
                $events->execute([$runId]);
                $count = (int) $events->fetchColumn();
                if ($count > 0) {
                    return $count;
                }
            }
            $attempts = $this->pdo->prepare(
                'SELECT COALESCE(SUM(physical_http_calls),0)
                 FROM queue_v4_clean_attempts WHERE run_id=?'
            );
            $attempts->execute([$runId]);
            return (int) $attempts->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function hasTable(string $table): bool
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'
            );
            $stmt->execute([$table]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table . $column) !== 1) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1'
            );
            $stmt->execute([$table, $column]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $job
     * @return array{state:string,classification?:string,next_safe_at?:?string}
     */
    private function handle(array $job): array
    {
        if ($this->jobHandler !== null) {
            ($this->jobHandler)($job);
            return ['state' => 'completed'];
        }
        $companyId = (int) $job['company_id'];
        $accountId = (int) $job['meli_account_id'];
        $type = (string) $job['job_type'];
        $payload = is_array($job['payload']) ? $job['payload'] : [];
        if ($type === 'fresh_orders_discovery') {
            $account = $this->pdo->prepare(
                'SELECT meli_user_id FROM meli_accounts WHERE company_id=? AND id=? LIMIT 1'
            );
            $account->execute([$companyId, $accountId]);
            $sellerId = trim((string) $account->fetchColumn());
            if ($sellerId === '') {
                throw new RuntimeException('queue_v4_clean_payload_account_identity');
            }
            $from = trim((string) ($payload['from'] ?? ''));
            $to = trim((string) ($payload['to'] ?? ''));
            if ($from === '' || $to === '') {
                throw new RuntimeException('queue_v4_clean_payload_window');
            }
            $client = ($this->clientFactory)($accountId);
            $offset = max(0, (int) ($payload['offset'] ?? 0));
            $limit = (new SyncSettingsService())->pageLimit();
            $response = ApiExecutionMetadataContext::run(
                [
                    'source' => 'queue_v4_clean',
                    'job_type' => 'fresh_orders_discovery',
                    'company_id' => $companyId,
                    'account_id' => $accountId,
                    'source_queue_key' => 'queue_v4_clean',
                    'source_work_id' => (string) $job['id'],
                    'bulk' => false,
                ] + $this->transportMeta($job),
                static fn (): array => $client->get('/orders/search', [
                    'seller' => $sellerId,
                    'order.date_created.from' => $from,
                    'order.date_created.to' => $to,
                    'sort' => 'date_asc',
                    'offset' => $offset,
                    'limit' => $limit,
                ])
            );
            $results = is_array($response['results'] ?? null) ? $response['results'] : [];
            $sync = ($this->syncFactory)($accountId);
            foreach ($results as $order) {
                $id = trim((string) ($order['id'] ?? ''));
                if ($id === '' || !ctype_digit($id)) {
                    continue;
                }
                $sync->persistSearchSnapshotForQueueV4Clean($order, $companyId);
            }
            $total = max(0, (int) ($response['paging']['total'] ?? count($results)));
            $responseOffset = max(0, (int) ($response['paging']['offset'] ?? $offset));
            if ($responseOffset !== $offset || ($total > $offset && $results === [])) {
                throw new RuntimeException('queue_v4_clean_remote_paging_invalid');
            }
            $nextOffset = $offset + count($results);
            if ($nextOffset < $total) {
                $this->repository->enqueue(
                    $companyId,
                    $accountId,
                    'fresh_orders_discovery',
                    null,
                    'fresh:' . hash('sha256', $from . '|' . $to) . ':offset:' . $nextOffset,
                    ['from' => $from, 'to' => $to, 'offset' => $nextOffset, 'limit' => $limit],
                    3,
                );
            } else {
                $watermark = strtotime($to);
                if ($watermark === false) {
                    throw new RuntimeException('queue_v4_clean_payload_window');
                }
                $checkpoint = $this->pdo->prepare(
                    "UPDATE queue_v4_clean_checkpoints
                     SET watermark_at=?,next_due_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND)
                     WHERE producer_key='fresh_orders' AND company_id=? AND meli_account_id=?"
                );
                $checkpoint->execute([gmdate('Y-m-d H:i:s', $watermark), $companyId, $accountId]);
                if ($checkpoint->rowCount() !== 1) {
                    throw new RuntimeException('queue_v4_clean_checkpoint_lost');
                }
            }
            return ['state' => 'completed'];
        }
        if ($type === 'order_exact') {
            $orderId = trim((string) ($payload['order_id'] ?? $job['resource_id'] ?? ''));
            if ($orderId === '' || !ctype_digit($orderId)) {
                throw new RuntimeException('queue_v4_clean_payload_order_identity');
            }
            $sync = ($this->syncFactory)($accountId);
            $metadata = [
                'source' => 'queue_v4_clean',
                'job_type' => 'order_exact',
                'company_id' => $companyId,
                'account_id' => $accountId,
                'source_queue_key' => 'queue_v4_clean',
                'source_work_id' => (string) $job['id'],
                'bulk' => false,
            ] + $this->transportMeta($job);
            ApiExecutionMetadataContext::run(
                $metadata,
                static fn (): int => $sync->syncOrderByIdForQueueV4Clean($orderId, $metadata),
            );
            return ['state' => 'completed'];
        }
        if ($type === 'domain_exact') {
            return $this->handleDomainExact($job, $companyId, $accountId, $payload);
        }
        throw new RuntimeException('queue_v4_clean_payload_job_type');
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $payload
     * @return array{state:string,classification?:string,next_safe_at?:?string}
     */
    private function handleDomainExact(array $job, int $companyId, int $accountId, array $payload): array
    {
        $capability = trim((string) ($payload['capability'] ?? ''));
        $sourceId = (int) ($payload['source_id'] ?? $job['resource_id'] ?? 0);
        if (!in_array($capability, ['financial_recalc', 'financial_reconciliation', 'notification_work_item', 'order_enrichment_pack'], true) || $sourceId < 1) {
            return ['state' => 'review', 'classification' => 'domain_payload_invalid'];
        }
        $resourceId = trim((string) ($job['resource_id'] ?? ''));
        if ($resourceId === '' || !ctype_digit($resourceId) || (int) $resourceId !== $sourceId) {
            return ['state' => 'review', 'classification' => 'domain_payload_source_mismatch'];
        }
        if (!$this->domainTenantExists($companyId, $accountId)) {
            return ['state' => 'review', 'classification' => 'domain_source_tenant_mismatch'];
        }

        $source = $this->domainSource($capability, $sourceId, $companyId, $accountId);
        if ($source === null) {
            return ['state' => 'review', 'classification' => 'domain_source_missing'];
        }
        $before = $this->domainOutcome($capability, $source);
        if ($before['state'] !== 'waiting') {
            return $before;
        }

        if ($capability === 'notification_work_item') {
            $result = (new NotificationWorkItemService())->processQueueV4Exact(
                $sourceId,
                $accountId,
                $companyId,
                CronDeadlineContext::deadline(),
            );
            if ((string) ($result['status'] ?? '') === 'complete') {
                return ['state' => 'completed'];
            }
            if (in_array((string) ($result['status'] ?? ''), ['action_required', 'error'], true)) {
                return ['state' => 'review', 'classification' => 'notification_work_item_' . $this->safeToken((string) ($result['status'] ?? 'error'))];
            }
            return [
                'state' => 'waiting',
                'classification' => 'domain_source_waiting:notification_work_item',
                'next_safe_at' => isset($result['next_eligible_at']) ? (string) $result['next_eligible_at'] : gmdate('Y-m-d H:i:s', time() + 60),
            ];
        }

        if ($capability === 'order_enrichment_pack') {
            $service = new OrderEnrichmentService();
            $result = $service->processQueueV4PackExact(
                $sourceId,
                $accountId,
                $companyId,
                CronDeadlineContext::deadline(),
            );
            if ((string) ($result['status'] ?? '') === 'complete') {
                return ['state' => 'completed'];
            }
            $source = $this->domainSource($capability, $sourceId, $companyId, $accountId);
            if ($source === null) {
                return ['state' => 'review', 'classification' => 'domain_source_missing_after_process'];
            }
            $outcome = $this->domainOutcome($capability, $source);
            if (($outcome['state'] ?? '') === 'waiting') {
                $outcome['classification'] = $this->packWaitingClassification($result, $source);
                if (!array_key_exists('next_safe_at', $outcome) && isset($result['next_eligible_at'])) {
                    $next = $this->validFutureUtcDateTime($result['next_eligible_at']);
                    if ($next !== null) {
                        $outcome['next_safe_at'] = $next;
                    }
                }
                $outcome['reached_remote'] = ((int) ($source['reached_remote'] ?? 0)) === 1;
                $outcome['http_status'] = $this->httpStatusFromSource($source);
            }
            return $outcome === []
                ? ['state' => 'review', 'classification' => 'domain_source_missing_after_process']
                : $outcome;
        }

        $batchSourceIds = $capability === 'financial_reconciliation'
            ? $this->repository->contiguousFinancialReconciliationSourceIds($job, 60)
            : [$sourceId];
        $batchOutcomes = [];

        try {
            ApiExecutionMetadataContext::run(
                [
                    'source' => MeliTransportSourcePolicy::QUEUE_V4_DOMAIN_EXACT,
                    'job_type' => 'domain_exact',
                    'company_id' => $companyId,
                    'account_id' => $accountId,
                    'source_queue_key' => $capability,
                    'source_work_id' => (string) $sourceId,
                    'bulk' => count($batchSourceIds) > 1,
                ] + $this->transportMeta($job),
                function () use ($capability, $sourceId, $accountId, $companyId, $batchSourceIds, &$batchOutcomes): void {
                    if ($this->domainHandler !== null) {
                        ($this->domainHandler)($capability, $sourceId, $accountId);
                        return;
                    }
                    if ($capability === 'financial_recalc') {
                        (new OrderFinancialRecalcJobService())->processExact($sourceId, $accountId, 1);
                        return;
                    }
                    $batchOutcomes = ($this->financialFactory)()->processDomainExactBatch(
                        $batchSourceIds,
                        $companyId,
                        $accountId,
                    )['outcomes'];
                }
            );
        } catch (Throwable $error) {
            if ($capability === 'financial_reconciliation' && count($batchSourceIds) > 1) {
                $this->repository->alignReadyFinancialReconciliationPointersFromSources(
                    $companyId,
                    $accountId,
                    $batchSourceIds,
                    $sourceId,
                );
            }
            throw $error;
        }
        if ($capability === 'financial_reconciliation' && $batchOutcomes !== []) {
            $this->repository->alignReadyFinancialReconciliationPointers(
                $companyId,
                $accountId,
                $batchOutcomes,
                $sourceId,
            );
        }

        $source = $this->domainSource($capability, $sourceId, $companyId, $accountId);
        if ($source === null) {
            return ['state' => 'review', 'classification' => 'domain_source_missing_after_process'];
        }
        return $this->domainOutcome($capability, $source);
    }

    /** @return array<string,mixed>|null */
    private function domainSource(string $capability, int $sourceId, int $companyId, int $accountId): ?array
    {
        if ($capability === 'notification_work_item') {
            $sql = 'SELECT w.id,a.company_id,w.meli_account_id,w.status,w.next_run_at,NULL sale_key,NULL external_sale_id
               FROM meli_notification_work_items w
               JOIN meli_accounts a ON a.id=w.meli_account_id
               WHERE w.id=? AND a.company_id=? AND w.meli_account_id=? LIMIT 1';
        } elseif ($capability === 'order_enrichment_pack') {
            $sql = 'SELECT j.id,a.company_id,j.meli_account_id,j.status,j.next_run_at,NULL sale_key,
                          j.external_resource_id AS external_sale_id,j.resource_type,
                          j.attempts,j.failure_class,j.last_error_code,j.reached_remote,
                          j.last_started_at,j.last_processed_at
               FROM order_resource_enrichment_jobs j
               JOIN meli_accounts a ON a.id=j.meli_account_id
               WHERE j.id=? AND a.company_id=? AND j.meli_account_id=? AND j.resource_type="pack"
               LIMIT 1';
        } else {
            $sql = $capability === 'financial_recalc'
                ? 'SELECT id,company_id,meli_account_id,status,NULL next_run_at
               FROM order_financial_recalc_jobs
               WHERE id=? AND company_id=? AND meli_account_id=? LIMIT 1'
                : 'SELECT id,company_id,meli_account_id,status,next_run_at,sale_key,external_sale_id
               FROM sale_financial_reconciliation_jobs
               WHERE id=? AND company_id=? AND meli_account_id=? LIMIT 1';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$sourceId, $companyId, $accountId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function domainTenantExists(int $companyId, int $accountId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM meli_accounts WHERE id=? AND company_id=? LIMIT 1'
        );
        $statement->execute([$accountId, $companyId]);
        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string,mixed> $source
     * @return array{state:string,classification?:string,next_safe_at?:?string}
     */
    private function domainOutcome(string $capability, array $source): array
    {
        $status = strtolower(trim((string) ($source['status'] ?? '')));
        if ($status === 'complete') {
            return ['state' => 'completed'];
        }
        if ($capability === 'notification_work_item') {
            if (in_array($status, ['ignored'], true)) {
                return ['state' => 'completed'];
            }
            if (in_array($status, ['pending', 'retry', 'running'], true)) {
                $outcome = [
                    'state' => 'waiting',
                    'classification' => 'domain_source_waiting:notification_work_item',
                ];
                $next = $this->validFutureUtcDateTime($source['next_run_at'] ?? null);
                if ($next !== null) {
                    $outcome['next_safe_at'] = $next;
                }

                return $outcome;
            }

            return [
                'state' => 'review',
                'classification' => 'domain_source_' . ($status !== '' ? $this->safeToken($status) : 'unknown'),
            ];
        }
        if ($capability === 'order_enrichment_pack') {
            if (in_array($status, ['pending', 'running', 'retry'], true)) {
                $outcome = [
                    'state' => 'waiting',
                    'classification' => 'domain_source_waiting:order_enrichment_pack',
                ];
                $next = $this->validFutureUtcDateTime($source['next_run_at'] ?? null);
                if ($next !== null) {
                    $outcome['next_safe_at'] = $next;
                }

                return $outcome;
            }

            return [
                'state' => 'review',
                'classification' => 'domain_source_' . ($status !== '' ? $this->safeToken($status) : 'unknown'),
            ];
        }
        if (in_array($status, ['pending', 'running', 'retry', 'awaiting_remote'], true)) {
            $classification = 'domain_source_waiting:' . $capability;
            if ($capability === 'financial_reconciliation'
                && str_starts_with((string) ($source['sale_key'] ?? ''), 'P:')
                && !$this->financialPackIntegrityComplete($source)) {
                $classification .= ':pack_incomplete';
            }
            $outcome = [
                'state' => 'waiting',
                'classification' => $classification,
            ];
            if ($capability === 'financial_reconciliation') {
                $outcome['next_safe_at'] = $this->financialReconciliationNextSafeAt($source['next_run_at'] ?? null);
            } else {
                $next = $this->validFutureUtcDateTime($source['next_run_at'] ?? null);
                if ($next !== null) {
                    $outcome['next_safe_at'] = $next;
                }
            }

            return $outcome;
        }
        return [
            'state' => 'review',
            'classification' => 'domain_source_' . ($status !== '' ? $this->safeToken($status) : 'unknown'),
        ];
    }

    /**
     * @param array<string,mixed> $source
     */
    private function financialPackIntegrityComplete(array $source): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT integrity_status FROM meli_packs
             WHERE meli_account_id=? AND external_pack_id=? LIMIT 1'
        );
        $statement->execute([
            (int) ($source['meli_account_id'] ?? 0),
            (string) ($source['external_sale_id'] ?? substr((string) ($source['sale_key'] ?? ''), 2)),
        ]);

        return (string) ($statement->fetchColumn() ?: '') === 'complete';
    }

    private function financialReconciliationNextSafeAt(mixed $nextRunAt): string
    {
        $future = $this->validFutureUtcDateTime($nextRunAt);
        if ($future !== null) {
            return $future;
        }

        return gmdate('Y-m-d H:i:s', time() + self::FINANCIAL_RECONCILIATION_STALE_RECHECK_SECONDS);
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $source
     */
    private function packWaitingClassification(array $result, array $source): string
    {
        $prefix = 'domain_source_waiting:order_enrichment_pack:';
        $processed = (int) ($result['processed'] ?? 0);
        $stopReason = $this->optionalSafeToken((string) ($result['stop_reason'] ?? ''));
        $failureClass = $this->optionalSafeToken((string) ($source['failure_class'] ?? ''));
        $lastErrorCode = $this->optionalSafeToken((string) ($source['last_error_code'] ?? ''));
        $reachedRemote = ((int) ($source['reached_remote'] ?? 0)) === 1;

        if ($processed < 1) {
            if ($stopReason === 'waiting_deadline') {
                return $prefix . 'waiting_deadline';
            }
            if ($stopReason === 'empty') {
                return $prefix . 'source_not_claimed_state';
            }
            if ($stopReason !== '') {
                return $prefix . 'source_not_claimed_' . $stopReason;
            }

            return $prefix . 'source_not_claimed_state';
        }

        if ($failureClass === 'waiting_rhythm' && !$reachedRemote) {
            return $prefix . 'waiting_rhythm';
        }
        if ($failureClass === 'waiting_budget') {
            return $prefix . 'waiting_budget';
        }
        if ($failureClass === 'waiting_deadline') {
            return $prefix . 'waiting_deadline';
        }
        if ($lastErrorCode === 'http_429' || $failureClass === 'rate_limited') {
            return $prefix . 'rate_limited';
        }
        if (str_starts_with($lastErrorCode, 'http_5')) {
            return $prefix . 'remote_5xx';
        }
        if (in_array($failureClass, ['action_required', 'auth', 'oauth_refresh_required'], true)) {
            return $prefix . 'auth';
        }
        if ($failureClass === 'remote_result_uncertain') {
            return $prefix . 'remote_uncertain';
        }
        if ($failureClass !== '') {
            return $prefix . $failureClass;
        }

        return $prefix . 'unknown';
    }

    /** @param array<string,mixed> $source */
    private function httpStatusFromSource(array $source): ?int
    {
        $code = strtolower(trim((string) ($source['last_error_code'] ?? '')));
        if (preg_match('/^http_(\d{3})$/', $code, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    private function validFutureUtcDateTime(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $timestamp = strtotime($raw . ' UTC');
        if ($timestamp === false || $timestamp <= time()) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function rhythmDeferredCycleAction(ApiRhythmDeferredException $error): string
    {
        if (in_array($error->blockingScope, [
            'rhythm',
            'rhythm_permit_busy',
            'rhythm_authority_unavailable',
            'rhythm_fence_stale',
            'rhythm_interval',
            'rhythm_block_pause',
            'rhythm_global_window',
            'remote_429_global_pause',
            'rhythm_penalty_state_unavailable',
        ], true)) {
            return 'break';
        }

        if (in_array($error->blockingScope, [
            'billing_endpoint_interval',
            'billing_429_backoff',
            'retry_after',
            'rhythm_shared_orders_search_window',
            'rhythm_endpoint_shared_reduced',
            'rhythm_account_reduced',
            'remote_backoff',
        ], true)) {
            return 'continue';
        }

        return 'break';
    }

    private function deferredCycleAction(Throwable $error): string
    {
        if ($error instanceof OAuthRefreshRequiredException) {
            return 'continue';
        }
        if ($error instanceof ApiRhythmDeferredException) {
            return $this->rhythmDeferredCycleAction($error);
        }
        if ($error instanceof ApiBudgetInfrastructureException) {
            return 'break';
        }
        if ($error instanceof ApiBudgetExhaustedException) {
            return $this->budgetDeferredCycleAction($error);
        }
        if ($error instanceof QueueV4PreTransportDeferredException) {
            return 'continue';
        }
        if ($error instanceof CronDeadlineDeferredException
            || $error instanceof ManualRemoteCallLimitException) {
            return 'break';
        }
        if ($error instanceof RemoteResultUncertainException) {
            return 'continue';
        }
        if ($error instanceof ApiManualPauseException) {
            return $this->manualPauseCycleAction($error);
        }

        return 'break';
    }

    private function budgetDeferredCycleAction(ApiBudgetExhaustedException $error): string
    {
        $scopes = $error->blockedScopeNames();
        if ($scopes === []) {
            return 'break';
        }
        if (array_intersect($scopes, ['app', 'global', '*']) !== []) {
            return 'break';
        }
        foreach ($scopes as $scope) {
            if (!in_array($scope, ['account', 'endpoint', 'job_type'], true)) {
                return 'break';
            }
        }

        return 'continue';
    }

    private function manualPauseCycleAction(ApiManualPauseException $error): string
    {
        $scope = strtolower(trim($error->scope));
        if (in_array($scope, ['app', 'global', '*'], true)) {
            return 'break';
        }
        if (in_array($scope, ['account', 'endpoint'], true)) {
            return 'continue';
        }

        return 'break';
    }

    private function failureClass(Throwable $error): string
    {
        return substr(strtolower((new \ReflectionClass($error))->getShortName()), 0, 100);
    }

    /** @param array<string,mixed> $job */
    private function functionalFailure(array $job, int $runId, RuntimeException $error): void
    {
        if (str_starts_with($error->getMessage(), 'queue_v4_clean_payload_')) {
            $this->repository->dead($job, $runId, $error->getMessage());
        } elseif ((int) $job['attempt_count'] >= (int) $job['max_attempts']) {
            $this->repository->review($job, $runId, $this->failureClass($error));
        } else {
            $this->repository->wait($job, $runId, $this->failureClass($error), 30);
        }
    }

    private function safeToken(string $value): string
    {
        return substr(preg_replace('/[^a-z0-9_]+/', '_', strtolower($value)) ?: 'rhythm', 0, 70);
    }

    private function optionalSafeToken(string $value): string
    {
        $token = trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower($value)) ?: '', '_');
        return substr($token, 0, 70);
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function cycleJobReceipt(
        array $job,
        string $result,
        string $reason,
        bool $reachedRemote,
        ?int $httpStatus,
        ?string $retryAfter,
        ?string $nextSafeAt
    ): array {
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        return [
            'work_type' => (string) ($job['job_type'] ?? 'unknown'),
            'capability' => (string) ($payload['capability'] ?? ''),
            'queue_job_hash' => hash('sha256', 'queue_v4:' . (string) ($job['id'] ?? '0')),
            'source_hash' => isset($payload['source_id'])
                ? hash('sha256', 'source:' . (string) $payload['source_id'])
                : null,
            'result' => $result,
            'reason' => substr($reason, 0, 140),
            'reached_remote' => $reachedRemote,
            'http_status' => $httpStatus,
            'retry_after' => $retryAfter,
            'next_safe_at' => $nextSafeAt,
        ];
    }

    /** @param list<array<string,mixed>> $jobs */
    private function receiptCountRemoteHttp(array $jobs): int
    {
        $count = 0;
        foreach ($jobs as $job) {
            if (!empty($job['reached_remote']) || isset($job['http_status'])) {
                $count++;
            }
        }

        return $count;
    }

    /** @param list<array<string,mixed>> $jobs */
    private function receiptCountHttpStatus(array $jobs, int $status): int
    {
        $count = 0;
        foreach ($jobs as $job) {
            if (isset($job['http_status']) && (int) $job['http_status'] === $status) {
                $count++;
            }
        }

        return $count;
    }

    /** @param list<array<string,mixed>> $jobs */
    private function receiptCountHttp5xx(array $jobs): int
    {
        $count = 0;
        foreach ($jobs as $job) {
            $status = isset($job['http_status']) ? (int) $job['http_status'] : 0;
            if ($status >= 500 && $status <= 599) {
                $count++;
            }
        }

        return $count;
    }

    /** @param list<array<string,mixed>> $jobs */
    private function receiptCountLocalPolicyDelay(array $jobs): int
    {
        $count = 0;
        foreach ($jobs as $job) {
            $reason = (string) ($job['reason'] ?? '');
            if (str_contains($reason, 'waiting_deadline')
                || str_contains($reason, 'waiting_rhythm')
                || str_contains($reason, 'waiting_budget')
                || str_contains($reason, 'pre_transport_deferred')
                || str_contains($reason, 'capacity_deferred')) {
                $count++;
            }
        }

        return $count;
    }

    /** @param array<string,mixed> $receipt */
    private function writeCycleAuditReceipt(array $receipt): void
    {
        if (($receipt['launcher'] ?? '') !== 'scheduler') {
            return;
        }
        try {
            $root = dirname(__DIR__, 2);
            $dir = $root . DIRECTORY_SEPARATOR . self::QUEUE_V4_AUDIT_DIR;
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                return;
            }
            $file = $dir . DIRECTORY_SEPARATOR . gmdate('Y-m-d') . '.jsonl';
            $json = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json) || $json === '') {
                return;
            }
            file_put_contents($file, $json . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Audit receipts must never break the Queue V4 worker.
        }
    }

    /** @param array<string,mixed> $job */
    private function shouldParkFinancialReconciliation(array $job, ApiRhythmDeferredException $error): bool
    {
        if (!in_array($error->blockingScope, ['billing_endpoint_interval', 'billing_429_backoff'], true)) {
            return false;
        }
        if ((string) ($job['job_type'] ?? '') !== 'domain_exact') {
            return false;
        }
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        return (string) ($payload['capability'] ?? '') === 'financial_reconciliation';
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function transportMeta(array $job): array
    {
        return [
            'queue_v4_job_id' => (int) ($job['id'] ?? 0),
            'queue_v4_attempt_id' => (int) ($job['attempt_id'] ?? 0),
            'queue_v4_lease_owner' => (string) ($job['lease_owner'] ?? ''),
            'queue_v4_lease_generation' => (int) ($job['lease_generation'] ?? 0),
        ];
    }
}
