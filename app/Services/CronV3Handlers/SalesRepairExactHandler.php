<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3ExecutionContext;
use App\Services\SalesAuditExactRepairService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;
use InvalidArgumentException;

final class SalesRepairExactHandler implements CronV3WorkHandler
{
    /** @var Closure(int):array<string,mixed> */
    private readonly Closure $process;

    /** @param null|callable(int):array<string,mixed> $process */
    public function __construct(?callable $process = null)
    {
        $this->process = $process !== null
            ? Closure::fromCallable($process)
            : static fn (int $jobId): array => (new SalesAuditExactRepairService())->processExact($jobId, 1);
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $jobId = (int) ($work->payload['job_id'] ?? $work->payload['legacy_job_id'] ?? 0);
        if ($jobId < 1) {
            throw new InvalidArgumentException('La reparación exacta de ventas requiere job_id.');
        }

        $result = $context->logicalRemoteCall(fn (): array => ($this->process)($jobId));
        $metadata = [
            'job_id' => $jobId,
            'processed' => (int) ($result['processed'] ?? 0),
            'jobs' => (int) ($result['jobs'] ?? 0),
            'errors' => (int) ($result['errors'] ?? 0),
            'status' => (string) ($result['status'] ?? 'complete'),
            'stop_reason' => (string) ($result['stop_reason'] ?? ($result['status'] ?? 'complete')),
        ];
        if ($metadata['status'] === 'lease_lost') {
            return WorkResult::deferred(gmdate('Y-m-d H:i:s', time() + 60), 'sales_repair_lease_retry', $metadata);
        }
        if ($metadata['errors'] > 0 || $metadata['status'] === 'error') {
            return WorkResult::review('sales_repair_review', $metadata);
        }
        if (in_array($metadata['status'], ['deferred', 'waiting_budget'], true)
            || in_array($metadata['stop_reason'], ['api_budget', 'api_rhythm'], true)) {
            return WorkResult::deferred(gmdate('Y-m-d H:i:s', time() + 60), 'sales_repair_deferred', $metadata);
        }

        return WorkResult::completed($metadata);
    }
}
