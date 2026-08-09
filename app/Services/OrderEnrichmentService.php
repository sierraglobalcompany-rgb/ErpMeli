<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Cola deduplicada para packs y envíos asociados a órdenes.
 */
final class OrderEnrichmentService
{
    private SchemaInspectorService $schema;
    private AppSettingsService $settings;

    public function __construct()
    {
        $this->schema = new SchemaInspectorService();
        $this->settings = new AppSettingsService();
    }

    public function isAvailable(): bool
    {
        return $this->schema->hasTable('order_resource_enrichment_jobs')
            && $this->schema->hasColumn('meli_orders', 'enrichment_status');
    }

    public function enqueueOrder(
        int $accountId,
        int $orderId,
        ?string $packExternalId,
        ?string $shipmentExternalId
    ): int {
        if (!$this->isAvailable()) {
            return 0;
        }

        $queued = 0;
        if ($packExternalId !== null && $packExternalId !== '') {
            $this->enqueue($accountId, $orderId, 'pack', $packExternalId, 10);
            $queued++;
        }
        if ($shipmentExternalId !== null && $shipmentExternalId !== '') {
            $this->enqueue($accountId, $orderId, 'shipment', $shipmentExternalId, 20);
            $queued++;
        }

        $status = $queued > 0 ? 'enrichment_pending' : 'complete';
        Database::connection()->prepare(
            'UPDATE meli_orders
             SET enrichment_status=:status,enrichment_error_message=NULL,
                 enriched_at=IF(:status_check="complete",UTC_TIMESTAMP(),enriched_at)
             WHERE id=:id AND meli_account_id=:account'
        )->execute(['status' => $status, 'status_check' => $status, 'id' => $orderId, 'account' => $accountId]);
        return $queued;
    }

