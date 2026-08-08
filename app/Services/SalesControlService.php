<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use PDO;
use Throwable;

/**
 * Read model y comandos locales del Control de ventas.
 *
 * Ningún método web de esta clase consulta Mercado Libre: checkYear/checkMonth
 * solo crean trabajos para SalesAuditRunService.
 */
final class SalesControlService
{
    private const MONTHS = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    public function available(): bool
    {
        return $this->schemaStatus()['ready'];
    }

    /** @return array{ready:bool,base_ready:bool,missing:list<string>,message:string} */
    public function schemaStatus(): array
    {
        return (new SalesControlSchemaContractService())->inspect();
    }

    /** @return list<array<string,mixed>> */
    public function accounts(int $companyId = 0): array
    {
        return (new SalesAuditAccessGateway())->accounts($companyId);
    }

    /** @return array<string,mixed> */
    public function overview(int $accountId, int $year, int $companyId = 0): array
    {
        $account = (new SalesAuditAccessGateway())->account($accountId, $companyId);
        $companyId = (int) $account['company_id'];
        $this->assertYear($year);
        $runs = $this->latestRuns($companyId, $accountId, $year);
        $stored = $this->storedMonths($companyId, $accountId, $year);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota'));
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('n');
        $lastMonth = $year < $currentYear ? 12 : ($year === $currentYear ? $currentMonth : 0);
        $safety = (new SystemSafetyStatusService())->status();
        $automationStopped = (string) ($safety['automation'] ?? 'unknown') === 'stopped';
        $remoteWindowMonths = max(
            1,
            min(24, (new AppSettingsService())->int('sales_control.remote_window_months', 12))
        );
        $temporalCoverageService = new SalesTemporalCoverageService();

        $months = [];
        $totals = [
            'sales' => 0,
            'missing' => 0,
            'fiscal_missing' => 0,
            'closed' => 0,
            'checked' => 0,
            'unknown' => 0,
        ];
        for ($month = 1; $month <= 12; $month++) {
            $isFuture = $month > $lastMonth;
            $run = $runs[$month] ?? null;
            $control = $stored[$month] ?? null;
            $row = $this->presentMonth(
                $year,
                $month,
                $isFuture,
                $run,
                $control,
                $month === $currentMonth && $year === $currentYear,
                $automationStopped,
                $temporalCoverageService->month($year, $month, $lastMonth, $now, $remoteWindowMonths, $run)
            );
            $months[] = $row;
            if (!$isFuture) {
                $totals['sales'] += (int) ($row['remote_total'] ?? 0);
                $totals['missing'] += (int) ($row['missing_total'] ?? 0);
                if (!array_key_exists('remote_total', $row) || $row['remote_total'] === null) {
                    $totals['unknown']++;
                }
                if (array_key_exists('fiscal_missing_total', $row) && $row['fiscal_missing_total'] !== null) {
                    $totals['fiscal_missing'] += (int) $row['fiscal_missing_total'];
                }
                $totals['closed'] += $row['state'] === 'closed' ? 1 : 0;
                $totals['checked'] += !empty($row['checked']) ? 1 : 0;
            }
        }
        $totals['sales_confirmed'] = $totals['sales'];
        $totals['missing_confirmed'] = $totals['missing'];
        $totals['fiscal_missing_confirmed'] = $totals['fiscal_missing'];
        if ($totals['unknown'] > 0) {
            $totals['sales'] = null;
            $totals['missing'] = null;
            $totals['fiscal_missing'] = null;
        }

        $recommendation = $this->recommendation($months);
        $coverage = $temporalCoverageService->yearSummary(
            $year,
            $lastMonth,
            $now,
            $remoteWindowMonths,
            (int) $totals['checked'],
            max(0, $lastMonth)
        );

        return [
            'account' => $account,
            'year' => $year,
            'months' => $months,
            'totals' => $totals,
            'month_count' => max(0, $lastMonth),
            'coverage' => [
                'from' => $coverage['available_from'],
                'to' => $coverage['available_to'],
                'requested_from' => $coverage['requested_from'],
                'requested_to' => $coverage['requested_to'],
                'available' => $coverage['available'],
                'confidence' => $coverage['confidence'],
                'partial' => $coverage['partial'],
                'remote_window_months' => $coverage['remote_window_months'],
                'message' => $coverage['message'],
            ],
            'recommendation' => $recommendation,
            'state' => $this->yearState($months, $lastMonth),
            'system_safety' => $safety,
            'automation_stopped' => $automationStopped,
        ];
    }

    /** @return array<string,mixed> */
    public function month(
        int $accountId,
        int $year,
        int $month,
        int $companyId = 0,
        int $page = 1,
        int $perPage = 50,
        ?string $classification = null
    ): array {
        $account = (new SalesAuditAccessGateway())->account($accountId, $companyId);
        $this->assertPeriod($year, $month);
        $run = (new SalesAuditRunService())->latest(
            $accountId,
            $year,
            $month,
            $page,
            $perPage,
            $classification,
            (int) $account['company_id']
        );
        $overview = $this->overview($accountId, $year, (int) $account['company_id']);
        $monthRow = $overview['months'][$month - 1];
        $fiscal = $this->fiscalSummary((int) $account['company_id'], $accountId, $year, $month);
        $automationStopped = !empty($overview['automation_stopped']);
        return compact('account', 'year', 'month', 'run', 'monthRow', 'fiscal', 'page', 'perPage', 'classification', 'automationStopped');
    }

    /** @return array{created:int,jobs:list<int>} */
    public function checkYear(int $accountId, int $year, int $companyId = 0, ?int $userId = null): array
    {
        $account = (new SalesAuditAccessGateway())->account($accountId, $companyId);
        $companyId = (int) $account['company_id'];
        $this->assertYear($year);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota'));
        $lastMonth = $year < (int) $now->format('Y') ? 12 : ($year === (int) $now->format('Y') ? (int) $now->format('n') : 0);
        if ($lastMonth === 0) {
            throw new \InvalidArgumentException('El año seleccionado todavía no ha comenzado.');
        }
        $this->ensureYear($companyId, $accountId, $year, $userId);
        $annualRunId = $this->ensureAnnualRun($companyId, $accountId, $year, $lastMonth, $userId);
        Database::connectionFresh()->prepare(
            'UPDATE sales_control_years SET status="checking",updated_at=UTC_TIMESTAMP()
             WHERE company_id=? AND meli_account_id=? AND control_year=?'
        )->execute([$companyId, $accountId, $year]);
        for ($month = 1; $month <= $lastMonth; $month++) {
            $this->ensureMonth($companyId, $accountId, $year, $month, $userId);
        }
        $this->enqueueNextYearMonth($companyId, $accountId, $year);
        $jobsStmt = Database::connectionFresh()->prepare(
            'SELECT j.id
             FROM sync_sales_audit_jobs j
             JOIN sync_sales_audit_runs r ON r.id=j.sync_sales_audit_run_id
             WHERE r.company_id=? AND r.meli_account_id=? AND r.period_year=?
               AND j.status IN ("pending","running","waiting_budget")
             ORDER BY j.id'
        );
        $jobsStmt->execute([$companyId, $accountId, $year]);
        $jobs = array_map('intval', $jobsStmt->fetchAll(PDO::FETCH_COLUMN));
        return [
            'created' => $jobs === [] ? 0 : 1,
            'jobs' => $jobs,
            'annual_run_id' => $annualRunId,
        ];
    }

