<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use PDO;
use Throwable;

final class SyncQueueService
{
    public function processDue(?int $limit = null): array
    {
        $settings = new SyncSettingsService();
        $limit = $limit ?: $settings->queueMaxChunksPerRun();
        $reservationFilter = (new SchemaInspectorService())->hasTable('manual_campaign_reservations')
            ? ' AND NOT EXISTS (
                 SELECT 1 FROM manual_campaign_reservations r
                 WHERE r.queue_key="orders_sync" AND r.source_id=CAST(ch.id AS CHAR)
                   AND r.status="active" AND r.expires_at>UTC_TIMESTAMP(3)
               )'
            : '';
        $stmt = Database::connection()->prepare(
            'SELECT ch.*,b.period_year,b.period_month,b.chunk_mode,b.chunk_parts,a.account_name
             FROM sync_batch_chunks ch
             JOIN sync_batches b ON b.id=ch.sync_batch_id
             JOIN meli_accounts a ON a.id=ch.meli_account_id
             WHERE ch.sync_type="orders" AND ch.status IN ("queued","partial") AND (ch.next_run_at IS NULL OR ch.next_run_at<=UTC_TIMESTAMP())'
             . $reservationFilter . '
             ORDER BY ch.next_run_at IS NULL DESC, ch.next_run_at ASC, ch.id ASC
             LIMIT ' . (int) $limit
        );
        $stmt->execute();
        $chunks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $summary = $this->emptySummary();
        foreach ($chunks as $chunk) {
            $result = $this->processChunk($chunk);
            $summary = $this->addResultToSummary($summary, $chunk, $result);
        }
        if ((int) $summary['processed_chunks'] === 0) {
            $summary['message'] = 'No hay bloques vencidos para procesar ahora.';
            $summary['queue'] = $this->queueSnapshot();
            $summary['stop_reason'] = 'no_pending_jobs';
        }
        return $summary;
    }

    public function processReady(int $accountId = 0, bool $includeScheduled = false): array
    {
        $chunk = $includeScheduled ? $this->nextQueuedChunk($accountId) : $this->nextDueChunk($accountId);
        if (!$chunk) {
            $message = $includeScheduled
                ? ($accountId > 0 ? 'No hay bloques en cola para esta cuenta.' : 'No hay bloques en cola para procesar.')
                : 'No hay bloques vencidos para procesar ahora.';
            $summary = $this->emptySummary($message, $this->queueSnapshot($accountId));
            $summary['stop_reason'] = 'no_pending_jobs';
            return $summary;
        }
        return $this->summaryFromResult($chunk, $this->processChunk($chunk));
    }

    public function processOne(
        ?int $chunkId = null,
        int $accountId = 0,
        ?int $maxApiPages = null,
        bool $allowInlineEnrichment = true
    ): array
    {
        if ($chunkId !== null && $chunkId > 0) {
            $accountFilter = $accountId > 0 ? ' AND ch.meli_account_id=:account' : '';
            $stmt = Database::connection()->prepare(
                'SELECT ch.*,b.period_year,b.period_month,b.chunk_mode,b.chunk_parts,a.account_name
                 FROM sync_batch_chunks ch
                 JOIN sync_batches b ON b.id=ch.sync_batch_id
                 JOIN meli_accounts a ON a.id=ch.meli_account_id
                 WHERE ch.id=:id AND ch.status IN ("queued","partial")' . $accountFilter . ' LIMIT 1'
            );
            $params = ['id' => $chunkId];
            if ($accountId > 0) {
                $params['account'] = $accountId;
            }
            $stmt->execute($params);
            $chunk = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$chunk) {
                return $this->emptySummary('El bloque no está listo para procesar.');
            }
            return $this->summaryFromResult(
                $chunk,
                $this->processChunk($chunk, $maxApiPages, $allowInlineEnrichment)
            );
        }
        $where = ['ch.sync_type="orders"', 'ch.status IN ("queued","partial")'];
        if ((new SchemaInspectorService())->hasTable('manual_campaign_reservations')) {
            $where[] = 'NOT EXISTS (
                SELECT 1 FROM manual_campaign_reservations r
                WHERE r.queue_key="orders_sync" AND r.source_id=CAST(ch.id AS CHAR)
                  AND r.status="active" AND r.expires_at>UTC_TIMESTAMP(3)
            )';
        }
        $params = [];
        if ($accountId > 0) {
            $where[] = 'ch.meli_account_id=:account';
            $params['account'] = $accountId;
        }
        $stmt = Database::connection()->prepare(
            'SELECT ch.*,b.period_year,b.period_month,b.chunk_mode,b.chunk_parts,a.account_name
             FROM sync_batch_chunks ch
             JOIN sync_batches b ON b.id=ch.sync_batch_id
             JOIN meli_accounts a ON a.id=ch.meli_account_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ch.next_run_at IS NULL DESC, ch.next_run_at ASC, ch.id ASC
             LIMIT 1'
        );
        $stmt->execute($params);
        $chunk = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$chunk) {
            $summary = $this->emptySummary($accountId > 0 ? 'No hay bloques listos para esta cuenta.' : 'No hay bloques en cola para procesar.', $this->queueSnapshot($accountId));
            $summary['stop_reason'] = 'no_pending_jobs';
            return $summary;
        }
        return $this->summaryFromResult(
            $chunk,
            $this->processChunk($chunk, $maxApiPages, $allowInlineEnrichment)
        );
    }

    public function resumePending(int $accountId = 0): int
    {
        $where = ['sync_type="orders"', 'status="pending"'];
        $params = [];
        if ($accountId > 0) {
            $where[] = 'meli_account_id=:account';
            $params['account'] = $accountId;
        }
        $stmt = Database::connection()->prepare('UPDATE sync_batch_chunks SET status="queued",next_run_at=UTC_TIMESTAMP(),blocked_reason=NULL WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function retryFailed(int $accountId = 0): int
    {
        $where = ['sync_type="orders"', 'status="error"'];
        $params = [];
        if ($accountId > 0) {
            $where[] = 'meli_account_id=:account';
            $params['account'] = $accountId;
        }
        $stmt = Database::connection()->prepare(
            'UPDATE sync_batch_chunks
             SET status="queued",next_run_at=UTC_TIMESTAMP(),last_error=NULL,blocked_reason=NULL,
                 error_type=NULL,error_http_status=NULL,error_endpoint=NULL,error_recommendation=NULL
             WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function repairStalled(int $accountId = 0): int
    {
        $where = ['sync_type="orders"', 'status="running"', '(started_at IS NULL OR started_at < UTC_TIMESTAMP() - INTERVAL 30 MINUTE)'];
        $params = [];
        if ($accountId > 0) {
            $where[] = 'meli_account_id=:account';
            $params['account'] = $accountId;
        }
        $stmt = Database::connection()->prepare('UPDATE sync_batch_chunks SET status="partial",next_run_at=UTC_TIMESTAMP(),blocked_reason="Bloque running recuperado por modo asistido." WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function processChunk(
        array $chunk,
        ?int $maxApiPages = null,
        bool $allowInlineEnrichment = true
    ): array
    {
        $pdo = Database::connection();
        $chunkId = (int) $chunk['id'];
        $accountId = (int) $chunk['meli_account_id'];
        $from = !empty($chunk['utc_from'])
            ? new DateTimeImmutable((string) $chunk['utc_from'], new \DateTimeZone('UTC'))
            : new DateTimeImmutable((string) $chunk['date_from'], new \DateTimeZone(DateTimePresenter::timezone()));
        $to = !empty($chunk['utc_to'])
            ? new DateTimeImmutable((string) $chunk['utc_to'], new \DateTimeZone('UTC'))
            : new DateTimeImmutable((string) $chunk['date_to'], new \DateTimeZone(DateTimePresenter::timezone()));
        $offsetBefore = (int) $chunk['cursor_offset'];
        $settings = new SyncSettingsService();
        $lock = new SyncLockService();
        $lockId = 0;
        $runId = 0;
        try {
            $manualExecution = (string) (ApiExecutionMetadataContext::current()['source'] ?? '') === 'manual_campaign';
            $reservationReady = (new SchemaInspectorService())->hasTable('manual_campaign_reservations');
            $claim = $pdo->prepare(
                'UPDATE sync_batch_chunks
                 SET status="running",started_at=UTC_TIMESTAMP(),attempt_count=attempt_count+1
                 WHERE id=:id AND status IN ("queued","partial")'
                 . ($manualExecution || !$reservationReady ? '' : '
                   AND NOT EXISTS (
                     SELECT 1 FROM manual_campaign_reservations r
                     WHERE r.queue_key="orders_sync" AND r.source_id=CAST(sync_batch_chunks.id AS CHAR)
                       AND r.status="active" AND r.expires_at>UTC_TIMESTAMP(3)
                   )')
            );
            $claim->execute(['id' => $chunkId]);
            if ($claim->rowCount() === 0) {
                return ['status' => 'skipped', 'processed' => 0];
            }
            $lockId = $lock->acquire($accountId, 'orders', $from, $to, 30);
            $pdo->prepare(
                'INSERT INTO sync_chunk_runs (sync_batch_chunk_id,meli_account_id,status,cursor_offset_before,started_at)
                 VALUES (:chunk,:account,"running",:offset,UTC_TIMESTAMP())'
            )->execute(['chunk' => $chunkId, 'account' => $accountId, 'offset' => $offsetBefore]);
            $runId = (int) $pdo->lastInsertId();
            $result = (new OrderSyncService($accountId))->syncRangeChunk(
                $from,
                $to,
                $offsetBefore,
                $maxApiPages,
                $allowInlineEnrichment
            );
            $status = $result['status'] === 'complete' ? 'complete' : 'partial';
            $nextRun = $status === 'partial' ? gmdate('Y-m-d H:i:s', time() + ($settings->continuationDelayMinutes() * 60)) : null;
            $totalWarning = null;
            $previousTotal = (int) ($chunk['estimated_total'] ?? 0);
            $currentTotal = (int) ($result['total'] ?? 0);
            if ($previousTotal > 0 && $currentTotal > 0 && $previousTotal !== $currentTotal) {
                $totalWarning = 'El total remoto cambió durante la sincronización: antes ' . $previousTotal . ', ahora ' . $currentTotal . '. Recomendado ejecutar auditoría exacta.';
                Logger::write('warning', 'Total remoto cambió durante bloque de sincronización.', [
                    'chunk_id' => $chunkId,
                    'account_id' => $accountId,
                    'previous_total' => $previousTotal,
                    'current_total' => $currentTotal,
                ]);
            }
            $pdo->prepare(
                'UPDATE sync_batch_chunks
                 SET status=:status,processed_count=processed_count+:processed,cursor_offset=:offset,estimated_total=:total,
                     next_run_at=:next_run,completed_at=IF(:status_complete=1,UTC_TIMESTAMP(),completed_at),last_error=NULL,blocked_reason=:blocked_reason,
                     error_type=NULL,error_http_status=NULL,error_endpoint=NULL,error_recommendation=NULL
                 WHERE id=:id'
            )->execute([
                'status' => $status,
                'processed' => (int) $result['processed'],
                'offset' => (int) $result['offset_after'],
                'total' => (int) $result['total'],
                'next_run' => $nextRun,
                'status_complete' => $status === 'complete' ? 1 : 0,
                'blocked_reason' => $totalWarning,
                'id' => $chunkId,
            ]);
            $pdo->prepare(
                'UPDATE sync_chunk_runs SET status=:status,processed_count=:processed,cursor_offset_after=:offset,total_remote=:total,finished_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute([
                'status' => $status === 'complete' ? 'success' : 'partial',
                'processed' => (int) $result['processed'],
                'offset' => (int) $result['offset_after'],
                'total' => (int) $result['total'],
                'id' => $runId,
            ]);
            (new SyncCoverageService())->markChunk($accountId, $from, $to, $status, (int) $result['offset_after'], $chunkId);
            (new SyncCenterService())->refreshBatchStatusByChunk($chunkId);
            return ['status' => $status, 'processed' => (int) $result['processed'], 'offset_after' => (int) $result['offset_after'], 'total' => (int) $result['total'], 'next_run_at' => $nextRun, 'total_warning' => $totalWarning];
        } catch (Throwable $e) {
            $classified = SyncErrorClassifier::classify($e);
            $message = $classified['message'];
            $isApiPaused = in_array($classified['type'], ['api_circuit_open', 'api_budget_exhausted', 'api_manual_pause'], true);
            $pdo->prepare(
                'UPDATE sync_batch_chunks
                 SET status=:status,last_error=:error,blocked_reason=:blocked,next_run_at=:next_run,
                     error_type=:type,error_http_status=:http,error_endpoint=:endpoint,error_recommendation=:recommendation
                 WHERE id=:id'
            )->execute([
                'status' => $isApiPaused ? 'partial' : 'error',
                'error' => $message,
                'blocked' => $isApiPaused ? $message : null,
                'next_run' => $isApiPaused ? gmdate('Y-m-d H:i:s', time() + ($settings->continuationDelayMinutes() * 60)) : null,
                'type' => $classified['type'],
                'http' => $classified['http_status'],
                'endpoint' => $classified['endpoint'],
                'recommendation' => $classified['recommendation'],
                'id' => $chunkId,
            ]);
            if ($runId > 0) {
                $pdo->prepare('UPDATE sync_chunk_runs SET status="error",error_message=:error,finished_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['error' => $message, 'id' => $runId]);
            }
            Logger::write('error', 'Error procesando bloque de sincronización.', ['chunk_id' => $chunkId, 'account_id' => $accountId, 'error' => $message, 'type' => $classified['type'], 'http_status' => $classified['http_status'], 'endpoint' => $classified['endpoint']]);
            (new SyncCoverageService())->markChunk($accountId, $from, $to, 'error', 0, $chunkId);
            (new SyncCenterService())->refreshBatchStatusByChunk($chunkId);
            return ['status' => $isApiPaused ? 'partial' : 'error', 'processed' => 0, 'error' => $classified];
        } finally {
            if ($lockId > 0) {
                $lock->release($lockId);
            }
        }
    }

    private function nextDueChunk(int $accountId = 0): ?array
    {
        $where = ['ch.sync_type="orders"', 'ch.status IN ("queued","partial")', '(ch.next_run_at IS NULL OR ch.next_run_at<=UTC_TIMESTAMP())'];
        $params = [];
        if ($accountId > 0) {
            $where[] = 'ch.meli_account_id=:account';
            $params['account'] = $accountId;
        }
        $stmt = Database::connection()->prepare(
            'SELECT ch.*,b.period_year,b.period_month,b.chunk_mode,b.chunk_parts,a.account_name
             FROM sync_batch_chunks ch
             JOIN sync_batches b ON b.id=ch.sync_batch_id
             JOIN meli_accounts a ON a.id=ch.meli_account_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ch.next_run_at IS NULL DESC, ch.next_run_at ASC, ch.id ASC LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function nextQueuedChunk(int $accountId = 0): ?array
    {
        $where = ['ch.sync_type="orders"', 'ch.status IN ("queued","partial")'];
        $params = [];
        if ($accountId > 0) {
            $where[] = 'ch.meli_account_id=:account';
            $params['account'] = $accountId;
        }
        $stmt = Database::connection()->prepare(
            'SELECT ch.*,b.period_year,b.period_month,b.chunk_mode,b.chunk_parts,a.account_name
             FROM sync_batch_chunks ch
             JOIN sync_batches b ON b.id=ch.sync_batch_id
             JOIN meli_accounts a ON a.id=ch.meli_account_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ch.next_run_at IS NULL DESC, ch.next_run_at ASC, ch.id ASC LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function queueSnapshot(int $accountId = 0): array
    {
        $where = ['sync_type="orders"', 'status IN ("queued","partial")'];
        $params = [];
        if ($accountId > 0) {
            $where[] = 'meli_account_id=:account';
            $params['account'] = $accountId;
        }
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) total,
                    SUM(next_run_at IS NULL OR next_run_at<=UTC_TIMESTAMP()) overdue,
                    MIN(next_run_at) next_run_at
             FROM sync_batch_chunks WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => (int) ($row['total'] ?? 0),
            'overdue' => (int) ($row['overdue'] ?? 0),
            'next_run_at' => $row['next_run_at'] ?? null,
            'next_run_at_local' => DateTimePresenter::formatQueue($row['next_run_at'] ?? null),
        ];
    }

    private function emptySummary(string $message = '', ?array $queue = null): array
    {
        $summary = ['processed_chunks' => 0, 'completed_chunks' => 0, 'partial_chunks' => 0, 'error_chunks' => 0, 'orders' => 0];
        if ($message !== '') {
            $summary['message'] = $message;
        }
        if ($queue !== null) {
            $summary['queue'] = $queue;
        }
        return $summary;
    }

    private function summaryFromResult(array $chunk, array $result): array
    {
        return $this->addResultToSummary($this->emptySummary(), $chunk, $result);
    }

    private function addResultToSummary(array $summary, array $chunk, array $result): array
    {
        $summary['processed_chunks'] = (int) $summary['processed_chunks'] + 1;
        $summary['completed_chunks'] = (int) $summary['completed_chunks'] + ($result['status'] === 'complete' ? 1 : 0);
        $summary['partial_chunks'] = (int) $summary['partial_chunks'] + ($result['status'] === 'partial' ? 1 : 0);
        $summary['error_chunks'] = (int) $summary['error_chunks'] + ($result['status'] === 'error' ? 1 : 0);
        $summary['orders'] = (int) $summary['orders'] + (int) $result['processed'];
        $summary['last_chunk'] = $this->chunkInfo($chunk);
        $summary['last_result'] = $result;
        $summary['progress'] = $this->progressInfo($chunk, $result);
        if (isset($result['error'])) {
            $summary['last_error'] = $result['error'];
            $summary['message'] = $result['error']['message'] ?? 'Error procesando bloque.';
            $summary['stop_reason'] = match ($result['error']['type'] ?? '') {
                'api_circuit_open' => 'circuit_breaker',
                'api_budget_exhausted' => 'api_budget',
                'api_manual_pause' => 'manual_pause',
                default => 'error',
            };
        } elseif (!empty($result['next_run_at'])) {
            $summary['message'] = 'Bloque parcial. Continuación programada para ' . DateTimePresenter::formatQueue($result['next_run_at']) . '.';
            $summary['stop_reason'] = 'api_limit';
        } else {
            $summary['message'] = 'Bloque procesado correctamente.';
            $summary['stop_reason'] = 'continue';
        }
        $summary['queue'] = $this->queueSnapshot((int) ($chunk['meli_account_id'] ?? 0));
        return $summary;
    }

    private function chunkInfo(array $chunk): array
    {
        return [
            'id' => (int) ($chunk['id'] ?? 0),
            'account_id' => (int) ($chunk['meli_account_id'] ?? 0),
            'account_name' => (string) ($chunk['account_name'] ?? ''),
            'period_year' => (int) ($chunk['period_year'] ?? 0),
            'period_month' => (int) ($chunk['period_month'] ?? 0),
            'chunk_mode' => (string) ($chunk['chunk_mode'] ?? ''),
            'chunk_parts' => isset($chunk['chunk_parts']) ? (int) $chunk['chunk_parts'] : null,
            'sequence_no' => (int) ($chunk['sequence_no'] ?? 0),
            'date_from' => (string) ($chunk['date_from'] ?? ''),
            'date_to' => (string) ($chunk['date_to'] ?? ''),
            'next_run_at' => $chunk['next_run_at'] ?? null,
            'next_run_at_local' => DateTimePresenter::formatQueue($chunk['next_run_at'] ?? null),
        ];
    }

    private function progressInfo(array $chunk, array $result): array
    {
        $processedBefore = (int) ($chunk['processed_count'] ?? 0);
        $processedNow = (int) ($result['processed'] ?? 0);
        $total = (int) ($result['total'] ?? ($chunk['estimated_total'] ?? 0));
        $processedAfter = $processedBefore + $processedNow;
        $remainingOrders = $total > 0 ? max(0, $total - $processedAfter) : null;
        $remainingBlocks = 0;
        try {
            $stmt = Database::connection()->prepare(
                'SELECT COUNT(*) FROM sync_batch_chunks
                 WHERE sync_batch_id=:batch AND status IN ("queued","partial","pending","error") AND id<>:id'
            );
            $stmt->execute(['batch' => (int) ($chunk['sync_batch_id'] ?? 0), 'id' => (int) ($chunk['id'] ?? 0)]);
            $remainingBlocks = (int) $stmt->fetchColumn();
        } catch (Throwable) {
            $remainingBlocks = 0;
        }
        return [
            'processed_before' => $processedBefore,
            'processed_now' => $processedNow,
            'processed_after' => $processedAfter,
            'estimated_total' => $total,
            'remaining_orders' => $remainingOrders,
            'remaining_blocks_in_month' => $remainingBlocks,
            'percent' => $total > 0 ? min(100, round(($processedAfter / $total) * 100, 1)) : null,
        ];
    }
}
