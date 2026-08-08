<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3ExecutionContext;
use App\Services\HistoricalPackReconciliationService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;
use InvalidArgumentException;

final class SalePackReconciliationExactHandler implements CronV3WorkHandler
{
    /** @var Closure(int):array<string,mixed> */
    private readonly Closure $process;

    /** @param null|callable(int):array<string,mixed> $process */
    public function __construct(?callable $process = null)
    {
        $this->process = $process !== null
            ? Closure::fromCallable($process)
            : static fn (int $jobId): array => (new HistoricalPackReconciliationService())->processExact($jobId);
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $jobId = (int) ($work->payload['job_id'] ?? $work->payload['legacy_job_id'] ?? 0);
        if ($jobId < 1) {
            throw new InvalidArgumentException('La reconstrucción de pack requiere job_id.');
        }

        $result = $context->logicalRemoteCall(fn (): array => ($this->process)($jobId));
        $metadata = [
            'job_id' => $jobId,
            'processed' => (int) ($result['processed'] ?? 0),
            'completed' => (int) ($result['completed'] ?? 0),
            'deferred' => (int) ($result['deferred'] ?? 0),
            'errors' => (int) ($result['errors'] ?? 0),
            'stop_reason' => (string) ($result['stop_reason'] ?? 'complete'),
        ];
        if ($metadata['errors'] > 0) {
            return WorkResult::review('sale_pack_reconciliation_review', $metadata);
        }
        if ($metadata['deferred'] > 0) {
            return WorkResult::deferred(gmdate('Y-m-d H:i:s', time() + 60), 'sale_pack_reconciliation_deferred', $metadata);
        }

        return WorkResult::completed($metadata);
    }
}
