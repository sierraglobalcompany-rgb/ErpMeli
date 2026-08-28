<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Services\ApiExecutionMetadataContext;
use App\Services\ApiBudgetInfrastructureException;
use App\Services\ApiBudgetExhaustedException;
use App\Services\ApiManualPauseException;
use App\Services\ApiRhythmDeferredException;
use App\Services\CronDeadlineContext;
use App\Services\CronDeadlineDeferredException;
use App\Services\MeliApiClient;
use App\Services\MeliApiException;
use App\Services\MeliReadClientInterface;
use App\Services\MeliTransportSourcePolicy;
use App\Services\ManualRemoteCallLimitException;
use App\Services\OAuthRefreshRequiredException;
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
    public const DEFAULT_MAX_JOBS = 15;
    public const HARD_MAX_JOBS = 15;
    private const FINANCIAL_RECONCILIATION_STALE_RECHECK_SECONDS = 900;

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

    /** @return array{run_id:int,claimed:int,completed:int,deferred:int} */
    public function run(string $launcher, int $maxJobs = self::DEFAULT_MAX_JOBS, int $runtimeSeconds = 45): array
    {
        if ($maxJobs < 1 || $runtimeSeconds < 5) {
            return ['run_id' => 0, 'claimed' => 0, 'completed' => 0, 'deferred' => 0];
        }
        $control = $this->repository->control();
        if ((string) $control['engine_state'] !== 'ACTIVE') {
            return ['run_id' => 0, 'claimed' => 0, 'completed' => 0, 'deferred' => 0];
        }
        $maxJobs = min(self::HARD_MAX_JOBS, $maxJobs);
        $deadline = microtime(true) + max(5, min(45, $runtimeSeconds));
        $owner = bin2hex(random_bytes(16));
        $runId = $this->repository->beginRun($launcher, $owner);
        $claimed = $completed = $deferred = 0;
        try {
            $this->repository->expireLeases();
            $this->repository->releaseDueWaiting();
            while ($claimed < $maxJobs
                && microtime(true) < $deadline - 2.0
                && CronDeadlineContext::canAcceptWork(2)) {
                $job = $this->repository->claim($runId, $owner, 60);
                if ($job === null) {
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
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
                        break;
                    }
                    continue;
                } catch (ApiRhythmDeferredException $error) {
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        'rate_limit_deferred:' . $this->safeToken($error->blockingScope),
                        $error->nextSafeAt,
                    );
                    if ($this->shouldParkFinancialReconciliation($job, $error)) {
                        $this->repository->parkFinancialReconciliationUntil(
                            $error->nextSafeAt,
                            (int) ($job['id'] ?? 0),
                        );
                    }
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
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
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
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
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
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
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
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
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
                        break;
                    }
                    continue;
                } catch (RemoteResultUncertainException $error) {
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        'remote_result_uncertain_safe_get',
                        gmdate('Y-m-d H:i:s', time() + 60),
                    );
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
                        break;
                    }
                    continue;
                } catch (ApiManualPauseException $error) {
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        'manual_pause:' . $this->safeToken($error->scope),
                        $error->resumeAt,
                    );
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
                        break;
                    }
                    continue;
                } catch (ApiBudgetInfrastructureException $error) {
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        'capacity_deferred:budget_infrastructure',
                        gmdate('Y-m-d H:i:s', time() + 60),
                    );
                    $deferred++;
                    if ($this->deferredCycleAction($error) === 'break') {
                        break;
                    }
                    continue;
                } catch (MeliApiException $error) {
                    $this->functionalFailure($job, $runId, $error);
                    $deferred++;
                    continue;
                } catch (RuntimeException $error) {
                    $this->functionalFailure($job, $runId, $error);
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
                    $deferred++;
                    continue;
                }
                if (($outcome['state'] ?? '') === 'review') {
                    $this->repository->review(
                        $job,
                        $runId,
                        (string) ($outcome['classification'] ?? 'domain_source_review'),
                    );
                    $deferred++;
                    continue;
                }
                if (($outcome['state'] ?? '') !== 'completed') {
                    throw new RuntimeException('queue_v4_clean_worker_outcome_invalid');
                }
                $this->repository->complete($job, $runId);
                $completed++;
            }
            $this->repository->finishRun($runId, 'completed');
            return ['run_id' => $runId, 'claimed' => $claimed, 'completed' => $completed, 'deferred' => $deferred];
        } catch (Throwable $error) {
            $this->repository->finishRun($runId, 'failed');
            throw $error;
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
        if (!in_array($capability, ['financial_recalc', 'financial_reconciliation'], true) || $sourceId < 1) {
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
        $sql = $capability === 'financial_recalc'
            ? 'SELECT id,company_id,meli_account_id,status,NULL next_run_at
               FROM order_financial_recalc_jobs
               WHERE id=? AND company_id=? AND meli_account_id=? LIMIT 1'
            : 'SELECT id,company_id,meli_account_id,status,next_run_at
               FROM sale_financial_reconciliation_jobs
               WHERE id=? AND company_id=? AND meli_account_id=? LIMIT 1';
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
        if (in_array($status, ['pending', 'running', 'retry', 'awaiting_remote'], true)) {
            $outcome = [
                'state' => 'waiting',
                'classification' => 'domain_source_waiting:' . $capability,
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

    private function financialReconciliationNextSafeAt(mixed $nextRunAt): string
    {
        $future = $this->validFutureUtcDateTime($nextRunAt);
        if ($future !== null) {
            return $future;
        }

        return gmdate('Y-m-d H:i:s', time() + self::FINANCIAL_RECONCILIATION_STALE_RECHECK_SECONDS);
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
        if ($error instanceof CronDeadlineDeferredException
            || $error instanceof ManualRemoteCallLimitException
            || $error instanceof QueueV4PreTransportDeferredException) {
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
