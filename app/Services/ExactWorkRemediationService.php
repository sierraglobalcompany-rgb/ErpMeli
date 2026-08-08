<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use PDO;
use PDOException;
use Throwable;

/**
 * Repara o reprograma un único recurso local.
 *
 * Ningún método de esta clase abre transporte HTTP ni ejecuta una cola completa. El
 * trabajo remoto, si corresponde, queda exclusivamente a cargo de Cron.
 */
final class ExactWorkRemediationService
{
    /** @param array<string,mixed> $work @return array<string,mixed> */
    public function context(array $work, int $userId): array
    {
        $this->assertScope($work, $userId);
        $source = $this->source((string) ($work['queue_key'] ?? ''), (string) ($work['source_id'] ?? ''), $work, false);
        if ($source !== null) {
            $work = array_merge($work, $source);
            $work = (new HistoricalWorkReconciliationService())->applyLatest($work);
            $source = $work;
        }
        $policy = (new WorkResolutionPolicyRegistry())->resolve($work);
        $campaigns = $this->campaignItems($work, $userId);
        $actions = array_values(array_filter(
            (array) ($policy['actions'] ?? []),
            fn (array $action): bool => $source !== null
                && $this->supportsAction((string) ($source['queue_key'] ?? ''), (string) ($action['key'] ?? ''))
        ));
        // La primera versión del motor exacto solo conoce la tabla, los leases
        // y el fencing de order_enrichment. Una proyección genérica puede
        // explicar cualquier cola, pero nunca debe prometer una mutación que
        // no sabe aplicar de forma exacta.
        foreach ($source !== null ? $campaigns : [] as $campaign) {
            if ((string) ($campaign['status'] ?? '') !== 'failed') {
                continue;
            }
            $actions[] = [
                'key' => 'skip_campaign',
                'label' => 'Omitir solo en campaña #' . (int) $campaign['campaign_id'],
                'description' => 'La orden y el trabajo original se conservan.',
                'primary' => false,
                'campaign_id' => (int) $campaign['campaign_id'],
                'campaign_item_id' => (int) $campaign['campaign_item_id'],
            ];
            if ((string) ($policy['key'] ?? '') !== 'remote_result_uncertain') {
                $actions[] = [
                    'key' => 'return_to_queue',
                    'label' => 'Devolver a la cola normal',
                    'description' => 'Retira la reserva de esta campaña y deja el recurso listo para Cron.',
                    'primary' => false,
                    'campaign_id' => (int) $campaign['campaign_id'],
                    'campaign_item_id' => (int) $campaign['campaign_item_id'],
                ];
            }
        }

        $ready = (new SchemaInspectorService())->hasTable('system_work_resolution_events');
        if (!$ready) {
            $actions = [];
        }
        return [
            'policy' => $policy,
            'actions' => $actions,
            'campaigns' => $campaigns,
            'expected_status' => (string) ($source['source_status'] ?? $work['source_status'] ?? ''),
            'expected_generation' => (int) ($source['source_generation'] ?? 0),
            'ready' => $ready,
            'unavailable_message' => !$ready
                ? 'Complete la actualización para habilitar acciones exactas y auditables.'
                : ($source === null && empty($policy['automatic'])
                    ? 'Esta función todavía no tiene una reparación web exacta certificada. Cron continuará con los recursos independientes.'
                    : null),
        ];
    }