    public function checkMonth(int $accountId, int $year, int $month, int $companyId = 0, ?int $userId = null): int
    {
        $account = (new SalesAuditAccessGateway())->account($accountId, $companyId);
        $companyId = (int) $account['company_id'];
        $this->assertPeriod($year, $month);
        $this->ensureYear($companyId, $accountId, $year, $userId);
        $this->ensureMonth($companyId, $accountId, $year, $month, $userId);
        return (new SalesAuditRunService())->createExactMonth($accountId, $year, $month, $userId, $companyId);
    }

    public function recordAuditRun(int $runId): void
    {
        if (!$this->available()) {
            return;
        }
        $run = (new SalesAuditAccessGateway())->run($runId);
        if (!in_array((string) $run['status'], ['complete', 'partial'], true) || empty($run['snapshot_hash'])) {
            return;
        }
        $companyId = (int) $run['account_company_id'];
        $accountId = (int) $run['meli_account_id'];
        $year = (int) $run['period_year'];
        $month = (int) $run['period_month'];
        $yearId = $this->ensureYear($companyId, $accountId, $year, null);
        $monthId = $this->ensureMonth($companyId, $accountId, $year, $month, null);
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $seqStmt = $pdo->prepare('SELECT COALESCE(MAX(capture_sequence),0)+1 FROM sales_control_captures WHERE sales_control_month_id=?');
            $seqStmt->execute([$monthId]);
            $sequence = min(255, max(1, (int) $seqStmt->fetchColumn()));
            $temporalState = (string) ($run['temporal_coverage_state'] ?? 'pending');
            $temporalFull = $temporalState === 'full'
                && (int) ($run['coverage_contract_version'] ?? 0) >= SalesAuditTemporalCoverageService::CONTRACT_VERSION;
            $coverageConfidence = (string) $run['remote_coverage'] === 'complete' && $temporalFull
                ? ($this->isCurrentPeriod($year, $month) ? 'provisional' : 'complete')
                : 'partial';
            $pdo->prepare(
                'INSERT IGNORE INTO sales_control_captures
                 (sales_control_month_id,company_id,meli_account_id,sync_sales_audit_run_id,capture_sequence,
                  capture_role,
                  requested_from_utc,requested_to_utc,effective_coverage_from_utc,effective_coverage_to_utc,
                  temporal_coverage_state,temporal_coverage_reason,coverage_contract_version,
                  snapshot_hash,remote_total,coverage_status,http_status,
                  content_missing_json,content_missing,source_breakdown_json,coverage_confidence,
                  validation_state,validation_json,timezone_used,normalizer_version,captured_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $monthId,
                $companyId,
                $accountId,
                $runId,
                $sequence,
                (string) ($run['capture_role'] ?? 'primary'),
                (string) ($run['requested_from_utc'] ?? $run['utc_from']),
                (string) ($run['requested_to_utc'] ?? $run['utc_to']),
                $run['effective_coverage_from_utc'] ?? null,
                $run['effective_coverage_to_utc'] ?? null,
                $temporalState,
                $run['temporal_coverage_reason'] ?? null,
                (int) ($run['coverage_contract_version'] ?? 1),
                (string) $run['snapshot_hash'],
                (int) $run['remote_unique_total'],
                (string) $run['remote_coverage'],
                $run['coverage_http_status'] !== null ? (int) $run['coverage_http_status'] : null,
                $run['content_missing_json'],
                $run['content_missing_json'] !== null
                    ? mb_substr((string) $run['content_missing_json'], 0, 500)
                    : null,
                json_encode(['search' => (int) $run['remote_unique_total']], JSON_UNESCAPED_UNICODE),
                $coverageConfidence,
                (string) ($run['coverage_validation_state'] ?? 'pending'),
                $run['coverage_validation_json'] ?? null,
                (string) $run['timezone_used'],
                (string) $run['normalizer_version'],
                (string) ($run['capture_finished_at'] ?? $run['completed_at'] ?? gmdate('Y-m-d H:i:s')),
            ]);
            $captureCountStmt = $pdo->prepare('SELECT COUNT(*) FROM sales_control_captures WHERE sales_control_month_id=?');
            $captureCountStmt->execute([$monthId]);
            $captureCount = (int) $captureCountStmt->fetchColumn();
            $state = $this->rawMonthState($run, $captureCount);
            $coverageState = $coverageConfidence;
            $pdo->prepare(
                'UPDATE sales_control_months
                 SET current_audit_run_id=?,
                     verification_audit_run_id=IF(? >= 2,?,verification_audit_run_id),
                     status=?,coverage_state=?,coverage_from=?,coverage_to=?,
                     source_search_total=?,cancellation_coverage="limited",
                     coverage_message=?,remote_total=?,local_total=?,missing_total=?,
                     temporal_issue_total=?,last_checked_at=?,updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND company_id=? AND meli_account_id=?'
            )->execute([
                $runId,
                $captureCount,
                $runId,
                $state,
                $coverageState,
                substr((string) $run['local_from'], 0, 10),
                substr((string) $run['local_to'], 0, 10),
                (int) $run['remote_unique_total'],
                'La búsqueda se verificó para el periodo, pero las ventas canceladas requieren evidencia complementaria.',
                (int) $run['remote_unique_total'],
                (int) $run['local_period_total'],
                (int) $run['missing_total'],
                (int) $run['shifted_total'] + (int) $run['missing_normalized_total'] + (int) $run['other_account_total'],
                (string) ($run['completed_at'] ?? gmdate('Y-m-d H:i:s')),
                $monthId,
                $companyId,
                $accountId,
            ]);
            $pdo->prepare(
                'INSERT INTO sales_control_month_sources
                 (sales_control_month_id,company_id,meli_account_id,external_order_id,source_kind,
                  first_seen_at,last_seen_at,current_capture_id)
                 SELECT ?,?,?,o.external_order_id,"search",COALESCE(o.checked_at,o.created_at),
                        COALESCE(o.checked_at,o.updated_at),c.id
                 FROM sync_sales_audit_run_orders o
                 JOIN sales_control_captures c ON c.sync_sales_audit_run_id=?
                 WHERE o.sync_sales_audit_run_id=? AND o.meli_account_id=?
                   AND o.classification<>"outside_range"
                 ON DUPLICATE KEY UPDATE last_seen_at=VALUES(last_seen_at),
                   current_capture_id=VALUES(current_capture_id)'
            )->execute([$monthId, $companyId, $accountId, $runId, $runId, $accountId]);
            $pdo->prepare(
                'UPDATE sales_control_years
                 SET last_checked_at=UTC_TIMESTAMP(),
                     status=IF(status="checking","checking","needs_review"),
                     updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND company_id=? AND meli_account_id=?'
            )->execute([$yearId, $companyId, $accountId]);
            $pdo->commit();
            $this->enqueueNextYearMonth($companyId, $accountId, $year);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        $this->classifyFiscalLocally($monthId, $companyId, $accountId, $run);
        $this->enqueueSafeRemediation($run);
    }

