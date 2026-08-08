<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use Throwable;

final class SalesRepairService
{
    public function createFromAudit(
        int $auditId,
        ?int $userId = null,
        int $companyId = 0,
        int $accountId = 0
    ): int {
        $pdo = Database::connection();
        $audit = $pdo->prepare(
            'SELECT s.*,a.company_id account_company_id
             FROM sync_sales_audits s
             JOIN meli_accounts a ON a.id=s.meli_account_id
             WHERE s.id=:id
               AND (:account=0 OR s.meli_account_id=:account_exact)
               AND (:company=0 OR a.company_id=:company_exact)
             LIMIT 1'
        );
        $audit->execute([
            'id' => $auditId,
            'account' => $accountId,
            'account_exact' => $accountId,
            'company' => $companyId,
            'company_exact' => $companyId,
        ]);
        $row = $audit->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Auditoría no encontrada.');
        }
        $companyId = (int) $row['account_company_id'];
        $accountId = (int) $row['meli_account_id'];
        $effectiveUserId = (int) ($userId ?? Auth::id() ?? 0);
        if ($effectiveUserId > 0) {
            (new BusinessScopeContext())->account($accountId, $companyId, $effectiveUserId);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO sync_sales_repair_jobs
                 (sync_sales_audit_id,company_id,meli_account_id,period_year,period_month,status,created_by)
                 VALUES (:audit,:company,:account,:year,:month,"pending",:user)'
            )->execute([
                'audit' => $auditId,
                'company' => $companyId,
                'account' => $accountId,
                'year' => (int) $row['period_year'],
                'month' => (int) $row['period_month'],
                'user' => $userId,
            ]);
            $jobId = (int) $pdo->lastInsertId();
            $hasRemoteIds = (new SchemaInspectorService())->hasTable('sync_sales_audit_remote_ids');
            $missingSql = 'SELECT m.external_order_id,m.sync_sales_audit_day_id
                 FROM sync_sales_audit_missing_orders m
                 JOIN sync_sales_audit_days d ON d.id=m.sync_sales_audit_day_id
                 JOIN sync_sales_audits s ON s.id=d.sync_sales_audit_id
                 JOIN meli_accounts a
                   ON a.id=s.meli_account_id AND a.company_id=:company';
            if ($hasRemoteIds) {
                $missingSql .= ' LEFT JOIN sync_sales_audit_remote_ids r
                    ON r.sync_sales_audit_day_id=m.sync_sales_audit_day_id
                   AND r.external_order_id=m.external_order_id';
            }
            $missingSql .= ' WHERE s.id=:audit AND s.meli_account_id=:account AND m.status="missing"';
            if ($hasRemoteIds) {
                $missingSql .= ' AND (r.id IS NULL OR r.classification="missing_remote")';
            }
            $missing = $pdo->prepare($missingSql);
            $missing->execute(['audit' => $auditId, 'company' => $companyId, 'account' => $accountId]);
            $insert = $pdo->prepare(
                'INSERT IGNORE INTO sync_sales_repair_job_items
                 (sync_sales_repair_job_id,external_order_id,audit_day_id,action,status)
                 SELECT :job,:external,d.id,"fetch_missing","pending"
                 FROM sync_sales_audit_days d
                 JOIN sync_sales_audits s ON s.id=d.sync_sales_audit_id
                 JOIN meli_accounts a
                   ON a.id=s.meli_account_id AND a.company_id=:company
                 WHERE d.id=:day AND s.id=:audit AND s.meli_account_id=:account'
            );
            $count = 0;
            foreach ($missing->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $insert->execute([
                    'job' => $jobId,
                    'external' => (string) $item['external_order_id'],
                    'day' => (int) $item['sync_sales_audit_day_id'],
                    'audit' => $auditId,
                    'company' => $companyId,
                    'account' => $accountId,
                ]);
                $count += $insert->rowCount();
            }
            $pdo->prepare(
                'UPDATE sync_sales_repair_jobs
                 SET total_items=:total
                 WHERE id=:id AND company_id=:company AND meli_account_id=:account'
            )->execute([
                'total' => $count,
                'id' => $jobId,
                'company' => $companyId,
                'account' => $accountId,
            ]);
            $pdo->commit();
            return $jobId;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function processDue(int $limit = 25): array
    {
        $exact = new SalesAuditExactRepairService();
        if ($exact->available()) {
            $exactJobId = $this->nextScopedExactJobId();
            if ($exactJobId > 0) {
                $exactResult = $exact->processExact($exactJobId, $limit);
                if ((int) ($exactResult['jobs'] ?? 0) > 0) {
                    return $exactResult;
                }
            }
        }

        $owner = 'legacy-sales-repair-' . getmypid() . '-' . bin2hex(random_bytes(8));
        $job = $this->claimLegacy($owner);
        if ($job === null) {
            return ['processed' => 0, 'jobs' => 0];
        }
        $pdo = Database::connection();
        $jobId = (int) $job['id'];
        $companyId = (int) $job['company_id'];
        $accountId = (int) $job['meli_account_id'];
        $generation = (int) $job['lease_generation'];
        $items = $pdo->prepare(
            'SELECT i.*
             FROM sync_sales_repair_job_items i
             JOIN sync_sales_repair_jobs j
               ON j.id=i.sync_sales_repair_job_id
              AND j.company_id=:company AND j.meli_account_id=:account
              AND j.lock_owner=:owner AND j.lease_generation=:generation
             WHERE i.sync_sales_repair_job_id=:job AND i.status="pending"
             ORDER BY i.id ASC LIMIT ' . max(1, min(100, $limit))
        );
        $items->execute([
            'job' => $jobId,
            'company' => $companyId,
            'account' => $accountId,
            'owner' => $owner,
            'generation' => $generation,
        ]);
        $processed = 0;
        $repairedOrderIds = [];
        $sync = new OrderSyncService($accountId);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if (!$this->ownsLegacyLease($jobId, $companyId, $accountId, $owner, $generation)) {
                return ['processed' => $processed, 'jobs' => 1, 'status' => 'lease_lost'];
            }
            try {
                $sync->syncOrderById((string) $item['external_order_id']);
                if (!$this->ownsLegacyLease($jobId, $companyId, $accountId, $owner, $generation)) {
                    return ['processed' => $processed, 'jobs' => 1, 'status' => 'lease_lost'];
                }
                $orderId = $this->localOrderId($companyId, $accountId, (string) $item['external_order_id']);
                if ($orderId > 0) {
                    $repairedOrderIds[] = $orderId;
                }
                if ($this->transitionItem(
                    $jobId,
                    (int) $item['id'],
                    $companyId,
                    $accountId,
                    $owner,
                    $generation,
                    'complete',
                    null
                )) {
                    $processed++;
                }
            } catch (Throwable $error) {
                $safe = SafeErrorPresenter::report($error, 'No fue posible reparar esta orden heredada.', [
                    'module' => 'sales_repair_legacy',
                    'job_id' => $jobId,
                    'item_id' => (int) $item['id'],
                ]);
                $this->transitionItem(
                    $jobId,
                    (int) $item['id'],
                    $companyId,
                    $accountId,
                    $owner,
                    $generation,
                    'error',
                    mb_substr($safe['message'], 0, 500)
                );
            }
        }
        if ($repairedOrderIds !== [] && $this->ownsLegacyLease($jobId, $companyId, $accountId, $owner, $generation)) {
            try {
                (new OrderFinancialRecalcJobService())->createForOrderIds(
                    array_values(array_unique($repairedOrderIds)),
                    'repaired',
                    'sales_repair',
                    $jobId,
                    null
                );
            } catch (Throwable $error) {
                SafeErrorPresenter::report($error, 'No se pudo encolar el recálculo financiero de órdenes reparadas.', [
                    'module' => 'sales_repair_legacy',
                    'job_id' => $jobId,
                ]);
            }
        }
        if (!$this->ownsLegacyLease($jobId, $companyId, $accountId, $owner, $generation)) {
            return ['processed' => $processed, 'jobs' => 1, 'status' => 'lease_lost'];
        }
        $counts = $pdo->prepare(
            'SELECT SUM(i.status="pending") pending_count,SUM(i.status="error") error_count
             FROM sync_sales_repair_job_items i
             JOIN sync_sales_repair_jobs j
               ON j.id=i.sync_sales_repair_job_id
              AND j.company_id=:company AND j.meli_account_id=:account
             WHERE i.sync_sales_repair_job_id=:job'
        );
        $counts->execute(['job' => $jobId, 'company' => $companyId, 'account' => $accountId]);
        $itemCounts = $counts->fetch(PDO::FETCH_ASSOC) ?: [];
        $pendingCount = (int) ($itemCounts['pending_count'] ?? 0);
        $errorCount = (int) ($itemCounts['error_count'] ?? 0);
        $jobStatus = $pendingCount > 0 ? 'pending' : ($errorCount > 0 ? 'error' : 'complete');
        $finish = $pdo->prepare(
            'UPDATE sync_sales_repair_jobs
             SET processed_items=processed_items+:processed,status=:status,
                 completed_at=IF(:terminal=1,UTC_TIMESTAMP(),completed_at),
                 error_message=:error_message,lock_owner=NULL,lock_expires_at=NULL,heartbeat_at=NULL
             WHERE id=:id AND company_id=:company AND meli_account_id=:account
               AND source_kind="legacy" AND lock_owner=:owner AND lease_generation=:generation'
        );
        $finish->execute([
            'processed' => $processed,
            'status' => $jobStatus,
            'terminal' => $pendingCount === 0 ? 1 : 0,
            'error_message' => $errorCount > 0
                ? 'Una o más órdenes requieren revisión antes de cerrar la reparación.'
                : null,
            'id' => $jobId,
            'company' => $companyId,
            'account' => $accountId,
            'owner' => $owner,
            'generation' => $generation,
        ]);
        if ($finish->rowCount() !== 1) {
            return ['processed' => $processed, 'errors' => $errorCount, 'jobs' => 1, 'status' => 'lease_lost'];
        }
        if ($pendingCount === 0 && $errorCount === 0) {
            try {
                (new SalesAuditRunService())->createExactMonth(
                    $accountId,
                    (int) $job['period_year'],
                    (int) $job['period_month'],
                    null,
                    $companyId
                );
                $pdo->prepare(
                    'UPDATE sync_sales_audits s
                     JOIN meli_accounts a
                       ON a.id=s.meli_account_id AND a.company_id=:company
                     SET s.last_repair_at=UTC_TIMESTAMP()
                     WHERE s.id=:id AND s.meli_account_id=:account'
                )->execute([
                    'id' => (int) $job['sync_sales_audit_id'],
                    'company' => $companyId,
                    'account' => $accountId,
                ]);
            } catch (Throwable $error) {
                SafeErrorPresenter::report($error, 'No se pudo encolar la comprobación posterior a la reparación.', [
                    'module' => 'sales_repair',
                    'job_id' => $jobId,
                ]);
            }
        }
        return ['processed' => $processed, 'errors' => $errorCount, 'jobs' => 1, 'status' => $jobStatus];
    }

    /** @return array<string,mixed>|null */
    private function claimLegacy(string $owner): ?array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT j.*
                 FROM sync_sales_repair_jobs j
                 JOIN meli_accounts a
                   ON a.id=j.meli_account_id AND a.company_id=j.company_id
                 WHERE j.source_kind="legacy" AND j.company_id IS NOT NULL
                   AND j.meli_account_id IS NOT NULL AND j.status IN ("pending","running")
                   AND (j.lock_expires_at IS NULL OR j.lock_expires_at<UTC_TIMESTAMP())
                 ORDER BY j.id ASC LIMIT 1 FOR UPDATE'
            );
            $stmt->execute();
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                $pdo->commit();
                return null;
            }
            $claim = $pdo->prepare(
                'UPDATE sync_sales_repair_jobs
                 SET status="running",started_at=COALESCE(started_at,UTC_TIMESTAMP()),
                     lock_owner=:owner,lock_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 180 SECOND),
                     heartbeat_at=UTC_TIMESTAMP(),lease_generation=lease_generation+1
                 WHERE id=:id AND company_id=:company AND meli_account_id=:account
                   AND source_kind="legacy"
                   AND (lock_expires_at IS NULL OR lock_expires_at<UTC_TIMESTAMP())'
            );
            $claim->execute([
                'owner' => $owner,
                'id' => (int) $job['id'],
                'company' => (int) $job['company_id'],
                'account' => (int) $job['meli_account_id'],
            ]);
            if ($claim->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }
            $pdo->commit();
            $job['lock_owner'] = $owner;
            $job['lease_generation'] = ((int) ($job['lease_generation'] ?? 0)) + 1;
            return $job;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function nextScopedExactJobId(): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT j.id
             FROM sync_sales_repair_jobs j
             JOIN meli_accounts a
               ON a.id=j.meli_account_id AND a.company_id=j.company_id
             WHERE j.source_kind="exact" AND j.company_id IS NOT NULL
               AND j.meli_account_id IS NOT NULL
               AND j.status IN ("pending","retry","waiting_budget")
               AND (j.next_run_at IS NULL OR j.next_run_at<=UTC_TIMESTAMP())
               AND (j.lock_expires_at IS NULL OR j.lock_expires_at<UTC_TIMESTAMP())
             ORDER BY j.created_at ASC,j.id ASC LIMIT 1'
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /** @phpstan-impure Lease ownership depends on database time and concurrent workers. */
    private function ownsLegacyLease(
        int $jobId,
        int $companyId,
        int $accountId,
        string $owner,
        int $generation
    ): bool {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM sync_sales_repair_jobs
             WHERE id=:id AND company_id=:company AND meli_account_id=:account
               AND source_kind="legacy" AND lock_owner=:owner AND lease_generation=:generation
               AND status="running" AND lock_expires_at>=UTC_TIMESTAMP()'
        );
        $stmt->execute([
            'id' => $jobId,
            'company' => $companyId,
            'account' => $accountId,
            'owner' => $owner,
            'generation' => $generation,
        ]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private function transitionItem(
        int $jobId,
        int $itemId,
        int $companyId,
        int $accountId,
        string $owner,
        int $generation,
        string $status,
        ?string $error
    ): bool {
        $stmt = Database::connection()->prepare(
            'UPDATE sync_sales_repair_job_items i
             JOIN sync_sales_repair_jobs j
               ON j.id=i.sync_sales_repair_job_id
              AND j.company_id=:company AND j.meli_account_id=:account
              AND j.source_kind="legacy" AND j.lock_owner=:owner
              AND j.lease_generation=:generation AND j.status="running"
             SET i.status=:status,i.error_message=:error,i.processed_at=UTC_TIMESTAMP()
             WHERE i.id=:id AND i.sync_sales_repair_job_id=:job'
        );
        $stmt->execute([
            'company' => $companyId,
            'account' => $accountId,
            'owner' => $owner,
            'generation' => $generation,
            'status' => $status,
            'error' => $error,
            'id' => $itemId,
            'job' => $jobId,
        ]);
        return $stmt->rowCount() === 1;
    }

    private function localOrderId(int $companyId, int $accountId, string $externalOrderId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT o.id
             FROM meli_orders o
             JOIN meli_accounts a
               ON a.id=o.meli_account_id AND a.company_id=:company
             WHERE o.meli_account_id=:account AND o.external_order_id=:external LIMIT 1'
        );
        $stmt->execute(['company' => $companyId, 'account' => $accountId, 'external' => $externalOrderId]);
        return (int) $stmt->fetchColumn();
    }
}
