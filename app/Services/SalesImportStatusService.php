<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Read model comercial para importaciones anuales de ventas.
 *
 * No consulta Mercado Libre y no muta datos. Solo traduce auditorias, reparaciones
 * y colas existentes a un estado entendible para Ventas de Mercado Libre.
 */
final class SalesImportStatusService
{
    /** @var array<string,array<string,true>>|null */
    private ?array $schemaColumns = null;

    private const MONTH_NAMES = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr',
        5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
        9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
    ];

    /** @return array<string,mixed> */
    public function year(int $accountId, int $year, int $companyId = 0): array
    {
        $account = (new SalesAuditAccessGateway())->account($accountId, $companyId);
        $companyId = (int) $account['company_id'];
        $this->assertYear($year);

        if (!$this->available()) {
            return $this->unavailable($account, $year);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('America/Bogota'));
        $currentYear = (int) $now->format('Y');
        $lastMonth = $year < $currentYear ? 12 : ($year === $currentYear ? (int) $now->format('n') : 0);
        $remoteWindowMonths = max(1, min(24, (new AppSettingsService())->int('sales_control.remote_window_months', 12)));
        $remoteWindowFrom = $now->modify('-' . $remoteWindowMonths . ' months');
        $temporalCoverage = new SalesTemporalCoverageService();

        $yearRow = $this->yearRow($companyId, $accountId, $year);
        $annualRun = $this->annualRun($companyId, $accountId, $year);
        $controlMonths = $this->controlMonths($companyId, $accountId, $year);
        $runs = $this->latestRuns($companyId, $accountId, $year);
        $auditJobs = $this->auditJobs($companyId, $accountId, $year);
        $repairJobs = $this->repairJobs($companyId, $accountId, $year);
        $dateJobs = $this->dateRepairJobs($companyId, $accountId, $year);
        $localCounts = $this->localOrderCounts($companyId, $accountId, $year);

        $months = [];
        $counts = [
            'not_started' => 0,
            'queued' => 0,
            'running' => 0,
            'needs_repair' => 0,
            'repairing' => 0,
            'date_repair_needed' => 0,
            'date_repairing' => 0,
            'review_needed' => 0,
            'verified' => 0,
            'partial_history' => 0,
            'blocked' => 0,
            'future' => 0,
        ];

        for ($month = 1; $month <= 12; $month++) {
            $state = $this->monthState(
                $year,
                $month,
                $lastMonth,
                $remoteWindowFrom,
                $now,
                $remoteWindowMonths,
                $temporalCoverage,
                $yearRow,
                $controlMonths[$month] ?? null,
                $runs[$month] ?? null,
                $auditJobs[$month] ?? [],
                $repairJobs[$month] ?? [],
                $dateJobs[$month] ?? [],
                (int) ($localCounts[$month] ?? 0)
            );
            $months[] = $state;
            $counts[$state['state']] = ($counts[$state['state']] ?? 0) + 1;
        }

        $activeMonthCount = max(0, $lastMonth);
        $done = $counts['verified'];
        $working = $counts['queued'] + $counts['running'] + $counts['repairing'] + $counts['date_repairing'];
        $attention = $counts['needs_repair'] + $counts['date_repair_needed'] + $counts['review_needed'] + $counts['blocked'];
        $partial = $counts['partial_history'];
        $state = $this->yearState($yearRow, $annualRun, $counts, $activeMonthCount);
        $stages = $this->commercialStages($companyId, $accountId, $year, $done, $activeMonthCount);

        return [
            'account' => $account,
            'year' => $year,
            'last_month' => $lastMonth,
            'state' => $state['key'],
            'tone' => $state['tone'],
            'headline' => $state['headline'],
            'message' => $state['message'],
            'months' => $months,
            'counts' => $counts,
            'progress' => [
                'percent' => $activeMonthCount > 0 ? (int) floor(($done / $activeMonthCount) * 100) : 0,
                'verified' => $done,
                'working' => $working,
                'attention' => $attention,
                'partial_history' => $partial,
                'total' => $activeMonthCount,
            ],
            'stages' => $stages,
            'coverage' => $this->coverage($year, $lastMonth, $now, $remoteWindowMonths, $done, $activeMonthCount),
            'advanced_url' => '/sales-control?' . http_build_query([
                'account_id' => $accountId,
                'year' => $year,
            ]),
            'prepared' => $yearRow !== null || $annualRun !== null,
        ];
    }

    private function available(): bool
    {
        $required = [
            'sales_control_years' => ['id'],
            'sales_control_months' => ['id'],
            'sync_sales_audit_runs' => ['id'],
            'sync_sales_audit_jobs' => ['id'],
            'sync_sales_repair_jobs' => [
                'id',
                'company_id',
                'sync_sales_audit_run_id',
                'safe_error_message',
            ],
            'order_datetime_repair_jobs' => ['id'],
            'meli_orders' => ['id', 'date_created_local_date'],
        ];
        $columns = $this->schemaColumns();
        foreach ($required as $table => $requiredColumns) {
            foreach ($requiredColumns as $column) {
                if (!isset($columns[$table][$column])) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function unavailable(array $account, int $year): array
    {
        return [
            'account' => $account,
            'year' => $year,
            'last_month' => 0,
            'state' => 'blocked',
            'tone' => 'blocked',
            'headline' => 'Actualizacion pendiente',
            'message' => 'Complete las migraciones del ERP antes de importar ventas anuales.',
            'months' => [],
            'counts' => ['blocked' => 1],
            'progress' => ['percent' => 0, 'verified' => 0, 'working' => 0, 'attention' => 1, 'partial_history' => 0, 'total' => 0],
            'stages' => [],
            'coverage' => ['message' => 'No se pudo leer la ventana historica configurada.'],
            'advanced_url' => '/sales-control?' . http_build_query(['account_id' => (int) $account['id'], 'year' => $year]),
            'prepared' => false,
        ];
    }

    /** @return array<string,mixed>|null */
    private function yearRow(int $companyId, int $accountId, int $year): ?array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT * FROM sales_control_years
             WHERE company_id=? AND meli_account_id=? AND control_year=? LIMIT 1'
        );
        $stmt->execute([$companyId, $accountId, $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function annualRun(int $companyId, int $accountId, int $year): ?array
    {
        if (!$this->hasSchemaTable('sales_control_year_runs')) {
            return null;
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT * FROM sales_control_year_runs
             WHERE company_id=? AND meli_account_id=? AND control_year=? LIMIT 1'
        );
        $stmt->execute([$companyId, $accountId, $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    private function controlMonths(int $companyId, int $accountId, int $year): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT * FROM sales_control_months
             WHERE company_id=? AND meli_account_id=? AND period_year=?'
        );
        $stmt->execute([$companyId, $accountId, $year]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['period_month']] = $row;
        }
        return $rows;
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
             ) latest ON latest.id=r.id AND r.company_id=? AND r.meli_account_id=?'
        );
        $stmt->execute([$companyId, $accountId, $year, $companyId, $accountId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['period_month']] = $row;
        }
        return $rows;
    }

    /** @return array<int,list<array<string,mixed>>> */
    private function auditJobs(int $companyId, int $accountId, int $year): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT r.period_month,j.status,j.next_run_at,j.safe_error_message,j.diagnostic_id
             FROM sync_sales_audit_jobs j
             JOIN sync_sales_audit_runs r
               ON r.id=j.sync_sales_audit_run_id AND r.company_id=j.company_id
              AND r.meli_account_id=j.meli_account_id
             WHERE r.company_id=? AND r.meli_account_id=? AND r.period_year=?'
        );
        $stmt->execute([$companyId, $accountId, $year]);
        return $this->groupByMonth($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int,list<array<string,mixed>>> */
    private function repairJobs(int $companyId, int $accountId, int $year): array
    {
        $hasCompany = $this->hasSchemaColumn('sync_sales_repair_jobs', 'company_id');
        $whereCompany = $hasCompany ? 'j.company_id=? AND ' : 'r.company_id=? AND ';
        $stmt = Database::connectionFresh()->prepare(
            'SELECT j.period_month,j.status,j.error_message,j.safe_error_message,j.diagnostic_id
             FROM sync_sales_repair_jobs j
             LEFT JOIN sync_sales_audit_runs r ON r.id=j.sync_sales_audit_run_id
             WHERE ' . $whereCompany . 'j.meli_account_id=? AND j.period_year=?'
        );
        $stmt->execute([$companyId, $accountId, $year]);
        return $this->groupByMonth($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int,list<array<string,mixed>>> */
    private function dateRepairJobs(int $companyId, int $accountId, int $year): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT j.period_month,j.status,j.error_message
             FROM order_datetime_repair_jobs j
             INNER JOIN meli_accounts a ON a.company_id=? AND a.id=j.meli_account_id
             WHERE j.meli_account_id=? AND j.period_year=?'
        );
        $stmt->execute([$companyId, $accountId, $year]);
        return $this->groupByMonth($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int,int> */
    private function localOrderCounts(int $companyId, int $accountId, int $year): array
    {
        if (!$this->hasSchemaColumn('meli_orders', 'date_created_local_date')) {
            return [];
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT MONTH(date_created_local_date) month_number,COUNT(*) total
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id
             WHERE a.company_id=? AND o.meli_account_id=?
               AND date_created_local_date >= ? AND date_created_local_date < ?
             GROUP BY MONTH(date_created_local_date)'
        );
        $stmt->execute([$companyId, $accountId, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)]);
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['month_number']] = (int) $row['total'];
        }
        return $counts;
    }

    /** @param list<array<string,mixed>> $rows @return array<int,list<array<string,mixed>>> */
    private function groupByMonth(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['period_month']][] = $row;
        }
        return $grouped;
    }

    /** @return array<string,mixed> */
    private function monthState(
        int $year,
        int $month,
        int $lastMonth,
        DateTimeImmutable $remoteWindowFrom,
        DateTimeImmutable $now,
        int $remoteWindowMonths,
        SalesTemporalCoverageService $temporalCoverageService,
        ?array $yearRow,
        ?array $control,
        ?array $run,
        array $auditJobs,
        array $repairJobs,
        array $dateJobs,
        int $localTotal
    ): array {
        $temporalCoverage = $temporalCoverageService->month($year, $month, $lastMonth, $now, $remoteWindowMonths, $run);
        if ($month > $lastMonth) {
            return $this->month($month, 'future', 'Futuro', 'No se importa todavía.', 'muted', $localTotal, $temporalCoverage);
        }

        $monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), new DateTimeZone('America/Bogota'));
        if ($monthStart < $remoteWindowFrom && $run === null) {
            $label = $temporalCoverage['key'] === 'outside_remote_window' ? 'Historial no demostrable' : 'Historial parcial';
            return $this->month($month, 'partial_history', $label, $temporalCoverage['message'], 'partial', $localTotal, $temporalCoverage);
        }

        if ($this->hasStatus($auditJobs, ['error', 'paused', 'waiting_budget']) || $this->hasStatus($repairJobs, ['error'])) {
            return $this->month($month, 'blocked', 'Pausado', 'Revise permisos, presupuesto API o el diagnóstico avanzado.', 'blocked', $localTotal, $temporalCoverage);
        }
        if ($this->hasStatus($dateJobs, ['pending', 'running'])) {
            return $this->month($month, 'date_repairing', 'Recalculando fechas', 'El ERP corrige fechas desde la venta original.', 'working', $localTotal, $temporalCoverage);
        }
        if ($this->hasStatus($repairJobs, ['pending', 'running', 'waiting_budget'])) {
            return $this->month($month, 'repairing', 'Descargando faltantes', 'El ERP está trayendo ventas faltantes de forma segura.', 'working', $localTotal, $temporalCoverage);
        }
        if ($this->hasStatus($auditJobs, ['running'])) {
            return $this->month($month, 'running', 'Verificando ventas', 'La auditoría exacta está preparada o en ejecución por el lanzador único.', 'working', $localTotal, $temporalCoverage);
        }
        if ($this->hasStatus($auditJobs, ['pending'])) {
            return $this->month($month, 'queued', 'Preparado', 'El trabajo local espera al procesador autorizado; todavía no consulta Mercado Libre.', 'queued', $localTotal, $temporalCoverage);
        }

        if ($run !== null) {
            $missing = (int) ($run['missing_total'] ?? 0);
            $dateIssues = (int) ($run['missing_normalized_total'] ?? 0) + (int) ($run['shifted_total'] ?? 0);
            $review = (int) ($run['other_account_total'] ?? 0) + (int) ($run['extra_total'] ?? 0);
            $coverage = (string) ($run['remote_coverage'] ?? 'pending');
            $validation = (string) ($run['coverage_validation_state'] ?? 'pending');
            if ($missing > 0) {
                return $this->month($month, 'needs_repair', 'Faltan por descargar', $missing . ' ventas faltan en el ERP.', 'attention', $localTotal, $temporalCoverage);
            }
            if ($dateIssues > 0) {
                return $this->month($month, 'date_repair_needed', 'Fechas por corregir', $dateIssues . ' ventas requieren fecha local normalizada.', 'attention', $localTotal, $temporalCoverage);
            }
            if ($review > 0 || $coverage !== 'complete' || $validation === 'invalid') {
                return $this->month($month, 'review_needed', 'Requiere revisión', 'Hay evidencia avanzada pendiente de revisar.', 'attention', $localTotal, $temporalCoverage);
            }
            if ((string) ($run['status'] ?? '') === 'complete') {
                return $this->month($month, 'verified', $temporalCoverage['label'], $temporalCoverage['message'], 'verified', $localTotal, $temporalCoverage);
            }
        }

        if ($yearRow !== null || $control !== null) {
            return $this->month($month, 'queued', 'Preparado', 'La importación quedó preparada para el lanzador único.', 'queued', $localTotal, $temporalCoverage);
        }

        return $this->month($month, 'not_started', 'Sin preparar', 'Aún no se ha pedido importar este mes.', 'muted', $localTotal, $temporalCoverage);
    }

    /** @return array<string,mixed> */
    private function month(int $month, string $state, string $label, string $summary, string $tone, int $localTotal, array $temporalCoverage = []): array
    {
        return [
            'month' => $month,
            'name' => self::MONTH_NAMES[$month],
            'state' => $state,
            'label' => $label,
            'summary' => $summary,
            'tone' => $tone,
            'local_total' => $localTotal,
            'temporal_coverage' => $temporalCoverage,
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function hasStatus(array $rows, array $statuses): bool
    {
        foreach ($rows as $row) {
            if (in_array((string) ($row['status'] ?? ''), $statuses, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{key:string,tone:string,headline:string,message:string} */
    private function yearState(?array $yearRow, ?array $annualRun, array $counts, int $activeMonthCount): array
    {
        if (($counts['blocked'] ?? 0) > 0) {
            return ['key' => 'blocked', 'tone' => 'blocked', 'headline' => 'Importacion pausada', 'message' => 'Hay un bloqueo de API, permisos o presupuesto. La evidencia avanzada muestra el diagnostico.'];
        }
        if (($counts['review_needed'] ?? 0) > 0) {
            return ['key' => 'review_needed', 'tone' => 'attention', 'headline' => 'Revision requerida', 'message' => 'Algunas ventas necesitan decision manual antes de cerrar el periodo.'];
        }
        if (($counts['needs_repair'] ?? 0) > 0) {
            return ['key' => 'needs_repair', 'tone' => 'attention', 'headline' => 'Faltan ventas por descargar', 'message' => 'El lanzador único preparará la descarga segura de ventas faltantes cuando la auditoría sea confiable.'];
        }
        if (($counts['date_repair_needed'] ?? 0) > 0) {
            return ['key' => 'date_repair_needed', 'tone' => 'attention', 'headline' => 'Fechas por corregir', 'message' => 'El ERP recalculara fechas usando date_created de Mercado Libre guardado en la orden.'];
        }
        if (($counts['running'] ?? 0) + ($counts['repairing'] ?? 0) + ($counts['date_repairing'] ?? 0) > 0) {
            return ['key' => 'running', 'tone' => 'working', 'headline' => 'Importando y verificando', 'message' => 'La cola esta procesando el año por meses y paginas para no sobrecargar el servidor.'];
        }
        if ($activeMonthCount > 0 && ($counts['verified'] ?? 0) >= $activeMonthCount) {
            return ['key' => 'verified', 'tone' => 'verified', 'headline' => 'Ventas verificadas', 'message' => 'Los meses disponibles del año estan verificados para esta cuenta.'];
        }
        if (($counts['queued'] ?? 0) > 0 || $annualRun !== null || $yearRow !== null) {
            return ['key' => 'queued', 'tone' => 'queued', 'headline' => 'Importacion preparada', 'message' => 'La automatizacion traera y verificara ventas sin modificar Mercado Libre.'];
        }
        if (($counts['partial_history'] ?? 0) > 0) {
            return ['key' => 'partial_history', 'tone' => 'partial', 'headline' => 'Historial remoto aproximado', 'message' => 'Mercado Libre puede limitar los meses antiguos; el ERP conserva la evidencia local.'];
        }
        return ['key' => 'not_started', 'tone' => 'muted', 'headline' => 'Importar ventas del año', 'message' => 'Seleccione la cuenta y el año para preparar la importacion comercial.'];
    }

    /** @return array<string,string|bool> */
    private function coverage(int $year, int $lastMonth, DateTimeImmutable $now, int $remoteWindowMonths, int $checkedMonths, int $activeMonths): array
    {
        $coverage = (new SalesTemporalCoverageService())->yearSummary(
            $year,
            $lastMonth,
            $now,
            $remoteWindowMonths,
            $checkedMonths,
            $activeMonths
        );
        return [
            'from' => $coverage['available_from'],
            'to' => $coverage['available_to'],
            'partial' => $coverage['partial'],
            'message' => $coverage['message'],
            'requested_until_started' => $coverage['available'],
            'requested_from' => $coverage['requested_from'],
            'requested_to' => $coverage['requested_to'],
            'confidence' => $coverage['confidence'],
            'remote_window_months' => $coverage['remote_window_months'],
        ];
    }

    private function assertYear(int $year): void
    {
        $current = (int) (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y');
        if ($year < 2020 || $year > $current) {
            throw new \InvalidArgumentException('Seleccione un año entre 2020 y ' . $current . '.');
        }
    }

    /** @return list<array<string,int|string>> */
    private function commercialStages(int $companyId, int $accountId, int $year, int $verifiedMonths, int $activeMonths): array
    {
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-01-01', $year + 1);
        $pdo = Database::connectionFresh();
        $hasLocalDate = $this->hasSchemaColumn('meli_orders', 'date_created_local_date');
        $dateColumn = $hasLocalDate ? 'date_created_local_date' : 'DATE(date_created)';
        $aliasedDateColumn = $hasLocalDate ? 'o.date_created_local_date' : 'DATE(o.date_created)';
        $orders = $pdo->prepare(
            'SELECT COUNT(*) orders_total,
                    SUM(' . ($hasLocalDate ? 'date_created_local_date IS NOT NULL' : 'date_created IS NOT NULL') . ') dates_ready
             FROM meli_orders
             JOIN meli_accounts stage_account ON stage_account.id=meli_orders.meli_account_id
             WHERE stage_account.company_id=? AND meli_orders.meli_account_id=?
               AND ' . $dateColumn . '>=? AND ' . $dateColumn . '<?'
        );
        $orders->execute([$companyId, $accountId, $from, $to]);
        $orderRow = $orders->fetch(PDO::FETCH_ASSOC) ?: [];

        $sales = ['total' => 0, 'integrity_ready' => 0, 'financial_ready' => 0, 'financial_attention' => 0];
        if ($this->hasSchemaTable('meli_sale_financials')) {
            $salesStmt = $pdo->prepare(
                'SELECT COUNT(*) total,
                        SUM(grouped.external_pack_id IS NULL OR p.integrity_status="complete") integrity_ready,
                        SUM(COALESCE(sf.reconciliation_status,"not_started")="reconciled") financial_ready,
                        SUM(COALESCE(sf.reconciliation_status,"not_started") IN ("review","error")) financial_attention
                 FROM (
                    SELECT o.meli_account_id,
                           CONCAT(IF(o.external_pack_id IS NULL,"O:","P:"),
                                  COALESCE(o.external_pack_id,o.external_order_id)) sale_key,
                           o.external_pack_id
                    FROM meli_orders o
                    JOIN meli_accounts grouped_account ON grouped_account.id=o.meli_account_id
                    WHERE grouped_account.company_id=? AND o.meli_account_id=?
                      AND ' . $aliasedDateColumn . '>=? AND ' . $aliasedDateColumn . '<?
                    GROUP BY o.meli_account_id,sale_key,o.external_pack_id
                 ) grouped
                 LEFT JOIN meli_packs p
                   ON p.meli_account_id=grouped.meli_account_id
                  AND p.external_pack_id=grouped.external_pack_id
                 LEFT JOIN meli_sale_financials sf
                   ON sf.company_id=? AND sf.meli_account_id=grouped.meli_account_id
                  AND sf.sale_key=grouped.sale_key'
            );
            $salesStmt->execute([
                $companyId, $accountId, $from, $to,
                $companyId,
            ]);
            $sales = $salesStmt->fetch(PDO::FETCH_ASSOC) ?: $sales;
        }

        $orderTotal = (int) ($orderRow['orders_total'] ?? 0);
        $saleTotal = (int) ($sales['total'] ?? 0);
        return [
            ['key' => 'orders', 'label' => 'Órdenes', 'ready' => $orderTotal, 'total' => $orderTotal],
            ['key' => 'integrity', 'label' => 'Integridad', 'ready' => (int) ($sales['integrity_ready'] ?? 0), 'total' => $saleTotal],
            ['key' => 'dates', 'label' => 'Fechas', 'ready' => (int) ($orderRow['dates_ready'] ?? 0), 'total' => $orderTotal],
            ['key' => 'financial', 'label' => 'Finanzas oficiales', 'ready' => (int) ($sales['financial_ready'] ?? 0), 'total' => $saleTotal],
            ['key' => 'verification', 'label' => 'Meses verificados', 'ready' => $verifiedMonths, 'total' => $activeMonths],
        ];
    }

    /** @return array<string,array<string,true>> */
    private function schemaColumns(): array
    {
        if ($this->schemaColumns !== null) {
            return $this->schemaColumns;
        }
        $tables = [
            'sales_control_years',
            'sales_control_months',
            'sales_control_year_runs',
            'sync_sales_audit_runs',
            'sync_sales_audit_jobs',
            'sync_sales_repair_jobs',
            'order_datetime_repair_jobs',
            'meli_orders',
            'meli_sale_financials',
        ];
        return $this->schemaColumns =
            (new InformationSchemaGateway())->columnNamesForTables($tables);
    }

    private function hasSchemaTable(string $table): bool
    {
        return ($this->schemaColumns()[$table] ?? []) !== [];
    }

    private function hasSchemaColumn(string $table, string $column): bool
    {
        return isset($this->schemaColumns()[$table][$column]);
    }
}
