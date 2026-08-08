<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3ExecutionContext;
use App\Services\WebhookSpoolService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Throwable;

final class NotificationSpoolHandler implements CronV3WorkHandler
{
    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $limit = max(1, min(100, (int) ($work->payload['limit'] ?? 50)));
        $deadline = microtime(true) + max(1, min(5, (int) ($work->payload['runtime_seconds'] ?? 3)));

        try {
            $result = (new WebhookSpoolService())->replay($limit, $deadline);
        } catch (Throwable) {
            return WorkResult::deferred(
                gmdate('Y-m-d H:i:s', time() + 60),
                'notification_spool_unavailable',
                ['safe_message' => 'No fue posible mover el spool local en este ciclo; se reintentará automáticamente.']
            );
        }

        $metadata = [
            'processed' => $result['processed'],
            'completed' => $result['completed'],
            'quarantined' => $result['quarantined'],
            'errors' => $result['errors'],
            'files' => $result['files'],
            'local_only' => true,
        ];

        if ($metadata['errors'] > 0 && $metadata['processed'] === 0 && $metadata['quarantined'] === 0) {
            return WorkResult::deferred(gmdate('Y-m-d H:i:s', time() + 60), 'notification_spool_retry', $metadata);
        }

        return WorkResult::completed($metadata);
    }
}
