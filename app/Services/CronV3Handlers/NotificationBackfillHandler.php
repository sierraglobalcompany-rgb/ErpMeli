<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3ExecutionContext;
use App\Services\NotificationBackfillService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Throwable;

final class NotificationBackfillHandler implements CronV3WorkHandler
{
    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $limit = max(50, min(500, (int) ($work->payload['limit'] ?? 200)));

        try {
            $result = (new NotificationBackfillService())->processDue($limit);
        } catch (Throwable) {
            return WorkResult::deferred(
                gmdate('Y-m-d H:i:s', time() + 300),
                'notification_backfill_retry',
                ['safe_message' => 'El backfill local quedó pendiente para el próximo ciclo seguro.']
            );
        }

        $metadata = [
            'processed' => (int) ($result['processed'] ?? $result['normalized_count'] ?? 0),
            'errors' => (int) ($result['errors'] ?? $result['error_count'] ?? 0),
            'skipped' => !empty($result['skipped']),
            'stop_reason' => (string) ($result['stop_reason'] ?? ($result['skipped'] ?? false ? 'no_pending_jobs' : 'complete')),
            'local_only' => true,
        ];

        if ($metadata['errors'] > 0) {
            return WorkResult::deferred(gmdate('Y-m-d H:i:s', time() + 300), 'notification_backfill_partial', $metadata);
        }
        if (!$metadata['skipped'] && $metadata['processed'] > 0) {
            return WorkResult::deferred(gmdate('Y-m-d H:i:s', time() + 5), 'notification_backfill_continue', $metadata);
        }

        return WorkResult::completed($metadata);
    }
}
