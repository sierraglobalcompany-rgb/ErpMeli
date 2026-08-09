<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Services\OrderSyncService;
use RuntimeException;

/** One exact GET /packs/{id}; any shipment discovered becomes another FIFO job. */
final class PackExactHandler implements QueueHandler
{
    /** @var \Closure(int):OrderSyncService */
    private \Closure $syncFactory;

    /** @param null|callable(int):OrderSyncService $syncFactory */
    public function __construct(
        private readonly SalePipelineCapabilityRepository $capabilities,
        ?callable $syncFactory = null,
        private readonly ?WebhookTriggerService $webhookTriggers = null,
    ) {
        $this->syncFactory = $syncFactory !== null
            ? \Closure::fromCallable($syncFactory)
            : static fn (int $accountId): OrderSyncService => new OrderSyncService($accountId);
    }

    public function handle(QueueClaim $job, QueueExecutionContext $context): QueueResult
    {
        if (!$context->hasTime(1.0)) {
            return QueueResult::automaticWait('deadline', gmdate('Y-m-d H:i:s', time() + 5));
        }
        $dependencies = $this->capabilities->pendingDependenciesForJob($job);
        $orderId = (int) ($job->payload['order_id'] ?? ($dependencies[0]['order_id'] ?? 0));
        $packId = trim((string) ($job->payload['pack_id'] ?? $job->resourceId ?? ''));
        $capabilityId = (int) ($job->payload['capability_id'] ?? 0);
        if (preg_match('/^\d+$/', $packId) !== 1) {
            return QueueResult::dead('invalid_pack_identity');
        }
        if ($capabilityId > 0 && $this->capabilities->dependencyCompleted($job, $capabilityId)) {
            $this->completeWebhookTrigger($job);
            return QueueResult::completed(1, 0);
        }
        $sync = ($this->syncFactory)($job->meliAccountId);
        $result = $orderId > 0
            ? $sync->processEnrichmentResource([
                'id' => $job->id,
                'meli_account_id' => $job->meliAccountId,
                'meli_order_id' => $orderId,
                'resource_type' => 'pack',
                'external_resource_id' => $packId,
            ])
            : ['spawned_shipment_id' => null, 'persisted_id' => $sync->syncPackByIdForQueueCore($packId, [
                'job_type' => 'pack_exact', 'source' => 'queue_core', 'queue_core_job_id' => $job->id,
            ])];
        $shipmentId = trim((string) ($result['spawned_shipment_id'] ?? ''));
        if ($shipmentId !== '') {
            $version = trim((string) ($job->payload['input_version'] ?? ''));
            if ($version === '') {
                $version = hash('sha256', $job->id . '|pack|' . $packId . '|shipment|' . $shipmentId);
            }
            foreach ($dependencies as $dependency) {
                $this->capabilities->appendShipmentDependency(
                    $this->withGeneration($job, $dependency['lifecycle_generation']),
                    $dependency['capability_id'],
                    $dependency['order_id'],
                    $shipmentId,
                    $version
                );
            }
        }
        if (!$this->capabilities->completeAllDependencies($job)) {
            throw new RuntimeException('Queue Core pack completion fence changed.');
        }
        $this->completeWebhookTrigger($job);
        return QueueResult::completed(1, 1);
    }

    private function withGeneration(QueueClaim $job, int $generation): QueueClaim
    {
        return new QueueClaim(
            $job->id,$job->companyId,$job->meliAccountId,$job->workType,$job->resourceType,
            $job->resourceId,$job->lane,$job->priority,$job->state,$job->attemptCount,
            $job->maxAttempts,$job->leaseOwner,$job->leaseGeneration,$job->dispatchState,
            array_replace($job->payload,['capability_generation'=>$generation]),$job->source,$job->sourceRef
        );
    }

    private function completeWebhookTrigger(QueueClaim $job): void
    {
        $trigger=(int)($job->payload['trigger_id']??0);$watermark=(int)($job->payload['scheduled_watermark']??0);
        if($this->webhookTriggers!==null&&$trigger>0&&$watermark>0){
            $this->webhookTriggers->complete($trigger,$job->companyId,$job->meliAccountId,$job->id,$watermark);
        }
    }
}
