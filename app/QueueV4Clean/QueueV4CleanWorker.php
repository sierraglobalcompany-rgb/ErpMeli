<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Services\ApiExecutionMetadataContext;
use App\Services\ApiBudgetExhaustedException;
use App\Services\ApiRhythmDeferredException;
use App\Services\ApiRhythmPolicyService;
use App\Services\CronDeadlineContext;
use App\Services\CronDeadlineDeferredException;
use App\Services\MeliApiClient;
use App\Services\MeliApiException;
use App\Services\MeliReadClientInterface;
use App\Services\MeliTransportSourcePolicy;
use App\Services\OAuthRefreshRequiredException;
use App\Services\OrderFinancialRecalcJobService;
use App\Services\OrderSyncService;
use App\Services\QueueV4PreTransportDeferredException;
use App\Services\RemoteResultUncertainException;
use App\Services\SaleFinancialService;
use PDO;
use RuntimeException;
use Throwable;

final class QueueV4CleanWorker
{
    public const DEFAULT_MAX_JOBS = 15;
    public const HARD_MAX_JOBS = 15;

    /** @var \Closure(int):MeliReadClientInterface */
    private \Closure $clientFactory;
    /** @var \Closure(int):OrderSyncService */
    private \Closure $syncFactory;
    /** @var null|\Closure(array<string,mixed>):void */
    private ?\Closure $jobHandler;
    /** @var null|\Closure(string,int,int):void */
    private ?\Closure $domainHandler;

    /**
     * @param null|callable(int):MeliReadClientInterface $clientFactory
     * @param null|callable(int):OrderSyncService $syncFactory
     * @param null|callable(array<string,mixed>):void $jobHandler Test-only/local fixture seam.
     * @param null|callable(string,int,int):void $domainHandler Test-only domain source seam.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly QueueV4CleanRepository $repository,
        ?callable $clientFactory = null,
        ?callable $syncFactory = null,
        ?callable $jobHandler = null,
        ?callable $domainHandler = null,
    ) {
        $this->clientFactory = $clientFactory !== null
            ? \Closure::fromCallable($clientFactory)
            : static fn (int $accountId): MeliReadClientInterface => new MeliApiClient($accountId);
        $this->syncFactory = $syncFactory !== null
            ? \Closure::fromCallable($syncFactory)
            : static fn (int $accountId): OrderSyncService => new OrderSyncService($accountId);
        $this->jobHandler = $jobHandler !== null ? \Closure::fromCallable($jobHandler) : null;
        $this->domainHandler = $domainHandler !== null ? \Closure::fromCallable($domainHandler) : null;
    }

    /** @return array{claimed:int,completed:int,deferred:int} */
    public function run(string $launcher, int $maxJobs = self::DEFAULT_MAX_JOBS, int $runtimeSeconds = 45): array
    {
        if ($maxJobs < 1 || $runtimeSeconds < 5) {
            return ['claimed' => 0, 'completed' => 0, 'deferred' => 0];
        }
        $control = $this->repository->control();
        if ((string) $control['engine_state'] !== 'ACTIVE') {
            return ['claimed' => 0, 'completed' => 0, 'deferred' => 0];
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
                } catch (OAuthRefreshRequiredException) {
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
                    continue;
                } catch (ApiBudgetExhaustedException $error) {
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        'capacity_deferred:budget',
                        $error->nextSafeAt,
                    );
                    $deferred++;
                    continue;
                } catch (CronDeadlineDeferredException $error) {
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        'capacity_deferred:cron_deadline',
                        $error->nextSafeAt,
                    );
                    $deferred++;
                    break;
                } catch (QueueV4PreTransportDeferredException $error) {
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        'pre_transport_deferred',
                        $error->nextSafeAt,
                    );
                    $deferred++;
                    continue;
                } catch (RemoteResultUncertainException) {
                    $this->repository->deferWithoutAttemptPenalty(
                        $job,
                        $runId,
                        'remote_result_uncertain_safe_get',
                        gmdate('Y-m-d H:i:s', time() + 60),
                    );
                    $deferred++;
                    continue;
                } catch (MeliApiException $error) {
                    if ($error->httpStatus === 429) {
                        $this->repository->deferWithoutAttemptPenalty(
                            $job,
                            $runId,
                            'rate_limit_deferred:http_429_fallback',
                            (new ApiRhythmPolicyService())->conservativeRateLimitNextSafeAt(),
                        );
                        $deferred++;
                        continue;
                    }
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
            return compact('claimed', 'completed', 'deferred');
        } catch (Throwable $error) {
            $this->repository->finishRun($runId, 'failed');
            throw $error;
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
            $limit = max(1, min(20, (int) ($payload['limit'] ?? 20)));
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
            foreach ($results as $order) {
                $id = trim((string) ($order['id'] ?? ''));
                if ($id === '' || !ctype_digit($id)) {
                    continue;
                }
                $this->repository->enqueue(
                    $companyId,
                    $accountId,
                    'order_exact',
                    $id,
                    'order:' . $id,
                    ['order_id' => $id],
                    3,
                );
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

        ApiExecutionMetadataContext::run(
            [
                'source' => MeliTransportSourcePolicy::QUEUE_V4_DOMAIN_EXACT,
                'job_type' => 'domain_exact',
                'company_id' => $companyId,
                'account_id' => $accountId,
                'source_queue_key' => $capability,
                'source_work_id' => (string) $sourceId,
                'bulk' => false,
            ] + $this->transportMeta($job),
            function () use ($capability, $sourceId, $accountId): void {
                if ($this->domainHandler !== null) {
                    ($this->domainHandler)($capability, $sourceId, $accountId);
                    return;
                }
                if ($capability === 'financial_recalc') {
                    (new OrderFinancialRecalcJobService())->processExact($sourceId, $accountId, 1);
                    return;
                }
                (new SaleFinancialService())->processExact($sourceId);
            }
        );

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
            $next = trim((string) ($source['next_run_at'] ?? ''));
            return [
                'state' => 'waiting',
                'classification' => 'domain_source_waiting:' . $capability,
                'next_safe_at' => $next !== '' ? $next : gmdate('Y-m-d H:i:s', time() + 5),
            ];
        }
        return [
            'state' => 'review',
            'classification' => 'domain_source_' . ($status !== '' ? $this->safeToken($status) : 'unknown'),
        ];
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
