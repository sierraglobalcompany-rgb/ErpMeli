<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class NotificationCoalescerService
{
    private AppSettingsService $settings;

    public function __construct()
    {
        $this->settings = new AppSettingsService();
    }

    public function processDue(?int $limit = null, ?float $deadline = null): array
    {
        $deadline = $this->effectiveDeadline($deadline);
        $canonical = new NotificationWorkItemService();
        if ($canonical->available()) {
            return $canonical->processDue($limit, $deadline);
        }
        if (!$this->settings->bool('notifications.enabled', true)) {
            return ['processed' => 0, 'ignored' => 0, 'errors' => 0, 'skipped' => true];
        }
        $limit = $limit ?: max(1, min(100, $this->settings->int('notifications.max_events_per_run', 20)));
        $events = $this->dueEvents($limit);
        if (!$events) {
            return ['processed' => 0, 'ignored' => 0, 'errors' => 0, 'resources' => 0];
        }
        $groups = [];
        foreach ($events as $event) {
            $key = ((int) ($event['meli_account_id'] ?? 0)) . '|' . $event['topic'] . '|' . $event['resource'];
            $groups[$key][] = $event;
        }
        $summary = [
            'processed' => 0,
            'ignored' => 0,
            'errors' => 0,
            'resources' => 0,
            'deferred' => 0,
            'stop_reason' => '',
        ];
        $perAccount = [];
        $maxPerAccount = max(1, min(50, $this->settings->int('notifications.max_resources_per_account_per_run', 10)));
        foreach ($groups as $group) {
            if (!$this->canStartResource($deadline)) {
                $summary['deferred']++;
                $summary['stop_reason'] = 'lane_deadline';
                break;
            }
            $event = $group[0];
            $accountId = (int) ($event['meli_account_id'] ?? 0);
            $perAccount[$accountId] = ($perAccount[$accountId] ?? 0) + 1;
            if ($perAccount[$accountId] > $maxPerAccount) {
                $this->defer($group, 5, 'Límite de recursos por cuenta/corrida.');
                continue;
            }
            $summary['resources']++;
            try {
                $this->markProcessing($group);
                $result = $this->processGroup($accountId, (string) $event['topic'], (string) $event['resource'], $group);
                $this->markGroup($group, 'processed', $result['message'] ?? null);
                $summary['processed'] += count($group);
                $pauseMs = max(0, $this->settings->int('notifications.pause_between_requests_ms', 750));
                if ($pauseMs > 0 && ($deadline === null || microtime(true) + ($pauseMs / 1000) < $deadline)) {
                    usleep($pauseMs * 1000);
                }
            } catch (MeliApiException $e) {
                $status = (int) ($e->httpStatus ?? 0);
                if ($status === 429) {
                    $this->defer($group, max(1, $this->settings->int('notifications.cooldown_429_minutes', 30)), $e->getMessage(), 'waiting_retry');
                    break;
                }
                if ($status === 403) {
                    $this->defer($group, max(1, $this->settings->int('notifications.cooldown_403_minutes', 60)), $e->getMessage(), 'circuit_breaker_wait');
                    break;
                }
                if ($status === 404) {
                    $this->markGroup($group, 'ignored', 'Recurso no disponible: ' . $e->getMessage());
                    $summary['ignored'] += count($group);
                    continue;
                }
                $this->failOrRetry($group, $e->getMessage());
                $summary['errors'] += count($group);
            } catch (Throwable $e) {
                $message = $e->getMessage();
                if (str_contains(strtolower($message), 'consultas pausadas') || str_contains(strtolower($message), 'circuit')) {
                    $this->defer($group, 10, $message, 'circuit_breaker_wait');
                    break;
                }
                $this->failOrRetry($group, $message);
                $summary['errors'] += count($group);
            }
        }
        return $summary;
    }

    private function effectiveDeadline(?float $deadline): ?float
    {
        $cronDeadline = CronDeadlineContext::deadline();
        if ($deadline === null) {
            return $cronDeadline;
        }
        return $cronDeadline === null ? $deadline : min($deadline, $cronDeadline);
    }

    private function canStartResource(?float $deadline, float $reserveSeconds = 2.0): bool
    {
        return $deadline === null || microtime(true) + max(0.5, $reserveSeconds) < $deadline;
    }

    private function processGroup(int $accountId, string $topic, string $resource, array $events): array
    {
        if ($accountId <= 0) {
            $this->createNotice($events[0], 'system', 'warning', 'Notificación sin cuenta asociada', 'No se pudo asociar user_id con una cuenta Mercado Libre.', null, null, null);
            return ['message' => 'Sin cuenta asociada'];
        }
        if ($topic === 'orders_v2' || $topic === 'orders') {
            if (!preg_match('~/orders/(\d+)~', $resource, $m)) {
                return ['message' => 'Resource de orden no reconocido'];
            }
            $orderId = (new OrderSyncService($accountId))->syncOrderById($m[1]);
            $title = count($events) > 1 ? 'Orden ' . $m[1] . ' actualizada varias veces' : 'Orden ' . $m[1] . ' actualizada';
            $this->createNotice($events[0], 'order', 'high', $title, 'Se refrescó el estado actual desde Mercado Libre.', '/orders/show?id=' . $orderId, 'meli_order', $orderId);
            return ['message' => 'Orden procesada'];
        }
        if ($topic === 'post_purchase') {
            $claimId = $this->claimId($resource);
            if (!$claimId) {
                return ['message' => 'Resource post_purchase no reconocido'];
            }
            $localId = (new ClaimSyncService($accountId))->syncClaimById($claimId);
            $this->createNotice($events[0], 'claim', 'high', 'Reclamo actualizado', 'Se recibió una notificación post venta y se refrescó el reclamo.', '/claims', 'meli_claim', $localId);
            return ['message' => 'Reclamo procesado'];
        }
        if ($topic === 'questions') {
            if (!$this->settings->bool('questions.sync_enabled', false) || !$this->settings->bool('questions.endpoint_confirmed', false)) {
                return ['message' => 'Preguntas omitidas por configuración segura'];
            }
            $count = (new QuestionSyncService())->syncAccount($accountId);
            $this->createNotice($events[0], 'question', 'high', 'Pregunta pendiente', 'Se revisaron preguntas de la cuenta. Nuevas/pendientes: ' . $count . '.', '/questions?account_id=' . $accountId, 'meli_question', null);
            return ['message' => 'Preguntas revisadas'];
        }
        $this->createNotice($events[0], 'system', 'warning', 'Evento webhook desconocido', 'Topic no activo o no confirmado: ' . $topic, '/notifications/events?topic=' . rawurlencode($topic), 'meli_notification_event', (int) $events[0]['id']);
        $this->markGroup($events, 'unknown_topic', 'Topic no activo en modo seguro');
        return ['message' => 'Topic desconocido'];
    }

    private function dueEvents(int $limit): array
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT * FROM meli_notification_events
                 WHERE status IN ("received","queued","waiting_retry","circuit_breaker_wait")
                   AND (process_after IS NULL OR process_after<=UTC_TIMESTAMP())
                 ORDER BY priority ASC, erp_received_at ASC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    private function markProcessing(array $events): void
    {
        $ids = array_map(static fn($e) => (int) $e['id'], $events);
        Database::connection()->exec('UPDATE meli_notification_events SET status="processing",retry_count=retry_count+1 WHERE id IN (' . implode(',', $ids) . ')');
    }

    private function markGroup(array $events, string $status, ?string $message): void
    {
        $ids = array_map(static fn($e) => (int) $e['id'], $events);
        $stmt = Database::connection()->prepare('UPDATE meli_notification_events SET status=:status,processed_at=UTC_TIMESTAMP(),error_message=:message WHERE id IN (' . implode(',', $ids) . ')');
        $stmt->execute(['status' => $status, 'message' => $message ? mb_substr($message, 0, 500) : null]);
    }

    private function defer(array $events, int $minutes, string $message, string $status = 'waiting_retry'): void
    {
        $ids = array_map(static fn($e) => (int) $e['id'], $events);
        $stmt = Database::connection()->prepare('UPDATE meli_notification_events SET status=:status,process_after=DATE_ADD(UTC_TIMESTAMP(), INTERVAL :minutes MINUTE),error_message=:message WHERE id IN (' . implode(',', $ids) . ')');
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':minutes', max(1, $minutes), PDO::PARAM_INT);
        $stmt->bindValue(':message', mb_substr($message, 0, 500));
        $stmt->execute();
    }

    private function failOrRetry(array $events, string $message): void
    {
        $max = max(1, $this->settings->int('notifications.max_retries', 3));
        foreach ($events as $event) {
            $status = ((int) $event['retry_count'] + 1) >= $max ? 'failed' : 'waiting_retry';
            $stmt = Database::connection()->prepare('UPDATE meli_notification_events SET status=:status,process_after=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE),error_message=:message WHERE id=:id');
            $stmt->execute(['status' => $status, 'message' => mb_substr($message, 0, 500), 'id' => (int) $event['id']]);
        }
    }

    private function createNotice(array $event, string $type, string $severity, string $title, string $message, ?string $url, ?string $entityType, ?int $entityId): void
    {
        $accountId = (int) ($event['meli_account_id'] ?? 0) ?: null;
        $companyId = null;
        if ($accountId) {
            $stmt = Database::connection()->prepare('SELECT company_id FROM meli_accounts WHERE id=:id');
            $stmt->execute(['id' => $accountId]);
            $companyId = $stmt->fetchColumn() ?: null;
        }
        Database::connection()->prepare(
            'INSERT INTO app_notifications (company_id,meli_account_id,notification_event_id,type,severity,title,message,action_url,entity_type,entity_id)
             VALUES (:company,:account,:event,:type,:severity,:title,:message,:url,:entity_type,:entity_id)'
        )->execute([
            'company' => $companyId,
            'account' => $accountId,
            'event' => (int) $event['id'],
            'type' => $type,
            'severity' => $severity,
            'title' => mb_substr($title, 0, 190),
            'message' => mb_substr($message, 0, 500),
            'url' => $url,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }

    private function claimId(string $resource): ?string
    {
        if (preg_match('~/claims/(\d+)~', $resource, $m)) {
            return $m[1];
        }
        if (preg_match('~claim_id=(\d+)~', $resource, $m)) {
            return $m[1];
        }
        return null;
    }
}
