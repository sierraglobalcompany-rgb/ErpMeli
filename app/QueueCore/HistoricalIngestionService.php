<?php
declare(strict_types=1);

namespace App\QueueCore;

use RuntimeException;

final class HistoricalIngestionService
{
    public const MAX_PER_CYCLE = 50;

    /** @param iterable<QueueJob> $jobs */
    public function ingest(QueueCoreRepository $repository, iterable $jobs, int $limit = self::MAX_PER_CYCLE, bool $explicit = false): int
    {
        if(!$explicit)throw new RuntimeException('Historical Queue Core ingestion requires an explicit bounded invocation.');
        $maximum = max(1, min(self::MAX_PER_CYCLE, $limit));
        $count = 0;
        foreach ($jobs as $job) {
            if ($count >= $maximum) break;
            if ($job->lane !== 'historical_backfill' || $job->domain() !== 'operational') continue;
            $repository->enqueue($job);
            $count++;
        }
        return $count;
    }
}
