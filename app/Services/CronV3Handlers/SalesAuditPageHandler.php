<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3ExecutionContext;
use App\Services\CronDeadlineContext;
use App\Services\SalesAuditRunService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;
use InvalidArgumentException;

final class SalesAuditPageHandler implements CronV3WorkHandler
{
    /** @var Closure(int,?float):array<string,mixed> */
    private readonly Closure $process;

    /** @param null|callable(int,?float):array<string,mixed> $process */
    public function __construct(?callable $process = null)
    {
        $this->process = $process !== null
            ? Closure::fromCallable($process)
            : static fn (int $jobId, ?float $deadline): array =>
                (new SalesAuditRunService())->processExact($jobId, 1, $deadline);
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $jobId = (int) ($work->payload['job_id'] ?? $work->payload['legacy_job_id'] ?? 0);
        if ($jobId < 1) {
            throw new InvalidArgumentException('La auditoría de ventas requiere job_id.');
        }

        $result = $context->logicalRemoteCall(fn (): array => ($this->process)($jobId, CronDeadlineContext::deadline()));
        $metadata = [
            'job_id' => $jobId,
            'processed' => (int) ($result['processed'] ?? 0),
            'errors' => (int) ($result['errors'] ?? 0),
            'status' => (string) ($result['status'] ?? 'complete'),
            'stop_reason' => (string) ($result['stop_reason'] ?? ($result['status'] ?? 'complete')),
        ];
        if ($metadata['errors'] > 0 || $metadata['status'] === 'error') {
            return WorkResult::review('sales_audit_review', $metadata);
        }
        if ($metadata['status'] === 'deferred' || in_array($metadata['stop_reason'], ['time_budget', 'page_checkpoint', 'api_budget'], true)) {
            return WorkResult::deferred(gmdate('Y-m-d H:i:s', time() + 60), 'sales_audit_deferred', $metadata);
        }

        return WorkResult::completed($metadata);
    }
}
