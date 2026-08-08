<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3ExecutionContext;
use App\Services\FinancialGapScanService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;

final class FinancialGapScanHandler implements CronV3WorkHandler
{
    /** @var Closure(WorkEnvelope,int,int):array<string,mixed> */
    private readonly Closure $scan;

    /** @param null|callable(WorkEnvelope,int,int):array<string,mixed> $scan */
    public function __construct(?callable $scan = null)
    {
        $this->scan = $scan !== null
            ? Closure::fromCallable($scan)
            : static fn (WorkEnvelope $work, int $read, int $enqueue): array =>
                (new FinancialGapScanService())->scanAccount(
                    $work->companyId,
                    $work->meliAccountId,
                    $read,
                    $enqueue
                );
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $read = max(1, min(50, (int) ($work->payload['read_limit'] ?? 50)));
        $enqueue = max(1, min(20, (int) ($work->payload['enqueue_limit'] ?? 20)));
        $result = ($this->scan)($work, $read, $enqueue);
        $metadata = [
            'read' => (int) ($result['read'] ?? 0),
            'enqueued' => (int) ($result['enqueued'] ?? 0),
            'local_jobs' => (int) ($result['local_jobs'] ?? 0),
            'billing_jobs' => (int) ($result['billing_jobs'] ?? 0),
            'has_more' => ($result['next_cursor'] ?? null) !== null,
        ];
        if ($metadata['has_more']) {
            return WorkResult::deferred(gmdate('Y-m-d H:i:s', time() + 5), 'financial_gap_scan_continue', $metadata);
        }

        return WorkResult::completed($metadata);
    }
}
