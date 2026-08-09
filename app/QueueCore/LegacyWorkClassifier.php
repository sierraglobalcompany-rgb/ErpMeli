<?php
declare(strict_types=1);
namespace App\QueueCore;
/**
 * Pure classification seam for a later, explicit legacy import phase.
 * It performs no reads, writes or cleanup and can never contaminate fresh.
 */
final class LegacyWorkClassifier
{
    public function __construct(private readonly int $companyId)
    {
        if ($companyId < 1) {
            throw new \InvalidArgumentException('Historical classification requires company scope.');
        }
    }

    public function notificationOrder(
        int $accountId,
        string $orderId,
        int $sourceId,
        int $sourceGeneration,
        string $inputVersion,
        ?string $availableAt = null,
    ): QueueJob {
        if ($accountId < 1 || $sourceId < 1 || !ctype_digit($orderId)) {
            throw new \InvalidArgumentException('Historical notification order identity is invalid.');
        }
        return new QueueJob(
            $this->companyId,
            $accountId,
            'order_exact',
            'order',
            $orderId,
            'historical_backfill',
            0,
            'order:' . $orderId,
            $inputVersion,
            'legacy_import',
            'legacy:notification_fallback:' . $sourceId,
            ['order_id' => $orderId, 'legacy_notification_work_id' => $sourceId],
            [
                'classification' => 'historical_backfill',
                'source_key' => 'notification_orders',
                'source_generation' => $sourceGeneration,
            ],
            5,
            $availableAt,
            'operational',
        );
    }
}
