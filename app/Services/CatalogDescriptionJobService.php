<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use Throwable;

final class CatalogDescriptionJobService
{
    private const MODES = ['missing', 'retry', 'refresh'];

    public function available(): bool
    {
        $schema = new SchemaInspectorService();
        return $schema->hasTable('catalog_description_jobs')
            && $schema->hasTable('catalog_description_job_items')
            && $schema->hasTable('meli_item_descriptions');
    }

    public function assertCatalogAuthorized(int $catalogId): void
    {
        $scope = new AuthorizedBusinessScope();
        $accountIds = $scope->accountIds();
        $companyIds = $scope->companyIds();
        if ($catalogId < 1 || $accountIds === [] || $companyIds === []) {
            throw new \App\Core\HttpException(404, 'No se encontró el catálogo solicitado.');
        }
        $stmt = Database::connection()->prepare(
            'SELECT id,meli_account_id,company_id FROM catalogs WHERE id=:id LIMIT 1'
        );
        $stmt->execute(['id' => $catalogId]);
        $catalog = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($catalog)
            || ((int) ($catalog['meli_account_id'] ?? 0) > 0 && !in_array((int) $catalog['meli_account_id'], $accountIds, true))
            || ((int) ($catalog['company_id'] ?? 0) > 0 && !in_array((int) $catalog['company_id'], $companyIds, true))) {
            throw new \App\Core\HttpException(404, 'No se encontró el catálogo solicitado.');
        }
        $allowed = implode(',', array_map('intval', $accountIds));
        $outside = Database::connection()->prepare(
            'SELECT 1 FROM catalog_items
             WHERE catalog_id=:catalog AND meli_account_id NOT IN (' . $allowed . ')
             LIMIT 1'
        );
        $outside->execute(['catalog' => $catalogId]);
        if ($outside->fetchColumn()) {
            throw new \App\Core\HttpException(404, 'No se encontró el catálogo solicitado.');
        }
    }

    public function assertJobAuthorized(int $jobId): void
    {
        if (!$this->available() || $jobId < 1) {
            throw new \App\Core\HttpException(404, 'No se encontró el trabajo solicitado.');
        }
        $stmt = Database::connection()->prepare('SELECT catalog_id FROM catalog_description_jobs WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $jobId]);
        $catalogId = (int) ($stmt->fetchColumn() ?: 0);
        if ($catalogId < 1) {
            throw new \App\Core\HttpException(404, 'No se encontró el trabajo solicitado.');
        }
        $this->assertCatalogAuthorized($catalogId);
        $accountIds = (new AuthorizedBusinessScope())->accountIds();
        $outside = Database::connection()->prepare(
            'SELECT 1 FROM catalog_description_job_items
             WHERE catalog_description_job_id=:job
               AND meli_account_id NOT IN (' . implode(',', array_map('intval', $accountIds)) . ')
             LIMIT 1'
        );
        $outside->execute(['job' => $jobId]);
        if ($outside->fetchColumn()) {
            throw new \App\Core\HttpException(404, 'No se encontró el trabajo solicitado.');
        }
    }

    /** @return array{job_id:int,reused:bool,total:int,message:string} */
    public function create(int $catalogId, string $mode = 'missing', ?int $userId = null): array
    {
        $this->assertCatalogAuthorized($catalogId);
        if (!$this->available()) {
            throw new \RuntimeException('Falta ejecutar la migración 058 de la cola de descripciones.');
        }
        $mode = in_array($mode, self::MODES, true) ? $mode : 'missing';
        if ($mode === 'refresh' && Auth::role() !== 'admin') {
            throw new \RuntimeException('Solo un administrador puede refrescar descripciones existentes.');
        }
        $catalog = (new CatalogService())->find($catalogId);
        if (!$catalog) {
            throw new \RuntimeException('Catálogo no encontrado.');
        }

        $dedupe = hash('sha256', 'catalog-description:' . $catalogId . ':' . $mode);
        $existing = $this->findByDedupe($dedupe);
        if ($existing) {
            return [
                'job_id' => (int) $existing['id'],
                'reused' => true,
                'total' => (int) $existing['total_items'],
                'message' => 'Ya existe un trabajo activo equivalente. Se abrió el progreso existente.',
            ];
        }

        $settings = new AppSettingsService();
        $batch = max(1, min(50, $settings->int('catalog.description_job_batch_limit', 20)));
        $pause = max(10, min(3600, $settings->int('catalog.description_job_pause_seconds', 30)));
        $attempts = max(1, min(10, $settings->int('catalog.description_job_max_attempts', 3)));
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO catalog_description_jobs
                 (catalog_id,mode,status,dedupe_key,batch_limit,pause_seconds,max_attempts,next_run_at,created_by)
                 VALUES (:catalog,:mode,"queued",:dedupe,:batch,:pause,:attempts,UTC_TIMESTAMP(),:user)'
            );
            $stmt->execute([
                'catalog' => $catalogId,
                'mode' => $mode,
                'dedupe' => $dedupe,
                'batch' => $batch,
                'pause' => $pause,
                'attempts' => $attempts,
                'user' => $userId ?? Auth::id(),
            ]);
            $jobId = (int) $pdo->lastInsertId();

            $criteria = match ($mode) {
                'refresh' => '1=1',
                'retry' => '(d.id IS NULL OR d.source_status IN ("pending","error"))',
                default => '(d.id IS NULL OR d.source_status="pending")',
            };
            $insert = $pdo->prepare(
                'INSERT IGNORE INTO catalog_description_job_items
                 (catalog_description_job_id,catalog_item_id,meli_item_id,meli_account_id,external_item_id,status)
                 SELECT :job,ci.id,ci.meli_item_id,ci.meli_account_id,ci.external_item_id,"pending"
                 FROM catalog_items ci
                 LEFT JOIN meli_item_descriptions d ON d.meli_item_id=ci.meli_item_id
                 WHERE ci.catalog_id=:catalog
                   AND ci.meli_item_id IS NOT NULL
                   AND ci.meli_account_id IS NOT NULL
                   AND ci.external_item_id IS NOT NULL
                   AND ci.external_item_id<>""
                   AND ' . $criteria
            );
            $insert->execute(['job' => $jobId, 'catalog' => $catalogId]);
            $total = (int) $pdo->query(
                'SELECT COUNT(*) FROM catalog_description_job_items WHERE catalog_description_job_id=' . $jobId
            )->fetchColumn();
            if ($total === 0) {
                $pdo->prepare(
                    'UPDATE catalog_description_jobs
                     SET total_items=0,status="completed",stop_reason="no_pending_items",
                         completed_at=UTC_TIMESTAMP(),dedupe_key=NULL
                     WHERE id=:id'
                )->execute(['id' => $jobId]);
            } else {
                $pdo->prepare('UPDATE catalog_description_jobs SET total_items=:total WHERE id=:id')
                    ->execute(['total' => $total, 'id' => $jobId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $existing = $this->findByDedupe($dedupe);
            if ($existing) {
                return [
                    'job_id' => (int) $existing['id'],
                    'reused' => true,
                    'total' => (int) $existing['total_items'],
                    'message' => 'Ya existe un trabajo activo equivalente. Se abrió el progreso existente.',
                ];
            }
            throw $e;
        }

        return [
            'job_id' => $jobId,
            'reused' => false,
            'total' => $total,
            'message' => $total > 0
                ? 'Trabajo de descripciones creado con ' . $total . ' productos.'
                : 'No hay productos que necesiten actualización en este modo.',
        ];
    }

    /** @return array<string,mixed> */
    public function processDue(
        ?int $jobId = null,
        ?int $limitOverride = null,
        ?float $deadline = null,
        ?int $forcedAccountId = null
    ): array
    {
        if (!$this->available()) {
            return $this->emptyResult('Falta la migración 058 de descripciones.', 'schema_missing');
        }
        $claimed = $this->claim($jobId, $limitOverride, $forcedAccountId);
        if ($claimed === null) {
            return $this->emptyResult('No hay lotes de descripciones listos para procesar.', 'no_pending_jobs');
        }

        $job = $claimed['job'];
        $items = $claimed['items'];
        $lockToken = $claimed['lock_token'];
        $jobId = (int) $job['id'];
        $confirmed = 0;
        $unavailable = 0;
        $errors = 0;
        $processed = 0;
        $stopReason = null;
        $lastError = null;
        $lastDiagnosticId = null;

        foreach ($items as $item) {
            if ($deadline !== null && microtime(true) >= $deadline - 2.0) {
                $stopReason = 'time_budget';
                $this->returnItemForRetry((int) $item['id'], 10, 'Lote aplazado por el límite seguro del cron.');
                break;
            }
            try {
                $snapshot = (new MeliItemDescriptionService((int) $item['meli_account_id']))
                    ->syncByItemId((int) $item['meli_item_id'], (string) $item['external_item_id']);
                $sourceStatus = (string) ($snapshot['source_status'] ?? 'error');
                $safeError = trim((string) ($snapshot['safe_error_message'] ?? ''));
                $diagnosticId = !empty($snapshot['diagnostic_id']) ? (string) $snapshot['diagnostic_id'] : null;
                if ($sourceStatus === 'confirmed') {
                    $this->finishItem((int) $item['id'], 'confirmed', 'confirmed', null);
                    $confirmed++;
                } elseif ($sourceStatus === 'unavailable') {
                    $this->finishItem((int) $item['id'], 'unavailable', 'unavailable', $safeError ?: null, $diagnosticId);
                    $unavailable++;
                } else {
                    $lastError = $safeError !== '' ? $safeError : 'No se pudo actualizar la descripción.';
                    $lastDiagnosticId = $diagnosticId;
                    $stopReason = $this->apiStopReason($lastError);
                    if ($stopReason !== null) {
                        $this->returnItemForRetry((int) $item['id'], (int) $job['pause_seconds'], $lastError, $lastDiagnosticId);
                    } else {
                        $this->finishItem((int) $item['id'], 'error', 'error', $lastError, $lastDiagnosticId);
                        $errors++;
                    }
                }
            } catch (Throwable $e) {
                $safe = SafeErrorPresenter::report($e, 'No fue posible procesar la descripción de esta publicación.', [
                    'module' => 'catalog_description_job',
                    'job_id' => $jobId,
                    'item_id' => (int) ($item['id'] ?? 0),
                    'account_id' => (int) ($item['meli_account_id'] ?? 0),
                ]);
                $lastError = mb_substr($safe['message'], 0, 500);
                $lastDiagnosticId = $safe['reference'];
                $stopReason = $this->apiStopReason($lastError);
                if ($stopReason !== null || Database::isLostConnection($e)) {
                    $stopReason ??= 'database_connection';
                    $this->returnItemForRetry((int) $item['id'], (int) $job['pause_seconds'], $lastError, $lastDiagnosticId);
                } else {
                    $this->finishItem((int) $item['id'], 'error', 'error', $lastError, $lastDiagnosticId);
                    $errors++;
                }
                Logger::write('error', 'Error procesando cola de descripciones.', [
                    'module' => 'catalogs',
                    'catalog_description_job_id' => $jobId,
                    'catalog_item_id' => (int) $item['catalog_item_id'],
                    'diagnostic_id' => $lastDiagnosticId,
                ]);
            }
            $processed++;
            if ($stopReason !== null) {
                break;
            }
            if ($processed < count($items)) {
                $minimumPause = max(
                    20,
                    (new AppSettingsService())->int('api.workload.description_pause_seconds', 20)
                );
                if ($deadline !== null && microtime(true) + $minimumPause >= $deadline - 2.0) {
                    $stopReason = 'time_budget';
                    break;
                }
                sleep(min(60, $minimumPause));
            }
        }

        $this->releaseAndSchedule($jobId, $lockToken, $stopReason, $lastError, $lastDiagnosticId);
        $job = $this->find($jobId) ?? $job;
        $message = $processed . ' productos procesados: ' . $confirmed . ' confirmados, '
            . $unavailable . ' sin descripción y ' . $errors . ' con error.';
        if ($stopReason !== null) {
            $message .= ' Trabajo pausado temporalmente: ' . $this->stopReasonLabel($stopReason) . '.';
        }
        return [
            'ok' => true,
            'processed' => $processed,
            'confirmed' => $confirmed,
            'unavailable' => $unavailable,
            'errors' => $errors,
            'job_id' => $jobId,
            'job' => $this->statusPayload($jobId),
            'message' => $message,
            'stop_reason' => $stopReason ?? ((string) ($job['status'] ?? '') === 'completed' ? 'complete' : 'waiting'),
        ];
    }

    /** @return array<string,mixed> */
    public function processExactItem(
        int $jobId,
        int $itemId,
        int $accountId,
        ?float $deadline = null
    ): array {
        if (!$this->available() || $jobId < 1 || $itemId < 1 || $accountId < 1) {
            return $this->emptyResult('La descripción seleccionada ya no está disponible.', 'no_pending_jobs');
        }
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT j.*,i.id item_id,i.meli_item_id,i.meli_account_id,
                        i.external_item_id,i.status item_status,i.attempts item_attempts
                 FROM catalog_description_jobs j
                 JOIN catalog_description_job_items i ON i.catalog_description_job_id=j.id
                 WHERE j.id=? AND i.id=? AND i.meli_account_id=?
                 FOR UPDATE'
            );
            $stmt->execute([$jobId, $itemId, $accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (string) $row['item_status'] !== 'pending') {
                $pdo->commit();
                return $this->emptyResult('La descripción ya fue resuelta o dejó de estar pendiente.', 'complete');
            }
            if (!empty($row['lock_expires_at']) && (strtotime((string) $row['lock_expires_at']) ?: 0) >= time()) {
                $pdo->commit();
                return $this->emptyResult('Otro proceso está terminando este trabajo.', 'locked')
                    + ['next_eligible_at' => (string) $row['lock_expires_at']];
            }
            $token = bin2hex(random_bytes(32));
            $pdo->prepare(
                'UPDATE catalog_description_jobs
                 SET status="running",lock_token=?,locked_at=UTC_TIMESTAMP(),
                     lock_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 MINUTE),
                     current_account_id=?,started_at=COALESCE(started_at,UTC_TIMESTAMP())
                 WHERE id=?'
            )->execute([$token, $accountId, $jobId]);
            $pdo->prepare(
                'UPDATE catalog_description_job_items
                 SET status="running",attempts=attempts+1 WHERE id=? AND status="pending"'
            )->execute([$itemId]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        $confirmed = 0;
        $unavailable = 0;
        $errors = 0;
        $message = 'Descripción procesada correctamente.';
        $stopReason = null;
        $diagnosticId = null;
        try {
            if ($deadline !== null && microtime(true) >= $deadline - 1.0) {
                $this->returnItemForRetry($itemId, 10, 'La descripción quedó para el siguiente paso seguro.');
                $stopReason = 'time_budget';
                $message = 'La descripción quedó programada para el siguiente paso seguro.';
            } else {
                $snapshot = (new MeliItemDescriptionService($accountId))
                    ->syncByItemId((int) $row['meli_item_id'], (string) $row['external_item_id']);
                $sourceStatus = (string) ($snapshot['source_status'] ?? 'error');
                $safeError = trim((string) ($snapshot['safe_error_message'] ?? ''));
                $diagnosticId = !empty($snapshot['diagnostic_id']) ? (string) $snapshot['diagnostic_id'] : null;
                if ($sourceStatus === 'confirmed') {
                    $this->finishItem($itemId, 'confirmed', 'confirmed', null);
                    $confirmed = 1;
                    $message = 'Descripción incorporada correctamente.';
                } elseif ($sourceStatus === 'unavailable') {
                    $this->finishItem($itemId, 'unavailable', 'unavailable', $safeError ?: null, $diagnosticId);
                    $unavailable = 1;
                    $message = 'La publicación no tiene una descripción disponible.';
                } else {
                    $message = $safeError ?: 'No se pudo actualizar la descripción.';
                    $stopReason = $this->apiStopReason($message);
                    if ($stopReason !== null) {
                        $this->returnItemForRetry($itemId, (int) $row['pause_seconds'], $message, $diagnosticId);
                    } else {
                        $this->finishItem($itemId, 'error', 'error', $message, $diagnosticId);
                        $errors = 1;
                    }
                }
            }
        } catch (Throwable $error) {
            $safe = SafeErrorPresenter::report($error, 'No fue posible procesar la descripción de esta publicación.', [
                'module' => 'catalog_description_job',
                'job_id' => $jobId,
                'item_id' => $itemId,
                'account_id' => $accountId,
            ]);
            $message = mb_substr($safe['message'], 0, 500);
            $diagnosticId = $safe['reference'];
            $stopReason = $this->apiStopReason($message);
            if ($stopReason !== null || Database::isLostConnection($error)) {
                $stopReason ??= 'database_connection';
                $this->returnItemForRetry($itemId, (int) $row['pause_seconds'], $message, $diagnosticId);
            } else {
                $this->finishItem($itemId, 'error', 'error', $message, $diagnosticId);
                $errors = 1;
            }
        } finally {
            $this->releaseAndSchedule($jobId, $token, $stopReason, $errors > 0 ? $message : null, $diagnosticId);
        }
        $job = $this->find($jobId) ?? [];
        return [
            'ok' => $errors === 0,
            'exact_item_done' => $confirmed + $unavailable > 0,
            'processed' => $confirmed + $unavailable + $errors,
            'confirmed' => $confirmed,
            'unavailable' => $unavailable,
            'errors' => $errors,
            'job_id' => $jobId,
            'job' => $this->statusPayload($jobId),
            'message' => $message,
            'next_eligible_at' => $job['next_run_at'] ?? null,
            'stop_reason' => $stopReason ?? ((string) ($job['status'] ?? '') === 'completed' ? 'complete' : 'waiting'),
        ];
    }

    /** @return array<string,mixed> */
    public function statusPayload(int $jobId): array
    {
        $job = $this->find($jobId);
        if (!$job) {
            return ['available' => false, 'id' => $jobId];
        }
        $total = max(0, (int) $job['total_items']);
        $processed = (int) $job['processed_items'];
        return $job + [
            'available' => true,
            'percent' => $total > 0 ? round(($processed / $total) * 100, 1) : 100.0,
            'remaining_items' => max(0, $total - $processed),
            'status_label' => $this->statusLabel((string) $job['status']),
            'stop_reason_label' => $this->stopReasonLabel((string) ($job['stop_reason'] ?? '')),
            'next_run_at_local' => DateTimePresenter::formatQueue($job['next_run_at'] ?? null),
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(int $jobId): ?array
    {
        if (!$this->available() || $jobId < 1) {
            return null;
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT j.*,c.name catalog_name,a.account_name current_account_name
             FROM catalog_description_jobs j
             JOIN catalogs c ON c.id=j.catalog_id
             LEFT JOIN meli_accounts a ON a.id=j.current_account_id
             WHERE j.id=:id LIMIT 1'
        );
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function recentForCatalog(int $catalogId, int $limit = 10): array
    {
        if (!$this->available()) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $stmt = Database::connection()->prepare(
            'SELECT * FROM catalog_description_jobs
             WHERE catalog_id=:catalog ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute(['catalog' => $catalogId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function items(int $jobId, string $status = '', int $limit = 200): array
    {
        if (!$this->available()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $where = ['i.catalog_description_job_id=:job'];
        $params = ['job' => $jobId];
        if ($status !== '') {
            $where[] = 'i.status=:status';
            $params['status'] = $status;
        }
        $stmt = Database::connection()->prepare(
            'SELECT i.*,a.account_name
             FROM catalog_description_job_items i
             LEFT JOIN meli_accounts a ON a.id=i.meli_account_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY i.id ASC LIMIT ' . $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pause(int $jobId): void
    {
        $this->setStatus($jobId, 'paused', 'manual_pause', null, false);
    }

    public function resume(int $jobId): void
    {
        $job = $this->requireJob($jobId);
        $this->ensureDedupeAvailable($job);
        Database::connection()->prepare(
            'UPDATE catalog_description_jobs
             SET status="queued",stop_reason=NULL,last_error_message=NULL,next_run_at=UTC_TIMESTAMP(),
                 last_error_diagnostic_id=NULL,lock_token=NULL,locked_at=NULL,lock_expires_at=NULL,dedupe_key=:dedupe,cancelled_at=NULL
             WHERE id=:id'
        )->execute(['dedupe' => $this->dedupeFor($job), 'id' => $jobId]);
    }

    public function cancel(int $jobId): void
    {
        $this->setStatus($jobId, 'cancelled', 'manual_cancel', 'Trabajo cancelado por el usuario.', true);
    }

    public function reopen(int $jobId): void
    {
        $job = $this->requireJob($jobId);
        Database::connection()->prepare(
            'UPDATE catalog_description_job_items
             SET status="pending",next_retry_at=NULL
             WHERE catalog_description_job_id=:job AND status="running"'
        )->execute(['job' => $jobId]);
        $this->ensureDedupeAvailable($job);
        Database::connection()->prepare(
            'UPDATE catalog_description_jobs
             SET status="queued",dedupe_key=:dedupe,cancelled_at=NULL,completed_at=NULL,
                 stop_reason=NULL,last_error_message=NULL,last_error_diagnostic_id=NULL,next_run_at=UTC_TIMESTAMP(),
                 lock_token=NULL,locked_at=NULL,lock_expires_at=NULL
             WHERE id=:id'
        )->execute(['dedupe' => $this->dedupeFor($job), 'id' => $jobId]);
        $this->syncCounters($jobId);
    }

    public function retryErrors(int $jobId): int
    {
        $job = $this->requireJob($jobId);
        $stmt = Database::connection()->prepare(
            'UPDATE catalog_description_job_items
             SET status="pending",source_status=NULL,attempts=0,next_retry_at=NULL,
                 safe_error_message=NULL,diagnostic_id=NULL,processed_at=NULL
             WHERE catalog_description_job_id=:job AND status="error"'
        );
        $stmt->execute(['job' => $jobId]);
        $count = $stmt->rowCount();
        if ($count > 0) {
            $this->ensureDedupeAvailable($job);
            Database::connection()->prepare(
                'UPDATE catalog_description_jobs
                 SET status="queued",dedupe_key=:dedupe,completed_at=NULL,stop_reason=NULL,
                     last_error_message=NULL,last_error_diagnostic_id=NULL,next_run_at=UTC_TIMESTAMP()
                 WHERE id=:id'
            )->execute(['dedupe' => $this->dedupeFor($job), 'id' => $jobId]);
            $this->syncCounters($jobId);
        }
        return $count;
    }

    public function cleanup(): int
    {
        if (!$this->available()) {
            return 0;
        }
        $days = max(7, min(3650, (new AppSettingsService())->int('catalog.description_job_retention_days', 90)));
        $stmt = Database::connection()->prepare(
            'DELETE FROM catalog_description_job_items
             WHERE catalog_description_job_id IN (
                SELECT id FROM catalog_description_jobs
                WHERE status IN ("completed","cancelled")
                  AND COALESCE(completed_at,cancelled_at,updated_at) < UTC_TIMESTAMP() - INTERVAL ' . $days . ' DAY
             )'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** @return array{job:array<string,mixed>,items:list<array<string,mixed>>,lock_token:string}|null */
    private function claim(?int $jobId, ?int $limitOverride = null, ?int $forcedAccountId = null): ?array
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $where = 'status IN ("queued","waiting","running")
                      AND (next_run_at IS NULL OR next_run_at<=UTC_TIMESTAMP())
                      AND (lock_expires_at IS NULL OR lock_expires_at<UTC_TIMESTAMP())';
            $params = [];
            if ($jobId !== null && $jobId > 0) {
                $where .= ' AND id=:id';
                $params['id'] = $jobId;
            } elseif ((new SchemaInspectorService())->hasTable('manual_campaign_reservations')) {
                $where .= ' AND NOT EXISTS (
                    SELECT 1 FROM manual_campaign_reservations r
                    WHERE r.queue_key="catalog_descriptions"
                      AND (
                        r.source_id=CAST(catalog_description_jobs.id AS CHAR)
                        OR r.source_id LIKE CONCAT(CAST(catalog_description_jobs.id AS CHAR),":%")
                      )
                      AND r.status="active" AND r.expires_at>UTC_TIMESTAMP(3)
                )';
            }
            $stmt = $pdo->prepare(
                'SELECT * FROM catalog_description_jobs
                 WHERE ' . $where . '
                 ORDER BY COALESCE(next_run_at,created_at),id
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute($params);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $pdo->commit();
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $pdo->prepare(
                'UPDATE catalog_description_jobs
                 SET status="running",lock_token=:token,locked_at=UTC_TIMESTAMP(),
                     lock_expires_at=UTC_TIMESTAMP()+INTERVAL 5 MINUTE,
                     started_at=COALESCE(started_at,UTC_TIMESTAMP()),stop_reason=NULL
                 WHERE id=:id'
            )->execute(['token' => $token, 'id' => (int) $job['id']]);
            $pdo->prepare(
                'UPDATE catalog_description_job_items
                 SET status="error",source_status="error",
                     safe_error_message=COALESCE(safe_error_message,"Se agotó el máximo de intentos."),
                     processed_at=COALESCE(processed_at,UTC_TIMESTAMP())
                 WHERE catalog_description_job_id=:job AND status="pending" AND attempts>=:max_attempts'
            )->execute(['job' => (int) $job['id'], 'max_attempts' => (int) $job['max_attempts']]);

            $accountId = $forcedAccountId !== null && $forcedAccountId > 0
                ? $forcedAccountId
                : $this->nextAccountId($pdo, (int) $job['id'], (int) ($job['current_account_id'] ?? 0));
            if ($accountId < 1) {
                $pdo->commit();
                $this->releaseAndSchedule((int) $job['id'], $token, null, null);
                return null;
            }
            $limit = max(1, min(
                50,
                $limitOverride !== null ? $limitOverride : (int) $job['batch_limit']
            ));
            $itemsStmt = $pdo->prepare(
                'SELECT * FROM catalog_description_job_items
                 WHERE catalog_description_job_id=:job
                   AND meli_account_id=:account
                   AND status="pending"
                   AND (next_retry_at IS NULL OR next_retry_at<=UTC_TIMESTAMP())
                   AND attempts<:max_attempts
                 ORDER BY id ASC LIMIT ' . $limit . ' FOR UPDATE'
            );
            $itemsStmt->execute([
                'job' => (int) $job['id'],
                'account' => $accountId,
                'max_attempts' => (int) $job['max_attempts'],
            ]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            if ($items === []) {
                $pdo->commit();
                $this->releaseAndSchedule((int) $job['id'], $token, null, null);
                return null;
            }
            $ids = array_map(static fn(array $item): int => (int) $item['id'], $items);
            $pdo->exec(
                'UPDATE catalog_description_job_items
                 SET status="running",attempts=attempts+1
                 WHERE id IN (' . implode(',', $ids) . ')'
            );
            $pdo->prepare('UPDATE catalog_description_jobs SET current_account_id=:account WHERE id=:id')
                ->execute(['account' => $accountId, 'id' => (int) $job['id']]);
            $pdo->commit();
            $job['current_account_id'] = $accountId;
            return ['job' => $job, 'items' => $items, 'lock_token' => $token];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function nextAccountId(PDO $pdo, int $jobId, int $current): int
    {
        $stmt = $pdo->prepare(
            'SELECT MIN(meli_account_id)
             FROM catalog_description_job_items
             WHERE catalog_description_job_id=:job AND status="pending"
               AND (next_retry_at IS NULL OR next_retry_at<=UTC_TIMESTAMP())
               AND meli_account_id>:current'
        );
        $stmt->execute(['job' => $jobId, 'current' => $current]);
        $next = (int) $stmt->fetchColumn();
        if ($next > 0) {
            return $next;
        }
        $stmt = $pdo->prepare(
            'SELECT MIN(meli_account_id)
             FROM catalog_description_job_items
             WHERE catalog_description_job_id=:job AND status="pending"
               AND (next_retry_at IS NULL OR next_retry_at<=UTC_TIMESTAMP())'
        );
        $stmt->execute(['job' => $jobId]);
        return (int) $stmt->fetchColumn();
    }

    private function finishItem(int $itemId, string $status, string $sourceStatus, ?string $error, ?string $diagnosticId = null): void
    {
        Database::executeWithReconnect(static function (PDO $pdo) use ($itemId, $status, $sourceStatus, $error, $diagnosticId): void {
            $pdo->prepare(
                'UPDATE catalog_description_job_items
                 SET status=:status,source_status=:source,safe_error_message=:error,diagnostic_id=:diagnostic,
                     next_retry_at=NULL,processed_at=UTC_TIMESTAMP()
                 WHERE id=:id'
            )->execute([
                'status' => $status,
                'source' => $sourceStatus,
                'error' => $error !== null ? mb_substr($error, 0, 500) : null,
                'diagnostic' => $diagnosticId !== null ? mb_substr($diagnosticId, 0, 80) : null,
                'id' => $itemId,
            ]);
        });
    }

    private function returnItemForRetry(int $itemId, int $pauseSeconds, string $error, ?string $diagnosticId = null): void
    {
        Database::executeWithReconnect(static function (PDO $pdo) use ($itemId, $pauseSeconds, $error, $diagnosticId): void {
            $pdo->prepare(
                'UPDATE catalog_description_job_items
                 SET status="pending",source_status="error",safe_error_message=:error,diagnostic_id=:diagnostic,
                     next_retry_at=UTC_TIMESTAMP()+INTERVAL ' . max(10, $pauseSeconds) . ' SECOND
                 WHERE id=:id'
            )->execute([
                'error' => mb_substr($error, 0, 500),
                'diagnostic' => $diagnosticId !== null ? mb_substr($diagnosticId, 0, 80) : null,
                'id' => $itemId,
            ]);
        });
    }

    private function releaseAndSchedule(int $jobId, string $token, ?string $stopReason, ?string $lastError, ?string $diagnosticId = null): void
    {
        $this->syncCounters($jobId);
        $job = $this->find($jobId);
        if (!$job) {
            return;
        }
        $remaining = max(0, (int) $job['total_items'] - (int) $job['processed_items']);
        $terminal = $remaining === 0;
        $status = $terminal ? ((int) $job['error_items'] > 0 ? 'partial' : 'completed') : 'waiting';
        $pause = max(10, (int) $job['pause_seconds']);
        $nextSql = $terminal ? 'NULL' : 'UTC_TIMESTAMP()+INTERVAL ' . $pause . ' SECOND';
        $completedSql = $terminal ? 'UTC_TIMESTAMP()' : 'NULL';
        $dedupeSql = $terminal ? 'NULL' : 'dedupe_key';
        Database::executeWithReconnect(static function (PDO $pdo) use ($jobId, $token, $status, $stopReason, $lastError, $diagnosticId, $nextSql, $completedSql, $dedupeSql): void {
            $pdo->prepare(
                'UPDATE catalog_description_jobs
                 SET status=:status,stop_reason=:reason,last_error_message=:error,last_error_diagnostic_id=:diagnostic,
                     next_run_at=' . $nextSql . ',completed_at=' . $completedSql . ',
                     dedupe_key=' . $dedupeSql . ',last_processed_at=UTC_TIMESTAMP(),
                     lock_token=NULL,locked_at=NULL,lock_expires_at=NULL
                 WHERE id=:id AND lock_token=:token'
            )->execute([
                'status' => $status,
                'reason' => $stopReason,
                'error' => $lastError !== null ? mb_substr($lastError, 0, 500) : null,
                'diagnostic' => $diagnosticId !== null ? mb_substr($diagnosticId, 0, 80) : null,
                'id' => $jobId,
                'token' => $token,
            ]);
        });
    }

    private function syncCounters(int $jobId): void
    {
        Database::executeWithReconnect(static function (PDO $pdo) use ($jobId): void {
            $pdo->prepare(
                'UPDATE catalog_description_jobs j
                 SET
                    processed_items=(SELECT COUNT(*) FROM catalog_description_job_items i WHERE i.catalog_description_job_id=j.id AND i.status IN ("confirmed","unavailable","error","skipped")),
                    confirmed_items=(SELECT COUNT(*) FROM catalog_description_job_items i WHERE i.catalog_description_job_id=j.id AND i.status="confirmed"),
                    unavailable_items=(SELECT COUNT(*) FROM catalog_description_job_items i WHERE i.catalog_description_job_id=j.id AND i.status="unavailable"),
                    error_items=(SELECT COUNT(*) FROM catalog_description_job_items i WHERE i.catalog_description_job_id=j.id AND i.status="error"),
                    skipped_items=(SELECT COUNT(*) FROM catalog_description_job_items i WHERE i.catalog_description_job_id=j.id AND i.status="skipped")
                 WHERE j.id=:id'
            )->execute(['id' => $jobId]);
        });
    }

    private function setStatus(int $jobId, string $status, string $reason, ?string $message, bool $cancelled): void
    {
        $this->requireJob($jobId);
        Database::connection()->prepare(
            'UPDATE catalog_description_jobs
             SET status=:status,stop_reason=:reason,last_error_message=:message,
                 next_run_at=NULL,lock_token=NULL,locked_at=NULL,lock_expires_at=NULL,
                 dedupe_key=' . ($cancelled ? 'NULL' : 'dedupe_key') . ',
                 cancelled_at=' . ($cancelled ? 'UTC_TIMESTAMP()' : 'cancelled_at') . '
             WHERE id=:id'
        )->execute(['status' => $status, 'reason' => $reason, 'message' => $message, 'id' => $jobId]);
        Database::connection()->prepare(
            'UPDATE catalog_description_job_items SET status="pending"
             WHERE catalog_description_job_id=:job AND status="running"'
        )->execute(['job' => $jobId]);
    }

    /** @return array<string,mixed> */
    private function requireJob(int $jobId): array
    {
        $job = $this->find($jobId);
        if (!$job) {
            throw new \RuntimeException('Trabajo de descripciones no encontrado.');
        }
        return $job;
    }

    /** @return array<string,mixed>|null */
    private function findByDedupe(string $dedupe): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM catalog_description_jobs
             WHERE dedupe_key=:dedupe AND status IN ("queued","running","waiting","paused")
             LIMIT 1'
        );
        $stmt->execute(['dedupe' => $dedupe]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $job */
    private function ensureDedupeAvailable(array $job): void
    {
        $existing = $this->findByDedupe($this->dedupeFor($job));
        if ($existing && (int) $existing['id'] !== (int) $job['id']) {
            throw new \RuntimeException('Ya existe otro trabajo activo equivalente para este catálogo.');
        }
    }

    /** @param array<string,mixed> $job */
    private function dedupeFor(array $job): string
    {
        return hash('sha256', 'catalog-description:' . (int) $job['catalog_id'] . ':' . (string) $job['mode']);
    }

    private function apiStopReason(string $message): ?string
    {
        $lower = mb_strtolower($message);
        return match (true) {
            str_contains($lower, 'pausadas manualmente') => 'manual_pause',
            str_contains($lower, '429'), str_contains($lower, 'rate limit'), str_contains($lower, 'too many requests') => 'rate_limit',
            str_contains($lower, '403'), str_contains($lower, 'circuit'), str_contains($lower, 'bloquead') => 'circuit_breaker',
            str_contains($lower, 'timeout'), str_contains($lower, 'timed out') => 'timeout',
            str_contains($lower, '2006'), str_contains($lower, '2013'), str_contains($lower, '4031'), str_contains($lower, 'server has gone away') => 'database_connection',
            default => null,
        };
    }

    private function statusLabel(string $status): string
    {
        return [
            'queued' => 'En cola',
            'running' => 'Procesando',
            'waiting' => 'Esperando',
            'paused' => 'Pausado',
            'completed' => 'Completo',
            'partial' => 'Completo con errores',
            'error' => 'Error',
            'cancelled' => 'Cancelado',
        ][$status] ?? $status;
    }

    private function stopReasonLabel(string $reason): string
    {
        return [
            'rate_limit' => 'Límite de solicitudes de Mercado Libre',
            'circuit_breaker' => 'Protección API activa',
            'timeout' => 'Tiempo de espera agotado',
            'database_connection' => 'Conexión MySQL interrumpida',
            'manual_pause' => 'Pausa manual',
            'manual_cancel' => 'Cancelación manual',
            'no_pending_items' => 'Sin productos pendientes',
        ][$reason] ?? ($reason !== '' ? $reason : '—');
    }

    /** @return array<string,mixed> */
    private function emptyResult(string $message, string $reason): array
    {
        return [
            'ok' => true,
            'processed' => 0,
            'confirmed' => 0,
            'unavailable' => 0,
            'errors' => 0,
            'message' => $message,
            'stop_reason' => $reason,
        ];
    }
}