    public function enqueue(
        int $accountId,
        int $orderId,
        string $resourceType,
        string $externalId,
        int $priority = 50
    ): int {
        if (!in_array($resourceType, ['pack', 'shipment'], true) || $externalId === '') {
            return 0;
        }
        if (!$this->orderBelongsToAccountCompany($accountId, $orderId)) {
            throw new \InvalidArgumentException('La orden no pertenece a la empresa y cuenta indicadas.');
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO order_resource_enrichment_jobs
             (meli_account_id,meli_order_id,resource_type,external_resource_id,status,priority,next_run_at)
             VALUES (:account,:order_id,:resource_type,:external_id,"pending",:priority,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                priority=LEAST(priority,VALUES(priority)),
                status=IF(status IN ("complete","running"),status,"pending"),
                next_run_at=IF(status IN ("complete","running"),next_run_at,UTC_TIMESTAMP()),
                last_error_message=IF(status IN ("complete","running"),last_error_message,NULL),
                last_error_diagnostic_id=IF(status IN ("complete","running"),last_error_diagnostic_id,NULL),
                last_error_code=IF(status IN ("complete","running"),last_error_code,NULL),
                failure_class=IF(status IN ("complete","running"),failure_class,NULL),
                reached_remote=IF(status IN ("complete","running"),reached_remote,NULL),
                updated_at=UTC_TIMESTAMP(),
                id=LAST_INSERT_ID(id)'
        );
        $stmt->execute([
            'account' => $accountId,
            'order_id' => $orderId,
            'resource_type' => $resourceType,
            'external_id' => $externalId,
            'priority' => $priority,
        ]);
        $jobId = (int) Database::connection()->lastInsertId();
        Database::connection()->prepare(
            'INSERT IGNORE INTO order_resource_enrichment_job_orders
             (order_resource_enrichment_job_id,meli_order_id)
             VALUES (:job_id,:order_id)'
        )->execute(['job_id' => $jobId, 'order_id' => $orderId]);
        return $jobId;
    }

    /**
     * @return array{processed:int,completed:int,errors:int,deferred:int,stop_reason:string}
     */
    public function processDue(?int $limit = null, ?float $deadline = null): array
    {
        return $this->processSelected($limit, $deadline, null);
    }

    /** @return array{processed:int,completed:int,errors:int,deferred:int,stop_reason:string} */
    public function processExact(int $jobId, ?float $deadline = null): array
    {
        return $this->processSelected(1, $deadline, $jobId);
    }

    public function processManualExact(int $jobId,?float $deadline=null): array
    {
        return $this->processSelected(1,$deadline,$jobId,false);
    }

    /** @return array{processed:int,completed:int,errors:int,deferred:int,stop_reason:string} */
    private function processSelected(?int $limit, ?float $deadline, ?int $jobId, bool $allowContinuation=true): array
    {
        if (!$this->isAvailable()) {
            return ['processed' => 0, 'completed' => 0, 'errors' => 0, 'deferred' => 0, 'stop_reason' => 'schema_unavailable'];
        }

        $limit ??= max(1, min(100, $this->settings->int('orders.enrichment_batch_limit', 10)));
        $deadline ??= microtime(true) + max(5, $this->settings->int('cron.run_time_budget_seconds', 45));
        $summary = ['processed' => 0, 'completed' => 0, 'errors' => 0, 'deferred' => 0, 'stop_reason' => 'empty'];
        $previousAccountId = null;

        for ($index = 0; $index < $limit; $index++) {
            if (!CronDeadlineContext::canStartRemote(2.0, $deadline)) {
                $summary['status'] = 'waiting_deadline';
                $summary['stop_reason'] = 'waiting_deadline';
                break;
            }
            $job = $this->claimNext($jobId, $previousAccountId);
            if ($job === null) {
                break;
            }
            $previousAccountId = (int) $job['meli_account_id'];
            $summary['processed']++;
            $summary['stop_reason'] = 'batch_limit';
            try {
                $result = (new OrderSyncService((int) $job['meli_account_id']))->processEnrichmentResource($job);
                if ($allowContinuation && !empty($result['spawned_shipment_id'])) {
                    $shipmentJobId = $this->enqueue(
                        (int) $job['meli_account_id'],
                        (int) $job['meli_order_id'],
                        'shipment',
                        (string) $result['spawned_shipment_id'],
                        20
                    );
                    $this->copyJobOrders((int) $job['id'], $shipmentJobId);
                }
                if (($job['resource_type'] ?? '') === 'pack') {
                    $this->attachPackToMappedOrders(
                        (int) $job['meli_account_id'],
                        (int) $job['id'],
                        (string) $job['external_resource_id']
                    );
                }
                $this->complete($job);
                $summary['completed']++;
            } catch (Throwable $error) {
                $failure = $this->failure($job, $error);
                $this->fail($job, $error, $failure);
                if ($failure['terminal']) {
                    $summary['completed']++;
                } elseif ($failure['retry']) {
                    $summary['deferred']++;
                } else {
                    $summary['errors']++;
                }
                if ($error instanceof RemoteResultUncertainException) {
                    $summary['stop_reason'] = 'action_required';
                    break;
                }
                if ($error instanceof CronDeadlineDeferredException) {
                    $summary['status'] = 'waiting_deadline';
                    $summary['stop_reason'] = 'waiting_deadline';
                    break;
                }
                if ($error instanceof ApiManualPauseException) {
                    $summary['status'] = 'waiting_api';
                    $summary['stop_reason'] = 'waiting_api';
                    break;
                }
                if ($error instanceof ApiBudgetExhaustedException) {
                    $summary['stop_reason'] = $error instanceof ApiRhythmDeferredException
                        ? 'api_rhythm'
                        : 'api_budget';
                    break;
                }
                if ($failure['code'] === 'http_429') {
                    $summary['status'] = 'retry_scheduled';
                    $summary['stop_reason'] = 'rate_limited';
                    break;
                }
            } finally {
                foreach ($this->jobOrderIds(
                    (int) $job['id'],
                    (int) $job['meli_account_id'],
                    (int) $job['company_id']
                ) as $orderId) {
                    $this->refreshOrderState($orderId, (int) $job['meli_account_id'], (int) $job['company_id']);
                }
            }
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function claimNext(?int $jobId = null, ?int $previousAccountId = null): ?array
    {
        $token = bin2hex(random_bytes(16));
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $reservationGuard = ManualCampaignReservationGuard::sql('order_enrichment', 'order_resource_enrichment_jobs.id');
            $stmt = $pdo->prepare(
                'SELECT order_resource_enrichment_jobs.*,a.company_id
                 FROM order_resource_enrichment_jobs
                 JOIN meli_accounts a ON a.id=order_resource_enrichment_jobs.meli_account_id
                 WHERE order_resource_enrichment_jobs.status IN ("pending","retry")
                   AND order_resource_enrichment_jobs.next_run_at<=UTC_TIMESTAMP()
                   AND (order_resource_enrichment_jobs.locked_at IS NULL OR order_resource_enrichment_jobs.locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))
                   AND (? IS NULL OR order_resource_enrichment_jobs.id=?)' . $reservationGuard . '
                  ORDER BY order_resource_enrichment_jobs.priority ASC,
                           CASE WHEN ? IS NOT NULL AND order_resource_enrichment_jobs.meli_account_id=? THEN 1 ELSE 0 END ASC,
                           order_resource_enrichment_jobs.next_run_at ASC,order_resource_enrichment_jobs.id ASC
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([$jobId, $jobId, $previousAccountId, $previousAccountId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $pdo->commit();
                return null;
            }
            $generation = (int) ($job['lease_generation'] ?? 0) + 1;
            $pdo->prepare(
                'UPDATE order_resource_enrichment_jobs
                 SET status="running",lock_token=:token,lease_generation=:generation,
                     locked_at=UTC_TIMESTAMP(),heartbeat_at=UTC_TIMESTAMP(),
                     attempts=attempts+1,last_started_at=UTC_TIMESTAMP()
                 WHERE id=:id'
            )->execute(['token' => $token, 'generation' => $generation, 'id' => (int) $job['id']]);
            $pdo->commit();
            $job['lock_token'] = $token;
            $job['lease_generation'] = $generation;
            $job['attempts'] = (int) $job['attempts'] + 1;
            return $job;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $job */
    private function complete(array $job): void
    {
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE order_resource_enrichment_jobs j
             JOIN meli_accounts a ON a.id=j.meli_account_id
             SET j.status="complete",j.completed_at=UTC_TIMESTAMP(),j.last_processed_at=UTC_TIMESTAMP(),
                 j.lock_token=NULL,j.locked_at=NULL,j.heartbeat_at=NULL,j.last_error_message=NULL,
                j.last_error_diagnostic_id=NULL,j.last_error_code=NULL,j.failure_class=NULL,j.reached_remote=1
             WHERE j.id=:id AND j.meli_account_id=:account AND j.lock_token=:token AND j.lease_generation=:generation
               AND a.company_id=:company'
        );
        $stmt->execute([
            'id' => (int) $job['id'],
            'account' => (int) $job['meli_account_id'],
            'company' => (int) $job['company_id'],
            'token' => (string) $job['lock_token'],
            'generation' => (int) $job['lease_generation'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('El resultado no se guardó porque el trabajo perdió su reserva.');
        }
    }

    /** @param array<string,mixed> $job */
    /**
     * @param array<string,mixed> $failure
     */
    private function fail(array $job, Throwable $error, array $failure): void
    {
        $reported = !empty($failure['report_error'])
            ? SafeErrorPresenter::report(
                $error,
                (string) $failure['safe_message'],
                [
                    'queue_key' => 'order_enrichment',
                    'source_work_id' => (int) $job['id'],
                    'meli_account_id' => (int) $job['meli_account_id'],
                    'company_id' => (int) $job['company_id'],
                    'resource_type' => (string) $job['resource_type'],
                    'failure_class' => (string) $failure['class'],
                    'reached_remote' => (bool) $failure['reached_remote'],
                ]
            )
            : ['reference' => null];
        $status = $failure['terminal'] ? 'complete' : ($failure['retry'] ? 'retry' : 'error');
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE order_resource_enrichment_jobs j
             JOIN meli_accounts a ON a.id=j.meli_account_id
                 SET j.status=:status,j.next_run_at=:next_run_at,
                 j.completed_at=IF(:terminal=1,UTC_TIMESTAMP(),j.completed_at),
                 j.attempts=GREATEST(0,j.attempts-:restore_attempt),
                 j.lock_token=NULL,j.locked_at=NULL,j.heartbeat_at=NULL,j.last_processed_at=UTC_TIMESTAMP(),
                 j.last_error_message=:error,j.last_error_diagnostic_id=:diagnostic,
                 j.last_error_code=:error_code,j.failure_class=:failure_class,j.reached_remote=:reached_remote
             WHERE j.id=:id AND j.meli_account_id=:account AND j.lock_token=:token AND j.lease_generation=:generation
               AND a.company_id=:company'
        );
        $stmt->execute([
            'status' => $status,
            'terminal' => $failure['terminal'] ? 1 : 0,
            'restore_attempt' => $failure['consume_attempt'] ? 0 : 1,
            'next_run_at' => gmdate('Y-m-d H:i:s', (int) $failure['next_at']),
            'error' => mb_substr((string) $failure['safe_message'], 0, 500),
            'diagnostic' => $reported['reference'] ?? null,
            'error_code' => mb_substr((string) $failure['code'], 0, 80),
            'failure_class' => mb_substr((string) $failure['class'], 0, 80),
            'reached_remote' => $failure['reached_remote'] ? 1 : 0,
            'id' => (int) $job['id'],
            'account' => (int) $job['meli_account_id'],
            'company' => (int) $job['company_id'],
            'token' => (string) $job['lock_token'],
            'generation' => (int) $job['lease_generation'],
        ]);
    }

    /**
     * @param array<string,mixed> $job
     * @return array{retry:bool,terminal:bool,class:string,code:string,reached_remote:bool,next_at:int,safe_message:string,consume_attempt:bool,report_error:bool}
     */
    private function failure(array $job, Throwable $error): array
    {
        $classified = SyncErrorClassifier::classify($error);
        $httpStatus = $error instanceof MeliApiException ? $error->httpStatus : null;
        // Un error de transporte puede no tener HTTP status, pero sí contar con
        // request_id porque cURL ya fue despachado. No perder esa evidencia.
        $reachedRemote = $error instanceof MeliApiException && $error->requestId !== null;
        $attemptsRemain = (int) $job['attempts'] < max(1, $this->settings->int('orders.enrichment_max_attempts', 3));
        $retry = $attemptsRemain;
        $terminal = false;
        $class = (string) $classified['type'];
        $code = $httpStatus !== null ? 'http_' . $httpStatus : $class;
        $safeMessage = (string) $classified['message'];
        $nextAt = time() + (max(1, $this->settings->int('orders.enrichment_retry_minutes', 5)) * 60);
        $consumeAttempt = true;
        $reportError = true;

        if ($error instanceof RemoteResultUncertainException) {
            $class = 'remote_result_uncertain';
            $code = 'remote_result_uncertain';
            $reachedRemote = true;
            $retry = false;
            $safeMessage = 'Mercado Libre respondió, pero el permiso local venció. Revise antes de reintentar.';
        } elseif ($error instanceof CronDeadlineDeferredException) {
            $class = 'waiting_deadline';
            $code = 'cron_deadline_deferred';
            $reachedRemote = false;
            $retry = true;
            $consumeAttempt = false;
            $reportError = false;
            $safeAt = (new SystemDatabaseUtcClock())->timestamp((string) ($error->nextSafeAt ?? ''));
            $nextAt = $safeAt !== null ? max(time() + 1, $safeAt) : time() + 5;
            $safeMessage = 'Esperando el siguiente ciclo: no se inició ninguna consulta remota.';
        } elseif ($error instanceof ApiRhythmDeferredException) {
            $class = 'waiting_rhythm';
            $code = 'api_rhythm_deferred';
            $reachedRemote = $error->reachedRemote;
            $retry = true;
            $safeAt = (new SystemDatabaseUtcClock())->timestamp((string) ($error->nextSafeAt ?? ''));
            $nextAt = $safeAt !== null ? max(time() + 5, $safeAt) : time() + 60;
            $safeMessage = 'Esperando la próxima oportunidad del ritmo seguro de consultas.';
            $consumeAttempt = false;
            $reportError = false;
        } elseif ($error instanceof ApiBudgetExhaustedException) {
            $class = 'waiting_budget';
            $code = 'api_budget_exhausted';
            $reachedRemote = false;
            $retry = true;
            $safeAt = (new SystemDatabaseUtcClock())->timestamp((string) ($error->nextSafeAt ?? ''));
            $nextAt = $safeAt !== null ? max($nextAt, $safeAt) : $nextAt;
            $safeMessage = 'Esperando la próxima ventana segura de presupuesto de Mercado Libre.';
            $consumeAttempt = false;
            $reportError = false;
        } elseif ($error instanceof ApiManualPauseException) {
            $class = 'waiting_api';
            $code = 'api_paused';
            $reachedRemote = false;
            $retry = true;
            $safeMessage = 'Mercado Libre está detenido. El recurso conserva su turno.';
            $consumeAttempt = false;
            $reportError = false;
        } elseif ($httpStatus === 404) {
            $class = 'remote_absent';
            $code = 'http_404';
            $retry = false;
            $terminal = true;
            $safeMessage = 'Mercado Libre indicó que el recurso ya no está disponible.';
            $reportError = false;
        } elseif ($httpStatus === 403) {
            $class = 'action_required';
            $code = 'http_403';
            $retry = false;
            $safeMessage = 'La cuenta no tiene permiso para consultar este recurso. Revise su autorización.';
        } elseif ($httpStatus === 429) {
            $class = 'rate_limited';
            $code = 'http_429';
            $retry = true;
            $safeMessage = 'Mercado Libre pidió esperar antes de volver a consultar este recurso.';
            $consumeAttempt = false;
            $reportError = false;
        } elseif ($error instanceof \InvalidArgumentException) {
            $class = 'missing_resource_identity';
            $code = 'invalid_resource_identity';
            $reachedRemote = false;
            $retry = false;
            $safeMessage = 'El trabajo no tiene una identidad de pack o envío válida y requiere corrección local.';
        }

        return [
            'retry' => $retry,
            'terminal' => $terminal,
            'class' => $class,
            'code' => $code,
            'reached_remote' => $reachedRemote,
            'next_at' => $nextAt,
            'safe_message' => $safeMessage,
            'consume_attempt' => $consumeAttempt,
            'report_error' => $reportError,
        ];
    }

    private function refreshOrderState(int $orderId, int $accountId, int $companyId): void
    {
        $stmt = Database::connection()->prepare(
            'SELECT
                SUM(status IN ("pending","retry","running")) pending_count,
                SUM(status="error") error_count,
                COUNT(*) total_count
             FROM order_resource_enrichment_jobs jobs
             WHERE jobs.id IN (
                 SELECT mapped.order_resource_enrichment_job_id
                 FROM order_resource_enrichment_job_orders mapped
                 JOIN meli_orders scoped_order ON scoped_order.id=mapped.meli_order_id
                 JOIN meli_accounts scoped_account ON scoped_account.id=scoped_order.meli_account_id
                 WHERE mapped.meli_order_id=:order_id
                   AND scoped_order.meli_account_id=:account_id
                   AND scoped_account.company_id=:company_id
             )'
        );
        $stmt->execute(['order_id' => $orderId, 'account_id' => $accountId, 'company_id' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $pending = (int) ($row['pending_count'] ?? 0);
        $errors = (int) ($row['error_count'] ?? 0);
        $total = (int) ($row['total_count'] ?? 0);
        $status = $pending > 0 ? 'enrichment_pending' : ($errors > 0 ? 'partial' : ($total > 0 ? 'complete' : 'basic'));
        Database::connection()->prepare(
            'UPDATE meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id
             SET o.enrichment_status=:status,
                 o.enrichment_error_message=IF(:has_error=1,"Uno o más recursos no pudieron enriquecerse.",NULL),
                 o.enriched_at=IF(:is_complete=1,UTC_TIMESTAMP(),o.enriched_at)
             WHERE o.id=:id AND o.meli_account_id=:account_id
               AND a.company_id=:company_id'
        )->execute([
            'status' => $status,
            'has_error' => $errors > 0 ? 1 : 0,
            'is_complete' => $status === 'complete' ? 1 : 0,
            'id' => $orderId,
            'account_id' => $accountId,
            'company_id' => $companyId,
        ]);
    }

    /**
     * @return list<int>
     */
    private function jobOrderIds(int $jobId, int $accountId, int $companyId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT mapped.meli_order_id
             FROM order_resource_enrichment_job_orders mapped
             JOIN meli_orders o ON o.id=mapped.meli_order_id AND o.meli_account_id=:account_id
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=:company_id
             WHERE mapped.order_resource_enrichment_job_id=:job_id'
        );
        $stmt->execute(['job_id' => $jobId, 'account_id' => $accountId, 'company_id' => $companyId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function copyJobOrders(int $sourceJobId, int $targetJobId): void
    {
        if ($sourceJobId < 1 || $targetJobId < 1) {
            return;
        }
        Database::connection()->prepare(
            'INSERT IGNORE INTO order_resource_enrichment_job_orders
             (order_resource_enrichment_job_id,meli_order_id)
             SELECT :target_job_id,meli_order_id
             FROM order_resource_enrichment_job_orders
             WHERE order_resource_enrichment_job_id=:source_job_id'
        )->execute([
            'target_job_id' => $targetJobId,
            'source_job_id' => $sourceJobId,
        ]);
    }

    private function attachPackToMappedOrders(int $accountId, int $jobId, string $externalPackId): void
    {
        $companyId = $this->companyIdForAccount($accountId);
        $stmt = Database::connection()->prepare(
            'SELECT p.id
             FROM meli_packs p
             JOIN meli_accounts a ON a.id=p.meli_account_id AND a.company_id=:company_id
             WHERE p.meli_account_id=:account_id AND p.external_pack_id=:external_id
             LIMIT 1'
        );
        $stmt->execute(['account_id' => $accountId, 'company_id' => $companyId, 'external_id' => $externalPackId]);
        $packId = (int) ($stmt->fetchColumn() ?: 0);
        if ($packId < 1) {
            return;
        }
        Database::connection()->prepare(
            'INSERT IGNORE INTO meli_pack_orders (meli_pack_id,meli_order_id)
             SELECT :pack_id,mapped.meli_order_id
             FROM order_resource_enrichment_job_orders mapped
             JOIN meli_orders o
              ON o.id=mapped.meli_order_id
              AND o.meli_account_id=:account_id
              AND o.external_pack_id=:external_pack_id
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=:company_id
             WHERE mapped.order_resource_enrichment_job_id=:job_id'
        )->execute([
            'pack_id' => $packId,
            'account_id' => $accountId,
            'company_id' => $companyId,
            'external_pack_id' => $externalPackId,
            'job_id' => $jobId,
        ]);
    }

    private function orderBelongsToAccountCompany(int $accountId, int $orderId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id
             JOIN companies c ON c.id=a.company_id AND c.status=1
             WHERE o.id=:order_id AND o.meli_account_id=:account_id
             LIMIT 1'
        );
        $stmt->execute(['order_id' => $orderId, 'account_id' => $accountId]);
        return $stmt->fetchColumn() !== false;
    }

    private function companyIdForAccount(int $accountId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT a.company_id
             FROM meli_accounts a
             JOIN companies c ON c.id=a.company_id AND c.status=1
             WHERE a.id=:account_id
             LIMIT 1'
        );
        $stmt->execute(['account_id' => $accountId]);
        $companyId = (int) ($stmt->fetchColumn() ?: 0);
        if ($companyId < 1) {
            throw new \InvalidArgumentException('La cuenta no pertenece a una empresa activa.');
        }
        return $companyId;
    }
}
