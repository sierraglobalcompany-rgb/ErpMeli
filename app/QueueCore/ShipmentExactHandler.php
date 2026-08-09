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
        $orderId = (int) ($job->payload['order_id'] ?? 0);
        $shipmentId = trim((string) ($job->payload['shipment_id'] ?? $job->resourceId ?? ''));
        $capabilityId = (int) ($job->payload['capability_id'] ?? 0);
        if ($orderId < 1 || $capabilityId < 1 || preg_match('/^\d+$/', $shipmentId) !== 1) {
            return QueueResult::dead('invalid_shipment_identity');
        }
        if ($this->capabilities->dependencyCompleted($job, $capabilityId)) {
            return QueueResult::completed(1, 0);
        }
        ($this->syncFactory)($job->meliAccountId)->processEnrichmentResource([
            'id' => $job->id,
            'meli_account_id' => $job->meliAccountId,
            'meli_order_id' => $orderId,
            'resource_type' => 'shipment',
            'external_resource_id' => $shipmentId,
        ]);
        if (!$this->capabilities->completeDependency($job, $capabilityId)) {
            throw new RuntimeException('Queue Core shipment completion fence changed.');
        }
        return QueueResult::completed(1, 1);
    }
}
