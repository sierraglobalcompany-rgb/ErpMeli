<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use Throwable;

/**
 * Crea y procesa reparaciones exclusivamente desde una auditoría exacta terminada.
 *
 * Las acciones web solo crean trabajo local. Las consultas a Mercado Libre se
 * realizan desde processDue(), ejecutado por CLI.
 */
final class SalesAuditExactRepairService
{
    /** @var \Closure(int):OrderSyncService */
    private \Closure $orderSyncFactory;

    /** @param null|callable(int):OrderSyncService $orderSyncFactory */
    public function __construct(?callable $orderSyncFactory = null)
    {
        $this->orderSyncFactory = $orderSyncFactory !== null
            ? \Closure::fromCallable($orderSyncFactory)
            : static fn(int $accountId): OrderSyncService => new OrderSyncService($accountId);
    }

    public function available(): bool
    {
        $schema = new SchemaInspectorService();
        return $schema->hasTable('sync_sales_audit_runs')
            && $schema->hasTable('sync_sales_audit_run_orders')
            && $schema->hasTable('sync_sales_repair_jobs')
            && $schema->hasColumn('sync_sales_repair_jobs', 'sync_sales_audit_run_id')
            && $schema->hasColumn('sync_sales_repair_jobs', 'lock_owner')
            && $schema->hasColumn('sync_sales_repair_job_items', 'sync_sales_audit_run_order_id');
    }

    /** @return array<string,mixed> */
    public function preview(int $runId, int $companyId = 0, int $accountId = 0): array
    {
        $run = $this->eligibleRun($runId, $companyId, $accountId);
        $missing = $this->missingCount($runId);
        $settings = new AppSettingsService();
        $batch = max(1, min(25, $settings->int('sales_audit.repair_batch_limit', 5)));
        $cycles = $missing > 0 ? (int) ceil($missing / $batch) : 0;
        $intervalMinutes = max(1, $settings->int('cron.main_interval_minutes', 5));
        $minimumMinutes = $cycles > 0 ? max(1, ($cycles - 1) * $intervalMinutes) : 0;
        $maximumMinutes = $cycles > 0 ? max($minimumMinutes, $cycles * $intervalMinutes * 2) : 0;
        $budget = (new ApiBudgetService())->summary();
        $existing = $this->jobForRun($runId);

        return [
            'run' => $run,
            'missing_count' => $missing,
            'estimated_calls' => $missing,
            'batch_limit' => $batch,
            'estimated_cycles' => $cycles,
            'estimated_minutes_min' => $minimumMinutes,
            'estimated_minutes_max' => $maximumMinutes,
            'next_safe_at' => $budget['next_safe_at'] ?? null,
            'existing_job' => $existing,
            'can_enqueue' => $missing > 0 && $existing === null,
        ];
    }