    /**
     * Encola correcciones locales seguras despues de una auditoria primaria.
     *
     * No consulta Mercado Libre desde web. Solo prepara jobs idempotentes para que
     * el lanzador único descargue faltantes o recalcule fechas desde raw_json.
     *
     * @param array<string,mixed> $run
     */
    private function enqueueSafeRemediation(array $run): void
    {
        if ((string) ($run['capture_role'] ?? 'primary') !== 'primary') {
            return;
        }
        if (!in_array((string) ($run['status'] ?? ''), ['complete', 'partial'], true)) {
            return;
        }

        $accountId = (int) $run['meli_account_id'];
        $companyId = (int) ($run['account_company_id'] ?? $run['company_id'] ?? 0);
        $year = (int) $run['period_year'];
        $month = (int) $run['period_month'];

        if ((int) ($run['missing_total'] ?? 0) > 0
            && (new AppSettingsService())->bool('sales_import.auto_repair_missing', true)) {
            try {
                (new SalesAuditExactRepairService())->createFromRun((int) $run['id'], null, $companyId, $accountId);
            } catch (Throwable $error) {
                Logger::write('warning', 'No se pudo preparar reparacion exacta automatica.', [
                    'audit_run_id' => (int) $run['id'],
                    'account_id' => $accountId,
                    'year' => $year,
                    'month' => $month,
                    'error' => $error->getMessage(),
                ]);
            }
        }

        $dateIssues = (int) ($run['missing_normalized_total'] ?? 0) + (int) ($run['shifted_total'] ?? 0);
        if ($dateIssues <= 0 || !(new AppSettingsService())->bool('sales_import.auto_repair_dates', true)) {
            return;
        }
        if ($this->hasActiveDateRepairJob($accountId, $year, $month)) {
            return;
        }
        try {
            (new OrderDateRepairService())->createJob($accountId, $year, $month, null);
        } catch (Throwable $error) {
            Logger::write('warning', 'No se pudo preparar reparacion automatica de fechas.', [
                'audit_run_id' => (int) $run['id'],
                'account_id' => $accountId,
                'year' => $year,
                'month' => $month,
                'error' => $error->getMessage(),
            ]);
        }
    }

