<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Services\OrderSyncService;
use RuntimeException;

/** One exact GET /shipments/{id}; no inline order, pack or financial fan-out. */
final class ShipmentExactHandler implements QueueHandler
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
        $shipmentId = trim((string) ($job->payload['shipment_id'] ?? $job->resourceId ?? ''));
        $capabilityId = (int) ($job->payload['capability_id'] ?? 0);
        if (preg_match('/^\d+$/', $shipmentId) !== 1) {
            return QueueResult::dead('invalid_shipment_identity');
        }
        if ($capabilityId > 0 && $this->capabilities->dependencyCompleted($job, $capabilityId)) {
            $trigger=(int)($job->payload['trigger_id']??0);$watermark=(int)($job->payload['scheduled_watermark']??0);
            if($this->webhookTriggers!==null&&$trigger>0&&$watermark>0){
                $this->webhookTriggers->complete($trigger,$job->companyId,$job->meliAccountId,$job->id,$watermark);
            }
            return QueueResult::completed(1, 0);
        }
        $sync = ($this->syncFactory)($job->meliAccountId);
        if ($orderId > 0) {
            $sync->processEnrichmentResource([
                'id' => $job->id,
                'meli_account_id' => $job->meliAccountId,
                'meli_order_id' => $orderId,
                'resource_type' => 'shipment',
                'external_resource_id' => $shipmentId,
            ]);
        } else {
            $sync->syncShipmentByIdForQueueCore($shipmentId, [
                'job_type' => 'shipment_exact', 'source' => 'queue_core', 'queue_core_job_id' => $job->id,
            ]);
        }
        if (!$this->capabilities->completeAllDependencies($job)) {
            throw new RuntimeException('Queue Core shipment completion fence changed.');
        }
        $trigger=(int)($job->payload['trigger_id']??0);$watermark=(int)($job->payload['scheduled_watermark']??0);
        if($this->webhookTriggers!==null&&$trigger>0&&$watermark>0){
            $this->webhookTriggers->complete($trigger,$job->companyId,$job->meliAccountId,$job->id,$watermark);
        }
        return QueueResult::completed(1, 1);
    }
}
