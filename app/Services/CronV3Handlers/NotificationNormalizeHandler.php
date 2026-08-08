<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Core\Database;
use App\Services\CronV3;
use App\Services\CronV3ExecutionContext;
use App\Services\SchemaInspectorService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use PDO;
use Throwable;

/**
 * Convierte eventos de notificación ya validados en trabajos V3 exactos.
 *
 * Este handler es estrictamente local: no llama Mercado Libre y no usa el
 * coalescer legacy porque ese flujo podía consultar APIs dentro del carril local.
 */
final class NotificationNormalizeHandler implements CronV3WorkHandler
{
    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $limit = max(1, min(100, (int) ($work->payload['limit'] ?? 50)));
        $pdo = Database::connectionFresh();

        try {
            $events = $this->events($pdo, $limit);
        } catch (Throwable) {
            return WorkResult::deferred(
                gmdate('Y-m-d H:i:s', time() + 60),
                'notification_normalize_read_failed',
                ['safe_message' => 'No fue posible leer eventos pendientes de notificación.']
            );
        }

        $created = 0;
        $parked = 0;
        $ignored = 0;
        $errors = 0;
        foreach ($events as $event) {
            try {
                $classification = $this->classify($pdo, $event);
                if ($classification['state'] === 'ignored') {
                    $this->markEvent($pdo, (int) $event['id'], 'ignored', 'cron_v3_ignored', $classification['reason']);
                    $ignored++;
                    continue;
                }
                $status = $classification['state'] === 'waiting_identity' ? 'waiting_identity' : 'ready';
                $enqueued = $status === 'ready'
                    ? CronV3::enqueue($classification['work'])
                    : CronV3::enqueueWithStatus($classification['work'], 'waiting_identity', (string) $classification['reason']);
                if (!empty($enqueued['created'])) {
                    $created++;
                }
                if ($status === 'waiting_identity') {
                    $parked++;
                }
                $this->markEvent($pdo, (int) $event['id'], 'processed', 'cron_v3_enqueued_' . $classification['work']->workType, null);
            } catch (Throwable) {
                $errors++;
            }
        }

        $metadata = [
            'events' => count($events),
            'created' => $created,
            'parked' => $parked,
            'ignored' => $ignored,
            'errors' => $errors,
            'local_only' => true,
        ];

        if ($errors > 0 && $created === 0 && $parked === 0 && $ignored === 0) {
            return WorkResult::deferred(gmdate('Y-m-d H:i:s', time() + 60), 'notification_normalize_retry', $metadata);
        }
        if (count($events) >= $limit) {
            return WorkResult::deferred(gmdate('Y-m-d H:i:s', time() + 5), 'notification_normalize_continue', $metadata);
        }

