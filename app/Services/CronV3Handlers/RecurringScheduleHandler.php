<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3ExecutionContext;
use App\Services\RecurringSyncService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Throwable;

final class RecurringScheduleHandler implements CronV3WorkHandler
{
    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        try {
            $result = (new RecurringSyncService())->processDue();
        } catch (Throwable) {
            return WorkResult::deferred(
                gmdate('Y-m-d H:i:s', time() + 300),
                'recurring_schedule_retry',
                ['safe_message' => 'La programación recurrente local no pudo prepararse en este ciclo.']
            );
        }

        $metadata = [
            'enabled' => !empty($result['enabled']),
            'enqueued' => (int) ($result['enqueued'] ?? 0),
            'rules' => (int) ($result['rules'] ?? 0),
            'skipped' => !empty($result['skipped']),
            'local_only' => true,
        ];

        return WorkResult::completed($metadata);
    }
}
