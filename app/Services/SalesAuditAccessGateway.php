<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use PDO;

/**
 * Único punto de autorización y pertenencia para auditorías de ventas.
 *
 * Toda lectura web se restringe a las empresas autorizadas del usuario.
 * Los jobs CLI siguen usando empresa y cuenta explícitas.
 */
final class SalesAuditAccessGateway
{
    /** @return list<array<string,mixed>> */
    public function accounts(int $companyId = 0): array
    {
        if ((int) (Auth::id() ?? 0) > 0) {
            $allowed = (new BusinessScopeContext())->accountIds(null, $companyId);
            if ($allowed === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($allowed), '?'));
            $stmt = Database::connectionFresh()->prepare(
                'SELECT a.id,a.company_id,a.account_name,a.meli_user_id,a.site_id,a.status,c.name company_name
                 FROM meli_accounts a
                 JOIN companies c ON c.id=a.company_id AND c.deleted_at IS NULL
                 WHERE a.id IN (' . $placeholders . ')
                 ORDER BY c.name,a.account_name,a.id'
            );
            $stmt->execute($allowed);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $sql = 'SELECT a.id,a.company_id,a.account_name,a.meli_user_id,a.site_id,a.status,c.name company_name
                FROM meli_accounts a
                JOIN companies c ON c.id=a.company_id
                WHERE c.deleted_at IS NULL';
        $params = [];
        if ($companyId > 0) {
            $sql .= ' AND a.company_id=?';
            $params[] = $companyId;
        }
        $sql .= ' ORDER BY c.name,a.account_name,a.id';
        $stmt = Database::connectionFresh()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    public function account(int $accountId, int $companyId = 0): array
    {
        if ($accountId <= 0) {
            throw new HttpException(404, 'La cuenta seleccionada no está disponible.');
        }
        if ((int) (Auth::id() ?? 0) > 0) {
            return (new BusinessScopeContext())->account($accountId, $companyId);
        }
        $sql = 'SELECT a.id,a.company_id,a.account_name,a.meli_user_id,a.status,c.name company_name
                FROM meli_accounts a
                JOIN companies c ON c.id=a.company_id
                WHERE a.id=? AND c.deleted_at IS NULL';
        $params = [$accountId];
        if ($companyId > 0) {
            $sql .= ' AND a.company_id=?';
            $params[] = $companyId;
        }
        $sql .= ' LIMIT 1';
        $stmt = Database::connectionFresh()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new HttpException(404, 'La cuenta seleccionada no pertenece a la empresa indicada.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public function run(int $runId, int $companyId = 0, int $accountId = 0): array
    {
        $sql = 'SELECT r.*,a.account_name,a.company_id account_company_id,c.name company_name
                FROM sync_sales_audit_runs r
                JOIN meli_accounts a ON a.id=r.meli_account_id
                JOIN companies c ON c.id=a.company_id
                WHERE r.id=? AND (r.company_id IS NULL OR r.company_id=a.company_id)';
        $params = [$runId];
        if ($companyId > 0) {
            $sql .= ' AND a.company_id=?';
            $params[] = $companyId;
        }
        if ($accountId > 0) {
            $sql .= ' AND r.meli_account_id=?';
            $params[] = $accountId;
        }
        $sql .= ' LIMIT 1';
        $stmt = Database::connectionFresh()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new HttpException(404, 'La comprobación solicitada no pertenece a la cuenta seleccionada.');
        }
        if ((int) (Auth::id() ?? 0) > 0) {
            (new BusinessScopeContext())->account((int) $row['meli_account_id'], (int) $row['account_company_id']);
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public function auditJob(int $jobId, int $companyId = 0): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT j.*,r.period_year,r.period_month,r.status run_status,r.remote_coverage,
                    r.remote_unique_total,r.checked_total,r.completed_at run_completed_at,
                    r.snapshot_hash,a.account_name,a.company_id account_company_id,c.name company_name
             FROM sync_sales_audit_jobs j
             JOIN sync_sales_audit_runs r ON r.id=j.sync_sales_audit_run_id
             JOIN meli_accounts a ON a.id=j.meli_account_id
             JOIN companies c ON c.id=a.company_id
             WHERE j.id=? AND (j.company_id IS NULL OR j.company_id=a.company_id)
               AND (?=0 OR a.company_id=?)
             LIMIT 1'
        );
        $stmt->execute([$jobId, $companyId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new HttpException(404, 'El trabajo de comprobación no está disponible.');
        }
        if ((int) (Auth::id() ?? 0) > 0) {
            (new BusinessScopeContext())->account((int) $row['meli_account_id'], (int) $row['account_company_id']);
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public function repairJob(int $jobId, int $companyId = 0): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT j.*,r.remote_coverage,r.reconciliation_status,r.completed_at audit_completed_at,
                    a.account_name,a.company_id account_company_id,c.name company_name
             FROM sync_sales_repair_jobs j
             JOIN sync_sales_audit_runs r ON r.id=j.sync_sales_audit_run_id
             JOIN meli_accounts a ON a.id=j.meli_account_id
             JOIN companies c ON c.id=a.company_id
             WHERE j.id=? AND j.source_kind="exact"
               AND (j.company_id IS NULL OR j.company_id=a.company_id)
               AND (?=0 OR a.company_id=?)
             LIMIT 1'
        );
        $stmt->execute([$jobId, $companyId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new HttpException(404, 'La reparación solicitada no está disponible.');
        }
        if ((int) (Auth::id() ?? 0) > 0) {
            (new BusinessScopeContext())->account((int) $row['meli_account_id'], (int) $row['account_company_id']);
        }
        return $row;
    }
}