    public function createFromRun(
        int $runId,
        ?int $userId = null,
        int $companyId = 0,
        int $accountId = 0
    ): int
    {
        if (!$this->available()) {
            throw new \RuntimeException('La reparación exacta todavía no está instalada.');
        }
        $run = $this->eligibleRun($runId, $companyId, $accountId);
        $existing = $this->jobForRun($runId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO sync_sales_repair_jobs
                 (sync_sales_audit_id,sync_sales_audit_run_id,source_kind,meli_account_id,company_id,
                  period_year,period_month,status,next_run_at,created_by)
                 VALUES (NULL,?,"exact",?,?,?,?,"pending",UTC_TIMESTAMP(),?)'
            )->execute([
                $runId,
                (int) $run['meli_account_id'],
                (int) $run['account_company_id'],
                (int) $run['period_year'],
                (int) $run['period_month'],
                $userId ?? Auth::id(),
            ]);
            $jobId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO sync_sales_repair_job_items
                 (sync_sales_repair_job_id,external_order_id,audit_day_id,
                  sync_sales_audit_run_order_id,action,attempts,next_run_at,status)
                 SELECT ?,r.external_order_id,NULL,r.id,"fetch_missing",0,UTC_TIMESTAMP(),"pending"
                 FROM sync_sales_audit_run_orders r
                 WHERE r.sync_sales_audit_run_id=? AND r.classification="missing_remote"
                 ORDER BY r.id'
            )->execute([$jobId, $runId]);
            $countStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM sync_sales_repair_job_items WHERE sync_sales_repair_job_id=?'
            );
            $countStmt->execute([$jobId]);
            $count = (int) $countStmt->fetchColumn();
            if ($count <= 0) {
                throw new \RuntimeException('La auditoría exacta ya no contiene órdenes faltantes.');
            }
            $pdo->prepare(
                'UPDATE sync_sales_repair_jobs SET total_items=? WHERE id=?'
            )->execute([$count, $jobId]);
            $pdo->commit();
            return $jobId;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($this->isDuplicate($error)) {
                $existing = $this->jobForRun($runId);
                if ($existing !== null) {
                    return (int) $existing['id'];
                }
            }
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    public function findJob(int $jobId, int $companyId = 0): ?array
    {
        if (!$this->available() || $jobId <= 0) {
            return null;
        }
        try {
            $job = (new SalesAuditAccessGateway())->repairJob($jobId, $companyId);
        } catch (\App\Core\HttpException) {
            return null;
        }
        $counts = Database::connectionFresh()->prepare(
            'SELECT status,COUNT(*) total
             FROM sync_sales_repair_job_items
             WHERE sync_sales_repair_job_id=?
             GROUP BY status'
        );
        $counts->execute([$jobId]);
        $job['item_counts'] = [];
        foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $job['item_counts'][(string) $row['status']] = (int) $row['total'];
        }
        $job['progress_percent'] = (int) $job['total_items'] > 0
            ? min(100, (int) floor(((int) $job['processed_items'] / (int) $job['total_items']) * 100))
            : 0;
        return $job;
    }

    /** @return array<string,mixed>|null */
    public function latestForPeriod(int $accountId, int $year, int $month): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT j.id
             FROM sync_sales_repair_jobs j
             WHERE j.source_kind="exact" AND j.meli_account_id=?
               AND j.period_year=? AND j.period_month=?
             ORDER BY j.id DESC LIMIT 1'
        );
        $stmt->execute([$accountId, $year, $month]);
        $id = (int) $stmt->fetchColumn();
        return $id > 0 ? $this->findJob($id) : null;
    }

    /** @return array<string,mixed> */
    public function processDue(int $limit = 5): array
    {
        return $this->processSelected($limit, null, true, true);
    }

    /** @return array<string,mixed> */
    public function processExact(int $jobId, int $limit = 1): array
    {
        return $this->processSelected($limit, $jobId);
    }

    public function processManualExact(int $jobId): array
    {
        return $this->processSelected(1, $jobId, false);
    }

    /** @return array<string,mixed> */
    private function processSelected(
        int $limit,
        ?int $jobId,
        bool $allowContinuation = true,
        bool $queueV4 = false,
    ): array
    {
        if (!$this->available()) {
            return ['processed' => 0, 'jobs' => 0, 'status' => 'empty'];
        }
        $limit = max(1, min(25, $limit));
        $worker = 'sales-repair-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $job = $this->claim($worker, $jobId);
        if ($job === null) {
            return ['processed' => 0, 'jobs' => 0, 'status' => 'empty'];
        }

        $items = Database::connectionFresh()->prepare(
            'SELECT i.* FROM sync_sales_repair_job_items i
             INNER JOIN sync_sales_repair_jobs j
               ON j.id=i.sync_sales_repair_job_id AND j.company_id=? AND j.meli_account_id=?
              AND j.source_kind="exact"
             WHERE i.sync_sales_repair_job_id=?
               AND i.status IN ("pending","retry","waiting_budget")
               AND (i.next_run_at IS NULL OR i.next_run_at<=UTC_TIMESTAMP())
             ORDER BY i.id ASC LIMIT ' . $limit
        );
        $items->execute([(int) $job['company_id'], (int) $job['meli_account_id'], (int) $job['id']]);
        $rows = $items->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return $this->finalizeOrRelease($job, $worker);
        }

        $processed = 0;
        $orderIds = [];
        foreach ($rows as $item) {
            if (!$this->ownsLease($job, $worker)) {
                return ['processed' => $processed, 'jobs' => 1, 'status' => 'lease_lost'];
            }
            $itemId = (int) $item['id'];
            $externalId = (string) $item['external_order_id'];
            $this->startItem($job, $itemId);
            try {
                $existingOrder = $this->localOrderId(
                    (int) $job['company_id'],
                    (int) $job['meli_account_id'],
                    $externalId,
                );
                if ($existingOrder > 0) {
                    $orderIds[] = $existingOrder;
                    $this->finishItem($job, $itemId, 'already_present', null, null);
                } else {
                    $sync = ($this->orderSyncFactory)((int) $job['meli_account_id']);
                    $meta=[
                        'job_type' => 'sales_repair',
                        'source' => $queueV4 ? MeliTransportSourcePolicy::QUEUE_V4_SALES_REPAIR : 'cron',
                        'company_id' => (int) $job['company_id'],
                        'account_id' => (int) $job['meli_account_id'],
                        'source_queue_key' => 'sync_sales_repair_jobs',
                        'source_work_id' => (string) $itemId,
                        'sales_repair_job_id' => (int) $job['id'],
                        'sales_repair_item_id' => $itemId,
                        'sales_repair_lease_owner' => $worker,
                        'sales_repair_lease_generation' => (int) $job['lease_generation'],
                        'bulk' => false,
                    ];
                    if ($queueV4) {
                        $orderId = ApiExecutionMetadataContext::run(
                            $meta,
                            static fn(): int => $sync->syncOrderByIdForQueueV4Clean($externalId, $meta),
                        );
                    } else {
                        $orderId = $allowContinuation
                            ? $sync->syncOrderById($externalId, $meta)
                            : $sync->syncOrderByIdForManual($externalId, $meta);
                    }
                    if ($orderId > 0) {
                        $orderIds[] = $orderId;
                    }
                    if (!$this->ownsLease($job, $worker)) {
                        return ['processed' => $processed, 'jobs' => 1, 'status' => 'lease_lost'];
                    }
                    $this->finishItem($job, $itemId, 'complete', null, null);
                }
                $processed++;
            } catch (OAuthRefreshRequiredException $error) {
                $next = gmdate('Y-m-d H:i:s', time() + 60);
                $this->deferItem($job, $itemId, 'waiting_budget', $next, 'Esperando renovación OAuth automática.', null, true);
                $this->releaseJob($job, $worker, 'waiting_budget', $next, null, null);
                return ['processed' => $processed, 'jobs' => 1, 'status' => 'waiting_oauth'];
            } catch (QueueV4PreTransportDeferredException $error) {
                $this->deferItem($job, $itemId, 'waiting_budget', $error->nextSafeAt, 'Esperando la próxima oportunidad segura.', null, true);
                $this->releaseJob($job, $worker, 'waiting_budget', $error->nextSafeAt, null, null);
                return ['processed' => $processed, 'jobs' => 1, 'status' => 'waiting_budget'];
            } catch (ApiBudgetExhaustedException $error) {
                $next = $error->nextSafeAt ?: gmdate('Y-m-d H:i:s', time() + 900);
                $this->deferItem($job, $itemId, 'waiting_budget', $next, 'Esperando presupuesto seguro de consultas.', null, true);
                $this->releaseJob($job, $worker, 'waiting_budget', $next, null, null);
                return ['processed' => $processed, 'jobs' => 1, 'status' => 'waiting_budget'];
            } catch (ApiManualPauseException $error) {
                $next = $error->resumeAt ?: gmdate('Y-m-d H:i:s', time() + 900);
                $this->deferItem($job, $itemId, 'waiting_budget', $next, 'Las consultas están pausadas preventivamente.', null, true);
                $this->releaseJob($job, $worker, 'waiting_budget', $next, null, null);
                return ['processed' => $processed, 'jobs' => 1, 'status' => 'waiting_budget'];
            } catch (RemoteResultUncertainException $error) {
                $message = 'El resultado remoto es incierto. Revise antes de autorizar otro intento.';
                $this->finishItem($job, $itemId, 'error', $message, $error->requestId);
                $this->releaseJob($job, $worker, 'error', null, $message, $error->requestId);
                throw $error; // Keep later items pending and stop the shared cycle.
            } catch (MeliApiException $error) {
                $this->handleApiFailure($job, $item, $error);
            } catch (Throwable $error) {
                $safe = SafeErrorPresenter::report($error, 'No fue posible incorporar esta orden.', [
                    'module' => 'sales_repair',
                    'job_id' => (int) $job['id'],
                    'item_id' => $itemId,
                ]);
                $this->retryOrFail($job, $item, $safe['message'], $safe['reference']);
            }
            $this->heartbeat($job, $worker);
        }

        if ($orderIds !== [] && $allowContinuation) {
            try {
                (new OrderFinancialRecalcJobService())->createForOrderIds(
                    array_values(array_unique($orderIds)),
                    'repaired',
                    'sales_repair',
                    (int) $job['id'],
                    null
                );
            } catch (Throwable $error) {
                SafeErrorPresenter::report($error, 'Las órdenes se incorporaron, pero el recálculo financiero quedó pendiente.', [
                    'module' => 'sales_repair',
                    'job_id' => (int) $job['id'],
                ]);
            }
        }
        $result = $this->finalizeOrRelease($job, $worker);
        $result['processed'] = $processed;
        return $result;
    }

    public function pause(int $jobId, int $companyId = 0): void
    {
        $job = (new SalesAuditAccessGateway())->repairJob($jobId, $companyId);
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE sync_sales_repair_jobs
             SET status="paused",lock_owner=NULL,lock_expires_at=NULL,heartbeat_at=NULL
             WHERE id=? AND company_id=? AND source_kind="exact" AND status IN ("pending","retry","waiting_budget")'
        );
        $stmt->execute([$jobId, (int) $job['account_company_id']]);
    }

    public function resume(int $jobId, int $companyId = 0): void
    {
        $job = (new SalesAuditAccessGateway())->repairJob($jobId, $companyId);
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE sync_sales_repair_jobs
             SET status="pending",next_run_at=UTC_TIMESTAMP(),consecutive_failures=0,
                 safe_error_message=NULL,diagnostic_id=NULL
             WHERE id=? AND company_id=? AND source_kind="exact" AND status IN ("paused","error","partial")'
        );
        $stmt->execute([$jobId, (int) $job['account_company_id']]);
        Database::connectionFresh()->prepare(
            'UPDATE sync_sales_repair_job_items
             SET status="retry",next_run_at=UTC_TIMESTAMP(),safe_error_message=NULL,diagnostic_id=NULL
             WHERE sync_sales_repair_job_id=? AND status="error"'
        )->execute([$jobId]);
    }

    /** @return array<string,mixed> */
    private function eligibleRun(int $runId, int $companyId = 0, int $accountId = 0): array
    {
        if (!$this->available() || $runId <= 0) {
            throw new \RuntimeException('La auditoría exacta seleccionada no está disponible.');
        }
        $run = (new SalesAuditAccessGateway())->run($runId, $companyId, $accountId);
        if (!$run || (string) $run['mode'] !== 'exact') {
            throw new \RuntimeException('La reparación requiere una auditoría exacta.');
        }
        if (!in_array((string) $run['status'], ['complete', 'partial'], true)
            || (string) $run['remote_coverage'] !== 'complete'
            || (int) $run['checked_total'] !== (int) $run['remote_unique_total']
            || (string) $run['reconciliation_status'] === 'blocked') {
            throw new \RuntimeException('La auditoría exacta todavía no terminó de comprobar el periodo.');
        }
        return $run;
    }

    /** @return array<string,mixed>|null */
    private function jobForRun(int $runId): ?array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT * FROM sync_sales_repair_jobs
             WHERE sync_sales_audit_run_id=? AND source_kind="exact"
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function missingCount(int $runId): int
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT COUNT(*) FROM sync_sales_audit_run_orders
             WHERE sync_sales_audit_run_id=? AND classification="missing_remote"'
        );
        $stmt->execute([$runId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    private function claim(string $worker, ?int $jobId = null): ?array
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $reservationGuard = ManualCampaignReservationGuard::sql('sales_repair', 'j.id');
            $stmt = $pdo->prepare(
                'SELECT j.* FROM sync_sales_repair_jobs j
                 INNER JOIN sync_sales_audit_runs r
                   ON r.id=j.sync_sales_audit_run_id AND r.company_id=j.company_id
                  AND r.meli_account_id=j.meli_account_id
                 INNER JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=j.company_id
                 WHERE j.source_kind="exact"
                   AND j.status IN ("pending","retry","waiting_budget")
                   AND NOT EXISTS (
                       SELECT 1 FROM queue_v4_clean_transport_events e
                       WHERE e.company_id=j.company_id AND e.meli_account_id=j.meli_account_id
                         AND e.source_kind="sales_repair" AND e.work_id=j.id
                         AND e.dispatch_state="PHYSICAL_STARTED" AND e.response_known_at IS NULL
                   )
                   AND (j.next_run_at IS NULL OR j.next_run_at<=UTC_TIMESTAMP())
                   AND (j.lock_expires_at IS NULL OR j.lock_expires_at<UTC_TIMESTAMP())
                   AND (? IS NULL OR j.id=?)' . $reservationGuard . '
                 ORDER BY j.created_at ASC,j.id ASC LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$jobId, $jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $pdo->commit();
                return null;
            }
            $seconds = max(20, min(180, (new AppSettingsService())->int('sales_audit.repair_lease_seconds', 45)));
            $pdo->prepare(
                'UPDATE sync_sales_repair_jobs
                 SET status="running",lock_owner=?,lock_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ' . $seconds . ' SECOND),
                      heartbeat_at=UTC_TIMESTAMP(),lease_generation=lease_generation+1,
                      started_at=COALESCE(started_at,UTC_TIMESTAMP())
                 WHERE id=? AND company_id=? AND meli_account_id=? AND source_kind="exact"'
            )->execute([
                $worker,
                (int) $job['id'],
                (int) $job['company_id'],
                (int) $job['meli_account_id'],
            ]);
            $pdo->commit();
            $job['lock_owner'] = $worker;
            $job['lease_generation'] = ((int) ($job['lease_generation'] ?? 0)) + 1;
            return $job;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $job */
    private function ownsLease(array $job, string $worker): bool
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT COUNT(*) FROM sync_sales_repair_jobs
             WHERE id=? AND company_id=? AND meli_account_id=? AND source_kind="exact"
               AND lock_owner=? AND lease_generation=?
               AND status="running" AND lock_expires_at>=UTC_TIMESTAMP()'
        );
        $stmt->execute([
            (int) $job['id'],
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
            $worker,
            (int) $job['lease_generation'],
        ]);
        return (int) $stmt->fetchColumn() === 1;
    }

    /** @param array<string,mixed> $job */
    private function heartbeat(array $job, string $worker): void
    {
        $seconds = max(20, min(180, (new AppSettingsService())->int('sales_audit.repair_lease_seconds', 45)));
        Database::connectionFresh()->prepare(
            'UPDATE sync_sales_repair_jobs
             SET heartbeat_at=UTC_TIMESTAMP(),lock_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ' . $seconds . ' SECOND)
             WHERE id=? AND company_id=? AND meli_account_id=? AND source_kind="exact"
               AND lock_owner=? AND lease_generation=?'
        )->execute([
            (int) $job['id'],
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
            $worker,
            (int) $job['lease_generation'],
        ]);
    }

    /** @param array<string,mixed> $job */
    private function startItem(array $job, int $itemId): void
    {
        Database::connectionFresh()->prepare(
            'UPDATE sync_sales_repair_job_items i
             INNER JOIN sync_sales_repair_jobs j ON j.id=i.sync_sales_repair_job_id
             SET i.status="running",i.attempts=i.attempts+1,i.safe_error_message=NULL,i.diagnostic_id=NULL
             WHERE i.id=? AND j.id=? AND j.company_id=? AND j.meli_account_id=? AND j.source_kind="exact"'
        )->execute([$itemId, (int) $job['id'], (int) $job['company_id'], (int) $job['meli_account_id']]);
    }

    /** @param array<string,mixed> $job */
    private function finishItem(
        array $job,
        int $itemId,
        string $status,
        ?string $message,
        ?string $diagnostic,
    ): void
    {
        Database::connectionFresh()->prepare(
            'UPDATE sync_sales_repair_job_items i
             INNER JOIN sync_sales_repair_jobs j ON j.id=i.sync_sales_repair_job_id
             SET i.status=?,i.safe_error_message=?,i.diagnostic_id=?,i.processed_at=UTC_TIMESTAMP()
             WHERE i.id=? AND j.id=? AND j.company_id=? AND j.meli_account_id=? AND j.source_kind="exact"
               AND j.lock_owner=? AND j.lease_generation=? AND j.status="running"'
        )->execute([
            $status,
            $message,
            $diagnostic,
            $itemId,
            (int) $job['id'],
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
            (string) $job['lock_owner'],
            (int) $job['lease_generation'],
        ]);
    }

    /** @param array<string,mixed> $job */
    private function deferItem(
        array $job,
        int $itemId,
        string $status,
        string $nextRunAt,
        string $message,
        ?string $diagnostic,
        bool $refundAttempt = false,
    ): void
    {
        Database::connectionFresh()->prepare(
            'UPDATE sync_sales_repair_job_items i
             INNER JOIN sync_sales_repair_jobs j ON j.id=i.sync_sales_repair_job_id
             SET i.status=?,i.next_run_at=?,i.safe_error_message=?,i.diagnostic_id=?,
                 i.attempts=GREATEST(i.attempts-?,0)
             WHERE i.id=? AND j.id=? AND j.company_id=? AND j.meli_account_id=? AND j.source_kind="exact"'
        )->execute([
            $status,
            $nextRunAt,
            $message,
            $diagnostic,
            $refundAttempt ? 1 : 0,
            $itemId,
            (int) $job['id'],
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
        ]);
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $item */
    private function handleApiFailure(array $job, array $item, MeliApiException $error): void
    {
        $status = (int) ($error->httpStatus ?? 0);
        if ($status === 404) {
            $this->finishItem($job, (int) $item['id'], 'unavailable', 'Mercado Libre indicó que la orden ya no está disponible.', $error->requestId);
            return;
        }
        if ($status === 429) {
            $retryAfter = max(60, (int) ($error->response['retry_after'] ?? 900));
            $this->deferItem(
                $job,
                (int) $item['id'],
                'retry',
                gmdate('Y-m-d H:i:s', time() + $retryAfter),
                'Mercado Libre pidió esperar antes de volver a consultar.',
                $error->requestId
            );
            return;
        }
        if ($status === 403 || str_contains(mb_strtolower($error->getMessage()), 'invalid_grant')) {
            $this->finishItem(
                $job,
                (int) $item['id'],
                'error',
                $status === 403
                    ? 'La cuenta no tiene permiso para consultar esta orden.'
                    : 'La cuenta debe volver a autorizarse.',
                $error->requestId
            );
            return;
        }
        $this->retryOrFail($job, $item, 'Mercado Libre no permitió completar la consulta.', $error->requestId);
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $item */
    private function retryOrFail(array $job, array $item, string $message, ?string $diagnostic): void
    {
        $attempts = (int) $item['attempts'] + 1;
        $max = max(1, min(10, (new AppSettingsService())->int('sales_audit.repair_max_attempts', 3)));
        if ($attempts < $max) {
            $delay = min(3600, 60 * (2 ** max(0, $attempts - 1)) + random_int(1, 30));
            $this->deferItem($job, (int) $item['id'], 'retry', gmdate('Y-m-d H:i:s', time() + $delay), $message, $diagnostic);
            return;
        }
        $this->finishItem($job, (int) $item['id'], 'error', $message, $diagnostic);
    }

    /** @return array<string,mixed> */
    private function finalizeOrRelease(array $job, string $worker): array
    {
        $pdo = Database::connectionFresh();
        $stmt = $pdo->prepare(
            'SELECT
               SUM(i.status IN ("complete","already_present")) success_items,
               SUM(i.status="unavailable") unavailable_items,
               SUM(i.status="error") error_items,
               SUM(i.status IN ("pending","running","waiting_budget","retry")) active_items,
               MIN(CASE WHEN i.status IN ("pending","waiting_budget","retry") THEN i.next_run_at END) next_run_at
             FROM sync_sales_repair_job_items i
             INNER JOIN sync_sales_repair_jobs j ON j.id=i.sync_sales_repair_job_id
             WHERE i.sync_sales_repair_job_id=? AND j.company_id=? AND j.meli_account_id=?
               AND j.source_kind="exact"'
        );
        $stmt->execute([(int) $job['id'], (int) $job['company_id'], (int) $job['meli_account_id']]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $success = (int) ($counts['success_items'] ?? 0);
        $unavailable = (int) ($counts['unavailable_items'] ?? 0);
        $errors = (int) ($counts['error_items'] ?? 0);
        $active = (int) ($counts['active_items'] ?? 0);
        $processed = $success + $unavailable + $errors;

        if ($active > 0) {
            $next = (string) ($counts['next_run_at'] ?: gmdate('Y-m-d H:i:s'));
            $this->releaseJob($job, $worker, 'pending', $next, null, null, $success, $unavailable, $errors, $processed);
            return ['processed' => 0, 'jobs' => 1, 'status' => 'deferred'];
        }

        $status = ($errors > 0 || $unavailable > 0) ? 'partial' : 'complete';
        $message = $status === 'partial'
            ? 'La reparación terminó con órdenes no disponibles o que requieren revisión.'
            : null;
        $this->releaseJob(
            $job,
            $worker,
            $status,
            null,
            $message,
            null,
            $success,
            $unavailable,
            $errors,
            $processed,
            true
        );
        $verificationJobId = null;
        try {
            $verificationJobId = (new SalesAuditRunService())->createExactMonth(
                (int) $job['meli_account_id'],
                (int) $job['period_year'],
                (int) $job['period_month'],
                null,
                (int) $job['company_id']
            );
            $pdo->prepare(
                'UPDATE sync_sales_repair_jobs SET verification_audit_job_id=?
                 WHERE id=? AND company_id=? AND meli_account_id=? AND source_kind="exact"'
            )->execute([
                $verificationJobId,
                (int) $job['id'],
                (int) $job['company_id'],
                (int) $job['meli_account_id'],
            ]);
        } catch (Throwable $error) {
            SafeErrorPresenter::report($error, 'La reparación terminó, pero la comprobación final quedó pendiente.', [
                'module' => 'sales_repair',
                'job_id' => (int) $job['id'],
            ]);
        }
        return [
            'processed' => 0,
            'jobs' => 1,
            'status' => $status,
            'verification_job_id' => $verificationJobId,
        ];
    }

    private function releaseJob(
        array $job,
        string $worker,
        string $status,
        ?string $nextRunAt,
        ?string $message,
        ?string $diagnostic,
        ?int $success = null,
        ?int $unavailable = null,
        ?int $errors = null,
        ?int $processed = null,
        bool $finished = false
    ): void {
        Database::connectionFresh()->prepare(
            'UPDATE sync_sales_repair_jobs
             SET status=?,next_run_at=?,safe_error_message=?,diagnostic_id=?,
                 success_items=COALESCE(?,success_items),unavailable_items=COALESCE(?,unavailable_items),
                 error_items=COALESCE(?,error_items),processed_items=COALESCE(?,processed_items),
                 consecutive_failures=IF(? IN ("error","partial"),consecutive_failures+1,0),
                 lock_owner=NULL,lock_expires_at=NULL,heartbeat_at=NULL,
                 completed_at=IF(?,UTC_TIMESTAMP(),completed_at)
               WHERE id=? AND company_id=? AND meli_account_id=? AND source_kind="exact"
                 AND lock_owner=? AND lease_generation=?'
        )->execute([
            $status,
            $nextRunAt,
            $message,
            $diagnostic,
            $success,
            $unavailable,
            $errors,
            $processed,
            $status,
            $finished ? 1 : 0,
            (int) $job['id'],
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
            $worker,
            (int) $job['lease_generation'],
        ]);
    }

    private function localOrderId(int $companyId, int $accountId, string $externalOrderId): int
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT o.id FROM meli_orders o
             INNER JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE o.meli_account_id=? AND o.external_order_id=? LIMIT 1'
        );
        $stmt->execute([$companyId, $accountId, $externalOrderId]);
        return (int) $stmt->fetchColumn();
    }

    private function isDuplicate(Throwable $error): bool
    {
        return $error instanceof \PDOException
            && ((string) $error->getCode() === '23000' || str_contains($error->getMessage(), '1062'));
    }
}
