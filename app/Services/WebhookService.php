<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use App\Core\Env;
use PDO;
use PDOException;
use Throwable;

final class WebhookService
{
    public function receive(string $raw): bool
    {
        return !empty($this->receiveResult($raw)['accepted']);
    }

    /**
     * Guarda el evento y su trabajo canónico sin consultar Mercado Libre.
     *
     * @return array{accepted:bool,http_status:int,event_id:?int,duplicate:bool,spooled:bool,terminal:bool,quarantined:bool,message:string}
     */
    public function receiveResult(string $raw, string $source = 'webhook', bool $allowSpool = true): array
    {
        $receiverStarted = microtime(true);
        $spool = new WebhookSpoolService();
        $validation = $spool->validateIngress($raw);
        if (empty($validation['valid'])) {
            $quarantined = $spool->quarantine($raw, (string) ($validation['reason'] ?? 'invalid_payload'));
            return [
                'accepted' => false,
                'http_status' => (int) ($validation['http_status'] ?? 400),
                'event_id' => null,
                'duplicate' => false,
                'spooled' => false,
                'terminal' => true,
                'quarantined' => $quarantined,
                'message' => (string) ($validation['message'] ?? 'Notificación inválida.'),
            ];
        }
        $payload = $validation['payload'];
        $topic = (string) $validation['topic'];
        $resource = $validation['resource'];
        $userId = (int) $validation['user_id'];
        $applicationId = $validation['application_id'];
        $accountValidation = $spool->validateLinkedAccount($userId);
        if (empty($accountValidation['valid'])) {
            $terminal = !empty($accountValidation['terminal']);
            $quarantined = $terminal
                ? $spool->quarantine($raw, (string) $accountValidation['reason'])
                : false;
            return [
                'accepted' => false,
                'http_status' => (int) $accountValidation['http_status'],
                'event_id' => null,
                'duplicate' => false,
                'spooled' => false,
                'terminal' => $terminal,
                'quarantined' => $quarantined,
                'message' => (string) $accountValidation['message'],
            ];
        }
        $accountId = (int) $accountValidation['account_id'];
        $classification = is_array($validation['classification'] ?? null)
            ? $validation['classification']
            : (new MeliNotificationTopicRegistry())->classify(
                $topic,
                $resource,
                $payload['actions'] ?? null
            );
        $canonical = json_encode(Logger::redact($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $notificationId = isset($payload['_id']) ? (string) $payload['_id'] : null;
        $hashSeed = $notificationId !== null && $notificationId !== ''
            ? $notificationId
            : $topic . '|' . $resource . '|' . $userId . '|' . $applicationId . '|' . (string) ($payload['sent'] ?? '');
        $payloadHash = hash('sha256', $hashSeed);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'cli');
        $hmacKey = trim((string) Env::get('APP_KEY', ''));
        if ($hmacKey === '') {
            $hmacKey = trim((string) Env::get('MELI_CLIENT_SECRET', ''));
        }
        if ($hmacKey === '') {
            $hmacKey = hash('sha256', AppPaths::installationRoot());
        }
        $pdo = null;
        try {
            $pdo = Database::connectionFresh();
            $schema = new SchemaInspectorService();
            $modern = $schema->hasColumn('meli_notification_events', 'canonical_topic')
                && $schema->hasTable('meli_notification_work_items');
            $status = empty($classification['valid'])
                ? 'unknown_topic'
                : (empty($classification['actionable']) ? 'ignored' : 'queued');
            $pdo->beginTransaction();
            $fields = [
                'notification_id','meli_account_id','meli_user_id','application_id','topic','actions_json','resource',
                'attempts','sent_at','received_at','erp_received_at','source_ip','user_agent','payload_json','payload_hash',
                'status','priority','process_after',
            ];
            $values = array_fill(0, count($fields), '?');
            $values[10] = 'UTC_TIMESTAMP()';
            $values[17] = 'UTC_TIMESTAMP()';
            $params = [
                $notificationId ?: null,
                $accountId,
                $userId,
                $applicationId,
                $topic,
                json_encode($payload['actions'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $resource,
                (int) ($payload['attempts'] ?? 0),
                $this->date($payload['sent'] ?? null),
                $this->date($payload['received'] ?? null),
                hash_hmac('sha256', $ip, $hmacKey),
                null,
                $canonical,
                $payloadHash,
                $status,
                (int) $classification['priority'],
            ];
            if ($modern) {
                $fields[] = 'source_type';
                $values[] = '?';
                $params[] = mb_substr($source, 0, 40);
                $fields[] = 'canonical_topic';
                $values[] = '?';
                $params[] = $classification['canonical_topic'];
                $fields[] = 'resource_type';
                $values[] = '?';
                $params[] = $classification['resource_type'];
                $fields[] = 'remote_resource_id';
                $values[] = '?';
                $params[] = $classification['resource_id'];
                $fields[] = 'validation_status';
                $values[] = '?';
                $params[] = $classification['valid'] ? 'valid' : 'invalid';
                $fields[] = 'disposition';
                $values[] = '?';
                $params[] = $status;
                $fields[] = 'source_user_agent_hash';
                $values[] = '?';
                $params[] = hash_hmac('sha256', $ua, $hmacKey);
            }
            $stmt = $pdo->prepare(
                'INSERT INTO meli_notification_events (' . implode(',', $fields) . ')
                 VALUES (' . implode(',', $values) . ')'
            );
            $stmt->execute($params);
            $eventId = (int) $pdo->lastInsertId();
            if ($modern) {
                (new NotificationWorkItemService())->enqueue(
                    $eventId,
                    $accountId,
                    $userId,
                    $classification,
                    $this->date($payload['sent'] ?? null)
                );
            }
            $pdo->commit();
            if ($modern && $eventId > 0) {
                try {
                    (new \App\Core\Modules\ModuleEventDispatcher())->publish(
                        $eventId,
                        $classification['canonical_topic'],
                        $accountId,
                        $classification['resource_type'] !== '' ? $classification['resource_type'] : null,
                        $classification['resource_id'] !== '' ? $classification['resource_id'] : null
                    );
                } catch (Throwable) {
                    // El consumidor modular nunca invalida el evento principal ya confirmado.
                }
            }
            if ($modern) {
                try {
                    $durationMs = max(0, (int) round((microtime(true) - $receiverStarted) * 1000));
                    $pdo->prepare('UPDATE meli_notification_events SET receiver_duration_ms=? WHERE id=?')
                        ->execute([$durationMs, $eventId]);
                } catch (Throwable) {
                    // La métrica nunca debe convertir un evento durable en fallo.
                }
            }
            return ['accepted' => true, 'http_status' => 200, 'event_id' => $eventId, 'duplicate' => false, 'spooled' => false, 'terminal' => false, 'quarantined' => false, 'message' => 'Evento recibido.'];
        } catch (PDOException $error) {
            $this->rollback($pdo);
            if (($error->getCode() === '23000' || (int) ($error->errorInfo[1] ?? 0) === 1062)) {
                $existingId = $this->existingEventId($payloadHash, $notificationId);
                return ['accepted' => true, 'http_status' => 200, 'event_id' => $existingId, 'duplicate' => true, 'spooled' => false, 'terminal' => false, 'quarantined' => false, 'message' => 'Evento duplicado reconocido.'];
            }
            Logger::write('error', 'No fue posible guardar webhook en base de datos.', [
                'topic' => $topic,
                'user_id' => $userId,
                'sqlstate' => $error->getCode(),
                'driver_code' => $error->errorInfo[1] ?? null,
            ]);
            if ($allowSpool && Env::bool('WEBHOOK_SPOOL_ENABLED', true) && (new WebhookSpoolService())->append($raw)) {
                return ['accepted' => true, 'http_status' => 200, 'event_id' => null, 'duplicate' => false, 'spooled' => true, 'terminal' => false, 'quarantined' => false, 'message' => 'Evento guardado temporalmente.'];
            }
            return ['accepted' => false, 'http_status' => 503, 'event_id' => null, 'duplicate' => false, 'spooled' => false, 'terminal' => false, 'quarantined' => false, 'message' => 'No fue posible almacenar el evento.'];
        } catch (Throwable $error) {
            $this->rollback($pdo);
            Logger::write('error', 'Fallo inesperado al recibir webhook.', ['topic' => $topic, 'error_class' => $error::class]);
            if ($allowSpool && Env::bool('WEBHOOK_SPOOL_ENABLED', true) && (new WebhookSpoolService())->append($raw)) {
                return ['accepted' => true, 'http_status' => 200, 'event_id' => null, 'duplicate' => false, 'spooled' => true, 'terminal' => false, 'quarantined' => false, 'message' => 'Evento guardado temporalmente.'];
            }
            return ['accepted' => false, 'http_status' => 503, 'event_id' => null, 'duplicate' => false, 'spooled' => false, 'terminal' => false, 'quarantined' => false, 'message' => 'No fue posible almacenar el evento.'];
        }
    }

    public static function maxPayloadBytes(): int
    {
        $configured = Env::get('WEBHOOK_MAX_PAYLOAD_BYTES', Env::get('WEBHOOK_MAX_BYTES', '262144'));
        return max(65536, min(1048576, (int) $configured));
    }

    private function existingEventId(string $payloadHash, ?string $notificationId): ?int
    {
        try {
            $stmt = Database::connectionFresh()->prepare(
                'SELECT id FROM meli_notification_events
                 WHERE payload_hash=? OR (notification_id IS NOT NULL AND notification_id=?)
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$payloadHash, $notificationId ?: '']);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int) $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function processPending(int $limit = 100): array
    {
        if ((new NotificationWorkItemService())->available()) {
            return (new NotificationWorkItemService())->processDue($limit);
        }
        return (new NotificationCoalescerService())->processDue($limit);
    }

    public function recentEvents(array $filters = [], int $limit = 200, int $offset = 0): array
    {
        try {
            $where = ['1=1'];
            $params = [];
            $this->appendAllowedAccounts($where, $params, 'e.meli_account_id', $filters['allowed_account_ids'] ?? null);
            foreach (['status', 'topic'] as $key) {
                if (($filters[$key] ?? '') !== '') {
                    $where[] = 'e.' . $key . '=?';
                    $params[] = (string) $filters[$key];
                }
            }
            if (!empty($filters['account_id'])) {
                $where[] = 'e.meli_account_id=?';
                $params[] = (int) $filters['account_id'];
            }
            $columns = (new SchemaInspectorService())->hasColumn('meli_notification_events', 'canonical_topic')
                ? 'e.id,e.notification_id,e.meli_account_id,e.meli_user_id,e.topic,e.canonical_topic,e.resource_type,e.remote_resource_id,e.resource,e.status,e.disposition,e.priority,e.erp_received_at,e.processed_at,e.error_message,e.retry_count,e.correlation_id,e.work_item_id'
                : 'e.id,e.notification_id,e.meli_account_id,e.meli_user_id,e.topic,e.resource,e.status,e.priority,e.erp_received_at,e.processed_at,e.error_message,e.retry_count';
            $stmt = Database::connection()->prepare(
                'SELECT ' . $columns . ',a.account_name
                 FROM meli_notification_events e
                 LEFT JOIN meli_accounts a ON a.id=e.meli_account_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY e.erp_received_at DESC
                 LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset)
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /** @param list<int>|null $allowedAccountIds */
    public function health(?array $allowedAccountIds = null): array
    {
        try {
            $work = new NotificationWorkItemService();
            if ($work->available()) {
                $summary = $work->summary($allowedAccountIds);
                $settingsService = new AppSettingsService();
                $settings = $settingsService->getMany([
                    'notifications.worker_last_heartbeat_at' => '',
                    'notifications.heartbeat_stale_seconds' => '180',
                    'notifications.worker_healthy_streak' => '0',
                    'notifications.worker_clean_streak' => '0',
                    'notifications.enabled' => '1',
                    'notifications.webhook_first_enabled' => '1',
                    'notifications.dedicated_cron_interval_minutes' => '1',
                    'cron.notifications_interval_minutes' => '5',
                    'notifications.last_reconcile_at' => '',
                    'notifications.last_reconcile_mode' => '',
                ]);
                $heartbeat = (string) ($settings['notifications.worker_last_heartbeat_at'] ?: '');
                $heartbeatAge = $heartbeat !== '' ? max(0, time() - (strtotime($heartbeat . ' UTC') ?: time())) : null;
                $cronInterval = max(
                    1,
                    (int) ($settings['cron.notifications_interval_minutes']
                        ?: $settings['notifications.dedicated_cron_interval_minutes'])
                );
                $staleAfter = max(
                    60,
                    (int) $settings['notifications.heartbeat_stale_seconds'],
                    ($cronInterval * 120) + 60
                );
                $streak = max(0, (int) $settings['notifications.worker_healthy_streak']);
                $cleanStreak = max(0, (int) $settings['notifications.worker_clean_streak']);
                $workerEnabled = filter_var($settings['notifications.enabled'], FILTER_VALIDATE_BOOL)
                    && filter_var($settings['notifications.webhook_first_enabled'], FILTER_VALIDATE_BOOL);
                $nextExpected = $heartbeat !== ''
                    ? gmdate('Y-m-d H:i:s', (strtotime($heartbeat . ' UTC') ?: time()) + ($cronInterval * 60))
                    : null;
                $spool = (new WebhookSpoolService())->pendingCount();
                $actionable = (int) ($summary['pending'] ?? 0)
                    + (int) ($summary['running'] ?? 0)
                    + (int) ($summary['paused'] ?? 0)
                    + (int) ($summary['errors'] ?? 0)
                    + $spool;
                $heartbeatMissing = $heartbeatAge === null || $heartbeatAge > $staleAfter;
                $status = 'green';
                if (!$workerEnabled) {
                    $status = 'paused';
                } elseif ((int) ($summary['errors'] ?? 0) > 0 || ($heartbeatMissing && $actionable > 0)) {
                    $status = 'red';
                } elseif ($heartbeatMissing || (int) ($summary['pending'] ?? 0) > 0 || (int) ($summary['paused'] ?? 0) > 0 || $spool > 0) {
                    $status = 'yellow';
                }
                $label = match (true) {
                    $status === 'green' => 'Saludable',
                    $status === 'red' => 'Crítico',
                    $status === 'paused' => 'Pausado',
                    $heartbeatMissing && $actionable === 0 => 'Automatización pendiente',
                    default => 'Atención',
                };
                return $summary + $this->latencyMetrics($allowedAccountIds) + [
                    'status' => $status,
                    'label' => $label,
                    'worker_last_heartbeat_at' => $heartbeat ?: null,
                    'worker_heartbeat_age_seconds' => $heartbeatAge,
                    'worker_healthy_streak' => $streak,
                    'worker_clean_streak' => $cleanStreak,
                    'automatic' => $workerEnabled && $streak >= 2 && !$heartbeatMissing,
                    'worker_enabled' => $workerEnabled,
                    'next_worker_expected_at' => $nextExpected,
                    'configured_cron_interval_minutes' => $cronInterval,
                    'last_reconcile_at' => $settings['notifications.last_reconcile_at'] ?: null,
                    'last_reconcile_mode' => $settings['notifications.last_reconcile_mode'] ?: null,
                    'spool_pending' => $spool,
                    'received_today' => $this->receivedToday($allowedAccountIds),
                    'processed_today' => $this->processedToday($allowedAccountIds),
                    'failed' => (int) ($summary['errors'] ?? 0),
                    'critical' => (int) ($summary['errors'] ?? 0),
                    'unknown_topics' => (int) ($summary['quarantined'] ?? 0),
                ];
            }
        } catch (Throwable) {
        }
        try {
            $where = [];
            $params = [];
            $this->appendAllowedAccounts($where, $params, 'meli_account_id', $allowedAccountIds);
            $stmt = Database::connection()->prepare(
                "SELECT
                 SUM(erp_received_at>=UTC_DATE()) received_today,
                 SUM(processed_at>=UTC_DATE() AND status='processed') processed_today,
                 SUM(status IN ('queued','received','waiting_retry','circuit_breaker_wait')) pending,
                 SUM(status='failed') failed,
                 SUM(status='duplicate') duplicates,
                 SUM(status='unknown_topic') unknown_topics,
                 SUM(priority<=2 AND status NOT IN ('processed','ignored','duplicate')) critical"
                . ' FROM meli_notification_events WHERE ' . implode(' AND ', $where)
            );
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $pending = (int) ($row['pending'] ?? 0);
            $failed = (int) ($row['failed'] ?? 0);
            $critical = (int) ($row['critical'] ?? 0);
            $status = 'green';
            if ($critical > 0 || $failed > 20) {
                $status = 'red';
            } elseif ($failed > 0 || $pending > 50) {
                $status = 'orange';
            } elseif ($pending > 10) {
                $status = 'yellow';
            }
            $row['status'] = $status;
            $row['label'] = ['green' => 'Verde', 'yellow' => 'Amarillo', 'orange' => 'Naranja', 'red' => 'Rojo'][$status];
            return $row;
        } catch (Throwable) {
            return ['status' => 'yellow', 'label' => 'Atención', 'pending' => 0, 'failed' => 0, 'critical' => 0];
        }
    }

    /** @param list<int>|null $allowedAccountIds */
    private function receivedToday(?array $allowedAccountIds): int
    {
        $where = ['erp_received_at>=UTC_DATE()'];
        $params = [];
        $this->appendAllowedAccounts($where, $params, 'meli_account_id', $allowedAccountIds);
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM meli_notification_events WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @param list<int>|null $allowedAccountIds */
    private function processedToday(?array $allowedAccountIds): int
    {
        $where = ['last_processed_at>=UTC_DATE()'];
        $params = [];
        $this->appendAllowedAccounts($where, $params, 'meli_account_id', $allowedAccountIds);
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM meli_notification_work_items WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{receiver_p50_ms:?int,receiver_p95_ms:?int,event_to_local_p50_seconds:?int,event_to_local_p95_seconds:?int} */
    /** @param list<int>|null $allowedAccountIds */
    private function latencyMetrics(?array $allowedAccountIds): array
    {
        try {
            $eventWhere = ['receiver_duration_ms IS NOT NULL'];
            $eventParams = [];
            $this->appendAllowedAccounts($eventWhere, $eventParams, 'meli_account_id', $allowedAccountIds);
            $eventStmt = Database::connection()->prepare(
                'SELECT receiver_duration_ms FROM meli_notification_events
                 WHERE ' . implode(' AND ', $eventWhere) . '
                 ORDER BY id DESC LIMIT 500'
            );
            $eventStmt->execute($eventParams);
            $durations = $eventStmt->fetchAll(PDO::FETCH_COLUMN);
            $workWhere = ['last_processed_at IS NOT NULL'];
            $workParams = [];
            $this->appendAllowedAccounts($workWhere, $workParams, 'meli_account_id', $allowedAccountIds);
            $workStmt = Database::connection()->prepare(
                'SELECT TIMESTAMPDIFF(SECOND,first_received_at,last_processed_at)
                 FROM meli_notification_work_items
                 WHERE ' . implode(' AND ', $workWhere) . '
                 ORDER BY id DESC LIMIT 500'
            );
            $workStmt->execute($workParams);
            $latencies = $workStmt->fetchAll(PDO::FETCH_COLUMN);
            return [
                'receiver_p50_ms' => $this->percentile($durations, 50),
                'receiver_p95_ms' => $this->percentile($durations, 95),
                'event_to_local_p50_seconds' => $this->percentile($latencies, 50),
                'event_to_local_p95_seconds' => $this->percentile($latencies, 95),
            ];
        } catch (Throwable) {
            return [
                'receiver_p50_ms' => null,
                'receiver_p95_ms' => null,
                'event_to_local_p50_seconds' => null,
                'event_to_local_p95_seconds' => null,
            ];
        }
    }

    /** @param list<mixed> $values */
    private function percentile(array $values, int $percent): ?int
    {
        $numeric = array_values(array_map('intval', array_filter($values, 'is_numeric')));
        if ($numeric === []) {
            return null;
        }
        sort($numeric, SORT_NUMERIC);
        $index = (int) ceil((count($numeric) - 1) * max(0, min(100, $percent)) / 100);
        return $numeric[$index] ?? null;
    }

    /** @param list<int>|null $allowedAccountIds */
    public function unreadSummary(?array $allowedAccountIds = null): array
    {
        try {
            $attention = (new SchemaInspectorService())->hasColumn('app_notifications', 'action_required')
                ? ' AND action_required=1'
                : '';
            $where = ['is_read=0', 'dismissed_at IS NULL'];
            $params = [];
            $this->appendAllowedAccounts($where, $params, 'meli_account_id', $allowedAccountIds);
            $stmt = Database::connection()->prepare(
                "SELECT COUNT(*) unread,
                        SUM(severity='critical') critical,
                        SUM(type='claim' AND is_read=0) claims,
                        SUM(type='question' AND is_read=0) questions,
                        SUM(type='order' AND severity IN ('warning','high','critical') AND is_read=0) order_errors
                 FROM app_notifications
                 WHERE " . implode(' AND ', $where) . $attention
            );
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return array_map('intval', $row);
        } catch (Throwable) {
            return ['unread' => 0, 'critical' => 0, 'claims' => 0, 'questions' => 0, 'order_errors' => 0];
        }
    }

    public function notifications(array $filters = [], int $limit = 200): array
    {
        try {
            $where = ['n.dismissed_at IS NULL'];
            $params = [];
            $this->appendAllowedAccountsNamed($where, $params, 'n.meli_account_id', $filters['allowed_account_ids'] ?? null);
            foreach (['severity', 'type'] as $key) {
                if (($filters[$key] ?? '') !== '') {
                    $where[] = 'n.' . $key . '=:' . $key;
                    $params[$key] = (string) $filters[$key];
                }
            }
            if (($filters['unread'] ?? '') === '1') {
                $where[] = 'n.is_read=0';
            }
            if (($filters['action_required'] ?? '') === '1'
                && (new SchemaInspectorService())->hasColumn('app_notifications', 'action_required')) {
                $where[] = 'n.action_required=1';
            }
            if (!empty($filters['account_id'])) {
                $where[] = 'n.meli_account_id=:account';
                $params['account'] = (int) $filters['account_id'];
            }
            $stmt = Database::connection()->prepare(
                'SELECT n.*,a.account_name,c.name company_name
                 FROM app_notifications n
                 LEFT JOIN meli_accounts a ON a.id=n.meli_account_id
                 LEFT JOIN companies c ON c.id=n.company_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY n.is_read ASC,n.created_at DESC LIMIT ' . max(1, min(500, $limit))
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /** @param list<int> $accountIds */
    public function markRead(int $id, array $accountIds): void
    {
        if ($accountIds === []) {
            return;
        }
        try {
            $sql = 'UPDATE app_notifications SET is_read=1,read_at=UTC_TIMESTAMP() WHERE id=? AND meli_account_id IN ('
                . implode(',', array_fill(0, count($accountIds), '?')) . ')';
            Database::connection()->prepare($sql)->execute(array_merge([$id], $accountIds));
        } catch (Throwable) {
        }
    }

    /** @param list<int> $accountIds */
    public function dismiss(int $id, array $accountIds): void
    {
        if ($accountIds === []) {
            return;
        }
        try {
            $sql = 'UPDATE app_notifications SET dismissed_at=UTC_TIMESTAMP() WHERE id=? AND meli_account_id IN ('
                . implode(',', array_fill(0, count($accountIds), '?')) . ')';
            Database::connection()->prepare($sql)->execute(array_merge([$id], $accountIds));
        } catch (Throwable) {
        }
    }

    /** @param list<int> $accountIds */
    public function requeueEvent(int $id, array $accountIds): void
    {
        if ($accountIds === []) {
            return;
        }
        try {
            $sql = 'UPDATE meli_notification_events SET status="queued",process_after=UTC_TIMESTAMP(),error_message=NULL '
                . 'WHERE id=? AND meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ') '
                . 'AND status IN ("failed","waiting_retry","circuit_breaker_wait")';
            Database::connection()->prepare($sql)->execute(array_merge([$id], $accountIds));
        } catch (Throwable) {
        }
    }

    public function accountIdFromUser(?int $userId): ?int
    {
        if (!$userId) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT id FROM meli_accounts WHERE meli_user_id=:user ORDER BY id LIMIT 2');
        $stmt->execute(['user' => $userId]);
        $ids = array_values(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
        return count($ids) === 1 ? $ids[0] : null;
    }

    /** @param array<int|string,mixed> $params @param list<int>|null $allowedAccountIds */
    private function appendAllowedAccounts(array &$where, array &$params, string $column, mixed $allowedAccountIds): void
    {
        if ($allowedAccountIds === null) {
            $where[] = '1=1';
            return;
        }
        $ids = is_array($allowedAccountIds)
            ? array_values(array_unique(array_filter(array_map('intval', $allowedAccountIds), static fn (int $id): bool => $id > 0)))
            : [];
        if ($ids === []) {
            $where[] = '1=0';
            return;
        }
        $where[] = $column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($params, ...$ids);
    }

    /** @param array<string,mixed> $params @param list<int>|null $allowedAccountIds */
    private function appendAllowedAccountsNamed(array &$where, array &$params, string $column, mixed $allowedAccountIds): void
    {
        if ($allowedAccountIds === null) {
            $where[] = '1=1';
            return;
        }
        $ids = is_array($allowedAccountIds)
            ? array_values(array_unique(array_filter(array_map('intval', $allowedAccountIds), static fn (int $id): bool => $id > 0)))
            : [];
        if ($ids === []) {
            $where[] = '1=0';
            return;
        }
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $key = 'allowed_account_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $where[] = $column . ' IN (' . implode(',', $placeholders) . ')';
    }

    private function date(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }
        $ts = strtotime((string) $value);
        return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
    }

    private function rollback(?PDO $pdo): void
    {
        if ($pdo !== null && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