    /**
     * @param array<string,mixed> $request
     * @return array{message:string,new_status:string,idempotent:bool}
     */
    public function remediate(array $request, int $userId): array
    {
        $queueKey = $this->identifier((string) ($request['queue_key'] ?? ''), 80);
        $sourceId = $this->identifier((string) ($request['source_id'] ?? ''), 100, true);
        $action = $this->identifier((string) ($request['action'] ?? ''), 80);
        $expectedStatus = $this->identifier((string) ($request['expected_status'] ?? ''), 40);
        $expectedGeneration = max(0, (int) ($request['expected_generation'] ?? 0));
        $actionNonce = strtolower(trim((string) ($request['action_nonce'] ?? '')));
        $idempotencyKey = strtolower(trim((string) ($request['idempotency_key'] ?? '')));
        $campaignId = max(0, (int) ($request['campaign_id'] ?? 0));
        $campaignItemId = max(0, (int) ($request['campaign_item_id'] ?? 0));
        if (preg_match('/^[a-f0-9]{32}$/', $actionNonce) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $idempotencyKey) !== 1) {
            throw new HttpException(422, 'La solicitud exacta no tiene una clave de idempotencia válida. Recargue la página.');
        }
        $expectedKey = hash('sha256', implode('|', [
            $queueKey, $sourceId, $action, (string) $campaignId, (string) $campaignItemId,
            $expectedStatus, (string) $expectedGeneration, $actionNonce,
        ]));
        if (!hash_equals($expectedKey, $idempotencyKey)) {
            throw new HttpException(422, 'La acción exacta no coincide con el estado mostrado. Recargue la página.');
        }
        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('system_work_resolution_events')) {
            throw new HttpException(409, 'Complete la actualización antes de modificar este trabajo.');
        }

        $projection = (new WorkQueueProjectionService())->find($queueKey, $sourceId);
        if ($projection === null) {
            throw new HttpException(404, 'El trabajo ya no está disponible o no pertenece a su alcance.');
        }
        $this->assertScope($projection, $userId);
        $context = $this->context($projection, $userId);
        $allowed = [];
        foreach ((array) $context['actions'] as $candidate) {
            if ((string) ($candidate['key'] ?? '') !== $action) {
                continue;
            }
            if (isset($candidate['campaign_item_id']) && (int) $candidate['campaign_item_id'] !== $campaignItemId) {
                continue;
            }
            $allowed[] = $candidate;
        }
        $actionAllowed = $allowed !== [];

        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $source = $this->source($queueKey, $sourceId, $projection, true, $pdo);
            if ($source === null) {
                throw new HttpException(404, 'El trabajo ya no está disponible o no pertenece a su alcance.');
            }
            $source = (new HistoricalWorkReconciliationService())->applyLatest($source, $pdo);
            $previous = $this->existingEvent($pdo, $idempotencyKey, $userId);
            if ($previous !== null) {
                $sameRequest = (string) ($previous['queue_key'] ?? '') === $queueKey
                    && (string) ($previous['source_id'] ?? '') === $sourceId
                    && (string) ($previous['action_key'] ?? '') === $action
                    && (int) ($previous['expected_generation'] ?? -1) === $expectedGeneration
                    && (string) ($previous['previous_status'] ?? '') === $expectedStatus
                    && (int) ($previous['campaign_id'] ?? 0) === $campaignId
                    && (int) ($previous['campaign_item_id'] ?? 0) === $campaignItemId;
                $sameResult = (string) ($source['source_status'] ?? '') === (string) ($previous['new_status'] ?? '')
                    && (int) ($source['source_generation'] ?? -1) === (int) ($previous['resulting_generation'] ?? -2);
                $sameCampaignResult = $this->campaignReplayMatches(
                    $pdo,
                    $projection,
                    $action,
                    $campaignId,
                    $campaignItemId
                );
                if (!$sameRequest || !$sameResult || !$sameCampaignResult
                    || (string) ($previous['result_status'] ?? '') !== 'completed') {
                    throw new HttpException(409, 'El recurso cambió después de la acción anterior. Recargue el detalle antes de continuar.');
                }
                $pdo->commit();
                return [
                    'message' => (string) ($previous['safe_message'] ?? 'La acción ya había sido aplicada.'),
                    'new_status' => (string) ($previous['new_status'] ?? ''),
                    'idempotent' => true,
                ];
            }
            if (!$actionAllowed) {
                throw new HttpException(409, 'Esta acción ya no corresponde al estado actual del trabajo. Recargue el detalle.');
            }
            if ((string) $source['source_status'] !== $expectedStatus
                || (int) $source['source_generation'] !== $expectedGeneration) {
                throw new HttpException(409, 'El trabajo cambió desde que abrió esta página. Recargue antes de decidir.');
            }

            $this->insertStartedEvent(
                $pdo,
                $idempotencyKey,
                $queueKey,
                $sourceId,
                $projection,
                $action,
                $expectedStatus,
                $expectedGeneration,
                $campaignId,
                $campaignItemId,
                $userId
            );

            [$newStatus, $newGeneration, $message] = match ($action) {
                'retry' => $this->retrySource($pdo, $source),
                'repair_identity_and_retry' => $this->retryOrderEnrichment($pdo, $source, true),
                'close_unavailable' => $this->closeUnavailable($pdo, $source),
                'diagnose_local' => $this->diagnoseLocal($pdo, $source, $userId),
                'hold_uncertain' => $this->holdUncertain($source),
                'skip_campaign' => $this->skipCampaign($pdo, $source, $campaignId, $campaignItemId, $userId),
                'return_to_queue' => $this->returnToQueue($pdo, $source, $campaignId, $campaignItemId, $userId),
                default => throw new HttpException(409, 'La acción solicitada no está disponible.'),
            };
            if ((string) ($source['queue_key'] ?? '') === 'order_enrichment'
                && in_array($action, ['retry', 'repair_identity_and_retry'], true)) {
                $this->reconcileSourceCampaignItems($pdo, $source, 'retry', $message);
            } elseif ((string) ($source['queue_key'] ?? '') === 'order_enrichment' && $action === 'close_unavailable') {
                $this->reconcileSourceCampaignItems($pdo, $source, 'skipped', $message);
            }

            $update = $pdo->prepare(
                'UPDATE system_work_resolution_events
                 SET new_status=?,resulting_generation=?,result_status="completed",safe_message=?
                 WHERE idempotency_key=? AND created_by=? AND result_status="started"'
            );
            $update->execute([$newStatus, $newGeneration, mb_substr($message, 0, 500), $idempotencyKey, $userId]);
            if ($update->rowCount() !== 1) {
                throw new HttpException(409, 'La acción perdió su autorización transaccional. Recargue el detalle.');
            }
            $pdo->commit();
            return ['message' => $message, 'new_status' => $newStatus, 'idempotent' => false];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof HttpException) {
                throw $error;
            }
            if ($error instanceof PDOException && (string) $error->getCode() === '23000') {
                throw new HttpException(409, 'Otro administrador aplicó una acción al mismo tiempo. Recargue el detalle.');
            }
            throw $error;
        }
    }

    /** @return array{total:int,failed:int,continues:bool,groups:list<array<string,mixed>>} */
    public function campaignSummary(int $campaignId, int $userId): array
    {
        if ($campaignId < 1 || !(new SchemaInspectorService())->hasTable('manual_campaign_items')) {
            return ['total' => 0, 'failed' => 0, 'continues' => false, 'groups' => []];
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT i.queue_key,i.source_id,i.company_id,i.meli_account_id,i.status,
                    i.result_summary,i.diagnostic_id
             FROM manual_campaign_items i
             JOIN manual_campaigns c ON c.id=i.manual_campaign_id
             WHERE i.manual_campaign_id=? AND i.status="failed"
             ORDER BY i.id'
        );
        $stmt->execute([$campaignId]);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['meli_account_id'])) {
                try {
                    (new BusinessScopeContext())->account((int) $row['meli_account_id'], (int) ($row['company_id'] ?? 0), $userId);
                } catch (Throwable) {
                    continue;
                }
            }
            $policy = (new WorkResolutionPolicyRegistry())->resolve([
                'queue_key' => $row['queue_key'],
                'source_status' => 'error',
                'safe_error_message' => $row['result_summary'],
            ]);
            $key = (string) $policy['key'];
            if (!isset($groups[$key])) {
                $groups[$key] = ['key' => $key, 'label' => $policy['label'], 'count' => 0];
            }
            $groups[$key]['count']++;
        }
        $failed = array_sum(array_map(static fn (array $group): int => (int) $group['count'], $groups));
        return [
            'total' => $failed,
            'failed' => $failed,
            'continues' => $failed > 0,
            'groups' => array_values($groups),
        ];
    }

    /** @param array<string,mixed> $work */
    private function assertScope(array $work, int $userId): void
    {
        $accountId = (int) ($work['meli_account_id'] ?? 0);
        $companyId = (int) ($work['company_id'] ?? 0);
        if ($companyId < 1) {
            throw new HttpException(404, 'El trabajo no tiene un alcance empresarial exacto.');
        }
        if ($accountId > 0) {
            (new BusinessScopeContext())->account($accountId, $companyId, $userId);
            return;
        }
        if (!in_array($companyId, (new BusinessScopeContext())->companyIds($userId), true)) {
            throw new HttpException(404, 'El trabajo no pertenece a una empresa autorizada.');
        }
    }

    /** @param array<string,mixed> $scope @return array<string,mixed>|null */
    private function source(string $queueKey, string $sourceId, array $scope, bool $lock, ?PDO $pdo = null): ?array
    {
        if (!ctype_digit($sourceId)) {
            return null;
        }
        $pdo ??= Database::connectionFresh();
        $sql = match ($queueKey) {
            'order_enrichment' =>
                'SELECT j.*,j.status source_status,j.lease_generation source_generation,a.company_id
                 FROM order_resource_enrichment_jobs j JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=?
                 WHERE j.id=? AND j.meli_account_id=? LIMIT 1',
            'notification_fallback' =>
                'SELECT w.*,w.status source_status,w.attempts source_generation,a.company_id,
                        w.last_error_code normalized_error_code,w.last_error_diagnostic_id diagnostic_id,
                        w.last_error_message safe_error_message,w.last_error_stage failure_class,
                        CASE WHEN w.last_error_stage IN ("database","claim","local") THEN 0
                             WHEN w.last_error_stage IN ("api","fencing") THEN 1 ELSE NULL END reached_remote
                 FROM meli_notification_work_items w JOIN meli_accounts a ON a.id=w.meli_account_id AND a.company_id=?
                 WHERE w.id=? AND w.meli_account_id=? LIMIT 1',
            'orders_sync' =>
                'SELECT c.*,c.status source_status,c.attempt_count source_generation,a.company_id,
                        c.error_type normalized_error_code,c.last_error safe_error_message,c.error_type failure_class,
                        CASE WHEN c.error_type IN ("database","database_error","local","validation","invalid_request") THEN 0
                             WHEN c.error_http_status IS NOT NULL THEN 1 ELSE NULL END reached_remote
                 FROM sync_batch_chunks c JOIN meli_accounts a ON a.id=c.meli_account_id AND a.company_id=?
                 WHERE c.id=? AND c.meli_account_id=? LIMIT 1',
            'sales_audit' =>
                'SELECT j.*,j.status source_status,j.lease_generation source_generation,a.company_id,
                        j.last_error_class normalized_error_code,j.safe_error_message,
                        j.last_error_class failure_class,
                        CASE WHEN LOWER(COALESCE(j.last_error_class,"")) REGEXP "pdo|database|mysql|mariadb|local" THEN 0
                             WHEN j.last_http_status IS NOT NULL THEN 1 ELSE NULL END reached_remote
                 FROM sync_sales_audit_jobs j JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=?
                 WHERE j.id=? AND j.meli_account_id=? LIMIT 1',
            'financial_recalc' =>
                'SELECT j.*,j.status source_status,j.processed_items source_generation,a.company_id,
                        CASE WHEN j.last_db_error_message IS NOT NULL THEN "database_error" ELSE NULL END normalized_error_code,
                        COALESCE(j.last_error_message,j.safe_message) safe_error_message,
                        CASE WHEN j.last_db_error_message IS NOT NULL OR j.current_phase="local_recalc" THEN 0 ELSE NULL END reached_remote
                 FROM order_financial_recalc_jobs j JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=?
                 WHERE j.id=? AND j.meli_account_id=? LIMIT 1',
            'sale_financial_reconciliation' =>
                'SELECT j.*,j.status source_status,j.lease_generation source_generation,a.company_id,
                        j.safe_message safe_error_message,j.last_remote_state failure_class,
                        CASE WHEN j.last_remote_state IS NULL AND j.attempts=0 THEN 0
                             WHEN j.last_remote_state IS NOT NULL THEN 1 ELSE NULL END reached_remote
                 FROM sale_financial_reconciliation_jobs j JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=?
                 WHERE j.id=? AND j.meli_account_id=? LIMIT 1',
            default => null,
        };
        if ($sql === null) {
            return null;
        }
        $stmt = $pdo->prepare($sql . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([(int) ($scope['company_id'] ?? 0), (int) $sourceId, (int) ($scope['meli_account_id'] ?? 0)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['queue_key'] = $queueKey;
        $row['normalized_error_code'] ??= $row['last_error_code'] ?? null;
        $row['diagnostic_id'] ??= $row['last_error_diagnostic_id'] ?? null;
        $row['safe_error_message'] ??= $row['last_error_message'] ?? $row['safe_message'] ?? null;
        if (array_key_exists('reached_remote', $row) && $row['reached_remote'] !== null) {
            $row['reached_remote'] = (int) $row['reached_remote'];
        }
        return $row;
    }

    /** @param array<string,mixed> $work @return list<array<string,mixed>> */
    private function campaignItems(array $work, int $userId): array
    {
        if (!(new SchemaInspectorService())->hasTable('manual_campaign_items')) {
            return [];
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT c.id campaign_id,i.id campaign_item_id,i.status,c.status campaign_status
             FROM manual_campaign_items i
             JOIN manual_campaigns c ON c.id=i.manual_campaign_id
             WHERE i.queue_key=? AND i.source_id=? AND i.company_id=? AND i.meli_account_id=?
               AND c.status IN ("active","paused","pausing")
               AND i.status IN ("failed","retry","waiting")
             ORDER BY c.id DESC,i.id DESC'
        );
        $stmt->execute([
            (string) ($work['queue_key'] ?? ''),
            (string) ($work['source_id'] ?? ''),
            (int) ($work['company_id'] ?? 0),
            (int) ($work['meli_account_id'] ?? 0),
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function supportsAction(string $queueKey, string $action): bool
    {
        $supported = [
            'order_enrichment' => ['retry', 'repair_identity_and_retry', 'close_unavailable', 'diagnose_local', 'hold_uncertain'],
            'notification_fallback' => ['retry', 'close_unavailable', 'diagnose_local', 'hold_uncertain'],
            'orders_sync' => ['retry', 'diagnose_local', 'hold_uncertain'],
            'sales_audit' => ['retry', 'diagnose_local', 'hold_uncertain'],
            'financial_recalc' => ['retry', 'diagnose_local', 'hold_uncertain'],
            'sale_financial_reconciliation' => ['retry', 'diagnose_local', 'hold_uncertain'],
        ];
        return in_array($action, $supported[$queueKey] ?? [], true);
    }

    /** @param array<string,mixed> $source @return array{0:string,1:int,2:string} */
    private function retrySource(PDO $pdo, array $source): array
    {
        if (($source['reached_remote'] ?? null) !== 0 && ($source['reached_remote'] ?? null) !== false) {
            throw new HttpException(409, 'No se confirmó que el transporte estuviera ausente. Diagnostique el recurso antes de reintentar.');
        }
        $queueKey = (string) ($source['queue_key'] ?? '');
        if ($queueKey === 'order_enrichment') {
            return $this->retryOrderEnrichment($pdo, $source, false);
        }
        $generation = (int) $source['source_generation'];
        $params = [(int) $source['company_id'], (int) $source['id'], (int) $source['meli_account_id'],
            (string) $source['source_status'], $generation];
        $sql = match ($queueKey) {
            'notification_fallback' =>
                'UPDATE meli_notification_work_items w JOIN meli_accounts a ON a.id=w.meli_account_id AND a.company_id=?
                 SET w.status="retry",w.next_run_at=UTC_TIMESTAMP(),w.locked_by=NULL,w.locked_at=NULL,w.lock_expires_at=NULL
                 WHERE w.id=? AND w.meli_account_id=? AND w.status=? AND w.attempts=?',
            'orders_sync' =>
                'UPDATE sync_batch_chunks c JOIN meli_accounts a ON a.id=c.meli_account_id AND a.company_id=?
                 SET c.status="queued",c.queued_at=COALESCE(c.queued_at,UTC_TIMESTAMP()),c.next_run_at=UTC_TIMESTAMP()
                 WHERE c.id=? AND c.meli_account_id=? AND c.status=? AND c.attempt_count=?',
            'sales_audit' =>
                'UPDATE sync_sales_audit_jobs j JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=?
                 SET j.status="pending",j.next_run_at=UTC_TIMESTAMP(),j.locked_by=NULL,j.lock_expires_at=NULL,
                     j.heartbeat_at=NULL,j.lease_generation=j.lease_generation+1
                 WHERE j.id=? AND j.meli_account_id=? AND j.status=? AND j.lease_generation=?',
            'financial_recalc' =>
                'UPDATE order_financial_recalc_jobs j JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=?
                 SET j.status="pending",j.completed_at=NULL
                 WHERE j.id=? AND j.meli_account_id=? AND j.status=? AND j.processed_items=?',
            'sale_financial_reconciliation' =>
                'UPDATE sale_financial_reconciliation_jobs j JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=?
                 SET j.status="retry",j.next_run_at=UTC_TIMESTAMP(),j.lock_owner=NULL,j.lease_expires_at=NULL,
                     j.heartbeat_at=NULL,j.lease_generation=j.lease_generation+1
                 WHERE j.id=? AND j.meli_account_id=? AND j.status=? AND j.lease_generation=?',
            default => throw new HttpException(409, 'Esta cola no admite reintento exacto desde la web.'),
        };
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            throw new HttpException(409, 'El trabajo cambió mientras se reprogramaba. Recargue el detalle.');
        }
        $resultingGeneration = in_array($queueKey, ['sales_audit', 'sale_financial_reconciliation'], true)
            ? $generation + 1 : $generation;
        $newStatus = match ($queueKey) {
            'orders_sync' => 'queued',
            'sales_audit', 'financial_recalc' => 'pending',
            default => 'retry',
        };
        return [$newStatus,
            $resultingGeneration, 'Este recurso exacto quedó disponible para el próximo Cron. No se consultó Mercado Libre.'];
    }

    /** @param array<string,mixed> $source @return array{0:string,1:int,2:string} */
    private function diagnoseLocal(PDO $pdo, array $source, int $userId): array
    {
        $reconciled = (new HistoricalWorkReconciliationService())->reconcile($pdo, $source, $userId);
        $state = (string) (($reconciled['historical_reconciliation']['state'] ?? 'remote_result_uncertain'));
        $message = match ($state) {
            'ready_for_exact_retry' => 'Diagnóstico local terminado. Se confirmó que no hubo transporte; ahora puede reintentar este recurso exacto.',
            'expected_absence' => 'Diagnóstico local terminado. Se confirmó que el recurso remoto ya no está disponible.',
            default => 'Diagnóstico local terminado. La evidencia no permite confirmar el resultado remoto y el recurso permanece bloqueado.',
        };
        return [(string) $source['source_status'], (int) $source['source_generation'], $message];
    }

    /** @param array<string,mixed> $source @return array{0:string,1:int,2:string} */
    private function holdUncertain(array $source): array
    {
        return [(string) $source['source_status'], (int) $source['source_generation'],
            'El recurso exacto permanece bloqueado por resultado remoto incierto. No se realizó transporte ni reintento.'];
    }

    /** @param array<string,mixed> $source @return array{0:string,1:int,2:string} */
    private function retryOrderEnrichment(PDO $pdo, array $source, bool $repairIdentity): array
    {
        $externalId = (string) $source['external_resource_id'];
        if ($repairIdentity) {
            $externalId = $this->localIdentity($pdo, $source);
        }
        $generation = (int) $source['source_generation'] + 1;
        $stmt = $pdo->prepare(
            'UPDATE order_resource_enrichment_jobs j
             JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=?
             SET j.external_resource_id=?,j.status="retry",j.next_run_at=UTC_TIMESTAMP(),
                 j.lock_token=NULL,j.locked_at=NULL,j.heartbeat_at=NULL,j.lease_generation=?
             WHERE j.id=? AND j.meli_account_id=? AND j.status=? AND j.lease_generation=?'
        );
        $stmt->execute([
            (int) $source['company_id'], $externalId, $generation, (int) $source['id'],
            (int) $source['meli_account_id'], (string) $source['source_status'], (int) $source['source_generation'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new HttpException(409, 'El trabajo cambió mientras se aplicaba la decisión. Recargue el detalle.');
        }
        return ['retry', $generation, $repairIdentity
            ? 'La identidad se reconstruyó localmente. Cron reintentará únicamente este recurso.'
            : 'Este recurso quedó programado para el próximo Cron disponible.'];
    }

    /** @param array<string,mixed> $source @return array{0:string,1:int,2:string} */
    private function closeUnavailable(PDO $pdo, array $source): array
    {
        if ((string) ($source['queue_key'] ?? '') === 'notification_fallback') {
            if ((string) ($source['last_error_code'] ?? '') !== 'http_404'
                || (string) ($source['resource_type'] ?? '') !== 'question') {
                throw new HttpException(409, 'Solo una pregunta con 404 confirmado puede cerrarse como dato no disponible.');
            }
            $generation = (int) $source['source_generation'];
            $stmt = $pdo->prepare(
                'UPDATE meli_notification_work_items w
                 JOIN meli_accounts a ON a.id=w.meli_account_id AND a.company_id=?
                 SET w.status="complete",w.last_result="question_not_available",w.completed_at=COALESCE(w.completed_at,UTC_TIMESTAMP()),
                     w.locked_by=NULL,w.locked_at=NULL,w.lock_expires_at=NULL
                 WHERE w.id=? AND w.meli_account_id=? AND w.status=? AND w.attempts=?'
            );
            $stmt->execute([(int) $source['company_id'], (int) $source['id'], (int) $source['meli_account_id'],
                (string) $source['source_status'], $generation]);
            if ($stmt->rowCount() !== 1) {
                throw new HttpException(409, 'El trabajo cambió mientras se cerraba. Recargue el detalle.');
            }
            return ['complete', $generation, 'La pregunta quedó cerrada como dato no disponible. El evento y su diagnóstico se conservaron.'];
        }
        if ((string) ($source['queue_key'] ?? '') !== 'order_enrichment') {
            throw new HttpException(409, 'Esta cola no admite cierre exacto como dato no disponible.');
        }
        if ((string) ($source['last_error_code'] ?? '') !== 'http_404'
            && (string) ($source['failure_class'] ?? '') !== 'remote_absent') {
            throw new HttpException(409, 'Solo un 404 confirmado puede cerrarse como ausencia esperada.');
        }
        $generation = (int) $source['source_generation'] + 1;
        $stmt = $pdo->prepare(
            'UPDATE order_resource_enrichment_jobs j
             JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=?
             SET j.status="complete",j.completed_at=UTC_TIMESTAMP(),j.last_processed_at=UTC_TIMESTAMP(),
                 j.lock_token=NULL,j.locked_at=NULL,j.heartbeat_at=NULL,j.lease_generation=?
             WHERE j.id=? AND j.meli_account_id=? AND j.status=? AND j.lease_generation=?'
        );
        $stmt->execute([(int) $source['company_id'], $generation, (int) $source['id'], (int) $source['meli_account_id'],
            (string) $source['source_status'], (int) $source['source_generation']]);
        if ($stmt->rowCount() !== 1) {
            throw new HttpException(409, 'El trabajo cambió mientras se cerraba. Recargue el detalle.');
        }
        return ['complete', $generation, 'El recurso quedó cerrado como dato no disponible. La orden se conservó.'];
    }

    /** @param array<string,mixed> $source @return array{0:string,1:int,2:string} */
    private function skipCampaign(PDO $pdo, array $source, int $campaignId, int $itemId, int $userId): array
    {
        $this->lockCampaignItem($pdo, $source, $campaignId, $itemId, $userId);
        $stmt = $pdo->prepare(
            'UPDATE manual_campaign_items
             SET status="skipped",failed_units=0,skipped_units=GREATEST(1,total_units),
                 lease_owner=NULL,lease_expires_at=NULL,completed_at=UTC_TIMESTAMP(),
                 result_summary="Omitido únicamente en esta campaña por decisión administrativa."
             WHERE id=? AND manual_campaign_id=? AND status="failed"'
        );
        $stmt->execute([$itemId, $campaignId]);
        if ($stmt->rowCount() !== 1) {
            throw new HttpException(409, 'El trabajo de la campaña cambió. Recargue el detalle.');
        }
        $this->releaseCampaignReservation($pdo, $campaignId, $itemId);
        $this->refreshCampaignCounters($pdo, $campaignId);
        $this->campaignEvent($pdo, $campaignId, $itemId, 'resolution_skipped', 'El trabajo se omitió solo en esta campaña. El recurso original se conservó.');
        return [(string) $source['source_status'], (int) $source['source_generation'], 'El trabajo se omitió solo en la campaña #' . $campaignId . '.'];
    }

    /** @param array<string,mixed> $source @return array{0:string,1:int,2:string} */
    private function returnToQueue(PDO $pdo, array $source, int $campaignId, int $itemId, int $userId): array
    {
        $this->lockCampaignItem($pdo, $source, $campaignId, $itemId, $userId);
        [$status, $generation] = $this->retryOrderEnrichment($pdo, $source, false);
        $stmt = $pdo->prepare(
            'UPDATE manual_campaign_items
             SET status="returned",failed_units=0,lease_owner=NULL,lease_expires_at=NULL,
                 completed_at=UTC_TIMESTAMP(),result_summary="Devuelto a la cola normal por decisión administrativa."
             WHERE id=? AND manual_campaign_id=? AND status="failed"'
        );
        $stmt->execute([$itemId, $campaignId]);
        if ($stmt->rowCount() !== 1) {
            throw new HttpException(409, 'El trabajo de la campaña cambió. Recargue el detalle.');
        }
        $this->releaseCampaignReservation($pdo, $campaignId, $itemId);
        $this->refreshCampaignCounters($pdo, $campaignId);
        $this->campaignEvent($pdo, $campaignId, $itemId, 'resolution_returned', 'El trabajo salió de la campaña y quedó listo para la cola normal.');
        return [$status, $generation, 'El recurso salió de la campaña #' . $campaignId . ' y quedó listo para Cron.'];
    }

    /** @param array<string,mixed> $source */
    private function lockCampaignItem(PDO $pdo, array $source, int $campaignId, int $itemId, int $userId): void
    {
        if ($campaignId < 1 || $itemId < 1) {
            throw new HttpException(422, 'No se identificó el trabajo exacto de la campaña.');
        }
        $stmt = $pdo->prepare(
            'SELECT i.id
             FROM manual_campaign_items i
             JOIN manual_campaigns c ON c.id=i.manual_campaign_id
             WHERE i.id=? AND i.manual_campaign_id=? AND i.queue_key=? AND i.source_id=?
               AND i.company_id=? AND i.meli_account_id=? AND i.status="failed"
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$itemId, $campaignId, 'order_enrichment', (string) $source['id'],
            (int) $source['company_id'], (int) $source['meli_account_id']]);
        if ($stmt->fetchColumn() === false) {
            throw new HttpException(404, 'No se encontró ese trabajo dentro de una campaña autorizada.');
        }
    }

    /** @param array<string,mixed> $source */
    private function localIdentity(PDO $pdo, array $source): string
    {
        $column = (string) $source['resource_type'] === 'shipment' ? 'external_shipping_id' : 'external_pack_id';
        $stmt = $pdo->prepare(
            'SELECT DISTINCT CAST(o.' . $column . ' AS CHAR) candidate
             FROM order_resource_enrichment_job_orders m
             JOIN meli_orders o ON o.id=m.meli_order_id AND o.meli_account_id=?
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE m.order_resource_enrichment_job_id=? AND o.' . $column . ' IS NOT NULL
             UNION
             SELECT DISTINCT CAST(o.' . $column . ' AS CHAR) candidate
             FROM meli_orders o JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE o.id=? AND o.meli_account_id=? AND o.' . $column . ' IS NOT NULL'
        );
        $stmt->execute([(int) $source['meli_account_id'], (int) $source['company_id'], (int) $source['id'],
            (int) $source['company_id'], (int) $source['meli_order_id'], (int) $source['meli_account_id']]);
        $candidates = array_values(array_unique(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)))));
        if (count($candidates) !== 1 || trim($candidates[0]) === '') {
            throw new HttpException(409, 'Las relaciones locales no contienen una única identidad segura para este recurso.');
        }
        $duplicate = $pdo->prepare(
            'SELECT id FROM order_resource_enrichment_jobs
             WHERE meli_account_id=? AND resource_type=? AND external_resource_id=? AND id<>? LIMIT 1'
        );
        $duplicate->execute([(int) $source['meli_account_id'], (string) $source['resource_type'], $candidates[0], (int) $source['id']]);
        if ($duplicate->fetchColumn() !== false) {
            throw new HttpException(409, 'La identidad correcta ya pertenece a otro trabajo. Revise ambos antes de continuar.');
        }
        return $candidates[0];
    }

    private function releaseCampaignReservation(PDO $pdo, int $campaignId, int $itemId): void
    {
        $pdo->prepare(
            'UPDATE manual_campaign_reservations SET status="released",released_at=UTC_TIMESTAMP(3)
             WHERE manual_campaign_id=? AND manual_campaign_item_id=? AND status="active"'
        )->execute([$campaignId, $itemId]);
    }

    /** @param array<string,mixed> $source */
    private function reconcileSourceCampaignItems(PDO $pdo, array $source, string $newStatus, string $message): void
    {
        if (!in_array($newStatus, ['retry', 'skipped'], true)) {
            throw new HttpException(409, 'El estado de campaña solicitado no es seguro.');
        }
        $select = $pdo->prepare(
            'SELECT i.id,i.manual_campaign_id
             FROM manual_campaign_items i
             JOIN manual_campaigns c ON c.id=i.manual_campaign_id
             WHERE i.queue_key="order_enrichment" AND i.source_id=?
               AND i.company_id=? AND i.meli_account_id=? AND i.status="failed"
               AND c.status IN ("active","paused","pausing")
             ORDER BY i.id FOR UPDATE'
        );
        $select->execute([
            (string) $source['id'],
            (int) $source['company_id'],
            (int) $source['meli_account_id'],
        ]);
        $items = $select->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as $item) {
            $itemId = (int) $item['id'];
            $campaignId = (int) $item['manual_campaign_id'];
            $update = $pdo->prepare(
                'UPDATE manual_campaign_items
                 SET status=?,source_resolution=?,failed_units=0,
                     skipped_units=IF(?="skipped",GREATEST(0,total_units-completed_units),0),
                     next_eligible_at=IF(?="retry",UTC_TIMESTAMP(3),NULL),
                     completed_at=IF(?="skipped",UTC_TIMESTAMP(3),NULL),
                     lease_owner=NULL,lease_expires_at=NULL,result_summary=?
                 WHERE id=? AND manual_campaign_id=? AND company_id=? AND meli_account_id=? AND status="failed"'
            );
            $update->execute([
                $newStatus,
                $newStatus === 'retry' ? 'remediation_retry' : 'skipped_expected',
                $newStatus,
                $newStatus,
                $newStatus,
                mb_substr($message, 0, 500),
                $itemId,
                $campaignId,
                (int) $source['company_id'],
                (int) $source['meli_account_id'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new HttpException(409, 'Un trabajo de campaña cambió durante la reparación. Recargue el detalle.');
            }
            if ($newStatus === 'skipped') {
                $this->releaseCampaignReservation($pdo, $campaignId, $itemId);
            }
            $this->refreshCampaignCounters($pdo, $campaignId);
            $this->campaignEvent(
                $pdo,
                $campaignId,
                $itemId,
                $newStatus === 'retry' ? 'resolution_retry' : 'resolution_expected_absence',
                $newStatus === 'retry'
                    ? 'El trabajo fuente fue reparado y este ítem volverá a Cron.'
                    : 'El recurso se cerró como ausencia esperada; la información comercial se conservó.'
            );
        }
    }

    private function refreshCampaignCounters(PDO $pdo, int $campaignId): void
    {
        $pdo->prepare(
            'UPDATE manual_campaigns c
             JOIN (
               SELECT manual_campaign_id,SUM(status="completed") completed_count,
                      SUM(status="skipped") skipped_count,SUM(status="failed") failed_count,
                      SUM(status IN ("waiting","retry")) retry_count,
                      SUM(CASE WHEN status="completed" THEN completed_units ELSE 0 END) completed_units_count,
                      SUM(CASE WHEN status="failed" THEN failed_units ELSE 0 END) failed_units_count,
                      SUM(CASE WHEN status="skipped" THEN skipped_units ELSE 0 END) skipped_units_count,
                      SUM(primary_calls) primary_count,SUM(derived_calls) derived_count
               FROM manual_campaign_items WHERE manual_campaign_id=? GROUP BY manual_campaign_id
             ) x ON x.manual_campaign_id=c.id
             SET c.completed_items=x.completed_count,c.skipped_items=x.skipped_count,c.failed_items=x.failed_count,
                 c.retry_items=x.retry_count,c.completed_units=x.completed_units_count,c.failed_units=x.failed_units_count,
                 c.skipped_units=x.skipped_units_count,c.primary_calls=x.primary_count,c.derived_calls=x.derived_count,
                 c.outbound_calls=x.primary_count+x.derived_count,
                 c.version_no=c.version_no+1
             WHERE c.id=?'
        )->execute([$campaignId, $campaignId]);
    }

    private function campaignEvent(PDO $pdo, int $campaignId, int $itemId, string $type, string $message): void
    {
        $pdo->prepare(
            'INSERT INTO manual_campaign_events
             (manual_campaign_id,event_type,severity,safe_message,manual_campaign_item_id,created_at)
             VALUES (?, ?, "info", ?, ?, UTC_TIMESTAMP(3))'
        )->execute([$campaignId, $type, $message, $itemId]);
    }

    /** @return array<string,mixed>|null */
    private function existingEvent(PDO $pdo, string $key, int $userId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT queue_key,source_id,action_key,previous_status,new_status,expected_generation,
                    resulting_generation,campaign_id,campaign_item_id,safe_message,result_status
             FROM system_work_resolution_events
             WHERE idempotency_key=? AND created_by=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$key, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $scope */
    private function campaignReplayMatches(
        PDO $pdo,
        array $scope,
        string $action,
        int $campaignId,
        int $campaignItemId
    ): bool {
        if (!in_array($action, ['skip_campaign', 'return_to_queue'], true)) {
            return true;
        }
        if ($campaignId < 1 || $campaignItemId < 1) {
            return false;
        }
        $expected = $action === 'skip_campaign' ? 'skipped' : 'returned';
        $stmt = $pdo->prepare(
            'SELECT i.status
             FROM manual_campaign_items i
             JOIN manual_campaigns c ON c.id=i.manual_campaign_id
             WHERE i.id=? AND i.manual_campaign_id=? AND i.queue_key=? AND i.source_id=?
               AND i.company_id=? AND i.meli_account_id=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([
            $campaignItemId,
            $campaignId,
            (string) ($scope['queue_key'] ?? ''),
            (string) ($scope['source_id'] ?? ''),
            (int) ($scope['company_id'] ?? 0),
            (int) ($scope['meli_account_id'] ?? 0),
        ]);
        return (string) $stmt->fetchColumn() === $expected;
    }

    /** @param array<string,mixed> $scope */
    private function insertStartedEvent(
        PDO $pdo,
        string $idempotencyKey,
        string $queueKey,
        string $sourceId,
        array $scope,
        string $action,
        string $previousStatus,
        int $generation,
        int $campaignId,
        int $campaignItemId,
        int $userId
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO system_work_resolution_events
             (idempotency_key,queue_key,source_id,company_id,meli_account_id,campaign_id,campaign_item_id,
              action_key,previous_status,new_status,expected_generation,resulting_generation,
              diagnostic_id,safe_message,result_status,created_by,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,"Acción exacta iniciada.","started",?,UTC_TIMESTAMP(3))'
        );
        $stmt->execute([
            $idempotencyKey, $queueKey, $sourceId, (int) ($scope['company_id'] ?? 0),
            (int) ($scope['meli_account_id'] ?? 0), $campaignId ?: null, $campaignItemId ?: null,
            $action, $previousStatus, $previousStatus, $generation, $scope['diagnostic_id'] ?? null, $userId,
        ]);
    }

    private function identifier(string $value, int $maximum, bool $numericAllowed = false): string
    {
        $value = trim($value);
        $pattern = $numericAllowed ? '/^[a-zA-Z0-9:_-]+$/' : '/^[a-z0-9_]+$/';
        if ($value === '' || strlen($value) > $maximum || preg_match($pattern, $value) !== 1) {
            throw new HttpException(422, 'La solicitud contiene un identificador no válido.');
        }
        return $value;
    }
}
