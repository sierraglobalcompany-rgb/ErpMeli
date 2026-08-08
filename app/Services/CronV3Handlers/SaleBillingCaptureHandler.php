<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3ExecutionContext;
use App\Services\SaleFinancialService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;
use InvalidArgumentException;

final class SaleBillingCaptureHandler implements CronV3WorkHandler
{
    /** @var Closure(WorkEnvelope,int):array<string,mixed> */
    private readonly Closure $process;

    /** @param null|callable(WorkEnvelope,int):array<string,mixed> $process */
    public function __construct(?callable $process = null)
    {
        $this->process = $process !== null
            ? Closure::fromCallable($process)
            : static fn (WorkEnvelope $work, int $jobId): array =>
                (new SaleFinancialService())->processExact($jobId);
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $jobId = (int) ($work->payload['job_id'] ?? 0);
        if ($jobId < 1) {
            throw new InvalidArgumentException('La captura Billing requiere job_id.');
        }
        $result = $context->logicalRemoteCall(fn (): array => ($this->process)($work, $jobId));
        $metadata = [
            'job_id' => $jobId,
            'processed' => (int) ($result['processed'] ?? 0),
            'completed' => (int) ($result['completed'] ?? 0),
            'deferred' => (int) ($result['deferred'] ?? 0),
            'errors' => (int) ($result['errors'] ?? 0),
            'stop_reason' => (string) ($result['stop_reason'] ?? 'complete'),
        ];
        if ($metadata['errors'] > 0) {
            return WorkResult::review('sale_billing_capture_review', $metadata);
        }
        if ($metadata['deferred'] > 0) {
            if ($metadata['stop_reason'] === 'http_429') {
                return WorkResult::deferred(
                    gmdate('Y-m-d H:i:s', time() + 60 + random_int(1, 15)),
                    'http_429',
                    $metadata
                );
            }
            if ($metadata['stop_reason'] === 'http_5xx') {
                return WorkResult::deferred(
                    gmdate('Y-m-d H:i:s', time() + 60 + random_int(1, 15)),
                    'http_5xx',
                    $metadata
                );
            }
            return WorkResult::deferred(gmdate('Y-m-d H:i:s', time() + 60), 'sale_billing_capture_deferred', $metadata);
        }

        return WorkResult::completed($metadata);
    }
}
