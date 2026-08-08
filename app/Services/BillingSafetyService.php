<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use RuntimeException;

final class BillingSafetyService
{
    /**
     * @return array{status:string,audit_status:string,coverage_status:string,message:string,audit_id:?int,snapshot:array<string,mixed>}
     */
    public function evaluate(int $accountId, string $from, string $to): array
    {
        $coverage = (new SyncCoverageService())->summary($accountId, $from, $to);
        $audit = $this->latestAuditForRange($accountId, $from, $to);
        $auditStatus = $audit['status'] ?? 'missing';
        $unsafeAudit = in_array($auditStatus, ['incomplete', 'error', 'blocked', 'missing'], true)
            || !empty($audit['timezone_suspect']);
        $status = $coverage['status'] === 'complete' && !$unsafeAudit ? 'complete' : 'incomplete';
        return [
            'status' => $status,
            'audit_status' => $auditStatus,
            'coverage_status' => (string) $coverage['status'],
            'message' => $status === 'complete'
                ? 'Cobertura y auditoría suficientes para facturación definitiva.'
                : 'Este periodo no tiene cobertura/auditoría completa. Puede crear borrador, pero no aprobar ni facturar sin revisión admin.',
            'audit_id' => isset($audit['id']) ? (int) $audit['id'] : null,
            'snapshot' => ['coverage' => $coverage, 'audit' => $audit],
        ];
    }

    public function assertCanCreateFinal(int $accountId, string $from, string $to, bool $allowOverride, ?string $reason = null): array
    {
        $evaluation = $this->evaluate($accountId, $from, $to);
        if ($evaluation['status'] === 'complete') {
            return $evaluation;
        }
        if ($allowOverride && Auth::role() === 'admin' && trim((string) $reason) !== '') {
            return $evaluation;
        }
        throw new RuntimeException($evaluation['message']);
    }

    public function assertCanTransition(array $run, string $target, bool $allowOverride = false, ?string $reason = null): void
    {
        if (!in_array($target, ['aprobado', 'facturado'], true)) {
            return;
        }
        $status = (string) ($run['coverage_status'] ?? 'unknown');
        $audit = (string) ($run['audit_status'] ?? 'missing');
        if ($status === 'complete' && $audit === 'complete') {
            return;
        }
        if ((int) ($run['is_admin_override'] ?? 0) === 1 && trim((string) ($run['override_reason'] ?? '')) !== '') {
            return;
        }
        if ($allowOverride && Auth::role() === 'admin' && trim((string) $reason) !== '') {
            return;
        }
        throw new RuntimeException('No se puede aprobar/facturar porque la cobertura o auditoría del periodo no está completa.');
    }

    private function latestAuditForRange(int $accountId, string $from, string $to): array
    {
        $fromMonth = (int) substr($from, 5, 2);
        $fromYear = (int) substr($from, 0, 4);
        $toMonth = (int) substr($to, 5, 2);
        $toYear = (int) substr($to, 0, 4);
        if ($fromYear !== $toYear || $fromMonth !== $toMonth) {
            return ['status' => 'missing', 'message' => 'Rango cruza meses; requiere auditoría completa por cada mes.'];
        }
        try {
            $stmt = Database::connection()->prepare(
                'SELECT * FROM sync_sales_audits WHERE meli_account_id=:account AND period_year=:year AND period_month=:month LIMIT 1'
            );
            $stmt->execute(['account' => $accountId, 'year' => $fromYear, 'month' => $fromMonth]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['status' => 'missing'];
        } catch (\Throwable) {
            return ['status' => 'missing'];
        }
    }
}
