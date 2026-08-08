<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Cierra fuentes legacy solo después de que Cron V3 ganó el fencing del trabajo.
 *
 * No consulta Mercado Libre y no decide reintentos. Su único objetivo es que un
 * recurso legacy convertido a trabajo exacto V3 no quede como pendiente
 * perpetuo después de una respuesta remota conocida.
 */
final class CronV3LegacySourceFinalizer
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    public function finalize(WorkEnvelope $work, WorkResult $result): bool
    {
        [$legacyQueue, $sourceId] = $this->legacySource($work);
        if ($legacyQueue === '' || $sourceId < 1) {
            return true;
        }
        $sourceStatus = (string) ($work->payload['source_status'] ?? '');
        $sourceGeneration = (int) ($work->payload['source_generation'] ?? -1);
        if ($legacyQueue === 'notification_fallback' && ($sourceStatus === '' || $sourceGeneration < 0)) {
            return false;
        }

        try {
            $ok = $legacyQueue === 'notification_fallback'
                ? $this->finalizeNotificationFallback($work, $result, $sourceId, $sourceStatus, $sourceGeneration)
                : $this->finalizeImportedLegacySource($work, $result, $legacyQueue, $sourceId);
            if ($ok) {
                return true;
            }
            $this->recordPendingReconciliation($work, $sourceId, 'legacy_source_changed', $legacyQueue);
            return true;
        } catch (Throwable) {
            $this->recordPendingReconciliation($work, $sourceId, 'legacy_finalizer_failed', $legacyQueue);
            return true;
        }
    }

    /** @return array{0:string,1:int} */
    private function legacySource(WorkEnvelope $work): array
    {
        $queue = (string) ($work->payload['legacy_queue'] ?? '');
        $sourceId = (int) ($work->payload['legacy_job_id'] ?? $work->payload['notification_work_item_id'] ?? 0);
        if ($queue !== '' && $sourceId > 0) {
            return [$queue, $sourceId];
        }
        $sourceRef = (string) ($work->sourceRef ?? '');
        if (preg_match('/^legacy:([a-z0-9_]+):([0-9]+)$/', $sourceRef, $match) === 1) {
            return [(string) $match[1], (int) $match[2]];
        }

        return ['', 0];
    }

    private function finalizeNotificationFallback(
        WorkEnvelope $work,
        WorkResult $result,
        int $sourceId,
        string $sourceStatus,
        int $sourceGeneration
    ): bool
    {
        $pdo = $this->pdo ?? Database::connection();
        $lock = $pdo->prepare(
            'SELECT w.id,w.status,w.latest_event_id,w.attempts
             FROM meli_notification_work_items w
             INNER JOIN meli_accounts a ON a.id=w.meli_account_id AND a.company_id=?
             WHERE w.id=? AND w.meli_account_id=? AND w.status=? AND w.attempts=?
             FOR UPDATE'
        );
        $lock->execute([$work->companyId, $sourceId, $work->meliAccountId, $sourceStatus, $sourceGeneration]);
        $source = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($source)) {
            return false;
        }
        $status = (string) ($source['status'] ?? '');
        if (in_array($status, ['complete', 'ignored', 'cancelled'], true)) {
            return true;
        }
        if (!in_array($status, ['pending', 'retry', 'error', 'running'], true)) {
            return true;
        }

        $eventId = (int) ($source['latest_event_id'] ?? 0);
        $disposition = $result->status === 'completed'
            ? (!empty($result->metadata['remote_absent']) ? 'cron_v3_remote_absent' : 'cron_v3_' . $work->workType . '_completed')
            : 'cron_v3_' . $result->status;
        $disposition = substr(preg_replace('/[^a-z0-9_:-]/i', '_', $disposition) ?: 'cron_v3_completed', 0, 40);

        if ($result->status === 'completed') {
            $eventSql =
                'UPDATE meli_notification_events
                 SET status="processed",disposition=?,processed_at=COALESCE(processed_at,UTC_TIMESTAMP()),
                     error_message=NULL,process_after=NULL
                 WHERE work_item_id=? AND status NOT IN ("duplicate","ignored")';
            $params = [$disposition, $sourceId];
            if ($eventId > 0) {
                $eventSql .= ' AND id<=?';
                $params[] = $eventId;
            }
            $pdo->prepare($eventSql)->execute($params);

            $update = $pdo->prepare(
                'UPDATE meli_notification_work_items
                 SET status="complete",locked_by=NULL,locked_at=NULL,lock_expires_at=NULL,
                     last_error_code=NULL,last_error_message=NULL,last_error_diagnostic_id=NULL,last_error_stage=NULL,
                     last_result=?,last_processed_at=UTC_TIMESTAMP(),last_success_at=UTC_TIMESTAMP(),
                     completed_at=UTC_TIMESTAMP(),processing_event_id=NULL,rerun_requested=0,consecutive_failures=0
                 WHERE id=? AND meli_account_id=? AND status=? AND attempts=?'
            );
            $update->execute([$disposition, $sourceId, $work->meliAccountId, $sourceStatus, $sourceGeneration]);
            return $update->rowCount() === 1;
        }

        $next = $result->availableAt ?? gmdate('Y-m-d H:i:s', time() + 60);
        $code = $result->code ?? 'cron_v3_deferred';
        $update = $pdo->prepare(
            'UPDATE meli_notification_work_items
             SET status=IF(? IN ("review","dead"),"error","retry"),
                 locked_by=NULL,locked_at=NULL,lock_expires_at=NULL,
                 next_run_at=?,last_error_code=?,last_error_message=?,
                 last_error_diagnostic_id=COALESCE(last_error_diagnostic_id,?),
                 last_error_stage=?,
                 last_processed_at=UTC_TIMESTAMP(),processing_event_id=NULL
             WHERE id=? AND meli_account_id=? AND status=? AND attempts=?'
        );
        $diagnostic = 'V3-' . gmdate('Ymd-His') . '-' . substr(hash('sha256', $work->dedupeKey), 0, 6);
        $update->execute([
            $result->status,
            $next,
            $code,
            $result->status === 'review'
                ? 'Cron V3 dejó el recurso en revisión segura.'
                : 'Cron V3 aplazó el recurso para el próximo ciclo seguro.',
            $diagnostic,
            $result->status === 'review' ? 'remote_result_uncertain' : 'policy',
            $sourceId,
            $work->meliAccountId,
            $sourceStatus,
            $sourceGeneration,
        ]);

        return $update->rowCount() === 1;
    }

    private function finalizeImportedLegacySource(WorkEnvelope $work, WorkResult $result, string $queue, int $sourceId): bool
    {
        $pdo = $this->pdo ?? Database::connection();
        $completed = $result->status === 'completed';
        $message = $completed
            ? null
            : ($result->status === 'review'
                ? 'Cron V3 dejó el recurso en revisión segura.'
                : 'Cron V3 aplazó el recurso para el próximo ciclo seguro.');

        if (in_array($queue, ['pack_exact', 'shipment_exact'], true)) {
            $resourceType = $queue === 'shipment_exact' ? 'shipment' : 'pack';
            $status = $this->scalar(
                'SELECT j.status
                 FROM order_resource_enrichment_jobs j
                 JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=?
                 WHERE j.id=? AND j.meli_account_id=? AND j.resource_type=? FOR UPDATE',
                [$work->companyId, $sourceId, $work->meliAccountId, $resourceType]
            );
            if ($status === null || in_array($status, ['complete', 'cancelled'], true)) {
                return $status !== null;
            }
            $statement = $pdo->prepare(
                $completed
                    ? 'UPDATE order_resource_enrichment_jobs
                       SET status="complete",lock_token=NULL,locked_at=NULL,last_error_message=NULL,
                           last_processed_at=UTC_TIMESTAMP(),completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                       WHERE id=? AND meli_account_id=? AND resource_type=? AND status=?'
                    : 'UPDATE order_resource_enrichment_jobs
                       SET status=?,lock_token=NULL,locked_at=NULL,next_run_at=?,last_error_message=?,updated_at=UTC_TIMESTAMP()
                       WHERE id=? AND meli_account_id=? AND resource_type=? AND status=?'
            );
            $completed
                ? $statement->execute([$sourceId, $work->meliAccountId, $resourceType, $status])
                : $statement->execute([
                    in_array($result->status, ['review', 'dead'], true) ? 'error' : 'retry',
                    $result->availableAt ?? gmdate('Y-m-d H:i:s', time() + 60),
                    $message,
                    $sourceId,
                    $work->meliAccountId,
                    $resourceType,
                    $status,
                ]);
            return $statement->rowCount() === 1;
        }

        if ($queue === 'orders_search_page') {
            $status = $this->scalar(
                'SELECT c.status
                 FROM sync_batch_chunks c
                 JOIN meli_accounts a ON a.id=c.meli_account_id AND a.company_id=?
                 WHERE c.id=? AND c.meli_account_id=? AND c.sync_type="orders" FOR UPDATE',
                [$work->companyId, $sourceId, $work->meliAccountId]
            );
            if ($status === null || in_array($status, ['complete', 'empty', 'cancelled'], true)) {
                return $status !== null;
            }
            $nextOffset = $result->metadata['next_offset'] ?? null;
            $orderCount = max(0, (int) ($result->metadata['order_count'] ?? 0));
            $newStatus = $completed
                ? ($nextOffset === null ? ($orderCount > 0 ? 'complete' : 'empty') : 'partial')
                : (in_array($result->status, ['review', 'dead'], true) ? 'error' : 'partial');
            $statement = $pdo->prepare(
                'UPDATE sync_batch_chunks
                 SET status=?,processed_count=processed_count+?,cursor_offset=COALESCE(?,cursor_offset),
                     next_run_at=IF(? IN ("partial","error"),?,next_run_at),
                     completed_at=IF(? IN ("complete","empty"),UTC_TIMESTAMP(),completed_at),
                     last_error=?,updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND meli_account_id=? AND status=?'
            );
            $statement->execute([
                $newStatus,
                $completed ? $orderCount : 0,
                $nextOffset,
                $newStatus,
                $result->availableAt ?? gmdate('Y-m-d H:i:s', time() + 60),
                $newStatus,
                $completed ? null : $message,
                $sourceId,
                $work->meliAccountId,
                $status,
            ]);
            return $statement->rowCount() === 1;
        }

        if ($queue === 'items_search_page') {
            $phase = $this->scalar(
                'SELECT j.phase
                 FROM meli_item_sync_jobs j
                 JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=?
                 WHERE j.id=? AND j.meli_account_id=? FOR UPDATE',
                [$work->companyId, $sourceId, $work->meliAccountId]
            );
            if ($phase === null || in_array($phase, ['complete', 'cancelled'], true)) {
                return $phase !== null;
            }
            $nextOffset = $result->metadata['next_offset'] ?? null;
            $itemCount = max(0, (int) ($result->metadata['item_count'] ?? 0));
            $newPhase = $completed
                ? ($nextOffset === null ? 'complete' : 'partial')
                : (in_array($result->status, ['review', 'dead'], true) ? 'error' : 'partial');
            $statement = $pdo->prepare(
                'UPDATE meli_item_sync_jobs
                 SET phase=?,processed_count=processed_count+?,offset_value=COALESCE(?,offset_value),
                     next_run_at=IF(? IN ("partial","error"),?,next_run_at),
                     last_error_message=?,completed_at=IF(?="complete",UTC_TIMESTAMP(),completed_at),
                     updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND meli_account_id=? AND phase=?'
            );
            $statement->execute([
                $newPhase,
                $completed ? $itemCount : 0,
                $nextOffset,
                $newPhase,
                $result->availableAt ?? gmdate('Y-m-d H:i:s', time() + 60),
                $completed ? null : $message,
                $newPhase,
                $sourceId,
                $work->meliAccountId,
                $phase,
            ]);
            return $statement->rowCount() === 1;
        }

        if ($queue === 'catalog_description_exact') {
            $status = $this->scalar(
                'SELECT i.status
                 FROM catalog_description_job_items i
                 JOIN meli_accounts a ON a.id=i.meli_account_id AND a.company_id=?
                 WHERE i.id=? AND i.meli_account_id=? FOR UPDATE',
                [$work->companyId, $sourceId, $work->meliAccountId]
            );
            if ($status === null || in_array($status, ['confirmed', 'unavailable', 'skipped'], true)) {
                return $status !== null;
            }
            $sourceStatus = strtolower((string) ($result->metadata['source_status'] ?? 'confirmed'));
            $newStatus = $completed
                ? (in_array($sourceStatus, ['missing', 'not_found', 'unavailable'], true) ? 'unavailable' : 'confirmed')
                : (in_array($result->status, ['review', 'dead'], true) ? 'error' : 'retry');
            $statement = $pdo->prepare(
                'UPDATE catalog_description_job_items
                 SET status=?,next_retry_at=IF(? IN ("retry","error"),?,next_retry_at),
                     safe_error_message=?,processed_at=IF(? IN ("confirmed","unavailable"),UTC_TIMESTAMP(),processed_at),
                     updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND meli_account_id=? AND status=?'
            );
            $statement->execute([
                $newStatus,
                $newStatus,
                $result->availableAt ?? gmdate('Y-m-d H:i:s', time() + 60),
                $completed ? null : $message,
                $newStatus,
                $sourceId,
                $work->meliAccountId,
                $status,
            ]);
            return $statement->rowCount() === 1;
        }

        if ($queue === 'financial_recalc') {
            $status = $this->scalar(
                'SELECT j.status
                 FROM order_financial_recalc_jobs j
                 JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=?
                 WHERE j.id=? AND j.meli_account_id=? FOR UPDATE',
                [$work->companyId, $sourceId, $work->meliAccountId]
            );
            if ($status === null || in_array($status, ['complete', 'cancelled'], true)) {
                return $status !== null;
            }
            $statement = $pdo->prepare(
                'UPDATE order_financial_recalc_jobs
                 SET status=?,safe_message=?,completed_at=IF(?="complete",UTC_TIMESTAMP(),completed_at)
                 WHERE id=? AND meli_account_id=? AND status=?'
            );
            $newStatus = $completed ? 'complete' : 'error';
            $statement->execute([$newStatus, $completed ? null : $message, $newStatus, $sourceId, $work->meliAccountId, $status]);
            return $statement->rowCount() === 1;
        }

        if ($queue === 'sale_billing_capture') {
            $status = $this->scalar(
                'SELECT j.status
                 FROM sale_financial_reconciliation_jobs j
                 JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=j.company_id
                 WHERE j.id=? AND j.company_id=? AND j.meli_account_id=? FOR UPDATE',
                [$sourceId, $work->companyId, $work->meliAccountId]
            );
            if ($status === null || in_array($status, ['complete', 'cancelled'], true)) {
                return $status !== null;
            }
            $statement = $pdo->prepare(
                'UPDATE sale_financial_reconciliation_jobs
                 SET status=?,lock_owner=NULL,lease_expires_at=NULL,heartbeat_at=NULL,
                     next_run_at=IF(? IN ("retry","error"),?,next_run_at),
                     safe_message=?,completed_at=IF(?="complete",UTC_TIMESTAMP(),completed_at),
                     updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND company_id=? AND meli_account_id=? AND status=?'
            );
            $newStatus = $completed ? 'complete' : (in_array($result->status, ['review', 'dead'], true) ? 'error' : 'retry');
            $statement->execute([
                $newStatus,
                $newStatus,
                $result->availableAt ?? gmdate('Y-m-d H:i:s', time() + 60),
                $completed ? null : $message,
                $newStatus,
                $sourceId,
                $work->companyId,
                $work->meliAccountId,
                $status,
            ]);
            return $statement->rowCount() === 1;
        }

        return true;
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): ?string
    {
        $statement = ($this->pdo ?? Database::connection())->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function recordPendingReconciliation(WorkEnvelope $work, int $sourceId, string $reason, string $legacyQueue = 'notification_fallback'): void
    {
        try {
            $pdo = $this->pdo ?? Database::connection();
            $statement = $pdo->prepare(
                'INSERT INTO cron_v3_legacy_reconciliation
                 (work_id,company_id,meli_account_id,legacy_queue,source_id,status,reason_code,safe_message)
                 VALUES (?,?,?,?,?,"pending",?,?)
                 ON DUPLICATE KEY UPDATE
                   status=IF(status="completed",status,"pending"),
                   reason_code=VALUES(reason_code),
                   safe_message=VALUES(safe_message),
                   attempts=attempts+1,
                   updated_at=UTC_TIMESTAMP(3)'
            );
            $statement->execute([
                (int) ($work->id ?? 0),
                $work->companyId,
                $work->meliAccountId,
                $legacyQueue,
                $sourceId,
                $reason,
                'El resultado V3 fue confirmado; falta reconciliar la fuente legacy sin repetir la consulta remota.',
            ]);
        } catch (Throwable) {
            // El resultado V3 ya quedó cercado. Esta reconciliación es auxiliar.
        }
    }
}
