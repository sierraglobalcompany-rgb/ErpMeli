<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use PDOException;
use Throwable;

final class NotificationWorkItemService
{
    private AppSettingsService $settings;

    public function __construct()
    {
        $this->settings = new AppSettingsService();
    }

    public function available(): bool
    {
        return (new SchemaInspectorService())->hasTable('meli_notification_work_items');
    }

    /**
     * @param array<string,mixed> $classification
     */
    public function enqueue(
        int $eventId,
        ?int $accountId,
        ?int $meliUserId,
        array $classification,
        ?string $sentAt
    ): ?int {
        if (!$this->available()) {
            return null;
        }
        $scopeKey = $accountId !== null && $accountId > 0
            ? 'account:' . $accountId
            : 'user:' . ($meliUserId ?? 0);
        $status = empty($classification['valid'])
            ? ((string) ($classification['canonical_topic'] ?? 'unknown') === 'unknown' ? 'ignored' : 'quarantined')
            : (empty($classification['actionable']) ? 'ignored' : ($accountId ? 'pending' : 'quarantined'));
        $correlationId = self::uuid();
        $delay = max(0, min(60, $this->settings->int('notifications.debounce_seconds', 5)));
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO meli_notification_work_items
             (meli_account_id,account_scope_key,meli_user_id,canonical_topic,resource_type,remote_resource_id,
              latest_event_id,latest_event_sent_at,priority,occurrence_count,status,next_run_at,correlation_id,
              last_result,first_received_at,last_received_at)
             VALUES (?,?,?,?,?,?,?,?,?,1,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
              id=LAST_INSERT_ID(id),
              meli_account_id=COALESCE(VALUES(meli_account_id),meli_account_id),
              meli_user_id=COALESCE(VALUES(meli_user_id),meli_user_id),
              canonical_topic=VALUES(canonical_topic),
              latest_event_id=CASE
                WHEN VALUES(latest_event_sent_at) IS NULL
                  OR latest_event_sent_at IS NULL
                  OR VALUES(latest_event_sent_at)>=latest_event_sent_at
                THEN VALUES(latest_event_id) ELSE latest_event_id END,
              latest_event_sent_at=CASE
                WHEN latest_event_sent_at IS NULL OR VALUES(latest_event_sent_at)>=latest_event_sent_at
                THEN VALUES(latest_event_sent_at) ELSE latest_event_sent_at END,
              priority=LEAST(priority,VALUES(priority)),
              occurrence_count=occurrence_count+1,
              rerun_requested=IF(status="running",1,rerun_requested),
              status=CASE
                WHEN status="running" THEN status
                WHEN VALUES(status) IN ("ignored","quarantined") THEN VALUES(status)
                ELSE "pending" END,
              next_run_at=CASE
                WHEN status="running" THEN next_run_at
                ELSE DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND) END,
              last_received_at=UTC_TIMESTAMP(),
              consecutive_failures=CASE
                WHEN VALUES(latest_event_sent_at) IS NULL
                  OR latest_event_sent_at IS NULL
                  OR VALUES(latest_event_sent_at)>=latest_event_sent_at
                THEN 0 ELSE consecutive_failures END,
              last_error_code=NULL,
              last_error_message=NULL,
              last_error_diagnostic_id=NULL,
              last_error_stage=NULL'
        );
        $params = [
            $accountId,
            $scopeKey,
            $meliUserId,
            (string) $classification['canonical_topic'],
            (string) $classification['resource_type'],
            (string) $classification['resource_id'],
            $eventId,
            $sentAt,
            (int) $classification['priority'],
            $status,
            $delay,
            $correlationId,
            $status === 'ignored'
                ? 'recognized_ignored'
                : ($status === 'quarantined'
                    ? ($accountId === null && !empty($classification['actionable']) ? 'account_not_unique' : (string) $classification['reason'])
                    : null),
            $delay,
        ];
        $stmt->execute($params);
        $workId = (int) $pdo->lastInsertId();
        $correlationStmt = $pdo->prepare('SELECT correlation_id FROM meli_notification_work_items WHERE id=? LIMIT 1');
        $correlationStmt->execute([$workId]);
        $workCorrelationId = (string) ($correlationStmt->fetchColumn() ?: $correlationId);
        $pdo->prepare(
            'UPDATE meli_notification_events
             SET work_item_id=?,canonical_topic=?,resource_type=?,remote_resource_id=?,
                 validation_status=?,disposition=?,correlation_id=?,acknowledged_at=UTC_TIMESTAMP()
             WHERE id=?'
        )->execute([
            $workId,
            $classification['canonical_topic'],
            $classification['resource_type'],
            $classification['resource_id'],
            !empty($classification['valid']) ? 'valid' : 'invalid',
            $status,
            $workCorrelationId,
            $eventId,
        ]);
        $this->admitCanonicalWork($workId, $accountId);
        return $workId;
    }

    /** @return array<string,mixed> */
    public function processDue(?int $limit = null, ?float $deadline = null): array
    {
        if (\App\QueueCore\QueueCoreOwnershipGuard::v4OwnsWebhook()) {
            return \App\QueueCore\QueueCoreOwnershipGuard::skippedResult();
        }
        if (!$this->settings->bool('notifications.webhook_first_enabled', true)) {
            return ['processed' => 0, 'ignored' => 0, 'errors' => 0, 'skipped' => true, 'stop_reason' => 'feature_disabled'];
        }
        if (!$this->settings->bool('notifications.enabled', true)) {
            return ['processed' => 0, 'ignored' => 0, 'errors' => 0, 'skipped' => true, 'stop_reason' => 'manual_pause'];
        }
        if (!$this->available()) {
            return ['processed' => 0, 'ignored' => 0, 'errors' => 0, 'skipped' => true, 'message' => 'Migración 068 pendiente.'];
        }
        $limit ??= $this->settings->int('notifications.worker_batch_limit', 15);
        $limit = max(1, min(50, $limit));
        $timeBudget = max(5, min(120, $this->settings->int('notifications.worker_time_budget_seconds', 40)));
        $deadline = $this->effectiveDeadline($deadline, $timeBudget);
        $maxPerAccount = max(1, min(20, $this->settings->int('notifications.max_resources_per_account_per_run', 5)));
        $summary = [
            'processed' => 0,
            'ignored' => 0,
            'errors' => 0,
            'resources' => 0,
            'orders' => 0,
            'shipments' => 0,
            'questions' => 0,
            'claims' => 0,
            'items' => 0,
            'stock' => 0,
            'api_calls_avoided' => 0,
            'deferred' => 0,
            'stop_reason' => '',
            'error_diagnostics' => [],
        ];
        $owner = 'notification-' . bin2hex(random_bytes(12));
        $perAccount = [];
        foreach ($this->dueCandidates(max(60, $limit * 8)) as $candidate) {
            if ($summary['resources'] >= $limit) {
                break;
            }
            if (!$this->canStartResource($deadline)) {
                $summary['stop_reason'] = 'lane_deadline';
                break;
            }
            $accountKey = (string) ($candidate['account_scope_key'] ?? 'unknown');
            if (($perAccount[$accountKey] ?? 0) >= $maxPerAccount) {
                continue;
            }
            $work = $this->lease((int) $candidate['id'], $owner);
            if ($work === null) {
                continue;
            }
            $perAccount[$accountKey] = ($perAccount[$accountKey] ?? 0) + 1;
            $summary['resources']++;
            $started = microtime(true);
            try {
                $result = $this->processOne($work);
                $this->complete($work, $owner, $result, (int) round((microtime(true) - $started) * 1000));
                $summary['processed']++;
                $type = (string) ($work['resource_type'] ?? '');
                if (array_key_exists($type . 's', $summary)) {
                    $summary[$type . 's']++;
                } elseif ($type === 'stock_location') {
                    $summary['stock']++;
                }
                $summary['api_calls_avoided'] += max(0, (int) ($work['occurrence_count'] ?? 1) - 1);
            } catch (ApiBudgetExhaustedException $waiting) {
                $summary['deferred']++;
                $diagnosticId = 'WAIT-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
                $safeAt = (new SystemDatabaseUtcClock())->timestamp((string) ($waiting->nextSafeAt ?? ''));
                $minutes = $safeAt !== null ? max(1, (int) ceil(($safeAt - time()) / 60)) : 1;
                $this->defer(
                    $work,
                    $owner,
                    $minutes,
                    $waiting instanceof ApiRhythmDeferredException ? 'api_rhythm_deferred' : 'api_budget_exhausted',
                    $waiting->getMessage(),
                    $diagnosticId,
                    'policy',
                    false
                );
                $summary['stop_reason'] = $waiting instanceof ApiRhythmDeferredException ? 'api_rhythm' : 'api_budget';
                break;
            } catch (RemoteResultUncertainException $uncertain) {
                $summary['errors']++;
                $diagnosticId = $this->reportWorkError($work, $uncertain, 'fencing');
                $summary['error_diagnostics'][] = $diagnosticId;
                $this->markActionRequired($work, $owner, $uncertain, $diagnosticId);
                $summary['stop_reason'] = 'action_required';
                break;
            } catch (ApiManualPauseException $pause) {
                $summary['deferred']++;
                $diagnosticId = 'PAUSE-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
                $this->defer($work, $owner, 5, 'api_manual_pause', $pause->getMessage(), $diagnosticId, 'guard', false);
                if ($pause->scope === 'app') {
                    break;
                }
            } catch (MeliApiException $error) {
                $summary['errors']++;
                $diagnosticId = $this->reportWorkError($work, $error, 'api');
                $summary['error_diagnostics'][] = $diagnosticId;
                $this->deferApiError($work, $owner, $error, $diagnosticId);
                if (in_array((int) $error->httpStatus, [403, 429], true)) {
                    break;
                }
            } catch (Throwable $error) {
                $summary['errors']++;
                $diagnosticId = $this->reportWorkError($work, $error, 'processing');
                $summary['error_diagnostics'][] = $diagnosticId;
                $this->failOrRetry($work, $owner, $error, $diagnosticId);
            }
        }
        $this->heartbeat($summary);
        $summary['queue'] = $this->summary();
        return $summary;
    }

    /** @return array<string,mixed> */
    /** @param list<int>|null $allowedAccountIds */
    public function summary(?array $allowedAccountIds = null): array
    {
        if (!$this->available()) {
            return ['available' => false, 'pending' => 0, 'running' => 0, 'errors' => 0];
        }
        $sla = max(30, $this->settings->int('notifications.target_sla_seconds', 120));
        [$scopeSql, $scopeParams] = $this->readScope($allowedAccountIds, 'meli_account_id');
        $stmt = Database::connection()->prepare(
            'SELECT
              SUM(status IN ("pending","retry")) pending,
              SUM(status="running") running,
              SUM(status="paused") paused,
              SUM(status="error") errors,
              SUM(status="quarantined") quarantined,
              SUM(status="ignored") ignored,
              SUM(status="complete") complete,
              MIN(CASE WHEN status IN ("pending","retry") THEN first_received_at END) oldest_pending_at,
              SUM(CASE WHEN status IN ("pending","retry") AND TIMESTAMPDIFF(SECOND,first_received_at,UTC_TIMESTAMP())<=' . $sla . ' THEN 1 ELSE 0 END) within_sla,
              SUM(status IN ("pending","retry")) sla_denominator,
              SUM(occurrence_count) event_occurrences,
              SUM(resource_type="order" AND last_result="order_created" AND last_processed_at>=UTC_DATE()) orders_created_today,
              SUM(resource_type="order" AND last_result="order_updated" AND last_processed_at>=UTC_DATE()) orders_updated_today,
              COUNT(*) unique_resources
             FROM meli_notification_work_items WHERE ' . $scopeSql
        );
        $stmt->execute($scopeParams);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['pending', 'running', 'paused', 'errors', 'quarantined', 'ignored', 'complete', 'within_sla', 'sla_denominator', 'event_occurrences', 'orders_created_today', 'orders_updated_today', 'unique_resources'] as $key) {
            $row[$key] = (int) ($row[$key] ?? 0);
        }
        $denominator = (int) $row['sla_denominator'];
        $row['sla_percent'] = $denominator > 0
            ? (int) round(((int) $row['within_sla'] / $denominator) * 100)
            : null;
        $row['sla_label'] = $denominator > 0 ? 'Dentro del objetivo' : 'Sin pendientes';
        $row['deduplicated_events'] = max(0, (int) $row['event_occurrences'] - (int) $row['unique_resources']);
        $row['target_sla_seconds'] = $sla;
        $row['available'] = true;
        return $row;
    }

    /** @return list<array<string,mixed>> */
    /** @param list<int>|null $allowedAccountIds */
    public function recentFailures(int $limit = 10, ?array $allowedAccountIds = null): array
    {
        if (!$this->available()) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        [$scopeSql, $scopeParams] = $this->readScope($allowedAccountIds, 'w.meli_account_id');
        $stmt = Database::connection()->prepare(
            'SELECT w.id,w.resource_type,w.status,w.attempts,w.last_error_code,w.last_error_message,
                    w.last_error_diagnostic_id,w.last_error_stage,w.last_processed_at,w.updated_at,a.account_name
             FROM meli_notification_work_items w
             LEFT JOIN meli_accounts a ON a.id=w.meli_account_id
             WHERE w.last_error_code IS NOT NULL AND ' . $scopeSql . '
             ORDER BY COALESCE(w.last_processed_at,w.updated_at) DESC,w.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute($scopeParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    /** @param list<int>|null $allowedAccountIds */
    public function recentActivity(int $limit = 40, ?array $allowedAccountIds = null): array
    {
        if (!$this->available()) {
            return [];
        }
        [$scopeSql, $scopeParams] = $this->readScope($allowedAccountIds, 'w.meli_account_id');
        $stmt = Database::connection()->prepare(
            'SELECT w.id,w.canonical_topic,w.resource_type,w.remote_resource_id,w.last_result,w.occurrence_count,
                    w.last_processed_at,w.processing_ms,w.correlation_id,a.account_name
             FROM meli_notification_work_items w
             LEFT JOIN meli_accounts a ON a.id=w.meli_account_id
             WHERE w.status IN ("complete","ignored") AND ' . $scopeSql . '
             ORDER BY w.last_processed_at DESC,w.updated_at DESC
             LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute($scopeParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    /** @param list<int>|null $allowedAccountIds */
    public function attention(int $limit = 80, ?array $allowedAccountIds = null): array
    {
        if (!$this->available()) {
            return [];
        }
        [$scopeSql, $scopeParams] = $this->readScope($allowedAccountIds, 'w.meli_account_id');
        $stmt = Database::connection()->prepare(
            'SELECT w.id,w.canonical_topic,w.resource_type,w.remote_resource_id,w.status,w.attempts,w.next_run_at,
                    w.last_error_code,w.last_error_message,w.last_received_at,w.correlation_id,a.account_name
             FROM meli_notification_work_items w
             LEFT JOIN meli_accounts a ON a.id=w.meli_account_id
             WHERE (w.status IN ("error","paused","quarantined")
                OR (w.status IN ("pending","retry") AND w.first_received_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 120 SECOND)))
               AND ' . $scopeSql . '
             ORDER BY w.priority ASC,w.first_received_at ASC
             LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute($scopeParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<int>|null $allowedAccountIds */
    public function pause(?int $accountId = null, ?array $allowedAccountIds = null): int
    {
        if (!$this->available()) {
            return 0;
        }
        $sql = 'UPDATE meli_notification_work_items SET status="paused",locked_by=NULL,locked_at=NULL,lock_expires_at=NULL WHERE status IN ("pending","retry","running")';
        $params = [];
        if ($allowedAccountIds !== null) {
            if ($allowedAccountIds === []) {
                return 0;
            }
            $sql .= ' AND meli_account_id IN (' . implode(',', array_fill(0, count($allowedAccountIds), '?')) . ')';
            array_push($params, ...$allowedAccountIds);
        }
        if ($accountId !== null && $accountId > 0) {
            $sql .= ' AND meli_account_id=?';
            $params[] = $accountId;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        if ($accountId === null && $allowedAccountIds === null) {
            $this->settings->set('notifications.enabled', '0', 'notifications');
            $this->settings->set('notifications.worker_healthy_streak', '0', 'notifications');
        }
        return $stmt->rowCount();
    }

    /** @param list<int>|null $allowedAccountIds */
    public function resume(?int $accountId = null, ?array $allowedAccountIds = null): int
    {
        if (!$this->available()) {
            return 0;
        }
        $sql = 'UPDATE meli_notification_work_items SET status="pending",next_run_at=UTC_TIMESTAMP(),last_error_code=NULL,last_error_message=NULL,last_error_diagnostic_id=NULL,last_error_stage=NULL WHERE status="paused"';
        $params = [];
        if ($allowedAccountIds !== null) {
            if ($allowedAccountIds === []) {
                return 0;
            }
            $sql .= ' AND meli_account_id IN (' . implode(',', array_fill(0, count($allowedAccountIds), '?')) . ')';
            array_push($params, ...$allowedAccountIds);
        }
        if ($accountId !== null && $accountId > 0) {
            $sql .= ' AND meli_account_id=?';
            $params[] = $accountId;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        if ($accountId === null && $allowedAccountIds === null) {
            $this->settings->set('notifications.enabled', '1', 'notifications');
        }
        return $stmt->rowCount();
    }

    /** @param list<int>|null $allowedAccountIds */
    public function retry(?int $workId = null, ?array $allowedAccountIds = null): int
    {
        if (!$this->available()) {
            return 0;
        }
        $sql = 'UPDATE meli_notification_work_items
                SET status="pending",consecutive_failures=0,processing_event_id=NULL,next_run_at=UTC_TIMESTAMP(),
                    last_error_code=NULL,last_error_message=NULL,last_error_diagnostic_id=NULL,last_error_stage=NULL
                WHERE status IN ("error","quarantined")';
        $params = [];
        if ($allowedAccountIds !== null) {
            if ($allowedAccountIds === []) {
                return 0;
            }
            $sql .= ' AND meli_account_id IN (' . implode(',', array_fill(0, count($allowedAccountIds), '?')) . ')';
            array_push($params, ...$allowedAccountIds);
        }
        if ($workId !== null && $workId > 0) {
            $sql .= ' AND id=?';
            $params[] = $workId;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function inspectExact(int $workId, int $accountId, int $companyId = 0, bool $queueV4Exact = false): CampaignItemState
    {
        if ($workId < 1 || !$this->available()) {
            return new CampaignItemState(false, true, false, true, 'order_exact', 'Venta notificada', 'El trabajo ya no existe.', 0, 0, 'missing');
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT w.*,a.company_id
             FROM meli_notification_work_items w
             JOIN meli_accounts a ON a.id=w.meli_account_id
             WHERE w.id=? LIMIT 1'
        );
        $stmt->execute([$workId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return new CampaignItemState(false, true, false, true, 'order_exact', 'Venta notificada', 'El trabajo ya no existe.', 0, 0, 'missing');
        }
        if ($accountId > 0 && (int) $row['meli_account_id'] !== $accountId) {
            throw new \RuntimeException('La notificación no pertenece a la cuenta seleccionada.');
        }
        if ($companyId > 0 && (int) $row['company_id'] !== $companyId) {
            throw new \RuntimeException('La notificación no pertenece a la empresa seleccionada.');
        }
        $type = (string) $row['resource_type'];
        $operation = match ($type) {
            'question' => 'question_exact',
            'claim' => 'claim_exact',
            'shipment' => 'shipment_exact',
            'item' => 'item_exact',
            default => 'order_exact',
        };
        $label = match ($type) {
            'question' => 'Pregunta notificada',
            'claim' => 'Reclamo notificado',
            'shipment' => 'Envío notificado',
            'item' => 'Cambio de publicación',
            default => 'Venta notificada',
        };
        $status = (string) $row['status'];
        if ($status === 'complete') {
            return new CampaignItemState(true, true, false, $type !== 'item', $operation, $label, 'Automatización ya completó este recurso.', 0, 1, 'completed_elsewhere');
        }
        if (!in_array($status, ['pending', 'retry'], true)) {
            $message = in_array($status, ['error', 'quarantined'], true)
                ? 'Este recurso tiene un error anterior y requiere revisión.'
                : ($status === 'running' ? 'Otro proceso está terminando este recurso.' : 'Este recurso está pausado.');
            return new CampaignItemState(true, false, false, true, $operation, $label, $message, 0, 1, $status === 'running' ? 'locked' : 'action_required');
        }
        $clock = new SystemDatabaseUtcClock();
        if (!empty($row['next_run_at']) && !$clock->isDue((string) $row['next_run_at'])) {
            return new CampaignItemState(true, false, false, true, $operation, $label, 'Este recurso está programado para después.', 0, 1, 'future', (string) $row['next_run_at']);
        }
        if (!empty($row['lock_expires_at']) && !$clock->isDue((string) $row['lock_expires_at'])) {
            return new CampaignItemState(true, false, false, true, $operation, $label, 'Otro proceso está terminando este recurso.', 0, 1, 'locked', (string) $row['lock_expires_at']);
        }
        if (!$queueV4Exact && $type === 'shipment' && !$this->shipmentHasLocalOrder((int) $row['meli_account_id'], (string) $row['remote_resource_id'])) {
            return new CampaignItemState(
                true,
                false,
                false,
                true,
                $operation,
                $label,
                'Necesita completar primero la orden asociada. Automatización puede resolverla sin romper el ritmo manual.',
                0,
                1,
                'automatic_only'
            );
        }
        if (!in_array($type, ['order', 'shipment', 'question', 'claim', 'item'], true)) {
            return new CampaignItemState(true, false, false, true, $operation, $label, 'Este tipo de notificación todavía no es seguro para procesamiento manual.', 0, 1, 'unsupported');
        }
        return new CampaignItemState(
            true,
            false,
            true,
            true,
            $operation,
            $label,
            'Listo para procesar.',
            1,
            1,
            'ready'
        );
    }

    /** @return array<string,mixed> */
    public function processExact(
        int $workId,
        int $accountId,
        CampaignExecutionContext $context,
        bool $allowContinuation=true
    ): array {
        $coreManual = $context->worker === 'queue_core_manual';
        if (($coreManual && !$this->certifiedManualAttempt($workId, $accountId, $context))
            || (!$coreManual && \App\QueueCore\QueueCoreOwnershipGuard::v4OwnsWebhook())) {
            return ['status'=>'protected','stop_reason'=>'manual_ownership_unproven','processed'=>0,
                'message'=>'La autoridad exacta no está disponible. Vuelva a calcular.'];
        }
        if (!$this->canStartResource($context->deadline)) {
            return [
                'status' => 'deferred',
                'processed' => 0,
                'deferred' => 1,
                'stop_reason' => 'lane_deadline',
                'message' => 'La ventana segura terminó antes de reservar esta notificación.',
                'next_eligible_at' => gmdate('Y-m-d H:i:s', time() + 60),
            ];
        }
        $state = $this->inspectExact($workId, $accountId, $context->companyId);
        if (!$state->eligible) {
            return [
                'status' => $state->terminal ? 'skipped' : 'deferred',
                'processed' => 0,
                'message' => $state->message,
                'next_eligible_at' => $state->nextEligibleAt,
            ];
        }
        $owner = 'manual-campaign-' . $context->campaignId . '-' . $context->campaignItemId . '-' . bin2hex(random_bytes(4));
        $work = $this->lease($workId, $owner, true);
        if ($work === null) {
            return ['status' => 'deferred', 'processed' => 0, 'message' => 'Otro proceso reservó el recurso antes de este paso.', 'next_eligible_at' => gmdate('Y-m-d H:i:s', time() + 15)];
        }
        $started = microtime(true);
        try {
            $result = $this->processOne($work,$allowContinuation,$coreManual);
            $this->complete($work, $owner, $result, (int) round((microtime(true) - $started) * 1000));
            return [
                'status' => 'complete',
                'processed' => 1,
                'message' => (string) $result['message'],
                'result' => (string) $result['result'],
            ];
        } catch (ApiBudgetExhaustedException $waiting) {
            $diagnosticId = 'WAIT-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $safeAt = (new SystemDatabaseUtcClock())->timestamp((string) ($waiting->nextSafeAt ?? ''));
            $minutes = $safeAt !== null ? max(1, (int) ceil(($safeAt - time()) / 60)) : 1;
            $this->defer($work, $owner, $minutes, $waiting instanceof ApiRhythmDeferredException ? 'api_rhythm_deferred' : 'api_budget_exhausted', $waiting->getMessage(), $diagnosticId, 'policy', false);
            return ['status'=>'protected','stop_reason'=>$waiting instanceof ApiRhythmDeferredException && $waiting->reachedRemote ? 'remote_429' : 'policy_deferred', 'processed'=>0, 'message'=>$waiting->getMessage(), 'next_eligible_at'=>$waiting->nextSafeAt];
        } catch (RemoteResultUncertainException $uncertain) {
            $diagnosticId = $this->reportWorkError($work, $uncertain, 'fencing');
            $this->markActionRequired($work, $owner, $uncertain, $diagnosticId);
            return ['status' => 'action_required', 'processed' => 0, 'errors' => 1, 'message' => $this->safeMessage($uncertain), 'diagnostic_id' => $diagnosticId];
        } catch (ManualRemoteCallLimitException) {
            $this->defer($work, $owner, 1, 'manual_step_limit', 'La consulta principal continuará en el siguiente paso.', 'STEP-' . gmdate('Ymd-His'), 'guard', false);
            return ['status'=>'protected','stop_reason'=>'manual_step_limit', 'processed' => 0, 'message' => 'La autorización se renovó. El recurso continuará después del intervalo.', 'next_eligible_at' => gmdate('Y-m-d H:i:s', time() + 60)];
        } catch (ApiManualPauseException $pause) {
            $diagnosticId = 'PAUSE-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $this->defer($work, $owner, 5, 'api_manual_pause', $pause->getMessage(), $diagnosticId, 'guard', false);
            return ['status'=>'protected','stop_reason'=>'api_manual_pause', 'processed' => 0, 'message' => 'La protección preventiva aplazó esta consulta.', 'next_eligible_at' => gmdate('Y-m-d H:i:s', time() + 300)];
        } catch (MeliApiException $error) {
            $diagnosticId = $this->reportWorkError($work, $error, 'api');
            if ($this->deferApiError($work, $owner, $error, $diagnosticId)) {
                return ['status'=>'complete','processed'=>1,'result'=>'question_not_available',
                    'message'=>'La pregunta ya no está disponible en Mercado Libre.'];
            }
            return ['status'=>'protected','stop_reason'=>'remote_'.max(0,(int)$error->httpStatus),'http_status'=>$error->httpStatus, 'processed' => 0, 'message' => $this->safeMessage($error), 'diagnostic_id' => $diagnosticId];
        } catch (Throwable $error) {
            $diagnosticId = $this->reportWorkError($work, $error, 'processing');
            $this->failOrRetry($work, $owner, $error, $diagnosticId);
            return ['status' => 'error', 'processed' => 0, 'errors' => 1, 'message' => $this->safeMessage($error), 'diagnostic_id' => $diagnosticId];
        }
    }

    /** Read-only authorization; the physical Core fence still repeats all leases and capabilities. */
    private function certifiedManualAttempt(int $workId, int $accountId, CampaignExecutionContext $context): bool
    {
        $m = ApiExecutionMetadataContext::current();
        if (($m['source'] ?? '') !== 'queue_core' || ($m['queue_core_launcher'] ?? '') !== 'manual'
            || ($m['queue_core_work_type'] ?? '') !== 'manual_exact'
            || (int)($m['account_id'] ?? 0) !== $accountId || (int)($m['company_id'] ?? 0) !== $context->companyId) {
            return false;
        }
        $s = Database::connectionFresh()->prepare(
            "SELECT 1 FROM queue_core_jobs j
             JOIN queue_core_attempts a ON a.job_id=j.id AND a.company_id=j.company_id AND a.meli_account_id=j.meli_account_id
               AND a.lease_owner=j.lease_owner AND a.lease_generation=j.lease_generation
             JOIN queue_core_execution_leases e ON e.lease_key='global' AND e.launcher='manual'
             WHERE j.id=? AND a.id=? AND j.company_id=? AND j.meli_account_id=?
               AND j.work_type='manual_exact' AND j.queue_domain='manual' AND j.resource_type='notification_fallback' AND j.resource_id=?
               AND JSON_UNQUOTE(JSON_EXTRACT(j.payload_json,'$.source_authority_version'))=?
               AND j.state='running' AND j.lease_owner=? AND j.lease_generation=? AND j.lease_expires_at>UTC_TIMESTAMP(3)
               AND a.finished_at IS NULL AND e.owner_token=? AND e.generation=? AND e.expires_at>UTC_TIMESTAMP(3) LIMIT 1"
        );
        $s->execute([(int)($m['queue_core_job_id']??0),(int)($m['queue_core_attempt_id']??0),$context->companyId,$accountId,(string)$workId,
            $context->expectedSourceAuthorityVersion,(string)($m['queue_core_lease_owner']??''),$context->leaseGeneration,
            (string)($m['queue_core_execution_owner']??''),(int)($m['queue_core_execution_generation']??0)]);
        return (bool)$s->fetchColumn();
    }

    /** @return array<string,mixed> */
    public function processQueueV4Exact(
        int $workId,
        int $accountId,
        int $companyId,
        ?float $deadline = null
    ): array {
        $deadline ??= $this->effectiveDeadline(null, 30);
        if (!$this->canStartResource($deadline)) {
            return [
                'status' => 'deferred',
                'processed' => 0,
                'deferred' => 1,
                'stop_reason' => 'lane_deadline',
                'message' => 'La ventana segura terminó antes de reservar esta notificación.',
                'next_eligible_at' => gmdate('Y-m-d H:i:s', time() + 60),
            ];
        }
        $state = $this->inspectExact($workId, $accountId, $companyId, true);
        if (!$state->eligible) {
            return [
                'status' => $state->terminal ? 'skipped' : 'deferred',
                'processed' => 0,
                'message' => $state->message,
                'next_eligible_at' => $state->nextEligibleAt,
            ];
        }
        $owner = 'queue-v4-notification-' . $workId . '-' . bin2hex(random_bytes(4));
        $work = $this->lease($workId, $owner, false);
        if ($work === null) {
            return [
                'status' => 'deferred',
                'processed' => 0,
                'message' => 'Otro proceso reservó el recurso antes de este paso.',
                'next_eligible_at' => gmdate('Y-m-d H:i:s', time() + 15),
            ];
        }
        $started = microtime(true);
        try {
            $result = $this->processOne($work, false, true);
            $this->complete($work, $owner, $result, (int) round((microtime(true) - $started) * 1000));
            return [
                'status' => 'complete',
                'processed' => 1,
                'message' => (string) $result['message'],
                'result' => (string) $result['result'],
            ];
        } catch (ApiBudgetExhaustedException $waiting) {
            $diagnosticId = 'WAIT-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $safeAt = (new SystemDatabaseUtcClock())->timestamp((string) ($waiting->nextSafeAt ?? ''));
            $minutes = $safeAt !== null ? max(1, (int) ceil(($safeAt - time()) / 60)) : 1;
            $this->defer($work, $owner, $minutes, $waiting instanceof ApiRhythmDeferredException ? 'api_rhythm_deferred' : 'api_budget_exhausted', $waiting->getMessage(), $diagnosticId, 'policy', $waiting instanceof ApiRhythmDeferredException && $waiting->reachedRemote);
            throw $waiting;
        } catch (RemoteResultUncertainException $uncertain) {
            $diagnosticId = $this->reportWorkError($work, $uncertain, 'fencing');
            $this->markActionRequired($work, $owner, $uncertain, $diagnosticId);
            return ['status' => 'action_required', 'processed' => 0, 'errors' => 1, 'message' => $this->safeMessage($uncertain), 'diagnostic_id' => $diagnosticId];
        } catch (ManualRemoteCallLimitException) {
            $this->defer($work, $owner, 1, 'queue_v4_step_limit', 'La consulta principal continuará en el siguiente ciclo.', 'STEP-' . gmdate('Ymd-His'), 'guard', false);
            return ['status' => 'deferred', 'processed' => 0, 'message' => 'El recurso continuará después del intervalo.', 'next_eligible_at' => gmdate('Y-m-d H:i:s', time() + 60)];
        } catch (ApiManualPauseException $pause) {
            $diagnosticId = 'PAUSE-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $this->defer($work, $owner, 5, 'api_manual_pause', $pause->getMessage(), $diagnosticId, 'guard', false);
            return ['status' => 'deferred', 'processed' => 0, 'message' => 'La protección preventiva aplazó esta consulta.', 'next_eligible_at' => gmdate('Y-m-d H:i:s', time() + 300)];
        } catch (MeliApiException $error) {
            $diagnosticId = $this->reportWorkError($work, $error, 'api');
            if ($this->deferApiError($work, $owner, $error, $diagnosticId)) {
                return ['status'=>'complete','processed'=>1,'result'=>'question_not_available',
                    'message'=>'La pregunta ya no está disponible en Mercado Libre.'];
            }
            throw $error;
        } catch (Throwable $error) {
            $diagnosticId = $this->reportWorkError($work, $error, 'processing');
            $this->failOrRetry($work, $owner, $error, $diagnosticId);
            throw $error;
        }
    }

    private function admitCanonicalWork(int $workId, ?int $accountId): void
    {
        if ($workId < 1 || $accountId === null || $accountId < 1) {
            return;
        }
        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $lookup = $pdo->prepare(
                'SELECT w.id,w.status,a.company_id,w.meli_account_id
                 FROM meli_notification_work_items w
                 JOIN meli_accounts a ON a.id=w.meli_account_id
                 WHERE w.id=? AND w.meli_account_id=? LIMIT 1 FOR UPDATE'
            );
            $lookup->execute([$workId, $accountId]);
            $row = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !in_array((string) ($row['status'] ?? ''), ['pending', 'retry'], true)) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return;
            }

            (new CronAdmissionService($pdo))->submit(
                'notification_work_item',
                (int) $row['company_id'],
                (int) $row['meli_account_id'],
                $workId,
                'source:' . $workId
            );
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    private function dueCandidates(int $limit): array
    {
        return Database::connection()->query(
            'SELECT id,account_scope_key
             FROM meli_notification_work_items
             WHERE status IN ("pending","retry")
               AND next_run_at<=UTC_TIMESTAMP()
               AND (lock_expires_at IS NULL OR lock_expires_at<UTC_TIMESTAMP())
               AND NOT EXISTS (
                   SELECT 1 FROM manual_campaign_reservations r
                   WHERE r.queue_key="notification_fallback"
                     AND r.source_id=CAST(meli_notification_work_items.id AS CHAR)
                     AND r.status="active" AND r.expires_at>UTC_TIMESTAMP(3)
               )
             ORDER BY priority ASC,next_run_at ASC,id ASC
             LIMIT ' . max(1, min(500, $limit))
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function effectiveDeadline(?float $deadline, int $fallbackSeconds): float
    {
        $cronDeadline = CronDeadlineContext::deadline();
        $localDeadline = $deadline ?? (microtime(true) + max(1, $fallbackSeconds));
        return $cronDeadline === null ? $localDeadline : min($localDeadline, $cronDeadline);
    }

    private function canStartResource(float $deadline, float $reserveSeconds = 2.0): bool
    {
        return microtime(true) + max(0.5, $reserveSeconds) < $deadline;
    }

    /** @return array<string,mixed>|null */
    private function lease(int $id, string $owner, bool $manual = false): ?array
    {
        $pdo = Database::connectionFresh();
        $stmt = $pdo->prepare(
            'UPDATE meli_notification_work_items
             SET status="running",locked_by=?,locked_at=UTC_TIMESTAMP(),lock_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 180 SECOND),
                 last_started_at=UTC_TIMESTAMP(),attempts=attempts+1,processing_event_id=latest_event_id
             WHERE id=? AND status IN ("pending","retry")
               AND next_run_at<=UTC_TIMESTAMP()
               AND (lock_expires_at IS NULL OR lock_expires_at<UTC_TIMESTAMP())'
                . ($manual ? '' : '
               AND NOT EXISTS (
                   SELECT 1 FROM manual_campaign_reservations r
                   WHERE r.queue_key="notification_fallback"
                     AND r.source_id=CAST(meli_notification_work_items.id AS CHAR)
                     AND r.status="active" AND r.expires_at>UTC_TIMESTAMP(3)
               )')
        );
        $stmt->execute([$owner, $id]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }
        $select = $pdo->prepare('SELECT * FROM meli_notification_work_items WHERE id=? AND locked_by=? LIMIT 1');
        $select->execute([$id, $owner]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function shipmentHasLocalOrder(int $accountId, string $shipmentId): bool
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT 1 FROM meli_shipments
             WHERE meli_account_id=? AND external_shipment_id=? AND meli_order_id IS NOT NULL LIMIT 1'
        );
        $stmt->execute([$accountId, $shipmentId]);
        return (bool) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $work @return array{result:string,entity_type:?string,entity_id:?int,action_url:?string,message:string} */
    private function processOne(array $work, bool $allowContinuation = true, bool $queueV4Exact = false): array
    {
        $accountId = (int) ($work['meli_account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new \RuntimeException('La notificación no tiene una cuenta Mercado Libre asociada.');
        }
        $type = (string) $work['resource_type'];
        $remoteId = (string) $work['remote_resource_id'];
        $meta = [
            'job_type' => 'orders_event_sync',
            'source' => 'webhook_worker',
            'bulk' => false,
            'account_id' => $accountId,
        ];
        if ($queueV4Exact) {
            $meta = array_replace($meta, ApiExecutionMetadataContext::current());
        }
        if ($type === 'order') {
            $existing = $this->localOrder($accountId, $remoteId);
            $sync=new OrderSyncService($accountId);
            $orderId = $queueV4Exact
                ? $sync->syncOrderByIdForQueueV4Clean($remoteId, $meta)
                : ($allowContinuation ? $sync->syncOrderById($remoteId, $meta) : $sync->syncOrderByIdForManual($remoteId, $meta));
            if($allowContinuation)$this->enqueueFinancial($orderId, (int) $work['id']);
            return [
                'result' => $existing ? 'order_updated' : 'order_created',
                'entity_type' => 'meli_order',
                'entity_id' => $orderId,
                'action_url' => '/orders/show?id=' . $orderId,
                'message' => $existing ? 'Venta actualizada automáticamente.' : 'Venta incorporada automáticamente.',
            ];
        }
        if ($type === 'shipment') {
            $sync = new OrderSyncService($accountId);
            $shipmentId = $queueV4Exact
                ? $sync->syncShipmentByIdForQueueCore($remoteId, $meta)
                : $sync->syncShipmentById($remoteId, $meta);
            if($allowContinuation)$this->refreshFinancialForShipment($accountId, $shipmentId, (int) $work['id']);
            return [
                'result' => 'shipment_updated',
                'entity_type' => 'meli_shipment',
                'entity_id' => $shipmentId,
                'action_url' => '/shipments',
                'message' => 'Envío actualizado automáticamente.',
            ];
        }
        if ($type === 'question') {
            $questionId = (new QuestionSyncService())->syncQuestionById($accountId, $remoteId);
            return [
                'result' => 'question_updated',
                'entity_type' => 'meli_question',
                'entity_id' => $questionId,
                'action_url' => '/questions?account_id=' . $accountId,
                'message' => 'Pregunta recibida; requiere atención.',
            ];
        }
        if ($type === 'claim') {
            $claimId = (new ClaimSyncService($accountId))->syncClaimById($remoteId);
            return [
                'result' => 'claim_updated',
                'entity_type' => 'meli_claim',
                'entity_id' => $claimId,
                'action_url' => '/claims?account_id=' . $accountId,
                'message' => 'Reclamo actualizado; revise si requiere atención.',
            ];
        }
        if ($type === 'item') {
            $reviewId = (new MeliProductUpdateReviewService())->createReviewForItem($accountId, $remoteId, $queueV4Exact);
            return [
                'result' => 'item_review_created',
                'entity_type' => 'meli_product_update_review',
                'entity_id' => $reviewId,
                'action_url' => '/products/meli',
                'message' => 'Publicación actualizada automáticamente o enviada a revisión si cambió su identidad comercial.',
            ];
        }
        if ($type === 'stock_location') {
            $itemId = $this->localItemByUserProduct($accountId, $remoteId);
            if ($itemId <= 0) {
                return ['result' => 'stock_item_not_local', 'entity_type' => null, 'entity_id' => null, 'action_url' => null, 'message' => 'Stock reconocido sin publicación local asociada.'];
            }
            $stock = (new MeliItemStockService($accountId))->syncItem($itemId);
            return ['result' => 'stock_updated', 'entity_type' => 'meli_item', 'entity_id' => $itemId, 'action_url' => '/products/meli/show?id=' . $itemId, 'message' => 'Stock por origen actualizado: ' . (int) ($stock['synced'] ?? 0) . '.'];
        }
        return ['result' => 'recognized_ignored', 'entity_type' => null, 'entity_id' => null, 'action_url' => null, 'message' => 'Evento reconocido; no requiere consulta API.'];
    }

    /** @param array<string,mixed> $work @param array<string,mixed> $result */
    private function complete(array $work, string $owner, array $result, int $durationMs): void
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare(
                'SELECT id,rerun_requested,processing_event_id
                 FROM meli_notification_work_items WHERE id=? AND locked_by=? FOR UPDATE'
            );
            $lock->execute([$work['id'], $owner]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current)) {
                throw new \RuntimeException('El lease del recurso venció antes de completar su persistencia.');
            }
            $processingEventId = (int) ($current['processing_event_id'] ?? $work['latest_event_id'] ?? 0);
            $rerun = (int) ($current['rerun_requested'] ?? 0) === 1;

            $eventsSql =
                'UPDATE meli_notification_events
                 SET status="processed",disposition=?,processed_at=UTC_TIMESTAMP(),error_message=NULL
                 WHERE work_item_id=? AND status NOT IN ("duplicate","ignored")';
            $eventParams = [$result['result'], $work['id']];
            if ($processingEventId > 0) {
                $eventsSql .= ' AND id<=?';
                $eventParams[] = $processingEventId;
            }
            $pdo->prepare($eventsSql)->execute($eventParams);
            $this->createNotice($pdo, $work, $result);

            $stmt = $pdo->prepare(
                'UPDATE meli_notification_work_items
                 SET status=?,
                     next_run_at=IF(?=1,UTC_TIMESTAMP(),next_run_at),
                     locked_by=NULL,locked_at=NULL,lock_expires_at=NULL,
                     last_error_code=NULL,last_error_message=NULL,last_error_diagnostic_id=NULL,last_error_stage=NULL,
                     last_result=?,last_processed_at=UTC_TIMESTAMP(),last_success_at=UTC_TIMESTAMP(),
                     completed_at=IF(?=1,NULL,UTC_TIMESTAMP()),processing_ms=?,
                     rerun_requested=0,consecutive_failures=0,processing_event_id=NULL
                 WHERE id=? AND locked_by=?'
            );
            $stmt->execute([
                $rerun ? 'pending' : 'complete',
                $rerun ? 1 : 0,
                $result['result'],
                $rerun ? 1 : 0,
                $durationMs,
                $work['id'],
                $owner,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('No fue posible cerrar el lease del recurso.');
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $work @return bool True only after expected absence is durably closed. */
    private function deferApiError(array $work, string $owner, MeliApiException $error, string $diagnosticId): bool
    {
        $statusCode = (int) $error->httpStatus;
        if ($statusCode === 404 && (string) ($work['resource_type'] ?? '') === 'question') {
            $this->completeExpectedAbsence($work, $owner, $diagnosticId);
            return true;
        }
        $minutes = $statusCode === 429
            ? max(1, $this->settings->int('notifications.cooldown_429_minutes', 30))
            : ($statusCode === 403
                ? max(1, $this->settings->int('notifications.cooldown_403_minutes', 60))
                : min(240, 5 * (2 ** min(5, (int) ($work['consecutive_failures'] ?? 0)))));
        $code = $statusCode > 0 ? 'http_' . $statusCode : 'api_error';
        $this->defer(
            $work,
            $owner,
            $minutes + random_int(0, max(1, (int) floor($minutes / 5))),
            $code,
            $this->safeMessage($error),
            $diagnosticId,
            'api',
            !in_array($statusCode, [403, 429], true)
        );
        return false;
    }

    /** @param array<string,mixed> $work */
    private function completeExpectedAbsence(array $work, string $owner, string $diagnosticId): void
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE meli_notification_events
                 SET status="processed",processed_at=COALESCE(processed_at,UTC_TIMESTAMP()),
                     disposition="question_not_available",error_message=NULL,process_after=NULL
                 WHERE work_item_id=? AND processed_at IS NULL'
            )->execute([(int) $work['id']]);
            $stmt = $pdo->prepare(
                'UPDATE meli_notification_work_items
                 SET status="complete",locked_by=NULL,locked_at=NULL,lock_expires_at=NULL,
                     last_result="question_not_available",last_error_code=NULL,
                     last_error_message="La pregunta ya no está disponible en Mercado Libre.",
                     last_error_diagnostic_id=?,last_error_stage="expected_absence",
                     consecutive_failures=0,last_processed_at=UTC_TIMESTAMP(),completed_at=UTC_TIMESTAMP(),
                     processing_event_id=NULL
                 WHERE id=? AND locked_by=?'
            );
            $stmt->execute([$diagnosticId, (int) $work['id'], $owner]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('El trabajo dejó de pertenecer a esta ejecución.');
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $work */
    private function failOrRetry(array $work, string $owner, Throwable $error, string $diagnosticId): void
    {
        $max = max(1, $this->settings->int('notifications.max_retries', 3));
        $failureStreak = (int) ($work['consecutive_failures'] ?? 0) + 1;
        if ($failureStreak >= $max) {
            $stmt = Database::connectionFresh()->prepare(
                'UPDATE meli_notification_work_items
                 SET status="error",locked_by=NULL,locked_at=NULL,lock_expires_at=NULL,last_error_code="processing_error",
                     last_error_message=?,last_error_diagnostic_id=?,last_error_stage="processing",last_processed_at=UTC_TIMESTAMP(),
                     consecutive_failures=?,processing_event_id=NULL
                 WHERE id=? AND locked_by=?'
            );
            $stmt->execute([$this->safeMessage($error), $diagnosticId, $failureStreak, $work['id'], $owner]);
            return;
        }
        $this->defer($work, $owner, 5, 'processing_error', $this->safeMessage($error), $diagnosticId, 'processing', true);
    }

    /** @param array<string,mixed> $work */
    private function markActionRequired(array $work, string $owner, Throwable $error, string $diagnosticId): void
    {
        Database::connectionFresh()->prepare(
            'UPDATE meli_notification_work_items
             SET status="error",locked_by=NULL,locked_at=NULL,lock_expires_at=NULL,
                 last_error_code="remote_result_uncertain",last_error_message=?,last_error_diagnostic_id=?,
                 last_error_stage="fencing",last_processed_at=UTC_TIMESTAMP(),processing_event_id=NULL
             WHERE id=? AND locked_by=?'
        )->execute([$this->safeMessage($error), $diagnosticId, $work['id'], $owner]);
    }

    /** @param array<string,mixed> $work */
    private function defer(
        array $work,
        string $owner,
        int $minutes,
        string $code,
        string $message,
        string $diagnosticId,
        string $stage,
        bool $incrementFailureStreak = false
    ): void
    {
        $minutes = max(1, min(1440, $minutes));
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE meli_notification_work_items
             SET status="retry",next_run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? MINUTE),
                 locked_by=NULL,locked_at=NULL,lock_expires_at=NULL,last_error_code=?,last_error_message=?,
                 last_error_diagnostic_id=?,last_error_stage=?,
                 consecutive_failures=consecutive_failures+?,processing_event_id=NULL
             WHERE id=? AND locked_by=?'
        );
        $stmt->execute([
            $minutes,
            $code,
            mb_substr($message, 0, 500),
            $diagnosticId,
            $stage,
            $incrementFailureStreak ? 1 : 0,
            $work['id'],
            $owner,
        ]);
    }

    /** @param array<string,mixed> $work @param array<string,mixed> $result */
    private function createNotice(PDO $pdo, array $work, array $result): void
    {
        $resultType = (string) ($result['result'] ?? '');
        $requiresAttention = in_array($resultType, ['question_updated', 'claim_updated'], true);
        if (!$requiresAttention) {
            return;
        }
        $type = $resultType === 'question_updated' ? 'question' : 'claim';
        $severity = 'high';
        $title = $resultType === 'question_updated' ? 'Pregunta pendiente' : 'Reclamo actualizado';
        $dedupe = 'webhook:' . (string) $work['resource_type'] . ':' . (string) $work['account_scope_key'] . ':' . (string) $work['remote_resource_id'];
        $find = $pdo->prepare('SELECT id FROM app_notifications WHERE dedupe_key=? LIMIT 1');
        $find->execute([$dedupe]);
        $id = (int) $find->fetchColumn();
        if ($id > 0) {
            $pdo->prepare(
                'UPDATE app_notifications
                 SET type=?,severity=?,title=?,message=?,action_url=?,entity_type=?,entity_id=?,action_required=?,
                     occurrence_count=occurrence_count+1,last_occurred_at=UTC_TIMESTAMP(),is_read=0,read_at=NULL,dismissed_at=NULL
                 WHERE id=?'
            )->execute([
                $type, $severity, $title, $result['message'], $result['action_url'], $result['entity_type'],
                $result['entity_id'], 1, $id,
            ]);
            return;
        }
        $eventId = (int) ($work['latest_event_id'] ?? 0);
        $pdo->prepare(
            'INSERT INTO app_notifications
             (meli_account_id,notification_event_id,dedupe_key,type,severity,action_required,occurrence_count,last_occurred_at,
              title,message,action_url,entity_type,entity_id)
             VALUES (?,?,?,?,?,?,1,UTC_TIMESTAMP(),?,?,?,?,?)'
        )->execute([
            $work['meli_account_id'] ?: null, $eventId > 0 ? $eventId : null, $dedupe, $type, $severity,
            1, $title, $result['message'], $result['action_url'],
            $result['entity_type'], $result['entity_id'],
        ]);
    }

    private function localOrder(int $accountId, string $externalId): bool
    {
        $stmt = Database::connection()->prepare('SELECT id FROM meli_orders WHERE meli_account_id=? AND external_order_id=? LIMIT 1');
        $stmt->execute([$accountId, $externalId]);
        return $stmt->fetchColumn() !== false;
    }

    private function localItemByUserProduct(int $accountId, string $userProductId): int
    {
        $stmt = Database::connection()->prepare('SELECT id FROM meli_items WHERE meli_account_id=? AND user_product_id=? LIMIT 1');
        $stmt->execute([$accountId, $userProductId]);
        return (int) $stmt->fetchColumn();
    }

    private function enqueueFinancial(int $orderId, int $workId): void
    {
        $schema = new SchemaInspectorService();
        if (!$this->settings->bool('sales_financial.auto_queue_notifications', true)
            || !$schema->hasTable('sale_financial_reconciliation_jobs')) {
            return;
        }
        $projection = (new SaleFinancialStateService())->projectOrder($orderId);
        (new SaleFinancialService())->queueFromOrderId(
            $orderId, 'notification', $workId, 10,
            (string) ($projection['input_version'] ?? '')
        );
    }

    private function refreshFinancialForShipment(int $accountId, int $shipmentId, int $workId): void
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT s.meli_order_id
             FROM meli_shipments s
             JOIN meli_accounts a ON a.id=s.meli_account_id
             WHERE s.id=? AND s.meli_account_id=? AND s.meli_order_id IS NOT NULL LIMIT 1'
        );
        $stmt->execute([$shipmentId, $accountId]);
        $orderId = (int) ($stmt->fetchColumn() ?: 0);
        if ($orderId > 0) {
            $this->enqueueFinancial($orderId, $workId);
        }
    }

    /** @param array<string,mixed> $summary */
    private function heartbeat(array $summary): void
    {
        try {
            $this->settings->set('notifications.worker_last_heartbeat_at', gmdate('Y-m-d H:i:s'), 'notifications');
            $this->settings->set('notifications.worker_last_summary', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', 'notifications');
            $streak = max(0, $this->settings->int('notifications.worker_healthy_streak', 0));
            $cleanStreak = max(0, $this->settings->int('notifications.worker_clean_streak', 0));
            $this->settings->set('notifications.worker_healthy_streak', (string) min(10, $streak + 1), 'notifications');
            $this->settings->set(
                'notifications.worker_clean_streak',
                (string) (($summary['errors'] ?? 0) > 0 ? 0 : min(10, $cleanStreak + 1)),
                'notifications'
            );
        } catch (Throwable) {
        }
    }

    /** @param array<string,mixed> $work */
    private function reportWorkError(array $work, Throwable $error, string $stage): string
    {
        $driverCode = '';
        if ($error instanceof PDOException) {
            $driverCode = (string) (($error->errorInfo ?? [])[1] ?? '');
        }
        $reported = SafeErrorPresenter::report(
            $error,
            'No fue posible completar el recurso notificado.',
            [
                'module' => 'notifications',
                'stage' => $stage,
                'work_item_id' => (int) ($work['id'] ?? 0),
                'account_id' => (int) ($work['meli_account_id'] ?? 0),
                'resource_type' => (string) ($work['resource_type'] ?? ''),
                'attempt' => (int) ($work['attempts'] ?? 0),
                'driver_code' => $driverCode,
                'query_fingerprint' => substr(hash(
                    'sha256',
                    'notifications|' . $stage . '|' . (string) ($work['resource_type'] ?? 'unknown')
                ), 0, 16),
            ]
        );
        return (string) $reported['reference'];
    }

    private function safeMessage(Throwable $error): string
    {
        $message = strtolower($error->getMessage());
        if (str_contains($message, '429')) {
            return 'Mercado Libre pidió reducir temporalmente las consultas.';
        }
        if (str_contains($message, '403')) {
            return 'Mercado Libre no permitió esta consulta para la cuenta.';
        }
        if (str_contains($message, 'circuit') || str_contains($message, 'pausad')) {
            return 'La consulta quedó pausada por protección API.';
        }
        if (str_contains($message, 'server has gone away') || str_contains($message, 'lost connection')) {
            return 'La conexión de base de datos se interrumpió; el trabajo puede reanudarse.';
        }
        return 'No fue posible completar el recurso. Revise el diagnóstico técnico.';
    }

    /**
     * @param list<int>|null $allowedAccountIds null está reservado al proceso CLI.
     * @return array{0:string,1:list<int>}
     */
    private function readScope(?array $allowedAccountIds, string $column): array
    {
        if ($allowedAccountIds === null) {
            return ['1=1', []];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $allowedAccountIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return ['1=0', []];
        }
        return [$column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
