<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3ExecutionContext;
use App\Services\OrderFinancialRecalcJobService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;
use InvalidArgumentException;

final class FinancialRecalcHandler implements CronV3WorkHandler
{
    /** @var Closure(WorkEnvelope,int):array<string,mixed> */
    private readonly Closure $process;

    /** @param null|callable(WorkEnvelope,int):array<string,mixed> $process */
    public function __construct(?callable $process = null)
    {
        $this->process = $process !== null
            ? Closure::fromCallable($process)
            : static fn (WorkEnvelope $work, int $jobId): array =>
                (new OrderFinancialRecalcJobService())->processExact($jobId, $work->meliAccountId, 1);
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $jobId = (int) ($work->payload['job_id'] ?? 0);
        if ($jobId < 1) {
            throw new InvalidArgumentException('El recálculo financiero requiere job_id.');
        }
        $result = ($this->process)($work, $jobId);
        $metadata = [
            'job_id' => $jobId,
            'processed' => (int) ($result['processed'] ?? 0),
            'errors' => (int) ($result['errors'] ?? 0),
            'stop_reason' => (string) ($result['stop_reason'] ?? 'complete'),
        ];
        if ($metadata['errors'] > 0) {
            return WorkResult::review('financial_recalc_error', $metadata);
        }
        if ($metadata['stop_reason'] === 'continue') {
            return WorkResult::deferred(gmdate('Y-m-d H:i:s', time() + 5), 'financial_recalc_continue', $metadata);
        }

        return WorkResult::completed($metadata);
    }
}
