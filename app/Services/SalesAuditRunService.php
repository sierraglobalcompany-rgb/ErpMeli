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
             JOIN sync_sales_audit_runs r ON r.id=j.sync_sales_audit_run_id
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
        $pagesPerCycle = max(1, min(5, $pagesPerCycle));
        $worker = 'sales-audit-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $job = $this->claim($worker, $jobId);
        if (!$job) {
            return ['processed' => 0, 'errors' => 0, 'status' => 'empty'];
        }

        $processed = 0;
        try {
            for ($page = 0; $page < $pagesPerCycle; $page++) {
                if ($deadline !== null && microtime(true) >= $deadline - 2.0) {
                    $this->release($job, $worker, 'pending', 'time_budget', null);
                    return ['processed' => $processed, 'errors' => 0, 'status' => 'deferred', 'stop_reason' => 'time_budget'];
                }
                $result = $this->fetchPage($job, $worker);
                $processed += $result['inserted'];
                $job['next_offset'] = $result['next_offset'];
                $job['remote_reported_total'] = $result['remote_total'];
                if (!empty($result['finished'])) {
                    $this->finalizeRun((int) $job['sync_sales_audit_run_id']);
                    $this->complete($job, $worker);
                    return ['processed' => $processed, 'errors' => 0, 'status' => 'complete', 'run_id' => (int) $job['sync_sales_audit_run_id']];
                }
            }
            $this->release($job, $worker, 'pending', null, null);
            return ['processed' => $processed, 'errors' => 0, 'status' => 'deferred', 'stop_reason' => 'page_checkpoint'];
        } catch (ApiBudgetExhaustedException|ApiManualPauseException $error) {
            $this->release($job, $worker, 'waiting_budget', 'api_budget', $error);
            return ['processed' => $processed, 'errors' => 0, 'status' => 'deferred', 'stop_reason' => 'api_budget'];
        } catch (Throwable $error) {
            $safe = SafeErrorPresenter::report($error, 'No fue posible continuar la auditoría exacta.', [
                'module' => 'sales_audit',
                'job_id' => (int) $job['id'],
            ]);
            $this->release($job, $worker, 'error', 'error', $error, $safe['reference']);
            return [
                'processed' => $processed,
                'errors' => 1,
                'status' => 'error',
                'diagnostic_id' => $safe['reference'],
                'message' => $safe['message'],
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
        $stmt = Database::connection()->prepare(
            'SELECT r.*,a.account_name,j.id job_id,j.status job_status,j.next_offset,j.remote_reported_total job_remote_total,
                    j.next_run_at,j.safe_error_message job_error,j.diagnostic_id job_diagnostic
             FROM sync_sales_audit_runs r
             JOIN meli_accounts a ON a.id=r.meli_account_id
             LEFT JOIN sync_sales_audit_jobs j ON j.sync_sales_audit_run_id=r.id
              WHERE r.meli_account_id=? AND r.period_year=? AND r.period_month=?
                AND (?=0 OR r.company_id=?)
              ORDER BY r.id DESC LIMIT 1'
        );
        $stmt->execute([$accountId, $year, $month, $companyId, $companyId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            return null;
        }
        $days = Database::connection()->prepare(
            'SELECT * FROM sync_sales_audit_run_days WHERE sync_sales_audit_run_id=? ORDER BY audit_date'
        );
        $days->execute([(int) $run['id']]);
        $run['days'] = $days->fetchAll(PDO::FETCH_ASSOC);

        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
        $page = max(1, $page);
        $where = 'sync_sales_audit_run_id=? AND classification<>"present"';
        $params = [(int) $run['id']];
        if ($classification !== null && $classification !== '') {
            $where .= ' AND classification=?';
            $params[] = $classification;
        }
        $count = Database::connection()->prepare('SELECT COUNT(*) FROM sync_sales_audit_run_orders WHERE ' . $where);
        $count->execute($params);
        $run['difference_total'] = (int) $count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $details = Database::connection()->prepare(
            'SELECT * FROM sync_sales_audit_run_orders WHERE ' . $where . '
             ORDER BY audit_date ASC,classification ASC,id ASC LIMIT ' . $perPage . ' OFFSET ' . $offset
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

    /** @return array<string,mixed>|null */
    private function claim(string $worker, ?int $jobId = null): ?array
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $reservationGuard = ManualCampaignReservationGuard::sql('sales_audit', 'sync_sales_audit_jobs.id');
            $stmt = $pdo->prepare(
                'SELECT * FROM sync_sales_audit_jobs
                 WHERE status IN ("pending","waiting_budget")
                   AND next_run_at<=UTC_TIMESTAMP()
                   AND (lock_expires_at IS NULL OR lock_expires_at<UTC_TIMESTAMP())
                   AND (? IS NULL OR id=?)' . $reservationGuard . '
                 ORDER BY created_at ASC,id ASC LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$jobId, $jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $pdo->commit();
                return null;
            }
            $pdo->prepare(
                'UPDATE sync_sales_audit_jobs
                 SET status="running",locked_by=?,lock_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND),
                      heartbeat_at=UTC_TIMESTAMP(),lease_generation=lease_generation+1,
                      started_at=COALESCE(started_at,UTC_TIMESTAMP()),attempts=attempts+1,updated_at=UTC_TIMESTAMP()
                  WHERE id=?'
            )->execute([$worker, (int) $job['id']]);
            $pdo->prepare(
                'UPDATE sync_sales_audit_runs SET status="running",started_at=COALESCE(started_at,UTC_TIMESTAMP())
                 WHERE id=?'
            )->execute([(int) $job['sync_sales_audit_run_id']]);
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
        $runStmt = $pdo->prepare('SELECT * FROM sync_sales_audit_runs WHERE id=?');
        $runStmt->execute([(int) $job['sync_sales_audit_run_id']]);
        $run = $runStmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            throw new \RuntimeException('La ejecución de auditoría ya no existe.');
        }
        $seller = $pdo->prepare('SELECT meli_user_id FROM meli_accounts WHERE id=?');
        $seller->execute([(int) $job['meli_account_id']]);
        $sellerId = (int) $seller->fetchColumn();
        if ($sellerId <= 0) {
            throw new \RuntimeException('La cuenta no tiene vendedor Mercado Libre asociado.');
        }
        $limit = max(1, min(50, (int) $job['page_limit']));
        $offset = max(0, (int) $job['next_offset']);
        $utcFrom = new \DateTimeImmutable((string) $run['utc_from'], new \DateTimeZone('UTC'));
        $utcTo = new \DateTimeImmutable((string) $run['utc_to'], new \DateTimeZone('UTC'));
        $client = new MeliApiClient((int) $job['meli_account_id']);
        $page = $client->get('/orders/search', [
            'seller' => $sellerId,
            'order.date_created.from' => $utcFrom->format(DATE_ATOM),
            'order.date_created.to' => $utcTo->format(DATE_ATOM),
            'sort' => 'date_desc',
            'offset' => $offset,
            'limit' => $limit,
        ], ['job_type' => 'sales_audit', 'bulk' => true]);
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
                 WHERE id=? AND locked_by=? AND lease_generation=? AND lock_expires_at>=UTC_TIMESTAMP()
                 FOR UPDATE'
            );
            $fence->execute([(int) $job['id'], $worker, (int) $job['lease_generation']]);
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
             WHERE id=? AND locked_by=? AND lease_generation=? AND lock_expires_at>=UTC_TIMESTAMP()'
        );
            $advance->execute([
            $nextOffset,
            $remoteTotal,
            $pageHash,
            (int) $responseMeta['status'],
            (int) $job['id'],
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

    private function finalizeRun(int $runId): void
    {
        $pdo = Database::connectionFresh();
        $runStmt = $pdo->prepare('SELECT * FROM sync_sales_audit_runs WHERE id=?');
        $runStmt->execute([$runId]);
        $run = $runStmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            throw new \RuntimeException('Ejecución de auditoría no encontrada.');
        }

        $remote = $pdo->prepare('SELECT * FROM sync_sales_audit_run_orders WHERE sync_sales_audit_run_id=? ORDER BY id');
        $remote->execute([$runId]);
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
            'UPDATE sync_sales_audit_run_orders
             SET found_local_order_id=?,local_meli_account_id=?,local_date_created=?,
                 local_date_created_local=?,classification=?,safe_explanation=?,checked_at=UTC_TIMESTAMP()
             WHERE id=?'
        );
        foreach ($byExternal as $external => $remoteRow) {
            $local = $locals[$external] ?? null;
            [$classification, $explanation] = $this->classify($run, $remoteRow, $local);
            $counts[$classification] = ($counts[$classification] ?? 0) + 1;
            $update->execute([
                $local['id'] ?? null,
                $local['meli_account_id'] ?? null,
                $local['date_created'] ?? null,
                $local['date_created_local'] ?? null,
                $classification,
                $explanation,
                (int) $remoteRow['id'],
            ]);
        }

        $rangeFrom = new \DateTimeImmutable((string) $run['local_from']);
        $rangeTo = new \DateTimeImmutable((string) $run['local_to']);
        $localPeriodTotal = $this->localCount((int) $run['meli_account_id'], $rangeFrom, $rangeTo);
        $extra = $this->extraLocalIds((int) $run['meli_account_id'], $rangeFrom, $rangeTo, array_keys($byExternal));
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
    private function extraLocalIds(int $accountId, \DateTimeImmutable $from, \DateTimeImmutable $to, array $remoteIds): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT external_order_id FROM meli_orders
             WHERE meli_account_id=? AND date_created_local>=? AND date_created_local<?'
        );
        $stmt->execute([$accountId, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
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
        $pdo->prepare('DELETE FROM sync_sales_audit_run_days WHERE sync_sales_audit_run_id=?')->execute([$runId]);
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
             FROM sync_sales_audit_run_orders WHERE sync_sales_audit_run_id=? AND audit_date=?'
        );
        $localStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM meli_orders
             WHERE meli_account_id=? AND date_created_local>=? AND date_created_local<?'
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
                    'SELECT external_order_id,DATE(date_created_local) audit_date
                     FROM meli_orders WHERE meli_account_id=? AND external_order_id IN (' . $placeholders . ')'
                );
                $stmt->execute(array_merge([(int) $run['meli_account_id']], $chunk));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $day = (string) ($row['audit_date'] ?? '');
                    $extraByDay[$day] = ($extraByDay[$day] ?? 0) + 1;
                }
            }
        }
        foreach ($range as $day) {
            $date = $day->format('Y-m-d');
            $remoteStmt->execute([$runId, $date]);
            $counts = $remoteStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $next = $day->modify('+1 day');
            $localStmt->execute([
                (int) $run['meli_account_id'],
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
            'UPDATE sync_sales_audits
             SET remote_total=?,local_total=?,difference_count=?,
                 daily_remote_sum=?,daily_local_sum=?,exact_missing_total=?,exact_shifted_total=?,
                 exact_extra_total=?,audit_consistency_status=?,status=?,last_exact_audit_at=UTC_TIMESTAMP(),
                 recommendation=?,checked_at=UTC_TIMESTAMP()
             WHERE meli_account_id=? AND period_year=? AND period_month=?'
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

    private function localCount(int $accountId, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT COUNT(*) FROM meli_orders
             WHERE meli_account_id=? AND date_created_local>=? AND date_created_local<?'
        );
        $stmt->execute([$accountId, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
        return (int) $stmt->fetchColumn();
    }

    private function release(array $job, string $worker, string $status, ?string $reason, ?Throwable $error, ?string $diagnostic = null): void
    {
        $delay = $status === 'waiting_budget' ? 300 : ($status === 'error' ? 120 : 5);
        $safeMessage = $error ? 'La comprobación se interrumpió de forma segura y se reintentará.' : null;
        $pdo = Database::connectionFresh();
        $update = $pdo->prepare(
            'UPDATE sync_sales_audit_jobs
             SET status=?,next_run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ' . $delay . ' SECOND),
                 locked_by=NULL,lock_expires_at=NULL,
                 consecutive_failures=CASE WHEN ?="error" THEN consecutive_failures+1 ELSE consecutive_failures END,
                 safe_error_message=?,diagnostic_id=?,updated_at=UTC_TIMESTAMP(),
                 last_error_class=?,last_error_retryable=?,heartbeat_at=NULL
             WHERE id=? AND locked_by=? AND lease_generation=?'
        );
        $update->execute([
            $status,
            $status,
            $safeMessage,
            $diagnostic,
            $error ? $error::class : null,
            $error ? 1 : 0,
            (int) $job['id'],
            $worker,
            (int) $job['lease_generation'],
        ]);
        if ($update->rowCount() !== 1) {
            return;
        }
        $pdo->prepare(
            'UPDATE sync_sales_audit_runs SET status=?,safe_error_message=?,diagnostic_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?'
        )->execute([$status === 'error' ? 'error' : 'running', $safeMessage, $diagnostic, (int) $job['sync_sales_audit_run_id']]);
    }

    private function complete(array $job, string $worker): void
    {
        $update = Database::connectionFresh()->prepare(
            'UPDATE sync_sales_audit_jobs
             SET status="complete",locked_by=NULL,lock_expires_at=NULL,consecutive_failures=0,
                 safe_error_message=NULL,diagnostic_id=NULL,completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),
                 heartbeat_at=NULL,last_error_class=NULL,last_error_retryable=0
             WHERE id=? AND locked_by=? AND lease_generation=?'
        );
        $update->execute([(int) $job['id'], $worker, (int) $job['lease_generation']]);
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
               AND status="running" AND lock_expires_at>=UTC_TIMESTAMP()'
        );
        $stmt->execute([(int) $job['id'], $worker, (int) $job['lease_generation']]);
        return (int) $stmt->fetchColumn() === 1;
    }
}
