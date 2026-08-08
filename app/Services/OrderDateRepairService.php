<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use Throwable;

final class OrderDateRepairService
{
    public function createJob(
        int $accountId,
        int $year,
        int $month,
        ?int $userId = null,
        int $companyId = 0
    ): int {
        $scope = $this->accountScope($accountId, $companyId, $userId);
        $companyId = $scope['company_id'];
        $accountId = $scope['meli_account_id'];
        $range = (new MeliDateRangeService())->localMonth($year, $month);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO order_datetime_repair_jobs
                 (company_id,meli_account_id,period_year,period_month,status,created_by)
                 VALUES (:company,:account,:year,:month,"pending",:user)'
            )->execute([
                'company' => $companyId,
                'account' => $accountId,
                'year' => $year,
                'month' => $month,
                'user' => $userId,
            ]);
            $jobId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare(
                'SELECT o.id
                 FROM meli_orders o
                 JOIN meli_accounts a
                   ON a.id=o.meli_account_id AND a.company_id=:company
                 WHERE o.meli_account_id=:account
                   AND o.date_created>=:from AND o.date_created<:to
                 ORDER BY o.date_created ASC'
            );
            $stmt->execute([
                'company' => $companyId,
                'account' => $accountId,
                'from' => $range['utc_from']->format('Y-m-d H:i:s'),
                'to' => $range['utc_to']->format('Y-m-d H:i:s'),
            ]);
            $insert = $pdo->prepare(
                'INSERT IGNORE INTO order_datetime_repair_items
                 (order_datetime_repair_job_id,meli_order_id)
                 SELECT :job,o.id
                 FROM meli_orders o
                 JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=:company
                 WHERE o.id=:order_id AND o.meli_account_id=:account'
            );
            $count = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
                $insert->execute([
                    'job' => $jobId,
                    'order_id' => (int) $orderId,
                    'company' => $companyId,
                    'account' => $accountId,
                ]);
                $count += $insert->rowCount();
            }
            $pdo->prepare(
                'UPDATE order_datetime_repair_jobs
                 SET total_orders=:total
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
    public function processDue(int $limit = 100): array
    {
        $owner = 'date-repair-' . getmypid() . '-' . bin2hex(random_bytes(8));
        $job = $this->claimDue($owner);
        if ($job === null) {
            return ['processed' => 0, 'jobs' => 0];
        }
        $pdo = Database::connection();
        $jobId = (int) $job['id'];
        $companyId = (int) $job['company_id'];
        $accountId = (int) $job['meli_account_id'];
        $generation = (int) $job['lease_generation'];
        $items = $pdo->prepare(
            'SELECT i.id item_id,o.*
             FROM order_datetime_repair_items i
             JOIN order_datetime_repair_jobs j
               ON j.id=i.order_datetime_repair_job_id
              AND j.company_id=:company AND j.meli_account_id=:account
              AND j.owner_token=:owner AND j.lease_generation=:generation
             JOIN meli_orders o
               ON o.id=i.meli_order_id AND o.meli_account_id=j.meli_account_id
             JOIN meli_accounts a
               ON a.id=o.meli_account_id AND a.company_id=j.company_id
             WHERE i.order_datetime_repair_job_id=:job AND i.status="pending"
             ORDER BY i.id ASC LIMIT ' . max(1, min(500, $limit))
        );
        $items->execute([
            'company' => $companyId,
            'account' => $accountId,
            'owner' => $owner,
            'generation' => $generation,
            'job' => $jobId,
        ]);
        $normalizer = new MeliDateTimeNormalizer();
        $processed = 0;
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $order) {
            if (!$this->ownsLease($jobId, $companyId, $accountId, $owner, $generation)) {
                return ['processed' => $processed, 'errors' => 0, 'jobs' => 1, 'status' => 'lease_lost'];
            }
            try {
                $raw = (new RawPayloadReader())->decode($order, 'meli_orders');
                if (!is_array($raw)) {
                    throw new \RuntimeException('La evidencia original de la orden no está disponible.');
                }
                $before = [
                    'date_created' => $order['date_created'] ?? null,
                    'date_created_local' => $order['date_created_local'] ?? null,
                    'date_closed' => $order['date_closed'] ?? null,
                ];
                $created = $normalizer->normalize($raw['date_created'] ?? null, 'orders.date_created');
                $closed = $normalizer->normalize($raw['date_closed'] ?? null, 'orders.date_closed');
                $updated = $normalizer->normalize($raw['last_updated'] ?? null, 'orders.last_updated');
                $orderUpdate = $pdo->prepare(
                    'UPDATE meli_orders o
                     JOIN meli_accounts a
                       ON a.id=o.meli_account_id AND a.company_id=:company
                     SET o.date_created=:created_utc,o.date_created_raw=:created_raw,
                         o.date_created_utc=:created_utc,o.date_created_local=:created_local,
                         o.date_created_local_date=:created_local_date,o.date_created_offset=:created_offset,
                         o.date_closed=:closed_utc,o.date_closed_raw=:closed_raw,
                         o.date_closed_utc=:closed_utc,o.date_closed_local=:closed_local,
                         o.date_closed_local_date=:closed_local_date,o.date_closed_offset=:closed_offset,
                         o.last_updated_raw=:updated_raw,o.last_updated_utc=:updated_utc,
                         o.last_updated_local=:updated_local,o.last_updated_local_date=:updated_local_date,
                         o.last_updated_offset=:updated_offset
                     WHERE o.id=:id AND o.meli_account_id=:account
                       AND EXISTS (
                         SELECT 1 FROM order_datetime_repair_jobs j
                         WHERE j.id=:job AND j.company_id=:company_scope
                           AND j.meli_account_id=:account_scope AND j.owner_token=:owner
                           AND j.lease_generation=:generation AND j.status="running"
                           AND j.lease_until>=UTC_TIMESTAMP()
                       )'
                );
                $orderUpdate->execute([
                    'id' => (int) $order['id'],
                    'company' => $companyId,
                    'account' => $accountId,
                    'job' => $jobId,
                    'company_scope' => $companyId,
                    'account_scope' => $accountId,
                    'owner' => $owner,
                    'generation' => $generation,
                    'created_utc' => $created['utc'],
                    'created_raw' => $created['raw_value'],
                    'created_local' => $created['local'],
                    'created_local_date' => $created['local_date'],
                    'created_offset' => $created['source_offset'],
                    'closed_utc' => $closed['utc'],
                    'closed_raw' => $closed['raw_value'],
                    'closed_local' => $closed['local'],
                    'closed_local_date' => $closed['local_date'],
                    'closed_offset' => $closed['source_offset'],
                    'updated_utc' => $updated['utc'],
                    'updated_raw' => $updated['raw_value'],
                    'updated_local' => $updated['local'],
                    'updated_local_date' => $updated['local_date'],
                    'updated_offset' => $updated['source_offset'],
                ]);
                $after = [
                    'date_created' => $created['utc'],
                    'date_created_local' => $created['local'],
                    'date_created_local_date' => $created['local_date'],
                ];
                $itemUpdate = $pdo->prepare(
                    'UPDATE order_datetime_repair_items i
                     JOIN order_datetime_repair_jobs j
                       ON j.id=i.order_datetime_repair_job_id
                      AND j.company_id=:company AND j.meli_account_id=:account
                      AND j.owner_token=:owner AND j.lease_generation=:generation
                     SET i.status="fixed",i.old_snapshot_json=:old,i.new_snapshot_json=:new,
                         i.processed_at=UTC_TIMESTAMP()
                     WHERE i.id=:id AND i.order_datetime_repair_job_id=:job'
                );
                $itemUpdate->execute([
                    'old' => json_encode($before, JSON_UNESCAPED_UNICODE),
                    'new' => json_encode($after, JSON_UNESCAPED_UNICODE),
                    'id' => (int) $order['item_id'],
                    'job' => $jobId,
                    'company' => $companyId,
                    'account' => $accountId,
                    'owner' => $owner,
                    'generation' => $generation,
                ]);
                if ($itemUpdate->rowCount() === 1) {
                    $processed++;
                }
            } catch (Throwable $error) {
                $safe = SafeErrorPresenter::report(
                    $error,
                    'No fue posible recalcular la fecha de esta orden.',
                    ['module' => 'order_date_repair', 'job_id' => $jobId, 'item_id' => (int) $order['item_id']]
                );
                $pdo->prepare(
                    'UPDATE order_datetime_repair_items i
                     JOIN order_datetime_repair_jobs j
                       ON j.id=i.order_datetime_repair_job_id
                      AND j.company_id=:company AND j.meli_account_id=:account
                      AND j.owner_token=:owner AND j.lease_generation=:generation
                     SET i.status="error",i.error_message=:error,i.processed_at=UTC_TIMESTAMP()
                     WHERE i.id=:id AND i.order_datetime_repair_job_id=:job'
                )->execute([
                    'error' => mb_substr($safe['message'], 0, 500),
                    'id' => (int) $order['item_id'],
                    'job' => $jobId,
                    'company' => $companyId,
                    'account' => $accountId,
                    'owner' => $owner,
                    'generation' => $generation,
                ]);
            }
        }

        if (!$this->ownsLease($jobId, $companyId, $accountId, $owner, $generation)) {
            return ['processed' => $processed, 'errors' => 0, 'jobs' => 1, 'status' => 'lease_lost'];
        }
        $counts = $pdo->prepare(
            'SELECT SUM(i.status="pending") pending_count,SUM(i.status="error") error_count
             FROM order_datetime_repair_items i
             JOIN order_datetime_repair_jobs j
               ON j.id=i.order_datetime_repair_job_id
              AND j.company_id=:company AND j.meli_account_id=:account
             WHERE i.order_datetime_repair_job_id=:job'
        );
        $counts->execute(['job' => $jobId, 'company' => $companyId, 'account' => $accountId]);
        $itemCounts = $counts->fetch(PDO::FETCH_ASSOC) ?: [];
        $pendingCount = (int) ($itemCounts['pending_count'] ?? 0);
        $errorCount = (int) ($itemCounts['error_count'] ?? 0);
        $jobStatus = $pendingCount > 0 ? 'running' : ($errorCount > 0 ? 'error' : 'complete');
        $finish = $pdo->prepare(
            'UPDATE order_datetime_repair_jobs
             SET processed_orders=processed_orders+:processed,status=:status,
                 completed_at=IF(:terminal=1,UTC_TIMESTAMP(),NULL),
                 error_message=:error_message,owner_token=NULL,lease_until=NULL
             WHERE id=:id AND company_id=:company AND meli_account_id=:account
               AND owner_token=:owner AND lease_generation=:generation'
        );
        $finish->execute([
            'processed' => $processed,
            'status' => $jobStatus,
            'terminal' => $pendingCount === 0 ? 1 : 0,
            'error_message' => $errorCount > 0
                ? 'Una o más órdenes requieren revisión antes de cerrar el recálculo de fechas.'
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
            $this->reaudit($companyId, $accountId, (int) $job['period_year'], (int) $job['period_month']);
        }
        return ['processed' => $processed, 'errors' => $errorCount, 'jobs' => 1, 'status' => $jobStatus];
    }

    /** @return array<string,mixed>|null */
    private function claimDue(string $owner): ?array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT j.*
                 FROM order_datetime_repair_jobs j
                 JOIN meli_accounts a
                   ON a.id=j.meli_account_id AND a.company_id=j.company_id
                 WHERE j.company_id IS NOT NULL AND j.meli_account_id IS NOT NULL
                   AND j.status IN ("pending","running")
                   AND (j.lease_until IS NULL OR j.lease_until<UTC_TIMESTAMP())
                 ORDER BY j.id ASC LIMIT 1 FOR UPDATE'
            );
            $stmt->execute();
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                $pdo->commit();
                return null;
            }
            $companyId = (int) $job['company_id'];
            $accountId = (int) $job['meli_account_id'];
            $claim = $pdo->prepare(
                'UPDATE order_datetime_repair_jobs
                 SET status="running",started_at=COALESCE(started_at,UTC_TIMESTAMP()),
                     owner_token=:owner,lease_generation=lease_generation+1,
                     lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 180 SECOND)
                 WHERE id=:id AND company_id=:company AND meli_account_id=:account
                   AND (lease_until IS NULL OR lease_until<UTC_TIMESTAMP())'
            );
            $claim->execute([
                'owner' => $owner,
                'id' => (int) $job['id'],
                'company' => $companyId,
                'account' => $accountId,
            ]);
            if ($claim->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }
            $pdo->commit();
            $job['owner_token'] = $owner;
            $job['lease_generation'] = ((int) ($job['lease_generation'] ?? 0)) + 1;
            return $job;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function ownsLease(int $jobId, int $companyId, int $accountId, string $owner, int $generation): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM order_datetime_repair_jobs
             WHERE id=:id AND company_id=:company AND meli_account_id=:account
               AND owner_token=:owner AND lease_generation=:generation
               AND status="running" AND lease_until>=UTC_TIMESTAMP()'
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

    /** @return array{company_id:int,meli_account_id:int} */
    private function accountScope(int $accountId, int $companyId = 0, ?int $userId = null): array
    {
        if ($accountId <= 0) {
            throw new \RuntimeException('La reparación requiere una cuenta exacta.');
        }
        if (($userId ?? Auth::id()) !== null && (int) ($userId ?? Auth::id()) > 0) {
            $account = (new BusinessScopeContext())->account($accountId, $companyId, (int) ($userId ?? Auth::id()));
            return ['company_id' => (int) $account['company_id'], 'meli_account_id' => (int) $account['id']];
        }
        $stmt = Database::connection()->prepare(
            'SELECT id,company_id FROM meli_accounts
             WHERE id=:account AND (:company=0 OR company_id=:company_exact) LIMIT 1'
        );
        $stmt->execute(['account' => $accountId, 'company' => $companyId, 'company_exact' => $companyId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account) || (int) $account['company_id'] <= 0) {
            throw new \RuntimeException('La cuenta no pertenece al alcance solicitado.');
        }
        return ['company_id' => (int) $account['company_id'], 'meli_account_id' => (int) $account['id']];
    }

    private function reaudit(int $companyId, int $accountId, int $year, int $month): void
    {
        try {
            (new SalesAuditRunService())->createExactMonth($accountId, $year, $month, null, $companyId);
        } catch (Throwable $error) {
            Logger::write('warning', 'No se pudo encolar la comprobación posterior al recálculo de fechas.', [
                'company_id' => $companyId,
                'account_id' => $accountId,
                'year' => $year,
                'month' => $month,
                'error_class' => $error::class,
            ]);
        }
    }
}