        return WorkResult::completed($metadata);
    }

    /** @return list<array<string,mixed>> */
    private function events(PDO $pdo, int $limit): array
    {
        $sql = 'SELECT e.id,e.meli_account_id,a.company_id,e.topic,e.resource,e.resource_type,e.remote_resource_id,
                       e.retry_count,e.erp_received_at
                FROM meli_notification_events e
                JOIN meli_accounts a ON a.id=e.meli_account_id
                WHERE e.meli_account_id IS NOT NULL
                  AND e.status IN ("received","queued","waiting_retry")
                  AND (e.process_after IS NULL OR e.process_after<=UTC_TIMESTAMP())
                  AND NOT EXISTS (
                    SELECT 1 FROM cron_v3_work w
                    WHERE w.source_ref=CONCAT("legacy:notification_event:",e.id)
                    LIMIT 1
                  )
                ORDER BY e.erp_received_at ASC,e.id ASC
                LIMIT ' . $limit;
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $event @return array{state:string,reason:string,work?:WorkEnvelope} */
    private function classify(PDO $pdo, array $event): array
    {
        $accountId = (int) ($event['meli_account_id'] ?? 0);
        $companyId = (int) ($event['company_id'] ?? 0);
        if ($accountId < 1 || $companyId < 1) {
            return ['state' => 'ignored', 'reason' => 'missing_scope'];
        }

        $topic = strtolower((string) ($event['topic'] ?? ''));
        $resource = (string) ($event['resource'] ?? '');
        $resourceType = (string) ($event['resource_type'] ?? '');
        $remoteId = trim((string) ($event['remote_resource_id'] ?? ''));
        if ($remoteId === '') {
            [$resourceType, $remoteId] = $this->parseResource($topic, $resource);
        }

        $payload = [
            'legacy_queue' => 'notification_event',
            'notification_event_id' => (int) $event['id'],
            'topic' => $topic,
            'resource_type' => $resourceType,
            'remote_resource_id' => $remoteId,
        ];
        $workType = '';
        $resourceKey = '';
        $state = 'ready';
        $reason = 'ready';

        if ($resourceType === 'order' && preg_match('/^[0-9]+$/', $remoteId)) {
            $workType = 'order_exact';
            $payload['external_order_id'] = $remoteId;
            $resourceKey = 'notification-event:order:' . $remoteId;
        } elseif ($resourceType === 'shipment' && preg_match('/^[0-9]+$/', $remoteId)) {
            $orderId = $this->orderForShipment($pdo, $accountId, $remoteId);
            if ($orderId < 1) {
                $workType = 'notification_identity_repair';
                $state = 'waiting_identity';
                $reason = 'shipment_missing_order_relation';
                $resourceKey = 'notification-event-identity:' . (int) $event['id'];
            } else {
                $workType = 'shipment_exact';
                $payload['meli_order_id'] = $orderId;
                $payload['external_resource_id'] = $remoteId;
                $resourceKey = 'notification-event:shipment:' . $remoteId;
            }
        } elseif ($resourceType === 'pack' && preg_match('/^[0-9]+$/', $remoteId)) {
            $orderId = $this->orderForPack($pdo, $accountId, $remoteId);
            if ($orderId < 1) {
                $workType = 'notification_identity_repair';
                $state = 'waiting_identity';
                $reason = 'pack_missing_order_relation';
                $resourceKey = 'notification-event-identity:' . (int) $event['id'];
            } else {
                $workType = 'pack_exact';
                $payload['meli_order_id'] = $orderId;
                $payload['external_resource_id'] = $remoteId;
                $resourceKey = 'notification-event:pack:' . $remoteId;
            }
        } elseif ($resourceType === 'question' && preg_match('/^[0-9]+$/', $remoteId)) {
            $workType = 'question_exact';
            $payload['question_id'] = $remoteId;
            $resourceKey = 'notification-event:question:' . $remoteId;
        } elseif ($resourceType === 'claim' && preg_match('/^[0-9]+$/', $remoteId)) {
            $workType = 'claim_exact';
            $payload['claim_id'] = $remoteId;
            $resourceKey = 'notification-event:claim:' . $remoteId;
        } elseif ($resourceType === 'item' && preg_match('/^[A-Z]{2,4}[0-9]+$/i', $remoteId)) {
            $workType = 'item_exact';
            $payload['external_item_id'] = strtoupper($remoteId);
            $resourceKey = 'notification-event:item:' . strtoupper($remoteId);
        } else {
            $workType = 'notification_identity_repair';
            $state = 'waiting_identity';
            $reason = 'unsupported_or_incomplete_notification_resource';
            $resourceKey = 'notification-event-identity:' . (int) $event['id'];
        }

        $payload['parking_reason'] = $reason;
        $work = WorkEnvelope::create(
            $companyId,
            $accountId,
            $workType,
            $workType === 'notification_identity_repair' ? 'local' : 'remote',
            $resourceKey,
            'notification-event:' . (int) $event['id'] . ':' . (string) ($event['erp_received_at'] ?? ''),
            $payload,
            'legacy:notification_event:' . (int) $event['id'],
            80
        );

        return ['state' => $state, 'reason' => $reason, 'work' => $work];
    }

    /** @return array{0:string,1:string} */
    private function parseResource(string $topic, string $resource): array
    {
        $topic = strtolower($topic);
        if (preg_match('~/orders/(\d+)~', $resource, $m)) {
            return ['order', $m[1]];
        }
        if (preg_match('~/shipments/(\d+)~', $resource, $m)) {
            return ['shipment', $m[1]];
        }
        if (preg_match('~/packs/(\d+)~', $resource, $m)) {
            return ['pack', $m[1]];
        }
        if (preg_match('~/questions/(\d+)~', $resource, $m)) {
            return ['question', $m[1]];
        }
        if (preg_match('~/claims/(\d+)~', $resource, $m) || preg_match('~claim_id=(\d+)~', $resource, $m)) {
            return ['claim', $m[1]];
        }
        if (preg_match('~/items/([A-Z]{2,4}\d+)~i', $resource, $m)) {
            return ['item', strtoupper($m[1])];
        }
        if ($topic === 'questions') {
            return ['question', ''];
        }
        return ['', ''];
    }

    private function orderForShipment(PDO $pdo, int $accountId, string $shipmentId): int
    {
        $stmt = $pdo->prepare(
            'SELECT meli_order_id FROM meli_shipments
             WHERE meli_account_id=? AND external_shipment_id=? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$accountId, $shipmentId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function orderForPack(PDO $pdo, int $accountId, string $packId): int
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM meli_orders
             WHERE meli_account_id=? AND external_pack_id=? ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([$accountId, $packId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function markEvent(PDO $pdo, int $eventId, string $status, string $disposition, ?string $message): void
    {
        $schema = new SchemaInspectorService();
        $hasDisposition = $schema->hasColumn('meli_notification_events', 'disposition');
        $sql = 'UPDATE meli_notification_events SET status=:status,processed_at=COALESCE(processed_at,UTC_TIMESTAMP()),error_message=:message';
        if ($hasDisposition) {
            $sql .= ',disposition=:disposition';
        }
        $sql .= ' WHERE id=:id AND status IN ("received","queued","waiting_retry")';
        $stmt = $pdo->prepare($sql);
        $params = [
            'status' => $status,
            'message' => $message === null ? null : mb_substr($message, 0, 500),
            'id' => $eventId,
        ];
        if ($hasDisposition) {
            $params['disposition'] = mb_substr($disposition, 0, 80);
        }
        $stmt->execute($params);
    }
}
