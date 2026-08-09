<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Database;
use App\Services\OrderSyncService;
use RuntimeException;

final class MeliWebhookExactGateway implements WebhookExactGateway
{
    public function __construct(
        private readonly ?SalePipelineCapabilityRepository $salePipeline = null,
    ) {}

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
        $persisted = match ($resourceType) {
            'order' => $sync->syncOrderByIdForQueueCore($resourceId, $meta),
            'pack' => $sync->syncPackByIdForQueueCore($resourceId, $meta),
            'shipment' => $sync->syncShipmentByIdForQueueCore($resourceId, $meta),
            default => throw new RuntimeException('Webhook exact resource type is unsupported.'),
        };
        if ($resourceType === 'order' && $this->salePipeline !== null) {
            $this->salePipeline->materializePending(4, $persisted);
        }
        if ($resourceType === 'pack') {
            $pack = Database::connectionFresh()->prepare(
                "SELECT external_shipment_id,
                        COALESCE(SHA2(raw_json,256),
                          SHA2(CONCAT(external_pack_id,'|',COALESCE(synced_at,'')),256)) input_version
                 FROM meli_packs
                 WHERE id=? AND meli_account_id=? AND external_pack_id=? LIMIT 1"
            );
            $pack->execute([$persisted, $meliAccountId, $resourceId]);
            $snapshot = $pack->fetch(\PDO::FETCH_ASSOC);
            $shipmentId = trim((string) ($snapshot['external_shipment_id'] ?? ''));
            $inputVersion = trim((string) ($snapshot['input_version'] ?? ''));
            if (preg_match('/^\d+$/', $shipmentId) !== 1 || $inputVersion === '') {
                throw new RuntimeException('Pack webhook did not expose a shipment identity.');
            }
            $pdo = Database::connectionFresh();
            $pipeline = $this->salePipeline ?? new SalePipelineCapabilityRepository(
                $pdo,
                new QueueCoreRepository($pdo)
            );
            $pipeline->materializeWebhookPackShipment(
                $companyId,
                $meliAccountId,
                $resourceId,
                $shipmentId,
                $inputVersion
            );
        }
        return $persisted;
    }
}
