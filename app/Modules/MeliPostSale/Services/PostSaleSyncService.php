<?php

declare(strict_types=1);

namespace App\Modules\MeliPostSale\Services;

use App\Core\Database;
use App\Modules\Shared\Gateways\CoreReadGateway;
use App\Modules\Shared\Services\AbstractModuleSyncService;
use App\Modules\Shared\Services\MeliReadGateway;
use App\Services\MeliEndpointRegistry;

final class PostSaleSyncService extends AbstractModuleSyncService
{
    protected function syncAccount(int $accountId, array $account, array $job): array
    {
        if (($job['job_type'] ?? '') === 'event_sync') {
            return $this->syncExactEvent($accountId, $account, $job);
        }

        $orders = (new CoreReadGateway())->recentOrders($accountId, 5);
        $count = 0;
        $errors = 0;
        foreach ($orders as $order) {
            $pack = (string) ($order['external_pack_id'] ?? '');
            if ($pack === '') {
                continue;
            }
            $path = '/messages/packs/' . rawurlencode($pack) . '/sellers/' . (int) $account['meli_user_id'];
            if (!MeliEndpointRegistry::isConfirmed('GET', $path)) {
                continue;
            }
            try {
                $payload = (new MeliReadGateway())->get('meli-postsale', $accountId, $path, ['mark_as_read' => 'false'], 'module_postsale');
                $this->persist($accountId, $pack, (string) $order['external_order_id'], $payload);
                $count++;
            } catch (\Throwable) {
                $errors++;
            }
        }
        return ['account_id' => $accountId, 'processed' => $count, 'errors' => $errors];
    }

    /** @param array<string,mixed> $account @param array<string,mixed> $job @return array<string,mixed> */
    private function syncExactEvent(int $accountId, array $account, array $job): array
    {
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $type = mb_strtolower(trim((string) ($payload['resource_type'] ?? '')));
        $packId = $this->numericResourceId((string) ($payload['resource_id'] ?? ''));
        if (!in_array($type, ['pack', 'packs', 'message', 'messages'], true) || $packId === '') {
            return $this->ignored($accountId, 'El evento posventa no contiene un pack exacto compatible.');
        }
        $path = '/messages/packs/' . rawurlencode($packId) . '/sellers/' . (int) $account['meli_user_id'];
        if (!MeliEndpointRegistry::isConfirmed('GET', $path)) {
            return $this->ignored($accountId, 'El endpoint exacto del evento posventa no está confirmado en el mapa API local.');
        }
        $remote = (new MeliReadGateway())->get('meli-postsale', $accountId, $path, ['mark_as_read' => 'false'], 'module_postsale_event');
        $this->persist($accountId, $packId, '', $remote);
        return ['account_id' => $accountId, 'processed' => 1, 'errors' => 0, 'status' => 'completed', 'resource_id' => $packId];
    }

    /** @return array<string,mixed> */
    private function ignored(int $accountId, string $message): array
    {
        return [
            'account_id' => $accountId,
            'processed' => 0,
            'errors' => 0,
            'status' => 'ignored_unsupported',
            'stage' => 'ignored_unsupported',
            'safe_message' => $message,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function persist(int $accountId, string $pack, string $orderId, array $payload): void
    {
        Database::connection()->prepare(
            'INSERT INTO ml_postsale_conversations
             (meli_account_id,external_pack_id,external_order_id,status,message_count,last_message_at,snapshot_json,observed_at)
             VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE message_count=VALUES(message_count),last_message_at=VALUES(last_message_at),
               snapshot_json=VALUES(snapshot_json),observed_at=UTC_TIMESTAMP()'
        )->execute([
            $accountId,
            $pack,
            $orderId,
            $payload['status'] ?? null,
            count($payload['messages'] ?? []),
            $payload['last_message_date'] ?? null,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function numericResourceId(string $resource): string
    {
        if (preg_match('~(?:^|/)(\d+)(?:\?.*)?$~', trim($resource), $match) !== 1) {
            return '';
        }
        return $match[1];
    }
}
