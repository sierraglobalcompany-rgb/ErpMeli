<?php

declare(strict_types=1);

namespace App\Modules\MeliLogistics\Services;

use App\Modules\Shared\Gateways\CoreReadGateway;
use App\Modules\Shared\Services\AbstractModuleSyncService;
use App\Modules\Shared\Services\MeliReadGateway;

final class LogisticsSyncService extends AbstractModuleSyncService
{
    protected function syncAccount(int $accountId, array $account, array $job): array
    {
        if (($job['job_type'] ?? '') === 'event_sync') {
            return $this->syncExactEvent($accountId, $job);
        }

        $shipments = (new CoreReadGateway())->recentShipments($accountId, 5);
        $count = 0;
        $errors = 0;
        foreach ($shipments as $shipment) {
            $id = (string) $shipment['external_shipping_id'];
            try {
                $payload = (new MeliReadGateway())->get('meli-logistics', $accountId, '/shipments/' . rawurlencode($id), [], 'module_logistics');
                $this->persist($accountId, $id, (string) ($shipment['meli_order_id'] ?? ''), $shipment, $payload);
                $count++;
            } catch (\Throwable) {
                $errors++;
            }
        }
        return ['account_id' => $accountId, 'processed' => $count, 'errors' => $errors];
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function syncExactEvent(int $accountId, array $job): array
    {
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $type = mb_strtolower(trim((string) ($payload['resource_type'] ?? '')));
        $id = $this->numericResourceId((string) ($payload['resource_id'] ?? ''));
        if (!in_array($type, ['shipment', 'shipments'], true) || $id === '') {
            return [
                'account_id' => $accountId,
                'processed' => 0,
                'errors' => 0,
                'status' => 'ignored_unsupported',
                'stage' => 'ignored_unsupported',
                'safe_message' => 'El evento no corresponde a un envío exacto compatible.',
            ];
        }

        $shipment = (new CoreReadGateway())->findShipment($accountId, $id) ?? [];
        $remote = (new MeliReadGateway())->get(
            'meli-logistics',
            $accountId,
            '/shipments/' . rawurlencode($id),
            [],
            'module_logistics_event'
        );
        $this->persist($accountId, $id, (string) ($shipment['meli_order_id'] ?? ''), $shipment, $remote);
        return ['account_id' => $accountId, 'processed' => 1, 'errors' => 0, 'status' => 'completed', 'resource_id' => $id];
    }

    /** @param array<string,mixed> $local @param array<string,mixed> $payload */
    private function persist(int $accountId, string $id, string $orderId, array $local, array $payload): void
    {
        Database::connection()->prepare(
            'INSERT INTO ml_logistics_shipment_snapshots
             (meli_account_id,external_shipment_id,external_order_id,status,substatus,logistic_type,estimated_delivery_at,snapshot_json,observed_at)
             VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE status=VALUES(status),substatus=VALUES(substatus),logistic_type=VALUES(logistic_type),
               estimated_delivery_at=VALUES(estimated_delivery_at),snapshot_json=VALUES(snapshot_json),observed_at=UTC_TIMESTAMP()'
        )->execute([
            $accountId,
            $id,
            $orderId,
            $payload['status'] ?? null,
            $payload['substatus'] ?? null,
            $payload['logistic_type'] ?? $local['logistic_type'] ?? null,
            $payload['shipping_option']['estimated_delivery_time']['date'] ?? null,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function numericResourceId(string $resource): string
    {
        $resource = trim($resource);
        if (preg_match('~(?:^|/)(\d+)(?:\?.*)?$~', $resource, $match) !== 1) {
            return '';
        }
        return $match[1];
    }
}
