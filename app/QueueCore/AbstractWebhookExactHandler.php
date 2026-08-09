<?php
declare(strict_types=1);

namespace App\QueueCore;

use RuntimeException;

abstract class AbstractWebhookExactHandler implements QueueHandler
{
    public function __construct(
        private readonly string $resourceType,
        private readonly WebhookTriggerService $triggers,
        private readonly WebhookExactGateway $gateway,
    ) {
    }

    public function handle(QueueClaim $job, QueueExecutionContext $context): QueueResult
    {
        if (!$context->hasTime(1.0)) {
            return QueueResult::automaticWait('deadline', gmdate('Y-m-d H:i:s', time() + 5));
        }
        $resourceId = trim((string) ($job->payload['resource_id'] ?? $job->resourceId ?? ''));
        $triggerId = (int) ($job->payload['trigger_id'] ?? 0);
        $watermark = (int) ($job->payload['scheduled_watermark'] ?? 0);
        if ($job->resourceType !== $this->resourceType || $resourceId === ''
            || !ctype_digit($resourceId) || $triggerId < 1 || $watermark < 1) {
            return QueueResult::dead('invalid_webhook_trigger_identity');
        }
        $trigger = $this->triggers->trigger(
            $triggerId, $job->companyId, $job->meliAccountId
        );
        if (is_array($trigger)
            && (int) $trigger['completed_watermark'] >= $watermark
            && $trigger['inflight_job_id'] === null) {
            // El snapshot y su trigger cerraron antes de que QueueRepository
            // pudiera cerrar el job. El reintento local no repite el GET.
            return QueueResult::completed(0, 1);
        }
        if (!is_array($trigger)
            || (string) $trigger['state'] !== 'inflight'
            || (int) $trigger['inflight_job_id'] !== $job->id
            || (int) $trigger['scheduled_watermark'] !== $watermark
            || (string) $trigger['resource_type'] !== $this->resourceType
            || (string) $trigger['resource_id'] !== $resourceId) {
            return QueueResult::dead('stale_webhook_trigger_fence');
        }
        $persisted = $this->gateway->sync(
            $job->companyId, $job->meliAccountId, $this->resourceType,
            $resourceId, $job->id
        );
        if (!$this->triggers->complete(
            $triggerId, $job->companyId, $job->meliAccountId, $job->id, $watermark
        )) {
            throw new RuntimeException('Webhook trigger completion fence changed.');
        }
        return QueueResult::completed($persisted > 0 ? 1 : 0, 1);
    }
}
