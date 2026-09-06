<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use DatePeriod;
use PDO;
use Throwable;

/**
 * Auditoría mensual exacta basada en un único snapshot remoto deduplicado.
 *
 * El trabajo pagina Mercado Libre en ciclos cortos y solo clasifica cuando el
 * snapshot del mes está completo. De esta forma los días nunca se suman desde
 * búsquedas remotas independientes que puedan compartir límites inclusivos.
 */
final class SalesAuditRunService
{
    private const OAUTH_DEFER_CLASS = 'oauth_refresh_required';
    private const LEGACY_OAUTH_ERROR_CLASS = 'App\\Services\\OAuthRefreshRequiredExcepti';

    /** @var \Closure(int):MeliApiClient */
    private \Closure $clientFactory;

    /** @param null|callable(int):MeliApiClient $clientFactory */
    public function __construct(?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory !== null
            ? \Closure::fromCallable($clientFactory)
            : static fn(int $accountId): MeliApiClient => new MeliApiClient($accountId);
    }

    public function available(): bool
    {
        $schema = new SchemaInspectorService();
        return $schema->hasTable('sync_sales_audit_runs')
            && $schema->hasTable('sync_sales_audit_run_orders')
            && $schema->hasTable('sync_sales_audit_jobs');
    }

    public function createExactMonth(
        int $accountId,
        int $year,
        int $month,
        ?int $userId = null,
        int $companyId = 0,
        string $captureRole = 'primary',
        ?int $verificationOfRunId = null,
        ?\DateTimeImmutable $notBefore = null
    ): int
    {
        $captureRole = $captureRole === 'verification' ? 'verification' : 'primary';
        $account = $this->assertAccountPeriod($accountId, $year, $month, $companyId);
        $companyId = (int) $account['company_id'];
        if (!$this->available()) {
            throw new \RuntimeException('La actualización de auditorías todavía no está instalada.');
        }

        $pdo = Database::connection();
        $lockName = 'sales-audit-create:' . $accountId . ':' . $year . ':' . $month;
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?,3)');
        $lockStmt->execute([$lockName]);
        if ((int) $lockStmt->fetchColumn() !== 1) {
            throw new \RuntimeException('Otra solicitud está creando esta auditoría. Espere unos segundos y vuelva a abrir el periodo.');
        }
        try {
            $active = $pdo->prepare(
            'SELECT j.id
             FROM sync_sales_audit_jobs j
             JOIN sync_sales_audit_runs r
               ON r.id=j.sync_sales_audit_run_id AND r.company_id=j.company_id
              AND r.meli_account_id=j.meli_account_id
             WHERE r.company_id=? AND r.meli_account_id=? AND r.period_year=? AND r.period_month=?
               AND j.status IN ("pending","running","waiting_budget","paused")
             ORDER BY j.id DESC LIMIT 1'
        );
            $active->execute([$companyId, $accountId, $year, $month]);
            $existing = (int) $active->fetchColumn();
            if ($existing > 0) {
                return $existing;
            }

            $range = (new MeliDateRangeService())->localMonth($year, $month);
            $temporalCoverage = (new SalesAuditTemporalCoverageService())->classify(
                $range['utc_from'],
                $range['utc_to']
            );
            $settings = new AppSettingsService();
            $pageLimit = max(1, min(50, $settings->int('sales_audit.exact_page_limit', 50)));
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                'INSERT INTO sync_sales_audit_runs
                 (meli_account_id,company_id,period_year,period_month,mode,status,remote_coverage,local_presence,
                   capture_role,verification_of_run_id,verification_not_before,
                   temporal_quality,reconciliation_status,timezone_used,normalizer_version,
                   local_from,local_to,utc_from,utc_to,
                   requested_from_utc,requested_to_utc,historical_window_starts_at,
                   effective_coverage_from_utc,effective_coverage_to_utc,
                   temporal_coverage_state,temporal_coverage_reason,coverage_contract_version,
                   created_by,capture_started_at)
                 VALUES (?,?,?,?,"exact","pending","pending","pending",?,?,?,
                         "pending","pending",?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())'
                )->execute([
                $accountId,
                $companyId,
                $year,
                $month,
                $captureRole,
                $verificationOfRunId,
                $notBefore?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $range['timezone'],
                MeliDateTimeNormalizer::VERSION,
                $range['local_from']->format('Y-m-d H:i:s'),
                $range['local_to']->format('Y-m-d H:i:s'),
                $range['utc_from']->format('Y-m-d H:i:s'),
                $range['utc_to']->format('Y-m-d H:i:s'),
                $temporalCoverage['requested_from_utc'],
                $temporalCoverage['requested_to_utc'],
                $temporalCoverage['historical_window_starts_at'],
                $temporalCoverage['effective_from_utc'],
                $temporalCoverage['effective_to_utc'],
                $temporalCoverage['state'],
                mb_substr($temporalCoverage['reason'], 0, 500),
                $temporalCoverage['contract_version'],
                $userId ?? Auth::id(),
                ]);
                $runId = (int) $pdo->lastInsertId();
                $pdo->prepare(
                'INSERT INTO sync_sales_audit_jobs
                 (sync_sales_audit_run_id,meli_account_id,company_id,status,capture_role,page_limit,next_run_at)
                 VALUES (?,?,?,"pending",?,?,?)'
                )->execute([
                    $runId,
                    $accountId,
                    $companyId,
                    $captureRole,
                    $pageLimit,
                    $notBefore?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
                        ?? gmdate('Y-m-d H:i:s'),
                ]);
                $jobId = (int) $pdo->lastInsertId();
                $pdo->commit();
                return $jobId;
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable) {
            }
        }
    }

    /** @return array<string,mixed> */
    public function processDue(int $pagesPerCycle = 2, ?float $deadline = null): array
    {
        return $this->processSelected($pagesPerCycle, $deadline, null);
    }

    /** @return array<string,mixed> */
    public function processExact(int $jobId, int $pagesPerCycle = 1, ?float $deadline = null): array
    {
        return $this->processSelected($pagesPerCycle, $deadline, $jobId);
    }

    /** @return array<string,mixed> */
    private function processSelected(int $pagesPerCycle, ?float $deadline, ?int $jobId): array
    {
        if (!$this->available()) {
            return ['processed' => 0, 'errors' => 0, 'status' => 'empty'];
        }
        // The global legacy repair belongs to the automatic scheduler. A
        // tenant-scoped manual `processExact()` request must never mutate
        // unrelated accounts as a side effect.
        $repair = $jobId === null
            ? $this->repairLegacyOAuthFalseErrors()
            : ['misclassified_oauth_repaired' => 0, 'abort_scheduler' => false];
        if (($repair['abort_scheduler'] ?? false) === true) {
            return $repair + ['claimed' => 0, 'processed' => 0, 'errors' => 0];
        }
        $repairCount = (int) ($repair['misclassified_oauth_repaired'] ?? 0);
        $retryableReadmitted = $jobId === null ? $this->readmitRetryableNotDispatchedErrors() : 0;
        $pagesPerCycle = max(1, min(5, $pagesPerCycle));
        $worker = 'sales-audit-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $admission = null;
        $job = $this->claim($worker, $jobId, $admission);
        if (!$job) {
            return ($admission ?? ['claimed' => 0, 'processed' => 0, 'errors' => 0, 'status' => 'empty'])
                + [
                    'misclassified_oauth_repaired' => $repairCount,
                    'retryable_not_dispatched_readmitted' => $retryableReadmitted,
                ];
        }

        $processed = 0;
        try {
            for ($page = 0; $page < $pagesPerCycle; $page++) {
                if ($deadline !== null && microtime(true) >= $deadline - 2.0) {
                    $this->release($job, $worker, 'pending', 'time_budget', null, gmdate('Y-m-d H:i:s', time() + 5), true);
                    return ['claimed' => 1, 'processed' => $processed, 'errors' => 0, 'status' => 'deferred', 'stop_reason' => 'time_budget', 'misclassified_oauth_repaired' => $repairCount, 'retryable_not_dispatched_readmitted' => $retryableReadmitted];
                }
                $result = $this->fetchPage($job, $worker);
                $processed += $result['inserted'];
                $job['next_offset'] = $result['next_offset'];
                $job['remote_reported_total'] = $result['remote_total'];
                if (!empty($result['finished'])) {
                    $this->finalizeRun(
                        (int) $job['sync_sales_audit_run_id'],
                        (int) $job['company_id'],
                        (int) $job['meli_account_id'],
                    );
                    $this->complete($job, $worker);
                    return ['claimed' => 1, 'processed' => $processed, 'errors' => 0, 'status' => 'complete', 'run_id' => (int) $job['sync_sales_audit_run_id'], 'misclassified_oauth_repaired' => $repairCount, 'retryable_not_dispatched_readmitted' => $retryableReadmitted];
                }
            }
            $this->release($job, $worker, 'pending', null, null, gmdate('Y-m-d H:i:s', time() + 5), true);
            return ['claimed' => 1, 'processed' => $processed, 'errors' => 0, 'status' => 'deferred', 'stop_reason' => 'page_checkpoint', 'misclassified_oauth_repaired' => $repairCount, 'retryable_not_dispatched_readmitted' => $retryableReadmitted];
        } catch (RemoteResultUncertainException $error) {
            $this->release(
                $job, $worker, 'error', 'remote_result_uncertain', $error,
                null, false, $error->requestId
            );
            throw $error; // Stop the shared cycle; do not start its next stage.
        } catch (QueueV4PreTransportDeferredException $error) {
            $this->release($job, $worker, 'waiting_budget', 'pre_transport_deferred', $error, $error->nextSafeAt, true);
            return ['claimed' => 1, 'processed' => $processed, 'errors' => 0, 'status' => 'deferred', 'stop_reason' => 'pre_transport', 'misclassified_oauth_repaired' => $repairCount, 'retryable_not_dispatched_readmitted' => $retryableReadmitted];
        } catch (ApiRhythmDeferredException $error) {
            $this->release($job, $worker, 'waiting_budget', $error->blockingScope, $error, $error->nextSafeAt, true);
            return ['claimed' => 1, 'processed' => $processed, 'errors' => 0, 'status' => 'deferred', 'stop_reason' => $error->blockingScope, 'misclassified_oauth_repaired' => $repairCount, 'retryable_not_dispatched_readmitted' => $retryableReadmitted];
        } catch (ApiBudgetExhaustedException $error) {
            $this->release($job, $worker, 'waiting_budget', 'api_budget', $error, $error->nextSafeAt, true);
            return ['claimed' => 1, 'processed' => $processed, 'errors' => 0, 'status' => 'deferred', 'stop_reason' => 'api_budget', 'misclassified_oauth_repaired' => $repairCount, 'retryable_not_dispatched_readmitted' => $retryableReadmitted];
        } catch (ApiManualPauseException $error) {
            $this->release($job, $worker, 'waiting_budget', 'api_pause', $error, $error->resumeAt, true);
            return ['claimed' => 1, 'processed' => $processed, 'errors' => 0, 'status' => 'deferred', 'stop_reason' => 'api_pause', 'misclassified_oauth_repaired' => $repairCount, 'retryable_not_dispatched_readmitted' => $retryableReadmitted];
        } catch (OAuthRefreshRequiredException $error) {
            $deferred = $this->deferClaimedForOAuth($job, $worker, $error);
            return $deferred + ['claimed' => 1, 'processed' => $processed, 'errors' => 0, 'misclassified_oauth_repaired' => $repairCount, 'retryable_not_dispatched_readmitted' => $retryableReadmitted];
        } catch (Throwable $error) {
            $safe = SafeErrorPresenter::report($error, 'No fue posible continuar la auditoría exacta.', [
                'module' => 'sales_audit',
                'job_id' => (int) $job['id'],
            ]);
            $this->release($job, $worker, 'error', 'error', $error, null, false, $safe['reference']);
            return [
                'claimed' => 1,
                'processed' => $processed,
                'errors' => 1,
                'status' => 'error',
                'diagnostic_id' => $safe['reference'],
                'message' => $safe['message'],
                'misclassified_oauth_repaired' => $repairCount,
                'retryable_not_dispatched_readmitted' => $retryableReadmitted,
            ];
        }
    }

    /** @return array<string,mixed>|null */
    public function latest(
        int $accountId,
        int $year,
        int $month,
        int $page = 1,
        int $perPage = 50,
        ?string $classification = null,
        int $companyId = 0
    ): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $authorizedAccount = (new SalesAuditAccessGateway())->account($accountId, $companyId);
        $companyId = (int) $authorizedAccount['company_id'];
        $stmt = Database::connection()->prepare(
            'SELECT r.*,a.account_name,j.id job_id,j.status job_status,j.next_offset,j.remote_reported_total job_remote_total,
                    j.next_run_at,j.safe_error_message job_error,j.diagnostic_id job_diagnostic
             FROM sync_sales_audit_runs r
             JOIN meli_accounts a ON a.company_id=r.company_id AND a.id=r.meli_account_id
             LEFT JOIN sync_sales_audit_jobs j
               ON j.sync_sales_audit_run_id=r.id AND j.company_id=r.company_id
              AND j.meli_account_id=r.meli_account_id
              WHERE r.company_id=? AND r.meli_account_id=? AND r.period_year=? AND r.period_month=?
              ORDER BY r.id DESC LIMIT 1'
        );
        $stmt->execute([$companyId, $accountId, $year, $month]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            return null;
        }
        $days = Database::connection()->prepare(
            'SELECT d.* FROM sync_sales_audit_run_days d
             INNER JOIN sync_sales_audit_runs r
               ON r.id=d.sync_sales_audit_run_id AND r.company_id=? AND r.meli_account_id=?
             WHERE d.sync_sales_audit_run_id=? ORDER BY d.audit_date'
        );
        $days->execute([$companyId, $accountId, (int) $run['id']]);
        $run['days'] = $days->fetchAll(PDO::FETCH_ASSOC);

        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
        $page = max(1, $page);
        $where = 'ro.sync_sales_audit_run_id=? AND ro.meli_account_id=?'
            . ' AND r.company_id=? AND r.meli_account_id=? AND ro.classification<>"present"';
        $params = [(int) $run['id'], $accountId, $companyId, $accountId];
        if ($classification !== null && $classification !== '') {
            $where .= ' AND ro.classification=?';
            $params[] = $classification;
        }
        $from = ' FROM sync_sales_audit_run_orders ro'
            . ' INNER JOIN sync_sales_audit_runs r ON r.id=ro.sync_sales_audit_run_id';
        $count = Database::connection()->prepare('SELECT COUNT(*)' . $from . ' WHERE ' . $where);
        $count->execute($params);
        $run['difference_total'] = (int) $count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $details = Database::connection()->prepare(
            'SELECT ro.*' . $from . ' WHERE ' . $where . '
             ORDER BY ro.audit_date ASC,ro.classification ASC,ro.id ASC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $details->execute($params);
        $run['differences'] = $details->fetchAll(PDO::FETCH_ASSOC);
        $run['page'] = $page;
        $run['per_page'] = $perPage;
        $run['pages'] = max(1, (int) ceil($run['difference_total'] / $perPage));
        return $run;
    }

    /** @return array<string,mixed>|null */
    public function findJob(int $jobId, int $companyId = 0): ?array
    {
        if (!$this->available() || $jobId <= 0) {
            return null;
        }
        try {
            $job = (new SalesAuditAccessGateway())->auditJob($jobId, $companyId);
        } catch (\App\Core\HttpException) {
            return null;
        }
        $reported = max(0, (int) ($job['remote_reported_total'] ?? 0));
        $offset = max(0, (int) ($job['next_offset'] ?? 0));
        $job['progress_percent'] = $reported > 0
            ? min(100, (int) floor(($offset / $reported) * 100))
            : ((string) $job['status'] === 'complete' ? 100 : 0);
        $job['estimated_pages'] = $reported > 0
            ? max(1, (int) ceil($reported / max(1, (int) $job['page_limit'])))
            : null;
        return $job;
    }

    /** @return array<string,mixed> */
    private function repairLegacyOAuthFalseErrors(): array
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $candidates = $pdo->prepare(
                "SELECT j.* FROM sync_sales_audit_jobs j
                 INNER JOIN sync_sales_audit_runs r
                   ON r.id=j.sync_sales_audit_run_id AND r.company_id=j.company_id
                  AND r.meli_account_id=j.meli_account_id
                 INNER JOIN meli_accounts a ON a.company_id=j.company_id AND a.id=j.meli_account_id
                 WHERE j.status='error' AND j.last_error_class=?
                   AND j.attempts>=1 AND j.consecutive_failures>=1
                   AND j.remote_dispatch_state='NOT_DISPATCHED' AND j.last_http_status IS NULL
                   AND NOT EXISTS (
                     SELECT 1 FROM queue_v4_clean_transport_events e
                     WHERE e.source_kind='sales_audit' AND e.work_id=j.id
                       AND e.company_id=j.company_id AND e.meli_account_id=j.meli_account_id
                       AND e.lease_generation=j.lease_generation
                   )
                 ORDER BY j.company_id,j.meli_account_id,j.id
                 LIMIT 100 FOR UPDATE"
            );
            $candidates->execute([self::LEGACY_OAUTH_ERROR_CLASS]);
            $rows = $candidates->fetchAll(PDO::FETCH_ASSOC);
            $repaired = 0;
            $skew = max(30, min(600, (new AppSettingsService())->int('oauth.token_expiry_skew_seconds', 120)));
            foreach ($rows as $row) {
                $next = $this->nextOAuthOpportunity(
                    $pdo,
                    (int) $row['company_id'],
                    (int) $row['meli_account_id'],
                    true,
                    $skew,
                );
                if ($next === null) {
                    $pdo->rollBack();
                    return $this->abortResult('oauth_dependency_authority_missing');
                }
                $update = $pdo->prepare(
                    "UPDATE sync_sales_audit_jobs j
                     SET j.status='waiting_budget',j.next_run_at=?,j.locked_by=NULL,j.lock_expires_at=NULL,
                         j.attempts=GREATEST(j.attempts-1,0),
                         j.consecutive_failures=GREATEST(j.consecutive_failures-1,0),
                         j.diagnostic_id=NULL,j.safe_error_message=NULL,
                         j.last_error_class=?,j.last_error_retryable=1,j.heartbeat_at=NULL,j.updated_at=UTC_TIMESTAMP()
                     WHERE j.id=? AND j.company_id=? AND j.meli_account_id=? AND j.sync_sales_audit_run_id=?
                       AND j.status='error' AND j.last_error_class=?
                       AND j.attempts>=1 AND j.consecutive_failures>=1
                       AND j.remote_dispatch_state='NOT_DISPATCHED' AND j.last_http_status IS NULL
                       AND NOT EXISTS (
                         SELECT 1 FROM queue_v4_clean_transport_events e
                         WHERE e.source_kind='sales_audit' AND e.work_id=j.id
                           AND e.company_id=j.company_id AND e.meli_account_id=j.meli_account_id
                           AND e.lease_generation=j.lease_generation
                       )"
                );
                $update->execute([
                    $next,
                    self::OAUTH_DEFER_CLASS,
                    (int) $row['id'],
                    (int) $row['company_id'],
                    (int) $row['meli_account_id'],
                    (int) $row['sync_sales_audit_run_id'],
                    self::LEGACY_OAUTH_ERROR_CLASS,
                ]);
                if ($update->rowCount() !== 1) {
                    $pdo->rollBack();
                    return $this->abortResult('sales_audit_oauth_repair_cas_lost');
                }
                $pdo->prepare(
                    "UPDATE sync_sales_audit_runs r
                     SET r.status='running',r.safe_error_message=NULL,r.diagnostic_id=NULL,r.updated_at=UTC_TIMESTAMP()
                     WHERE r.id=? AND r.company_id=? AND r.meli_account_id=? AND r.status='error'
                       AND r.diagnostic_id <=> ?
                       AND NOT EXISTS (
                         SELECT 1 FROM sync_sales_audit_jobs other
                         WHERE other.sync_sales_audit_run_id=r.id AND other.company_id=r.company_id
                           AND other.meli_account_id=r.meli_account_id AND other.status='error'
                       )"
                )->execute([
                    (int) $row['sync_sales_audit_run_id'],
                    (int) $row['company_id'],
                    (int) $row['meli_account_id'],
                    $row['diagnostic_id'] ?? null,
                ]);
                $repaired++;
            }
            $pdo->commit();
            return ['misclassified_oauth_repaired' => $repaired, 'abort_scheduler' => false];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function readmitRetryableNotDispatchedErrors(): int
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $candidates = $pdo->prepare(
                "SELECT j.id,j.company_id,j.meli_account_id,j.sync_sales_audit_run_id
                 FROM sync_sales_audit_jobs j
                 INNER JOIN sync_sales_audit_runs r
                   ON r.id=j.sync_sales_audit_run_id AND r.company_id=j.company_id
                  AND r.meli_account_id=j.meli_account_id
                 INNER JOIN meli_accounts a ON a.company_id=j.company_id AND a.id=j.meli_account_id
                 WHERE j.status='error'
                   AND COALESCE(j.last_error_retryable,0)=1
                   AND COALESCE(j.processed_pages,0)=0
                   AND j.remote_dispatch_state='NOT_DISPATCHED'
                   AND j.last_http_status IS NULL
                   AND j.next_run_at<=UTC_TIMESTAMP()
                   AND j.attempts<=10
                   AND LOWER(a.status) IN ('conectado','connected')
                   AND (j.lock_expires_at IS NULL OR j.lock_expires_at<UTC_TIMESTAMP())
                 ORDER BY j.company_id,j.meli_account_id,j.id
                 LIMIT 25 FOR UPDATE"
            );
            $candidates->execute();
            $rows = $candidates->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                $pdo->commit();
                return 0;
            }
            $update = $pdo->prepare(
                "UPDATE sync_sales_audit_jobs
                 SET status='waiting_budget',
                     next_run_at=UTC_TIMESTAMP(),
                     locked_by=NULL,
                     lock_expires_at=NULL,
                     heartbeat_at=NULL,
                     safe_error_message='La auditoría se reanudará automáticamente; el intento anterior no salió al transporte remoto.',
                     updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND company_id=? AND meli_account_id=? AND sync_sales_audit_run_id=?
                   AND status='error'
                   AND COALESCE(last_error_retryable,0)=1
                   AND COALESCE(processed_pages,0)=0
                   AND remote_dispatch_state='NOT_DISPATCHED'
                   AND last_http_status IS NULL"
            );
            $readmitted = 0;
            foreach ($rows as $row) {
                $update->execute([
                    (int) $row['id'],
                    (int) $row['company_id'],
                    (int) $row['meli_account_id'],
                    (int) $row['sync_sales_audit_run_id'],
                ]);
                $readmitted += $update->rowCount();
            }
            $pdo->commit();
            return $readmitted;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function deferOldestOAuthBlockedBeforeClaim(
        PDO $pdo,
        string $reservationGuard,
        int $skew,
    ): ?array {
        $blocked = $pdo->query(
            'SELECT j.* FROM sync_sales_audit_jobs j
             INNER JOIN sync_sales_audit_runs r
               ON r.id=j.sync_sales_audit_run_id AND r.company_id=j.company_id
              AND r.meli_account_id=j.meli_account_id
             INNER JOIN meli_accounts a ON a.company_id=j.company_id AND a.id=j.meli_account_id
             LEFT JOIN meli_tokens t ON t.meli_account_id=j.meli_account_id
             WHERE j.status IN ("pending","waiting_budget")
               AND j.next_run_at<=UTC_TIMESTAMP()
               AND (j.lock_expires_at IS NULL OR j.lock_expires_at<UTC_TIMESTAMP())
               AND NOT (
                 LOWER(a.status) IN ("conectado","connected")
                 AND COALESCE(t.access_token_encrypted,"")<>""
                 AND t.expires_at>DATE_ADD(UTC_TIMESTAMP(),INTERVAL ' . $skew . ' SECOND)
               )' . $reservationGuard . '
             ORDER BY COALESCE(j.heartbeat_at,j.updated_at,j.created_at) ASC,
                      j.company_id,j.meli_account_id,j.id
             LIMIT 1 FOR UPDATE'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($blocked)) {
            return null;
        }
        $next = $this->nextOAuthOpportunity(
            $pdo,
            (int) $blocked['company_id'],
            (int) $blocked['meli_account_id'],
            false,
            $skew,
        );
        if ($next === null) {
            return $this->abortResult('oauth_dependency_authority_missing');
        }
        $update = $pdo->prepare(
            "UPDATE sync_sales_audit_jobs
             SET status='waiting_budget',next_run_at=?,locked_by=NULL,lock_expires_at=NULL,
                 safe_error_message=NULL,diagnostic_id=NULL,last_error_class=?,last_error_retryable=1,
                 heartbeat_at=NULL,updated_at=UTC_TIMESTAMP()
             WHERE id=? AND company_id=? AND meli_account_id=? AND sync_sales_audit_run_id=?
               AND status IN ('pending','waiting_budget')
               AND (lock_expires_at IS NULL OR lock_expires_at<UTC_TIMESTAMP())"
        );
        $update->execute([
            $next,
            self::OAUTH_DEFER_CLASS,
            (int) $blocked['id'],
            (int) $blocked['company_id'],
            (int) $blocked['meli_account_id'],
            (int) $blocked['sync_sales_audit_run_id'],
        ]);
        if ($update->rowCount() !== 1) {
            return $this->abortResult('sales_audit_oauth_admission_cas_lost');
        }
        return [
            'claimed' => 0,
            'processed' => 0,
            'errors' => 0,
            'status' => 'deferred',
            'stop_reason' => self::OAUTH_DEFER_CLASS,
            'abort_scheduler' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function deferClaimedForOAuth(
        array $job,
        string $worker,
        OAuthRefreshRequiredException $error,
    ): array {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $current = $pdo->prepare(
                "SELECT j.remote_dispatch_state,j.last_http_status,j.lease_generation
                 FROM sync_sales_audit_jobs j
                 WHERE j.id=? AND j.company_id=? AND j.meli_account_id=? AND j.sync_sales_audit_run_id=?
                   AND j.status='running' AND j.locked_by=? AND j.lease_generation=?
                 FOR UPDATE"
            );
            $current->execute([
                (int) $job['id'],
                (int) $job['company_id'],
                (int) $job['meli_account_id'],
                (int) $job['sync_sales_audit_run_id'],
                $worker,
                (int) $job['lease_generation'],
            ]);
            $dispatch = $current->fetch(PDO::FETCH_ASSOC);
            if (!is_array($dispatch)) {
                $pdo->rollBack();
                return $this->abortResult('sales_audit_oauth_refund_cas_lost');
            }
            $tenant = $pdo->prepare('SELECT 1 FROM meli_accounts WHERE company_id=? AND id=?');
            $tenant->execute([(int) $job['company_id'], (int) $job['meli_account_id']]);
            if ($error->accountId !== (int) $job['meli_account_id'] || (int) $tenant->fetchColumn() !== 1) {
                return $this->markClaimedInvariant($pdo, $job, $worker, 'sales_audit_oauth_tenant_fence_failed');
            }
            $transport = $pdo->prepare(
                "SELECT COUNT(*) FROM queue_v4_clean_transport_events
                 WHERE source_kind='sales_audit' AND work_id=? AND company_id=? AND meli_account_id=?
                   AND lease_generation=?"
            );
            $transport->execute([
                (int) $job['id'],
                (int) $job['company_id'],
                (int) $job['meli_account_id'],
                (int) $job['lease_generation'],
            ]);
            if ((string) ($dispatch['remote_dispatch_state'] ?? '') !== 'NOT_DISPATCHED'
                || $dispatch['last_http_status'] !== null
                || (int) $transport->fetchColumn() !== 0) {
                return $this->markClaimedInvariant($pdo, $job, $worker, 'sales_audit_dispatch_fence_failed');
            }
            $next = $this->nextOAuthOpportunity(
                $pdo,
                (int) $job['company_id'],
                (int) $job['meli_account_id'],
                false,
                max(30, min(600, (new AppSettingsService())->int('oauth.token_expiry_skew_seconds', 120))),
            );
            if ($next === null) {
                return $this->markClaimedInvariant($pdo, $job, $worker, 'oauth_dependency_authority_missing');
            }
            $update = $pdo->prepare(
                "UPDATE sync_sales_audit_jobs j
                 SET j.status='waiting_budget',j.next_run_at=?,j.locked_by=NULL,j.lock_expires_at=NULL,
                     j.attempts=GREATEST(j.attempts-1,0),j.safe_error_message=NULL,j.diagnostic_id=NULL,
                     j.last_error_class=?,j.last_error_retryable=1,j.heartbeat_at=NULL,j.updated_at=UTC_TIMESTAMP()
                 WHERE j.id=? AND j.company_id=? AND j.meli_account_id=? AND j.sync_sales_audit_run_id=?
                   AND j.status='running' AND j.locked_by=? AND j.lease_generation=?
                   AND j.remote_dispatch_state='NOT_DISPATCHED' AND j.last_http_status IS NULL
                   AND NOT EXISTS (
                     SELECT 1 FROM queue_v4_clean_transport_events e
                     WHERE e.source_kind='sales_audit' AND e.work_id=j.id
                       AND e.company_id=j.company_id AND e.meli_account_id=j.meli_account_id
                       AND e.lease_generation=j.lease_generation
                   )"
            );
            $update->execute([
                $next,
                self::OAUTH_DEFER_CLASS,
                (int) $job['id'],
                (int) $job['company_id'],
                (int) $job['meli_account_id'],
                (int) $job['sync_sales_audit_run_id'],
                $worker,
                (int) $job['lease_generation'],
            ]);
            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                return $this->abortResult('sales_audit_oauth_refund_cas_lost');
            }
            $pdo->prepare(
                "UPDATE sync_sales_audit_runs SET status='running',updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND company_id=? AND meli_account_id=? AND status<>'complete'"
            )->execute([
                (int) $job['sync_sales_audit_run_id'],
                (int) $job['company_id'],
                (int) $job['meli_account_id'],
            ]);
            $pdo->commit();
            return [
                'status' => 'deferred',
                'stop_reason' => self::OAUTH_DEFER_CLASS,
                'abort_scheduler' => false,
            ];
        } catch (Throwable $failure) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $failure;
        }
    }

    /** @return array<string,mixed> */
    private function markClaimedInvariant(
        PDO $pdo,
        array $job,
        string $worker,
        string $errorClass,
    ): array {
        $update = $pdo->prepare(
            "UPDATE sync_sales_audit_jobs
             SET status='error',locked_by=NULL,lock_expires_at=NULL,
                 consecutive_failures=consecutive_failures+1,
                 safe_error_message='La dependencia OAuth no pudo verificarse de forma segura.',
                 diagnostic_id=NULL,last_error_class=?,last_error_retryable=0,heartbeat_at=NULL,updated_at=UTC_TIMESTAMP()
             WHERE id=? AND company_id=? AND meli_account_id=? AND sync_sales_audit_run_id=?
               AND status='running' AND locked_by=? AND lease_generation=?"
        );
        $update->execute([
            mb_substr($errorClass, 0, 40),
            (int) $job['id'],
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
            (int) $job['sync_sales_audit_run_id'],
            $worker,
            (int) $job['lease_generation'],
        ]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            return $this->abortResult('sales_audit_oauth_refund_cas_lost');
        }
        $pdo->prepare(
            "UPDATE sync_sales_audit_runs
             SET status='error',safe_error_message='La dependencia OAuth no pudo verificarse de forma segura.',
                 diagnostic_id=NULL,updated_at=UTC_TIMESTAMP()
             WHERE id=? AND company_id=? AND meli_account_id=?"
        )->execute([
            (int) $job['sync_sales_audit_run_id'],
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
        ]);
        $pdo->commit();
        return $this->abortResult($errorClass);
    }

    private function nextOAuthOpportunity(
        PDO $pdo,
        int $companyId,
        int $accountId,
        bool $allowAlreadyResolved,
        int $skew,
    ): ?string {
        $operation = $pdo->prepare(
            "SELECT DATE_FORMAT(
                        GREATEST(next_attempt_at,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 SECOND)),
                        '%Y-%m-%d %H:%i:%s'
                    ) effective_next_attempt_at
             FROM oauth_refresh_operations
             WHERE company_id=? AND meli_account_id=? AND state IN ('SCHEDULED','RUNNING','WAITING')
             ORDER BY next_attempt_at,id LIMIT 1 FOR UPDATE"
        );
        $operation->execute([$companyId, $accountId]);
        $value = $operation->fetchColumn();
        if ($value === false) {
            if (!$allowAlreadyResolved || !$this->businessTokenEligible($pdo, $companyId, $accountId, $skew)) {
                return null;
            }
            return (string) $pdo->query(
                "SELECT DATE_FORMAT(DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 SECOND),'%Y-%m-%d %H:%i:%s')"
            )->fetchColumn();
        }
        return (string) $value;
    }

    private function businessTokenEligible(PDO $pdo, int $companyId, int $accountId, int $skew): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM meli_accounts a
             INNER JOIN meli_tokens t ON t.meli_account_id=a.id
             WHERE a.company_id=? AND a.id=?
               AND LOWER(a.status) IN ("conectado","connected")
               AND COALESCE(t.access_token_encrypted,"")<>""
               AND t.expires_at>DATE_ADD(UTC_TIMESTAMP(),INTERVAL ' . $skew . ' SECOND)'
        );
        $statement->execute([$companyId, $accountId]);
        return (int) $statement->fetchColumn() === 1;
    }

    /** @return array<string,mixed> */
    private function abortResult(string $reason, int $repaired = 0): array
    {
        return [
            'status' => 'blocked',
            'stop_reason' => mb_substr($reason, 0, 40),
            'error_class' => mb_substr($reason, 0, 40),
            'abort_scheduler' => true,
            'misclassified_oauth_repaired' => $repaired,
        ];
    }

    /** @return array<string,mixed>|null */
    private function claim(string $worker, ?int $jobId = null, ?array &$admission = null): ?array
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $reservationGuard = ManualCampaignReservationGuard::sql('sales_audit', 'j.id');
            $automatic = $jobId === null;
            $skew = max(30, min(600, (new AppSettingsService())->int('oauth.token_expiry_skew_seconds', 120)));
            $eligibilityJoin = $automatic ? ' INNER JOIN meli_tokens t ON t.meli_account_id=j.meli_account_id' : '';
            $eligibilityWhere = $automatic
                ? ' AND LOWER(a.status) IN ("conectado","connected")'
                    . ' AND COALESCE(t.access_token_encrypted,"")<>""'
                    . ' AND t.expires_at>DATE_ADD(UTC_TIMESTAMP(),INTERVAL ' . $skew . ' SECOND)'
                : '';
            $stmt = $pdo->prepare(
                'SELECT j.* FROM sync_sales_audit_jobs j
                 INNER JOIN sync_sales_audit_runs r
                   ON r.id=j.sync_sales_audit_run_id AND r.company_id=j.company_id
                  AND r.meli_account_id=j.meli_account_id
                  INNER JOIN meli_accounts a ON a.company_id=j.company_id AND a.id=j.meli_account_id
                  ' . $eligibilityJoin . '
                  WHERE j.status IN ("pending","waiting_budget")
                   AND j.remote_dispatch_state<>"PHYSICAL_STARTED"
                   AND j.next_run_at<=UTC_TIMESTAMP()
                   AND (j.lock_expires_at IS NULL OR j.lock_expires_at<UTC_TIMESTAMP())
                    AND (? IS NULL OR j.id=?)' . $eligibilityWhere . $reservationGuard . '
                 ORDER BY COALESCE(j.heartbeat_at,j.updated_at,j.created_at) ASC,
                          j.company_id,j.meli_account_id,j.id
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$jobId, $jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                if ($automatic) {
                    $admission = $this->deferOldestOAuthBlockedBeforeClaim($pdo, $reservationGuard, $skew);
                }
                $pdo->commit();
                return null;
            }
            $pdo->prepare(
                'UPDATE sync_sales_audit_jobs
                 SET status="running",locked_by=?,lock_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND),
                      heartbeat_at=UTC_TIMESTAMP(),lease_generation=lease_generation+1,
                      started_at=COALESCE(started_at,UTC_TIMESTAMP()),attempts=attempts+1,updated_at=UTC_TIMESTAMP(),
                      remote_dispatch_state="NOT_DISPATCHED",remote_dispatched_at=NULL,response_known_at=NULL,
                      last_request_id=NULL,last_http_status=NULL
                  WHERE id=? AND company_id=? AND meli_account_id=? AND sync_sales_audit_run_id=?'
            )->execute([$worker, (int) $job['id'], (int) $job['company_id'], (int) $job['meli_account_id'], (int) $job['sync_sales_audit_run_id']]);
            $pdo->prepare(
                'UPDATE sync_sales_audit_runs SET status="running",started_at=COALESCE(started_at,UTC_TIMESTAMP())
                 WHERE id=? AND company_id=? AND meli_account_id=?'
            )->execute([(int) $job['sync_sales_audit_run_id'], (int) $job['company_id'], (int) $job['meli_account_id']]);
            $pdo->commit();
            $job['locked_by'] = $worker;
            $job['lease_generation'] = ((int) ($job['lease_generation'] ?? 0)) + 1;
            return $job;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{inserted:int,next_offset:int,remote_total:int,finished:bool} */
    private function fetchPage(array $job, string $worker): array
    {
        $pdo = Database::connectionFresh();
        $runStmt = $pdo->prepare('SELECT * FROM sync_sales_audit_runs WHERE id=? AND company_id=? AND meli_account_id=?');
        $runStmt->execute([(int) $job['sync_sales_audit_run_id'], (int) $job['company_id'], (int) $job['meli_account_id']]);
        $run = $runStmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            throw new \RuntimeException('La ejecución de auditoría ya no existe.');
        }
        $seller = $pdo->prepare('SELECT meli_user_id FROM meli_accounts WHERE company_id=? AND id=?');
        $seller->execute([(int) $job['company_id'], (int) $job['meli_account_id']]);
        $sellerId = (int) $seller->fetchColumn();
        if ($sellerId <= 0) {
            throw new \RuntimeException('La cuenta no tiene vendedor Mercado Libre asociado.');
        }
        $limit = max(1, min(50, (int) $job['page_limit']));
        $offset = max(0, (int) $job['next_offset']);
        $utcFrom = new \DateTimeImmutable((string) $run['utc_from'], new \DateTimeZone('UTC'));
        $utcTo = new \DateTimeImmutable((string) $run['utc_to'], new \DateTimeZone('UTC'));
        $client = ($this->clientFactory)((int) $job['meli_account_id']);
        $transportMeta = [
            'source' => MeliTransportSourcePolicy::QUEUE_V4_SALES_AUDIT,
            'job_type' => 'sales_audit',
            'bulk' => true,
            'company_id' => (int) $job['company_id'],
            'account_id' => (int) $job['meli_account_id'],
            'source_queue_key' => 'sync_sales_audit_jobs',
            'source_work_id' => (string) $job['id'],
            'sales_audit_job_id' => (int) $job['id'],
            'sales_audit_lease_owner' => $worker,
            'sales_audit_lease_generation' => (int) $job['lease_generation'],
        ];
        $page = ApiExecutionMetadataContext::run(
            $transportMeta,
            static fn(): array => $client->get('/orders/search', [
                'seller' => $sellerId,
                'order.date_created.from' => $utcFrom->format(DATE_ATOM),
                'order.date_created.to' => $utcTo->format(DATE_ATOM),
                'sort' => 'date_desc',
                'offset' => $offset,
                'limit' => $limit,
            ], $transportMeta)
        );
        $responseMeta = $client->lastResponseMetadata() ?? ['status' => 200, 'headers' => []];
        if (!$this->ownsLease($job, $worker)) {
            throw new \RuntimeException('La reserva temporal de la auditoría venció. El resultado tardío fue descartado.');
        }
        $rows = is_array($page['results'] ?? null) ? $page['results'] : [];
        $remoteTotal = max(0, (int) ($page['paging']['total'] ?? count($rows)));
        $contentMissing = trim((string) (($responseMeta['headers']['x-content-missing'] ?? '')));
        $contentMissingJson = $contentMissing === ''
            ? null
            : json_encode(array_values(array_filter(array_map('trim', explode(',', $contentMissing)))), JSON_UNESCAPED_UNICODE);
        $pdo->beginTransaction();
        try {
            $fence = $pdo->prepare(
                'SELECT id FROM sync_sales_audit_jobs
                 WHERE id=? AND company_id=? AND meli_account_id=? AND sync_sales_audit_run_id=?
                   AND locked_by=? AND lease_generation=? AND lock_expires_at>=UTC_TIMESTAMP()
                 FOR UPDATE'
            );
            $fence->execute([(int) $job['id'], (int) $job['company_id'], (int) $job['meli_account_id'], (int) $job['sync_sales_audit_run_id'], $worker, (int) $job['lease_generation']]);
            if (!$fence->fetchColumn()) {
                throw new \RuntimeException('La reserva temporal cambió antes de guardar la página. El resultado fue descartado.');
            }
            $insert = $pdo->prepare(
            'INSERT INTO sync_sales_audit_run_orders
             (sync_sales_audit_run_id,meli_account_id,external_order_id,audit_date,remote_date_created,remote_status,classification)
             VALUES (?,?,?,?,?,?,"pending")
             ON DUPLICATE KEY UPDATE remote_date_created=VALUES(remote_date_created),
               remote_status=VALUES(remote_status),audit_date=VALUES(audit_date),updated_at=UTC_TIMESTAMP()'
        );
            $inserted = 0;
            $tz = new \DateTimeZone((string) $run['timezone_used']);
            foreach ($rows as $row) {
            if (empty($row['id']) || empty($row['date_created'])) {
                continue;
            }
            $remoteUtcValue = (new MeliDateTimeNormalizer())->utc((string) $row['date_created'], 'orders.date_created');
            if ($remoteUtcValue === null) {
                continue;
            }
            $remoteUtc = new \DateTimeImmutable($remoteUtcValue, new \DateTimeZone('UTC'));
            if ($remoteUtc < $utcFrom || $remoteUtc >= $utcTo) {
                continue;
            }
            $insert->execute([
                (int) $run['id'],
                (int) $job['meli_account_id'],
                (string) $row['id'],
                $remoteUtc->setTimezone($tz)->format('Y-m-d'),
                $remoteUtc->format('Y-m-d H:i:s'),
                isset($row['status']) ? (string) $row['status'] : null,
            ]);
            $inserted += $insert->rowCount() > 0 ? 1 : 0;
            }
            $pageIds = [];
            foreach ($rows as $row) {
            if (isset($row['id'])) {
                $pageIds[] = (string) $row['id'];
            }
            }
            sort($pageIds, SORT_STRING);
            $pageHash = hash('sha256', implode("\n", $pageIds));
            $nextOffset = $offset + count($rows);
            $finished = $rows === [] || $nextOffset >= $remoteTotal;
            $pdo->prepare(
            'INSERT INTO sync_sales_audit_run_pages
             (sync_sales_audit_run_id,company_id,meli_account_id,page_offset,page_limit,result_count,
              remote_reported_total,http_status,content_missing_json,ids_hash)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE result_count=VALUES(result_count),
               remote_reported_total=VALUES(remote_reported_total),http_status=VALUES(http_status),
               content_missing_json=VALUES(content_missing_json),ids_hash=VALUES(ids_hash),
               captured_at=UTC_TIMESTAMP()'
            )->execute([
            (int) $run['id'],
            (int) $run['company_id'],
            (int) $job['meli_account_id'],
            $offset,
            $limit,
            count($rows),
            $remoteTotal,
            (int) $responseMeta['status'],
            $contentMissingJson,
            $pageHash,
            ]);
            $advance = $pdo->prepare(
            'UPDATE sync_sales_audit_jobs
             SET next_offset=?,remote_reported_total=?,processed_pages=processed_pages+1,
                 lock_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND),heartbeat_at=UTC_TIMESTAMP(),
                 last_page_hash=?,last_http_status=?,updated_at=UTC_TIMESTAMP()
             WHERE id=? AND company_id=? AND meli_account_id=? AND sync_sales_audit_run_id=?
               AND locked_by=? AND lease_generation=? AND lock_expires_at>=UTC_TIMESTAMP()'
        );
            $advance->execute([
            $nextOffset,
            $remoteTotal,
            $pageHash,
            (int) $responseMeta['status'],
            (int) $job['id'],
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
            (int) $job['sync_sales_audit_run_id'],
            $worker,
            (int) $job['lease_generation'],
            ]);
            if ($advance->rowCount() !== 1) {
                throw new \RuntimeException('La reserva temporal cambió antes de guardar la página. El trabajo será recuperado sin duplicar datos.');
            }
            $pdo->prepare(
            'UPDATE sync_sales_audit_runs
             SET remote_reported_total=?,coverage_http_status=GREATEST(COALESCE(coverage_http_status,0),?),
                 content_missing_json=COALESCE(?,content_missing_json)
             WHERE id=? AND company_id=? AND meli_account_id=?'
            )->execute([
            $remoteTotal,
            (int) $responseMeta['status'],
            $contentMissingJson,
            (int) $run['id'],
            (int) $run['company_id'],
            (int) $job['meli_account_id'],
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        return compact('inserted', 'nextOffset', 'remoteTotal', 'finished') + [
            'next_offset' => $nextOffset,
            'remote_total' => $remoteTotal,
        ];
    }

    private function finalizeRun(int $runId, int $companyId, int $accountId): void
    {
        $pdo = Database::connectionFresh();
        $runStmt = $pdo->prepare('SELECT * FROM sync_sales_audit_runs WHERE id=? AND company_id=? AND meli_account_id=?');
        $runStmt->execute([$runId, $companyId, $accountId]);
        $run = $runStmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            throw new \RuntimeException('Ejecución de auditoría no encontrada.');
        }

        $remote = $pdo->prepare(
            'SELECT ro.* FROM sync_sales_audit_run_orders ro
             INNER JOIN sync_sales_audit_runs r
               ON r.id=ro.sync_sales_audit_run_id AND r.company_id=? AND r.meli_account_id=ro.meli_account_id
             WHERE ro.sync_sales_audit_run_id=? AND ro.meli_account_id=? ORDER BY ro.id'
        );
        $remote->execute([$companyId, $runId, $accountId]);
        $remoteRows = $remote->fetchAll(PDO::FETCH_ASSOC);
        $byExternal = [];
        foreach ($remoteRows as $row) {
            $byExternal[(string) $row['external_order_id']] = $row;
        }
        $locals = $this->localRows(
            (int) $run['company_id'],
            (int) $run['meli_account_id'],
            array_keys($byExternal)
        );
        $counts = [
            'present' => 0,
            'missing_remote' => 0,
            'missing_normalized_date' => 0,
            'shifted_date' => 0,
            'other_account' => 0,
            'duplicate_accounts' => 0,
        ];
        $update = $pdo->prepare(
            'UPDATE sync_sales_audit_run_orders ro
             INNER JOIN sync_sales_audit_runs r
               ON r.id=ro.sync_sales_audit_run_id AND r.company_id=? AND r.meli_account_id=?
             SET ro.found_local_order_id=?,ro.local_meli_account_id=?,ro.local_date_created=?,
                 ro.local_date_created_local=?,ro.classification=?,ro.safe_explanation=?,ro.checked_at=UTC_TIMESTAMP()
             WHERE ro.id=? AND ro.sync_sales_audit_run_id=? AND ro.meli_account_id=?'
        );
        foreach ($byExternal as $external => $remoteRow) {
            $local = $locals[$external] ?? null;
            [$classification, $explanation] = $this->classify($run, $remoteRow, $local);
            $counts[$classification] = ($counts[$classification] ?? 0) + 1;
            $update->execute([
                $companyId,
                $accountId,
                $local['id'] ?? null,
                $local['meli_account_id'] ?? null,
                $local['date_created'] ?? null,
                $local['date_created_local'] ?? null,
                $classification,
                $explanation,
                (int) $remoteRow['id'],
                $runId,
                $accountId,
            ]);
        }

        $rangeFrom = new \DateTimeImmutable((string) $run['local_from']);
        $rangeTo = new \DateTimeImmutable((string) $run['local_to']);
        $localPeriodTotal = $this->localCount($companyId, $accountId, $rangeFrom, $rangeTo);
        $extra = $this->extraLocalIds($companyId, $accountId, $rangeFrom, $rangeTo, array_keys($byExternal));
        $this->persistDays($run, $runId, $extra);

        $temporalProblems = $counts['missing_normalized_date']
            + $counts['shifted_date']
            + $counts['other_account']
            + $counts['duplicate_accounts'];
        $localPresence = $counts['missing_remote'] > 0 && count($extra) > 0
            ? 'mixed'
            : ($counts['missing_remote'] > 0 ? 'missing' : (count($extra) > 0 ? 'extra' : 'complete'));
        $temporalQuality = $temporalProblems === 0
            ? 'correct'
            : (($counts['missing_normalized_date'] > 0 && $counts['shifted_date'] === 0 && $counts['other_account'] === 0)
                ? 'missing_normalized'
                : (($counts['shifted_date'] > 0 && $counts['missing_normalized_date'] === 0 && $counts['other_account'] === 0)
                    ? 'shifted'
                    : (($counts['other_account'] > 0 && $counts['missing_normalized_date'] === 0 && $counts['shifted_date'] === 0)
                        ? 'other_account'
                        : 'mixed')));
        $coverage = (new VerifiedSalesCaptureService())->verifyCoverage($runId);
        $coverageComplete = $coverage['valid'];
        $temporalCoverage = (new SalesAuditTemporalCoverageService())->classifyRun($run);
        $temporalCoverageFull = $temporalCoverage['state'] === 'full';
        $ids = array_keys($byExternal);
        sort($ids, SORT_STRING);
        $snapshotHash = hash('sha256', implode("\n", $ids));
        $ready = $coverageComplete
            && $temporalCoverageFull
            && $counts['missing_remote'] === 0
            && count($extra) === 0
            && $temporalProblems === 0;
        $pdo->prepare(
            'UPDATE sync_sales_audit_runs
             SET status=?,remote_coverage=?,local_presence=?,temporal_quality=?,
                  reconciliation_status=?,remote_unique_total=?,local_period_total=?,present_total=?,
                  missing_total=?,extra_total=?,shifted_total=?,missing_normalized_total=?,
                  other_account_total=?,checked_total=?,snapshot_hash=?,
                  coverage_validation_state=?,coverage_validation_json=?,
                  requested_from_utc=?,requested_to_utc=?,historical_window_starts_at=?,
                  effective_coverage_from_utc=?,effective_coverage_to_utc=?,
                  temporal_coverage_state=?,temporal_coverage_reason=?,coverage_contract_version=?,
                  capture_finished_at=UTC_TIMESTAMP(),
                  completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
             WHERE id=? AND company_id=? AND meli_account_id=?'
        )->execute([
            $ready ? 'complete' : 'partial',
            $coverageComplete ? 'complete' : 'truncated',
            $localPresence,
            $temporalQuality,
            $coverageComplete ? ($ready ? 'ready' : 'partial') : 'blocked',
            count($byExternal),
            $localPeriodTotal,
            $counts['present'],
            $counts['missing_remote'],
            count($extra),
            $counts['shifted_date'],
            $counts['missing_normalized_date'],
            $counts['other_account'] + $counts['duplicate_accounts'],
            count($byExternal),
            $snapshotHash,
            $coverage['state'],
            json_encode($coverage['reasons'], JSON_UNESCAPED_UNICODE),
            $temporalCoverage['requested_from_utc'],
            $temporalCoverage['requested_to_utc'],
            $temporalCoverage['historical_window_starts_at'],
            $temporalCoverage['effective_from_utc'],
            $temporalCoverage['effective_to_utc'],
            $temporalCoverage['state'],
            mb_substr($temporalCoverage['reason'], 0, 500),
            $temporalCoverage['contract_version'],
            $runId,
            (int) $run['company_id'],
            (int) $run['meli_account_id'],
        ]);
        $this->updateLegacySummary($run, count($byExternal), $localPeriodTotal, $counts, count($extra), $ready);
        try {
            (new SalesControlService())->recordAuditRun($runId);
        } catch (Throwable $error) {
            SafeErrorPresenter::report(
                $error,
                'La comprobación terminó, pero el resumen anual quedó pendiente de actualizar.',
                ['module' => 'sales_control', 'run_id' => $runId]
            );
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function localRows(int $companyId, int $auditedAccountId, array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }
        $result = [];
        foreach (array_chunk($externalIds, 100) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = Database::connectionFresh()->prepare(
                'SELECT o.id,o.meli_account_id,o.external_order_id,o.date_created,o.date_created_local
                 FROM meli_orders o
                 JOIN meli_accounts a ON a.id=o.meli_account_id
                 WHERE a.company_id=? AND o.external_order_id IN (' . $placeholders . ')
                 ORDER BY (o.meli_account_id=?) DESC,o.id DESC'
            );
            $stmt->execute(array_merge([$companyId], $chunk, [$auditedAccountId]));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = (string) $row['external_order_id'];
                if (!isset($result[$key])) {
                    $row['duplicate_accounts'] = [];
                    $result[$key] = $row;
                    continue;
                }
                if ((int) $result[$key]['meli_account_id'] !== (int) $row['meli_account_id']) {
                    $result[$key]['duplicate_accounts'][] = (int) $row['meli_account_id'];
                }
            }
        }
        return $result;
    }

    /** @return array{0:string,1:string} */
    private function classify(array $run, array $remote, ?array $local): array
    {
        if ($local === null) {
            return ['missing_remote', 'La orden existe en Mercado Libre, pero todavía no está guardada localmente.'];
        }
        if (!empty($local['duplicate_accounts'])) {
            return ['duplicate_accounts', 'La misma orden aparece asociada a más de una cuenta de la empresa. Revise la asignación antes de cerrar el mes.'];
        }
        if ((int) $local['meli_account_id'] !== (int) $run['meli_account_id']) {
            return ['other_account', 'La orden existe localmente, pero está asociada a otra cuenta Mercado Libre.'];
        }
        if (empty($local['date_created_local'])) {
            return ['missing_normalized_date', 'La orden existe localmente, pero falta calcular su fecha normalizada.'];
        }
        $localDay = substr((string) $local['date_created_local'], 0, 10);
        $remoteDay = (string) ($remote['audit_date'] ?? '');
        if ($localDay !== $remoteDay) {
            return ['shifted_date', 'La orden existe, pero su fecha local normalizada corresponde a otro día.'];
        }
        return ['present', 'La orden existe en la cuenta y fecha esperadas.'];
    }

    /** @return list<string> */
    private function extraLocalIds(
        int $companyId,
        int $accountId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $remoteIds,
    ): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT o.external_order_id FROM meli_orders o
             INNER JOIN meli_accounts a ON a.company_id=? AND a.id=o.meli_account_id
             WHERE o.meli_account_id=? AND o.date_created_local>=? AND o.date_created_local<?'
        );
        $stmt->execute([$companyId, $accountId, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
        $remoteMap = array_fill_keys($remoteIds, true);
        $extra = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $external) {
            if (!isset($remoteMap[(string) $external])) {
                $extra[] = (string) $external;
            }
        }
        return $extra;
    }

    private function persistDays(array $run, int $runId, array $extraIds): void
    {
        $pdo = Database::connectionFresh();
        $companyId = (int) $run['company_id'];
        $accountId = (int) $run['meli_account_id'];
        $pdo->prepare(
            'DELETE d FROM sync_sales_audit_run_days d
             INNER JOIN sync_sales_audit_runs r
               ON r.id=d.sync_sales_audit_run_id AND r.company_id=? AND r.meli_account_id=?
             WHERE d.sync_sales_audit_run_id=?'
        )->execute([$companyId, $accountId, $runId]);
        $range = new DatePeriod(
            new \DateTimeImmutable((string) $run['local_from']),
            new \DateInterval('P1D'),
            new \DateTimeImmutable((string) $run['local_to'])
        );
        $remoteStmt = $pdo->prepare(
            'SELECT COUNT(*) remote_total,
                    SUM(classification="present") present_total,
                    SUM(classification="missing_remote") missing_total,
                    SUM(classification="shifted_date") shifted_total,
                    SUM(classification="missing_normalized_date") missing_normalized_total
             FROM sync_sales_audit_run_orders
             WHERE sync_sales_audit_run_id=? AND meli_account_id=? AND audit_date=?'
        );
        $localStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM meli_orders o
             INNER JOIN meli_accounts a ON a.company_id=? AND a.id=o.meli_account_id
             WHERE o.meli_account_id=? AND o.date_created_local>=? AND o.date_created_local<?'
        );
        $insert = $pdo->prepare(
            'INSERT INTO sync_sales_audit_run_days
             (sync_sales_audit_run_id,audit_date,remote_unique_total,local_total,present_total,
              missing_total,extra_total,shifted_total,missing_normalized_total,status)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $extraByDay = [];
        if ($extraIds !== []) {
            foreach (array_chunk($extraIds, 100) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $pdo->prepare(
                    'SELECT o.external_order_id,DATE(o.date_created_local) audit_date
                     FROM meli_orders o
                     INNER JOIN meli_accounts a ON a.company_id=? AND a.id=o.meli_account_id
                     WHERE o.meli_account_id=? AND o.external_order_id IN (' . $placeholders . ')'
                );
                $stmt->execute(array_merge([$companyId, $accountId], $chunk));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $day = (string) ($row['audit_date'] ?? '');
                    $extraByDay[$day] = ($extraByDay[$day] ?? 0) + 1;
                }
            }
        }
        foreach ($range as $day) {
            $date = $day->format('Y-m-d');
            $remoteStmt->execute([$runId, $accountId, $date]);
            $counts = $remoteStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $next = $day->modify('+1 day');
            $localStmt->execute([
                $companyId,
                $accountId,
                $day->format('Y-m-d H:i:s'),
                $next->format('Y-m-d H:i:s'),
            ]);
            $missing = (int) ($counts['missing_total'] ?? 0);
            $shifted = (int) ($counts['shifted_total'] ?? 0);
            $without = (int) ($counts['missing_normalized_total'] ?? 0);
            $extra = (int) ($extraByDay[$date] ?? 0);
            $insert->execute([
                $runId,
                $date,
                (int) ($counts['remote_total'] ?? 0),
                (int) $localStmt->fetchColumn(),
                (int) ($counts['present_total'] ?? 0),
                $missing,
                $extra,
                $shifted,
                $without,
                ($missing + $shifted + $without + $extra) === 0 ? 'complete' : 'attention',
            ]);
        }
    }

    private function updateLegacySummary(array $run, int $remote, int $local, array $counts, int $extra, bool $ready): void
    {
        $pdo = Database::connectionFresh();
        $stmt = $pdo->prepare(
            'UPDATE sync_sales_audits s
             INNER JOIN meli_accounts a ON a.company_id=? AND a.id=s.meli_account_id
             SET s.remote_total=?,s.local_total=?,s.difference_count=?,
                  s.daily_remote_sum=?,s.daily_local_sum=?,s.exact_missing_total=?,s.exact_shifted_total=?,
                  s.exact_extra_total=?,s.audit_consistency_status=?,s.status=?,s.last_exact_audit_at=UTC_TIMESTAMP(),
                  s.recommendation=?,s.checked_at=UTC_TIMESTAMP()
             WHERE s.meli_account_id=? AND s.period_year=? AND s.period_month=?'
        );
        $temporal = (int) $counts['missing_normalized_date'] + (int) $counts['shifted_date'] + (int) $counts['other_account'];
        $consistency = $ready
            ? 'complete'
            : ((int) $counts['missing_remote'] > 0
                ? 'incomplete_missing_remote'
                : ((int) $counts['missing_normalized_date'] > 0 ? 'exists_without_normalized_date' : 'local_shifted_date'));
        $recommendation = $ready
            ? null
            : ((int) $counts['missing_normalized_date'] > 0
                ? 'Recalcule las fechas normalizadas antes de considerar completa esta auditoría.'
                : 'Revise las diferencias agrupadas y ejecute únicamente la acción recomendada.');
        $stmt->execute([
            (int) $run['company_id'],
            $remote,
            $local,
            $remote - $local,
            $remote,
            $local,
            (int) $counts['missing_remote'],
            $temporal,
            $extra,
            $consistency,
            $ready ? 'complete' : 'incomplete',
            $recommendation,
            (int) $run['meli_account_id'],
            (int) $run['period_year'],
            (int) $run['period_month'],
        ]);
    }

    private function localCount(
        int $companyId,
        int $accountId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): int
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT COUNT(*) FROM meli_orders o
             INNER JOIN meli_accounts a ON a.company_id=? AND a.id=o.meli_account_id
             WHERE o.meli_account_id=? AND o.date_created_local>=? AND o.date_created_local<?'
        );
        $stmt->execute([$companyId, $accountId, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
        return (int) $stmt->fetchColumn();
    }

    private function release(
        array $job,
        string $worker,
        string $status,
        ?string $reason,
        ?Throwable $error,
        ?string $nextSafeAt = null,
        bool $nonFailure = false,
        ?string $diagnostic = null,
    ): void
    {
        $fallbackDelay = $status === 'waiting_budget' ? 60 : ($status === 'error' ? 120 : 5);
        $timestamp = $nextSafeAt === null ? false : strtotime($nextSafeAt . ' UTC');
        $availableAt = gmdate('Y-m-d H:i:s', $timestamp === false ? time() + $fallbackDelay : max(time() + 1, $timestamp));
        $uncertain = $error instanceof RemoteResultUncertainException;
        $safeMessage = $uncertain
            ? 'El resultado remoto es incierto. Revise antes de autorizar otro intento.'
            : ($error ? 'La comprobación se interrumpió de forma segura y se reintentará.' : null);
        $pdo = Database::connectionFresh();
        $update = $pdo->prepare(
            'UPDATE sync_sales_audit_jobs
             SET status=?,next_run_at=?,
                 locked_by=NULL,lock_expires_at=NULL,
                 consecutive_failures=CASE WHEN ?="error" THEN consecutive_failures+1 ELSE consecutive_failures END,
                 attempts=CASE WHEN ?=1 THEN GREATEST(attempts-1,0) ELSE attempts END,
                 safe_error_message=?,diagnostic_id=?,updated_at=UTC_TIMESTAMP(),
                 last_error_class=?,last_error_retryable=?,heartbeat_at=NULL
             WHERE id=? AND company_id=? AND meli_account_id=? AND sync_sales_audit_run_id=?
               AND locked_by=? AND lease_generation=?'
        );
        $update->execute([
            $status,
            $availableAt,
            $status,
            $nonFailure ? 1 : 0,
            $safeMessage,
            $diagnostic,
            $uncertain ? 'remote_result_uncertain' : ($error ? mb_substr($error::class, 0, 40) : null),
            $error && !$uncertain ? 1 : 0,
            (int) $job['id'],
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
            (int) $job['sync_sales_audit_run_id'],
            $worker,
            (int) $job['lease_generation'],
        ]);
        if ($update->rowCount() !== 1) {
            return;
        }
        $pdo->prepare(
            'UPDATE sync_sales_audit_runs SET status=?,safe_error_message=?,diagnostic_id=?,updated_at=UTC_TIMESTAMP()
             WHERE id=? AND company_id=? AND meli_account_id=?'
        )->execute([$status === 'error' ? 'error' : 'running', $safeMessage, $diagnostic, (int) $job['sync_sales_audit_run_id'], (int) $job['company_id'], (int) $job['meli_account_id']]);
    }

    private function complete(array $job, string $worker): void
    {
        $update = Database::connectionFresh()->prepare(
            'UPDATE sync_sales_audit_jobs
             SET status="complete",locked_by=NULL,lock_expires_at=NULL,consecutive_failures=0,
                 safe_error_message=NULL,diagnostic_id=NULL,completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),
                 heartbeat_at=NULL,last_error_class=NULL,last_error_retryable=0
             WHERE id=? AND company_id=? AND meli_account_id=? AND sync_sales_audit_run_id=?
               AND locked_by=? AND lease_generation=?'
        );
        $update->execute([(int) $job['id'], (int) $job['company_id'], (int) $job['meli_account_id'], (int) $job['sync_sales_audit_run_id'], $worker, (int) $job['lease_generation']]);
        if ($update->rowCount() !== 1) {
            throw new \RuntimeException('La reserva temporal cambió antes de completar la auditoría.');
        }
    }

    /** @return array<string,mixed> */
    private function assertAccountPeriod(int $accountId, int $year, int $month, int $companyId = 0): array
    {
        if ($accountId <= 0 || $year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Cuenta o periodo inválido.');
        }
        $account = (new SalesAuditAccessGateway())->account($accountId, $companyId);
        if ((string) ($account['status'] ?? '') !== 'conectado') {
            throw new \RuntimeException(
                'La cuenta no está conectada. Puede consultar su historial, pero debe reautorizarla antes de comprobar ventas nuevas.'
            );
        }
        return $account;
    }

    /** @param array<string,mixed> $job */
    private function ownsLease(array $job, string $worker): bool
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT COUNT(*) FROM sync_sales_audit_jobs
             WHERE id=? AND locked_by=? AND lease_generation=?
               AND company_id=? AND meli_account_id=? AND sync_sales_audit_run_id=?
               AND status="running" AND lock_expires_at>=UTC_TIMESTAMP()'
        );
        $stmt->execute([(int) $job['id'], $worker, (int) $job['lease_generation'], (int) $job['company_id'], (int) $job['meli_account_id'], (int) $job['sync_sales_audit_run_id']]);
        return (int) $stmt->fetchColumn() === 1;
    }
}
