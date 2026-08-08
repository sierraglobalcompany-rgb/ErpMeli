<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;

final class CronV3Runner
{
    public function __construct(
        private readonly CronV3WorkRepository $repository,
        private readonly CronV3HandlerRegistry $handlers,
        private readonly CronV3WorkTypeRegistry $types,
        private readonly CronV3RateGate $rateGate,
        private readonly CronV3Enqueuer $enqueuer,
        private readonly int $rateLimit = 10,
    ) {
    }

    /** @return array<string,mixed> */
    public function run(
        string $lane,
        int $runtimeSeconds,
        int $maxItems,
        bool $shadow = false,
    ): array {
        $runtimeSeconds = max(1, min(55, $runtimeSeconds));
        $maxItems = max(1, min($lane === 'remote' ? 30 : 50, $maxItems));
        $started = microtime(true);

        if ($shadow) {
            $preview = $this->repository->preview($lane, $this->types->forLane($lane), min(50, $maxItems));
            $summary = [
                'ok' => true,
                'mode' => 'shadow',
                'lane' => $lane,
                'observed' => count($preview),
                'work' => $preview,
                'http_calls' => 0,
                'source_mutations' => 0,
            ];
            $this->repository->recordSnapshot($lane, $summary);
            return $summary;
        }

        $registeredTypes = $this->handlers->typesForLane($lane);
        $summary = [
            'ok' => true,
            'mode' => 'active',
            'lane' => $lane,
            'claimed' => 0,
            'completed' => 0,
            'deferred' => 0,
            'review' => 0,
            'dead' => 0,
            'lease_lost' => 0,
            'http_calls' => 0,
            'reason' => $registeredTypes === [] ? 'no_registered_handlers' : 'drained',
        ];

        while ($registeredTypes !== []
            && $summary['claimed'] < $maxItems
            && (microtime(true) - $started) < $runtimeSeconds
            && CronDeadlineContext::canAcceptWork(2)) {
            $work = $this->repository->claimOne($lane, $registeredTypes);
            if ($work === null) {
                break;
            }
            $summary['claimed']++;
            $this->repository->beginAttempt($work);

            $context = $lane === 'local'
                ? CronV3ExecutionContext::local()
                : CronV3ExecutionContext::remote(fn (): array => $this->rateGate->reserve(
                    $work,
                    (string) $work->ownerToken,
                    $this->rateLimit,
                    60,
                ));

            $dispatchesBefore = ApiExecutionMetadataContext::remoteDispatchCount();
            $knownBefore = ApiExecutionMetadataContext::knownResponseCount();
            try {
                if ($lane === 'remote') {
                    CronDeadlineContext::assertCanStartRemote(2.0);
                }
                $execute = fn (): WorkResult => $this->handlers->execute($work, $context);
                $result = $lane === 'remote'
                    ? ApiExecutionMetadataContext::run([
                        'source' => 'cron_v3_remote',
                        'cron_v3_work_id' => $work->id,
                        'company_id' => $work->companyId,
                        'meli_account_id' => $work->meliAccountId,
                        'work_type' => $work->workType,
                        'lease_generation' => $work->leaseGeneration,
                    ], $execute)
                    : $execute();
                if ($lane === 'remote' && $result->status === 'completed' && $context->logicalCallCount() !== 1) {
                    $result = WorkResult::review('remote_attempt_without_logical_call');
                }
            } catch (CronV3RateLimitedException $error) {
                $result = WorkResult::deferred($error->retryAt, 'rate_limited');
            } catch (CronDeadlineDeferredException $error) {
                $result = WorkResult::deferred($error->nextSafeAt, 'deadline_deferred');
            } catch (OAuthRefreshRequiredException) {
                $this->enqueueOAuthRefresh($work);
                $result = WorkResult::deferred(
                    gmdate('Y-m-d H:i:s', time() + 60),
                    'oauth_refresh_required'
                );
            } catch (ApiRhythmDeferredException $error) {
                $result = WorkResult::deferred(
                    $error->nextSafeAt ?? gmdate('Y-m-d H:i:s', time() + 60),
                    $error->blockingScope === 'retry_after' ? 'http_429' : 'remote_backoff'
                );
            } catch (ApiBudgetExhaustedException $error) {
                $result = WorkResult::deferred(
                    $error->nextSafeAt ?? gmdate('Y-m-d H:i:s', time() + 60),
                    'api_budget_deferred'
                );
            } catch (RemoteResultUncertainException) {
                $result = WorkResult::review('remote_result_uncertain');
            } catch (ManualRemoteCallLimitException) {
                $result = WorkResult::review('multiple_remote_calls');
            } catch (MeliApiException $error) {
                $result = $this->knownHttpFailure($error);
            } catch (Throwable) {
                $physicalCalls = max(
                    0,
                    ApiExecutionMetadataContext::remoteDispatchCount() - $dispatchesBefore
                );
                $result = $lane === 'remote' && $physicalCalls > 0
                    ? WorkResult::review('remote_result_uncertain')
                    : WorkResult::deferred(gmdate('Y-m-d H:i:s', time() + 60), 'handler_failed');
            }

            $logicalCalls = $context->logicalCallCount();
            $physicalCalls = $lane === 'remote'
                ? max(0, ApiExecutionMetadataContext::remoteDispatchCount() - $dispatchesBefore)
                : 0;
            $knownResponses = $lane === 'remote'
                ? max(0, ApiExecutionMetadataContext::knownResponseCount() - $knownBefore)
                : 0;
            $summary['http_calls'] += $physicalCalls;
            if (!$this->repository->finalize(
                $work,
                $result,
                $logicalCalls,
                $physicalCalls,
                $knownResponses,
                fn (): bool => $this->rateGate->recordResult($work, $result, (string) $work->ownerToken),
            )) {
                $summary['lease_lost']++;
                continue;
            }
            (new CronV3LegacySourceFinalizer())->finalize($work, $result);
            $summary[$result->status]++;
        }

        $summary['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
        $this->repository->recordSnapshot($lane, $summary);
        return $summary;
    }

    private function enqueueOAuthRefresh(WorkEnvelope $work): void
    {
        $this->enqueuer->enqueue(WorkEnvelope::create(
            $work->companyId,
            $work->meliAccountId,
            'oauth_refresh',
            'remote',
            'account:' . $work->meliAccountId,
            'oauth-hour:' . gmdate('Y-m-d-H'),
            ['requested_by_work_id' => $work->id],
            'cron_v3:' . $work->workType,
            1
        ));
    }

    private function knownHttpFailure(MeliApiException $error): WorkResult
    {
        $status = (int) ($error->httpStatus ?? 0);
        if ($status === 429) {
            $retryAfter = max(1, (int) ($error->response['retry_after'] ?? 60));
            return WorkResult::deferred(
                gmdate('Y-m-d H:i:s', time() + $retryAfter + random_int(1, 15)),
                'http_429'
            );
        }
        if ($status >= 500 || $status === 0) {
            return WorkResult::deferred(
                gmdate('Y-m-d H:i:s', time() + 60 + random_int(1, 15)),
                'http_5xx'
            );
        }
        if ($status === 404) {
            return WorkResult::completed(['remote_absent' => true]);
        }
        if (in_array($status, [401, 403], true)) {
            return WorkResult::review('http_' . $status);
        }
        return WorkResult::dead('http_' . max(400, $status));
    }
}
