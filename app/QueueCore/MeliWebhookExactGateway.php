<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Database;
use App\Services\OrderSyncService;
use RuntimeException;

final class MeliWebhookExactGateway implements WebhookExactGateway
{
    public function sync(
        int $companyId,
        int $meliAccountId,
        string $resourceType,
        string $resourceId,
        int $queueJobId
    ): int {
        $scope = Database::connectionFresh()->prepare(
            "SELECT COUNT(*) FROM meli_accounts
             WHERE id=? AND company_id=? AND status IN ('conectado','connected')"
        );
        $scope->execute([$meliAccountId, $companyId]);
        if ((int) $scope->fetchColumn() !== 1) {
            throw new RuntimeException('Webhook exact account scope is unavailable.');
        }
        $sync = new OrderSyncService($meliAccountId);
        $meta = [
            'job_type' => 'webhook_' . $resourceType . '_exact',
            'source' => 'queue_core_webhook',
            'queue_core_job_id' => $queueJobId,
            'bulk' => false,
        ];
        return match ($resourceType) {
            'order' => $sync->syncOrderByIdForQueueCore($resourceId, $meta),
            'pack' => $sync->syncPackByIdForQueueCore($resourceId, $meta),
            'shipment' => $sync->syncShipmentByIdForQueueCore($resourceId, $meta),
            default => throw new RuntimeException('Webhook exact resource type is unsupported.'),
        };
    }
}