    private function hasActiveDateRepairJob(int $accountId, int $year, int $month): bool
    {
        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('order_datetime_repair_jobs')) {
            return true;
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT COUNT(*) FROM order_datetime_repair_jobs
             WHERE meli_account_id=? AND period_year=? AND period_month=?
               AND status IN ("pending","running")'
        );
        $stmt->execute([$accountId, $year, $month]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function closeMonth(int $accountId, int $year, int $month, int $companyId, int $userId): int
    {
        $account = (new SalesAuditAccessGateway())->account($accountId, $companyId);
        $companyId = (int) $account['company_id'];
        $this->assertClosedPeriod($year, $month);
        $monthRow = $this->controlMonth($companyId, $accountId, $year, $month);
        $captures = $this->lastCaptures((int) $monthRow['id'], 2);
        if (count($captures) < 2) {
            throw new \RuntimeException('Falta una segunda comprobación. Vuelva a comprobar el mes antes de cerrarlo.');
        }
        $newest = $captures[0];
        $previous = $captures[1];
        $minimumDelay = max(
            5,
            min(1440, (new AppSettingsService())->int('sales_control.verification_min_delay_minutes', 15))
        );
        $newestAt = strtotime((string) $newest['captured_at']) ?: 0;
        $previousAt = strtotime((string) $previous['captured_at']) ?: 0;
        if ((int) $newest['sync_sales_audit_run_id'] === (int) $previous['sync_sales_audit_run_id']
            || (string) ($newest['validation_state'] ?? '') !== 'valid'
            || (string) ($previous['validation_state'] ?? '') !== 'valid'
            || $newestAt - $previousAt < $minimumDelay * 60) {
            throw new \RuntimeException(
                'Las dos comprobaciones todavía no son independientes y válidas. '
                . 'Espere la segunda captura programada antes de cerrar.'
            );
        }
        if ((string) $captures[0]['snapshot_hash'] !== (string) $captures[1]['snapshot_hash']
            || (string) $captures[0]['coverage_status'] !== 'complete'
            || (string) $captures[1]['coverage_status'] !== 'complete') {
            throw new \RuntimeException('Las dos comprobaciones no coinciden o tienen cobertura parcial. El mes todavía está cambiando.');
        }
        $run = (new SalesAuditAccessGateway())->run((int) $captures[0]['sync_sales_audit_run_id'], $companyId, $accountId);
        $temporal = (int) $run['shifted_total'] + (int) $run['missing_normalized_total'] + (int) $run['other_account_total'];
        if ((int) $run['missing_total'] > 0 || $temporal > 0 || (int) $run['extra_total'] > 0) {
            throw new \RuntimeException('El mes todavía tiene diferencias de ventas. Resuélvalas antes de cerrar.');
        }
        if (!(new SalesAuditTemporalCoverageService())->canClose($run)) {
            throw new \RuntimeException(
                'No se puede cerrar este mes porque la cobertura temporal no demuestra el periodo completo solicitado. '
                . 'Conserve la evidencia como historial parcial o revise un periodo dentro de la ventana disponible.'
            );
        }
        $fiscal = $this->fiscalSummary($companyId, $accountId, $year, $month);
        if ((int) $fiscal['needs_attention'] > 0) {
            throw new \RuntimeException('La preparación fiscal todavía tiene registros por revisar.');
        }
        $idsStmt = Database::connectionFresh()->prepare(
            'SELECT external_order_id FROM sync_sales_audit_run_orders
             WHERE sync_sales_audit_run_id=? AND meli_account_id=? ORDER BY external_order_id'
        );
        $idsStmt->execute([(int) $run['id'], $accountId]);
        $evidence = [
            'schema' => 'sales-control-close-v1',
            'company_id' => $companyId,
            'meli_account_id' => $accountId,
            'period' => sprintf('%04d-%02d', $year, $month),
            'local_range' => [(string) $run['local_from'], (string) $run['local_to']],
            'utc_range' => [(string) $run['utc_from'], (string) $run['utc_to']],
            'timezone' => (string) $run['timezone_used'],
            'normalizer_version' => (string) $run['normalizer_version'],
            'audit_run_id' => (int) $run['id'],
            'verification_run_id' => (int) $newest['sync_sales_audit_run_id'],
            'primary_run_id' => (int) $previous['sync_sales_audit_run_id'],
            'snapshot_hash' => (string) $run['snapshot_hash'],
            'coverage' => (string) $run['remote_coverage'],
            'temporal_coverage' => [
                'state' => (string) ($run['temporal_coverage_state'] ?? 'pending'),
                'reason' => (string) ($run['temporal_coverage_reason'] ?? ''),
                'requested_from_utc' => (string) ($run['requested_from_utc'] ?? $run['utc_from']),
                'requested_to_utc' => (string) ($run['requested_to_utc'] ?? $run['utc_to']),
                'effective_from_utc' => $run['effective_coverage_from_utc'] ?? null,
                'effective_to_utc' => $run['effective_coverage_to_utc'] ?? null,
                'contract_version' => (int) ($run['coverage_contract_version'] ?? 1),
            ],
            'totals' => [
                'remote' => (int) $run['remote_unique_total'],
                'local' => (int) $run['local_period_total'],
                'missing' => (int) $run['missing_total'],
                'extra' => (int) $run['extra_total'],
            ],
            'external_order_ids' => array_map('strval', $idsStmt->fetchAll(PDO::FETCH_COLUMN)),
            'fiscal' => $fiscal,
            'closed_at' => gmdate(DATE_ATOM),
            'closed_by' => $userId,
        ];
        $encoded = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $evidenceHash = hash('sha256', $encoded);
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT * FROM sales_control_months WHERE id=? AND company_id=? AND meli_account_id=? FOR UPDATE');
            $lock->execute([(int) $monthRow['id'], $companyId, $accountId]);
            $lockedMonth = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$lockedMonth || (string) $lockedMonth['status'] === 'closed') {
                throw new \RuntimeException('El mes ya está cerrado o cambió mientras se procesaba.');
            }
            $revisionStmt = $pdo->prepare('SELECT COALESCE(MAX(revision_number),0)+1 FROM sales_control_closes WHERE sales_control_month_id=?');
            $revisionStmt->execute([(int) $monthRow['id']]);
            $revision = (int) $revisionStmt->fetchColumn();
            $pdo->prepare(
                'INSERT INTO sales_control_closes
                 (sales_control_month_id,company_id,meli_account_id,revision_number,audit_run_id,
                  verification_run_id,snapshot_hash,coverage_contract_version,temporal_coverage_state,
                  evidence_json,evidence_hash,closed_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                (int) $monthRow['id'], $companyId, $accountId, $revision,
                (int) $previous['sync_sales_audit_run_id'], (int) $newest['sync_sales_audit_run_id'],
                (string) $run['snapshot_hash'],
                SalesAuditTemporalCoverageService::CONTRACT_VERSION,
                (string) ($run['temporal_coverage_state'] ?? 'pending'),
                $encoded,
                $evidenceHash,
                $userId,
            ]);
            $closeId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'UPDATE sales_control_months SET status="closed",current_close_id=?,updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND company_id=? AND meli_account_id=?'
            )->execute([$closeId, (int) $monthRow['id'], $companyId, $accountId]);
            $pdo->commit();
            $this->auditAccess($companyId, $accountId, $userId, 'close', 'month', (int) $monthRow['id']);
            return $closeId;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function reopenMonth(int $accountId, int $year, int $month, int $companyId, int $userId, string $reason): int
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new \InvalidArgumentException('Explique el motivo de la reapertura en al menos 10 caracteres.');
        }
        $account = (new SalesAuditAccessGateway())->account($accountId, $companyId);
        $companyId = (int) $account['company_id'];
        $monthRow = $this->controlMonth($companyId, $accountId, $year, $month);
        if ((string) $monthRow['status'] !== 'closed' || (int) $monthRow['current_close_id'] <= 0) {
            throw new \RuntimeException('Este mes no tiene un cierre vigente para reabrir.');
        }
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO sales_control_reopenings
                 (sales_control_month_id,previous_close_id,company_id,meli_account_id,reason,reopened_by)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                (int) $monthRow['id'], (int) $monthRow['current_close_id'],
                $companyId, $accountId, mb_substr($reason, 0, 500), $userId,
            ]);
            $reopeningId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'UPDATE sales_control_closes SET status="superseded",superseded_at=UTC_TIMESTAMP()
                 WHERE id=? AND company_id=? AND meli_account_id=? AND status="closed"'
            )->execute([(int) $monthRow['current_close_id'], $companyId, $accountId]);
            $pdo->prepare(
                'UPDATE sales_control_months
                 SET status="reopened",current_close_id=NULL,updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND company_id=? AND meli_account_id=?'
            )->execute([(int) $monthRow['id'], $companyId, $accountId]);
            $pdo->commit();
            $this->auditAccess($companyId, $accountId, $userId, 'reopen', 'month', (int) $monthRow['id']);
            return $reopeningId;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function closes(int $accountId, int $year, int $companyId = 0): array
    {
        $account = (new SalesAuditAccessGateway())->account($accountId, $companyId);
        $stmt = Database::connectionFresh()->prepare(
            'SELECT cl.id,cl.revision_number,cl.status,cl.snapshot_hash,cl.evidence_hash,
                    cl.closed_at,cl.superseded_at,m.period_year,m.period_month,u.name closed_by_name
             FROM sales_control_closes cl
             JOIN sales_control_months m ON m.id=cl.sales_control_month_id
             JOIN users u ON u.id=cl.closed_by
             WHERE cl.company_id=? AND cl.meli_account_id=? AND m.period_year=?
             ORDER BY m.period_month DESC,cl.revision_number DESC'
        );
        $stmt->execute([(int) $account['company_id'], $accountId, $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    public function fiscalSummary(int $companyId, int $accountId, int $year, int $month): array
    {
        if (!$this->available()) {
            return ['total' => 0, 'ready' => 0, 'missing' => 0, 'credit_note' => 0, 'reconciliation' => 0, 'needs_attention' => 0];
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT
                COUNT(fi.id) total,
                SUM(fi.fiscal_status IN ("ready","invoiced","not_invoiceable","excluded")) ready,
                SUM(fi.fiscal_status="fiscal_data_required") missing,
                SUM(fi.fiscal_status="credit_note_review") credit_note,
                SUM(fi.fiscal_status IN ("reconciliation","human_review")) reconciliation
             FROM sales_control_months m
             LEFT JOIN sales_control_fiscal_items fi ON fi.sales_control_month_id=m.id
               AND fi.company_id=m.company_id AND fi.meli_account_id=m.meli_account_id
             WHERE m.company_id=? AND m.meli_account_id=? AND m.period_year=? AND m.period_month=?'
        );
        $stmt->execute([$companyId, $accountId, $year, $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $result = [
            'total' => (int) ($row['total'] ?? 0),
            'ready' => (int) ($row['ready'] ?? 0),
            'missing' => (int) ($row['missing'] ?? 0),
            'credit_note' => (int) ($row['credit_note'] ?? 0),
            'reconciliation' => (int) ($row['reconciliation'] ?? 0),
        ];
        $result['needs_attention'] = $result['missing'] + $result['credit_note'] + $result['reconciliation'];
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function latestRuns(int $companyId, int $accountId, int $year): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT r.*
             FROM sync_sales_audit_runs r
             JOIN (
                SELECT period_month,MAX(id) id
                FROM sync_sales_audit_runs
                WHERE company_id=? AND meli_account_id=? AND period_year=? AND mode="exact"
                GROUP BY period_month
             ) latest ON latest.id=r.id
             WHERE r.company_id=? AND r.meli_account_id=? AND r.period_year=?'
        );
        $stmt->execute([$companyId, $accountId, $year, $companyId, $accountId, $year]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['period_month']] = $row;
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function storedMonths(int $companyId, int $accountId, int $year): array
    {
        if (!$this->available()) {
            return [];
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT * FROM sales_control_months
             WHERE company_id=? AND meli_account_id=? AND period_year=? ORDER BY period_month'
        );
        $stmt->execute([$companyId, $accountId, $year]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['period_month']] = $row;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function presentMonth(
        int $year,
        int $month,
        bool $future,
        ?array $run,
        ?array $control,
        bool $current,
        bool $automationStopped,
        array $temporalCoverage = []
    ): array
    {
        $withTemporal = static fn (array $row): array => $row + ['temporal_coverage' => $temporalCoverage];
        if ($future) {
            return $withTemporal([
                'year' => $year, 'month' => $month, 'name' => self::MONTHS[$month],
                'state' => 'future', 'label' => 'Todavía no corresponde',
                'tone' => 'neutral', 'summary' => 'Este mes aún no ha comenzado.',
                'action' => null, 'checked' => false,
            ]);
        }
        if ($control && (string) $control['status'] === 'closed') {
            return $withTemporal($this->monthNumbers($year, $month, $run, $control) + [
                'name' => self::MONTHS[$month], 'state' => 'closed', 'label' => 'Cerrado',
                'tone' => 'success', 'summary' => 'La evidencia de este mes está protegida.',
                'action' => 'Ver cierre', 'checked' => true,
            ]);
        }
        if (!$run) {
            if (($temporalCoverage['key'] ?? '') === 'outside_remote_window') {
                return $withTemporal([
                    'year' => $year, 'month' => $month, 'name' => self::MONTHS[$month],
                    'state' => 'remote_unavailable', 'label' => 'Historial no demostrable',
                    'tone' => 'neutral', 'summary' => (string) ($temporalCoverage['message'] ?? 'El mes está fuera de la ventana remota aproximada.'),
                    'action' => 'Ver evidencia local', 'checked' => false,
                ]);
            }
            if (($temporalCoverage['key'] ?? '') === 'partial_remote_window') {
                return $withTemporal([
                    'year' => $year, 'month' => $month, 'name' => self::MONTHS[$month],
                    'state' => 'partial_history', 'label' => 'Ventana parcial',
                    'tone' => 'warning', 'summary' => (string) ($temporalCoverage['message'] ?? 'Solo parte del mes puede demostrarse contra la ventana remota.'),
                    'action' => 'Comprobar parte disponible', 'checked' => false,
                ]);
            }
            return $withTemporal([
                'year' => $year, 'month' => $month, 'name' => self::MONTHS[$month],
                'state' => 'unchecked', 'label' => 'Sin comprobar',
                'tone' => 'neutral', 'summary' => 'Todavía no se ha comparado con Mercado Libre.',
                'action' => 'Comprobar mes', 'checked' => false,
            ]);
        }
        $status = (string) $run['status'];
        if ($automationStopped && in_array($status, ['pending', 'running', 'waiting_budget', 'paused'], true)) {
            return $withTemporal([
                'year' => $year, 'month' => $month,
                'remote_total' => null, 'local_total' => null, 'missing_total' => null,
                'fiscal_missing_total' => null, 'run_id' => (int) $run['id'],
                'job_id' => isset($run['job_id']) ? (int) $run['job_id'] : null,
                'checked_at' => null,
                'name' => self::MONTHS[$month], 'state' => 'waiting_automation',
                'label' => 'Preparado · esperando automatización',
                'tone' => 'queued',
                'summary' => 'El trabajo está guardado y continuará cuando se reactive la automatización.',
                'action' => 'Ver trabajo preparado', 'checked' => false,
            ]);
        }
        if ($status === 'pending') {
            return $withTemporal([
                'year' => $year, 'month' => $month,
                'remote_total' => null, 'local_total' => null, 'missing_total' => null,
                'fiscal_missing_total' => null, 'run_id' => (int) $run['id'],
                'job_id' => isset($run['job_id']) ? (int) $run['job_id'] : null,
                'checked_at' => null,
                'name' => self::MONTHS[$month], 'state' => 'queued', 'label' => 'Trabajo preparado',
                'tone' => 'queued', 'summary' => 'Automatización todavía no ha iniciado esta comprobación.',
                'action' => 'Ver trabajo preparado', 'checked' => false,
            ]);
        }
        if (in_array($status, ['pending', 'running', 'waiting_budget', 'paused'], true)) {
            return $withTemporal([
                'year' => $year, 'month' => $month,
                'remote_total' => null, 'local_total' => null, 'missing_total' => null,
                'fiscal_missing_total' => null, 'run_id' => (int) $run['id'],
                'job_id' => isset($run['job_id']) ? (int) $run['job_id'] : null,
                'checked_at' => null,
                'name' => self::MONTHS[$month], 'state' => 'checking', 'label' => 'Comprobando ventas',
                'tone' => 'active', 'summary' => 'El trabajo continuará desde su último punto guardado.',
                'action' => 'Ver progreso', 'checked' => false,
            ]);
        }
        $coverageValid = (string) ($run['coverage_validation_state'] ?? 'pending') === 'valid';
        $temporalState = (string) ($run['temporal_coverage_state'] ?? 'pending');
        $temporalReason = (string) ($run['temporal_coverage_reason'] ?? '');
        $temporalFull = $temporalState === 'full'
            && (int) ($run['coverage_contract_version'] ?? 0) >= SalesAuditTemporalCoverageService::CONTRACT_VERSION;
        $currentOpenTemporalPartial = $current
            && $temporalState === 'partial'
            && str_contains(mb_strtolower($temporalReason), 'abierto');
        if ($status !== 'error'
            && $coverageValid
            && (string) $run['remote_coverage'] === 'complete'
            && !$temporalFull
            && !$currentOpenTemporalPartial) {
            return $withTemporal($this->monthNumbers($year, $month, $run, $control) + [
                'name' => self::MONTHS[$month],
                'state' => $temporalState === 'outside' ? 'outside_remote_window' : 'partial_remote_window',
                'label' => $temporalState === 'outside' ? 'Fuera de ventana remota' : 'Historial parcial',
                'tone' => 'warning',
                'summary' => $temporalReason !== '' ? $temporalReason : 'La evidencia temporal no permite afirmar cobertura remota completa.',
                'action' => 'Ver evidencia',
                'checked' => false,
            ]);
        }
        if ($status === 'error' || (string) $run['remote_coverage'] !== 'complete' || !$coverageValid) {
            return $withTemporal($this->monthNumbers($year, $month, $run, $control) + [
                'name' => self::MONTHS[$month], 'state' => 'failed',
                'label' => $status === 'error' ? 'No se pudo comprobar' : 'Cobertura por comprobar',
                'tone' => 'danger', 'summary' => $status === 'error'
                    ? 'La comprobación se detuvo de forma segura.'
                    : 'La captura todavía no demuestra que todas sus páginas sean continuas y completas.',
                'action' => 'Revisar', 'checked' => false,
            ]);
        }
        if ((int) $run['missing_total'] > 0) {
            return $withTemporal($this->monthNumbers($year, $month, $run, $control) + [
                'name' => self::MONTHS[$month], 'state' => 'missing_sales', 'label' => 'Faltan ventas',
                'tone' => 'warning',
                'summary' => (int) $run['missing_total'] . ' ventas de Mercado Libre todavía no existen en el ERP.',
                'action' => 'Revisar diferencias', 'checked' => true,
            ]);
        }
        $temporal = (int) $run['shifted_total'] + (int) $run['missing_normalized_total'] + (int) $run['other_account_total'];
        if ($temporal > 0) {
            return $withTemporal($this->monthNumbers($year, $month, $run, $control) + [
                'name' => self::MONTHS[$month], 'state' => 'date_attention', 'label' => 'Requiere corregir fechas',
                'tone' => 'warning', 'summary' => $temporal . ' ventas necesitan revisar su fecha o cuenta.',
                'action' => 'Revisar fechas', 'checked' => true,
            ]);
        }
        $fiscalMissing = (int) ($control['fiscal_missing_total'] ?? 0)
            + (int) ($control['credit_note_review_total'] ?? 0)
            + (int) ($control['reconciliation_total'] ?? 0);
        if ($fiscalMissing > 0) {
            return $withTemporal($this->monthNumbers($year, $month, $run, $control) + [
                'name' => self::MONTHS[$month], 'state' => 'fiscal_incomplete', 'label' => 'Preparación fiscal incompleta',
                'tone' => 'warning', 'summary' => $fiscalMissing . ' ventas necesitan completar o revisar su clasificación fiscal.',
                'action' => 'Preparar datos fiscales', 'checked' => true,
            ]);
        }
        return $withTemporal($this->monthNumbers($year, $month, $run, $control) + [
            'name' => self::MONTHS[$month],
            'state' => $current ? 'tracking' : 'sales_verified',
            'label' => $current ? 'En seguimiento' : 'Ventas comprobadas',
            'tone' => $current ? 'active' : 'success',
            'summary' => $current
                ? 'El mes sigue abierto y puede recibir ventas nuevas.'
                : 'No se encontraron diferencias de ventas en la última comprobación.',
            'action' => $current ? 'Abrir' : 'Preparar cierre',
            'checked' => true,
        ]);
    }

    /** @return array<string,mixed> */
    private function monthNumbers(int $year, int $month, ?array $run, ?array $control): array
    {
        return [
            'year' => $year,
            'month' => $month,
            'remote_total' => $run ? (int) $run['remote_unique_total'] : null,
            'local_total' => $run ? (int) $run['local_period_total'] : null,
            'missing_total' => $run ? (int) $run['missing_total'] : null,
            'fiscal_missing_total' => $control && $control['fiscal_missing_total'] !== null
                ? (int) $control['fiscal_missing_total']
                : null,
            'temporal_coverage_state' => $run ? (string) ($run['temporal_coverage_state'] ?? 'pending') : null,
            'temporal_coverage_reason' => $run ? ($run['temporal_coverage_reason'] ?? null) : null,
            'run_id' => $run ? (int) $run['id'] : null,
            'job_id' => $run && isset($run['job_id']) ? (int) $run['job_id'] : null,
            'checked_at' => $run['completed_at'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function recommendation(array $months): array
    {
        foreach ($months as $month) {
            if (in_array((string) $month['state'], ['future', 'closed'], true)) {
                continue;
            }
            return [
                'title' => (string) ($month['action'] ?: 'Revisar ' . mb_strtolower((string) $month['name'])),
                'message' => (string) $month['summary'],
                'month' => (int) $month['month'],
                'uses_api' => in_array((string) $month['state'], ['unchecked', 'failed', 'tracking'], true),
            ];
        }
        return ['title' => 'Sin tareas pendientes', 'message' => 'Los meses disponibles no requieren una acción inmediata.', 'month' => null, 'uses_api' => false];
    }

    private function yearState(array $months, int $lastMonth): array
    {
        $actionable = array_filter(
            array_slice($months, 0, $lastMonth),
            static fn (array $month): bool => !in_array($month['state'], ['closed', 'sales_verified', 'tracking', 'remote_unavailable'], true)
        );
        return $actionable === []
            ? ['key' => 'ready', 'label' => 'El año está al día', 'message' => 'No hay diferencias de ventas pendientes.']
            : ['key' => 'attention', 'label' => 'El año necesita revisión', 'message' => count($actionable) . ' meses tienen un siguiente paso pendiente.'];
    }

    private function rawMonthState(array $run, int $captureCount): string
    {
        if ((string) $run['remote_coverage'] !== 'complete'
            || (string) ($run['coverage_validation_state'] ?? 'pending') !== 'valid') {
            return 'failed';
        }
        if ((string) ($run['temporal_coverage_state'] ?? 'pending') !== 'full'
            || (int) ($run['coverage_contract_version'] ?? 0) < SalesAuditTemporalCoverageService::CONTRACT_VERSION) {
            return 'failed';
        }
        if ((int) $run['missing_total'] > 0) {
            return 'missing_sales';
        }
        if ((int) $run['shifted_total'] + (int) $run['missing_normalized_total'] + (int) $run['other_account_total'] > 0) {
            return 'date_attention';
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota'));
        $current = (int) $run['period_year'] === (int) $now->format('Y')
            && (int) $run['period_month'] === (int) $now->format('n');
        return $current || $captureCount < 2 ? 'sales_verified' : 'fiscal_incomplete';
    }

    private function ensureYear(int $companyId, int $accountId, int $year, ?int $userId): int
    {
        $pdo = Database::connectionFresh();
        $pdo->prepare(
            'INSERT INTO sales_control_years
             (company_id,meli_account_id,control_year,status,coverage_from,coverage_to,created_by)
             VALUES (?,?,?,"needs_review",?,LEAST(CURRENT_DATE(),?),?)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),updated_at=UTC_TIMESTAMP()'
        )->execute([
            $companyId, $accountId, $year, sprintf('%04d-01-01', $year),
            sprintf('%04d-12-31', $year), $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function ensureMonth(int $companyId, int $accountId, int $year, int $month, ?int $userId): int
    {
        $yearId = $this->ensureYear($companyId, $accountId, $year, $userId);
        $pdo = Database::connectionFresh();
        $pdo->prepare(
            'INSERT INTO sales_control_months
             (sales_control_year_id,company_id,meli_account_id,period_year,period_month,status)
             VALUES (?,?,?, ?,?,"unchecked")
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),sales_control_year_id=VALUES(sales_control_year_id)'
        )->execute([$yearId, $companyId, $accountId, $year, $month]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function controlMonth(int $companyId, int $accountId, int $year, int $month): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT * FROM sales_control_months
             WHERE company_id=? AND meli_account_id=? AND period_year=? AND period_month=? LIMIT 1'
        );
        $stmt->execute([$companyId, $accountId, $year, $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new HttpException(404, 'Primero debe comprobar este mes.');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function lastCaptures(int $monthId, int $limit): array
    {
        $limit = max(1, min(5, $limit));
        $stmt = Database::connectionFresh()->prepare(
            'SELECT * FROM sales_control_captures
             WHERE sales_control_month_id=? ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([$monthId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function classifyFiscalLocally(int $monthId, int $companyId, int $accountId, array $run): void
    {
        $pdo = Database::connectionFresh();
        $orders = $pdo->prepare(
            'SELECT o.id,o.external_order_id,o.status,o.status_detail,
                    fs.id fiscal_snapshot_id,fs.completeness_status
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             LEFT JOIN sales_control_fiscal_snapshots fs ON fs.id=(
                SELECT MAX(fs2.id) FROM sales_control_fiscal_snapshots fs2
                WHERE fs2.company_id=? AND fs2.meli_account_id=o.meli_account_id AND fs2.meli_order_id=o.id
             )
             WHERE o.meli_account_id=? AND o.date_created_local>=? AND o.date_created_local<?
             ORDER BY o.id'
        );
        $orders->execute([
            $companyId, $companyId, $accountId,
            (string) $run['local_from'], (string) $run['local_to'],
        ]);
        $upsert = $pdo->prepare(
            'INSERT INTO sales_control_fiscal_items
             (sales_control_month_id,company_id,meli_account_id,meli_order_id,external_order_id,
              commercial_status,fiscal_status,source_kind,reason_code,fiscal_snapshot_id)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE commercial_status=VALUES(commercial_status),
               fiscal_status=IF(fiscal_status="invoiced","invoiced",VALUES(fiscal_status)),
               reason_code=VALUES(reason_code),fiscal_snapshot_id=VALUES(fiscal_snapshot_id),
               classified_at=UTC_TIMESTAMP()'
        );
        foreach ($orders->fetchAll(PDO::FETCH_ASSOC) as $order) {
            [$fiscalStatus, $reason] = $this->classifyFiscalOrder($order);
            $upsert->execute([
                $monthId, $companyId, $accountId, (int) $order['id'],
                (string) $order['external_order_id'], $order['status'], $fiscalStatus,
                'search', $reason, $order['fiscal_snapshot_id'],
            ]);
        }
        $summary = $this->fiscalSummary(
            $companyId,
            $accountId,
            (int) $run['period_year'],
            (int) $run['period_month']
        );
        $pdo->prepare(
            'UPDATE sales_control_months
             SET fiscal_ready_total=?,fiscal_missing_total=?,credit_note_review_total=?,
                 reconciliation_total=?,status=CASE
                    WHEN status IN ("closed","reopened","missing_sales","date_attention","failed") THEN status
                    WHEN ?>0 THEN "fiscal_incomplete"
                    ELSE "ready_to_close"
                 END,updated_at=UTC_TIMESTAMP()
             WHERE id=? AND company_id=? AND meli_account_id=?'
        )->execute([
            (int) $summary['ready'], (int) $summary['missing'], (int) $summary['credit_note'],
            (int) $summary['reconciliation'], (int) $summary['needs_attention'],
            $monthId, $companyId, $accountId,
        ]);
    }

    /** @return array{0:string,1:string} */
    private function classifyFiscalOrder(array $order): array
    {
        $status = mb_strtolower(trim((string) ($order['status'] ?? '')));
        if (in_array($status, ['cancelled', 'canceled'], true)) {
            return ['not_invoiceable', 'cancelled'];
        }
        if (str_contains($status, 'refund') || str_contains(mb_strtolower((string) ($order['status_detail'] ?? '')), 'refund')) {
            return ['credit_note_review', 'refund'];
        }
        if (!in_array($status, ['paid', 'confirmed'], true)) {
            return ['reconciliation', 'commercial_status'];
        }
        if (!empty($order['fiscal_snapshot_id']) && (string) $order['completeness_status'] === 'complete') {
            return ['ready', 'fiscal_snapshot_complete'];
        }
        return ['fiscal_data_required', 'billing_info_pending'];
    }

    private function auditAccess(int $companyId, int $accountId, int $userId, string $action, string $resourceType, int $resourceId): void
    {
        Database::connectionFresh()->prepare(
            'INSERT INTO sales_control_access_audit
             (company_id,meli_account_id,user_id,action,resource_type,resource_id)
             VALUES (?,?,?,?,?,?)'
        )->execute([$companyId, $accountId, $userId, $action, $resourceType, $resourceId]);
    }

    private function enqueueNextYearMonth(int $companyId, int $accountId, int $year): void
    {
        $yearStmt = Database::connectionFresh()->prepare(
            'SELECT status FROM sales_control_years
             WHERE company_id=? AND meli_account_id=? AND control_year=? LIMIT 1'
        );
        $yearStmt->execute([$companyId, $accountId, $year]);
        if ((string) $yearStmt->fetchColumn() !== 'checking') {
            return;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota'));
        $lastMonth = $year < (int) $now->format('Y')
            ? 12
            : ($year === (int) $now->format('Y') ? (int) $now->format('n') : 0);
        for ($month = 1; $month <= $lastMonth; $month++) {
            $primaryStmt = Database::connectionFresh()->prepare(
                'SELECT c.sync_sales_audit_run_id
                 FROM sales_control_captures c
                 JOIN sales_control_months m ON m.id=c.sales_control_month_id
                 WHERE m.company_id=? AND m.meli_account_id=? AND m.period_year=? AND m.period_month=?
                   AND c.capture_role="primary"
                 ORDER BY c.id DESC LIMIT 1'
            );
            $primaryStmt->execute([$companyId, $accountId, $year, $month]);
            if ((int) $primaryStmt->fetchColumn() > 0) {
                continue;
            }
            $this->ensureMonth($companyId, $accountId, $year, $month, null);
            (new SalesAuditRunService())->createExactMonth($accountId, $year, $month, null, $companyId);
            return;
        }
        for ($month = 1; $month <= $lastMonth; $month++) {
            if ($this->isCurrentPeriod($year, $month)
                || $this->captureCountForPeriod($companyId, $accountId, $year, $month) >= 2) {
                continue;
            }
            $primary = $this->primaryCaptureRunId($companyId, $accountId, $year, $month);
            if ($primary < 1) {
                continue;
            }
            $delay = max(5, min(1440, (new AppSettingsService())->int(
                'sales_control.verification_min_delay_minutes',
                15
            )));
            (new SalesAuditRunService())->createExactMonth(
                $accountId,
                $year,
                $month,
                null,
                $companyId,
                'verification',
                $primary,
                new \DateTimeImmutable('+' . $delay . ' minutes', new \DateTimeZone('UTC'))
            );
            return;
        }
        Database::connectionFresh()->prepare(
            'UPDATE sales_control_years
             SET status="ready",last_checked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
             WHERE company_id=? AND meli_account_id=? AND control_year=? AND status="checking"'
        )->execute([$companyId, $accountId, $year]);
    }

    private function primaryCaptureRunId(int $companyId, int $accountId, int $year, int $month): int
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT c.sync_sales_audit_run_id
             FROM sales_control_captures c
             JOIN sales_control_months m ON m.id=c.sales_control_month_id
             WHERE m.company_id=? AND m.meli_account_id=? AND m.period_year=? AND m.period_month=?
               AND c.capture_role="primary"
             ORDER BY c.id ASC LIMIT 1'
        );
        $stmt->execute([$companyId, $accountId, $year, $month]);
        return (int) $stmt->fetchColumn();
    }

    private function ensureAnnualRun(
        int $companyId,
        int $accountId,
        int $year,
        int $lastMonth,
        ?int $userId
    ): int {
        if (!(new SchemaInspectorService())->hasTable('sales_control_year_runs')) {
            return 0;
        }
        $pdo = Database::connectionFresh();
        $pdo->prepare(
            'INSERT INTO sales_control_year_runs
             (company_id,meli_account_id,control_year,status,last_requested_month,created_by)
             VALUES (?,?,?,"active",?,?)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),
                 status=IF(status="completed","active",status),
                 last_requested_month=GREATEST(last_requested_month,VALUES(last_requested_month)),
                 updated_at=UTC_TIMESTAMP(3)'
        )->execute([$companyId, $accountId, $year, $lastMonth, $userId]);
        return (int) $pdo->lastInsertId();
    }

    private function captureCountForPeriod(int $companyId, int $accountId, int $year, int $month): int
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT COUNT(*)
             FROM sales_control_captures c
             JOIN sales_control_months m ON m.id=c.sales_control_month_id
             WHERE m.company_id=? AND m.meli_account_id=? AND m.period_year=? AND m.period_month=?'
        );
        $stmt->execute([$companyId, $accountId, $year, $month]);
        return (int) $stmt->fetchColumn();
    }

    private function assertYear(int $year): void
    {
        $currentYear = (int) (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y');
        if ($year < 2020 || $year > $currentYear) {
            throw new \InvalidArgumentException('Seleccione un año válido hasta el año actual.');
        }
    }

    private function isCurrentPeriod(int $year, int $month): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota'));
        return $year === (int) $now->format('Y') && $month === (int) $now->format('n');
    }

    private function assertPeriod(int $year, int $month): void
    {
        $this->assertYear($year);
        $currentMonth = (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m');
        if ($month < 1 || $month > 12 || sprintf('%04d-%02d', $year, $month) > $currentMonth) {
            throw new \InvalidArgumentException('Seleccione un mes que ya haya comenzado.');
        }
    }

    private function assertClosedPeriod(int $year, int $month): void
    {
        $this->assertPeriod($year, $month);
        $currentMonth = (new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota')))->format('Y-m');
        if (sprintf('%04d-%02d', $year, $month) >= $currentMonth) {
            throw new \RuntimeException('El mes actual permanece provisional y no se puede cerrar todavía.');
        }
    }
}
