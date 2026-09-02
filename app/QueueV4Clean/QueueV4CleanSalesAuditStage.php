<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Services\CronDeadlineContext;
use App\Services\SalesAuditRunService;

/** One exact sales-audit page per scheduler cycle. */
final class QueueV4CleanSalesAuditStage
{
    /** @return array<string,mixed> */
    public function run(float $deadline): array
    {
        if (microtime(true) >= $deadline - 3.0 || !CronDeadlineContext::canAcceptWork(3)) {
            return ['processed' => 0, 'errors' => 0, 'status' => 'deferred', 'stop_reason' => 'cron_deadline'];
        }
        return (new SalesAuditRunService())->processDue(1, $deadline);
    }
}
