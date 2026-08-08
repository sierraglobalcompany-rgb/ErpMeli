<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Escáner incremental local. Lee como máximo 50 ventas y crea como máximo 20
 * trabajos exactos; nunca consulta Mercado Libre directamente.
 */
final class FinancialGapScanService
{
    /** @return array{read:int,enqueued:int,local_jobs:int,billing_jobs:int,next_cursor:?string} */
    public function scanAccount(int $companyId, int $accountId, int $readLimit = 50, int $enqueueLimit = 20): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new \LogicException('El diagnóstico financiero automático solo puede ejecutarse por CLI.');
        }
        $readLimit = max(1, min(50, $readLimit));
        $enqueueLimit = max(1, min(20, $enqueueLimit));
        $pdo = Database::connectionFresh();
        $scope = $pdo->prepare('SELECT 1 FROM meli_accounts WHERE id=? AND company_id=? LIMIT 1');
        $scope->execute([$accountId, $companyId]);
        if ($scope->fetchColumn() === false) {
            throw new \RuntimeException('La cuenta no pertenece a la empresa indicada.');
        }
        $checkpoint = $pdo->prepare(
            'SELECT cursor_sale_key FROM financial_gap_scan_state
             WHERE company_id=? AND meli_account_id=? LIMIT 1'
        );
        $checkpoint->execute([$companyId, $accountId]);
        $cursor = (string) ($checkpoint->fetchColumn() ?: '');
        $rows = $pdo->prepare(
            'SELECT CONCAT(IF(o.external_pack_id IS NULL,"O:","P:"),o.sale_identity) sale_key,
                    MIN(o.id) first_order_id
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE o.meli_account_id=?
               AND CONCAT(IF(o.external_pack_id IS NULL,"O:","P:"),o.sale_identity)>?
             GROUP BY CONCAT(IF(o.external_pack_id IS NULL,"O:","P:"),o.sale_identity)
             ORDER BY sale_key LIMIT ' . $readLimit
        );
        $rows->execute([$companyId, $accountId, $cursor]);
        $sales = $rows->fetchAll(PDO::FETCH_ASSOC);
        $stateService = new SaleFinancialStateService();
        $completeness = new FinancialCompletenessService();
        $enqueued = 0;
        $localJobs = 0;
        $billingJobs = 0;
        $lastCursor = $cursor;
        foreach ($sales as $sale) {
            $saleKey = (string) $sale['sale_key'];
            $lastCursor = $saleKey;
            $state = $stateService->projectSale($companyId, $accountId, $saleKey);
            $diagnostic = $completeness->inspectSale($companyId, $accountId, $saleKey);
            if ($enqueued >= $enqueueLimit) {
                continue;
            }
            if ((string) $diagnostic['next_step'] === 'local_projection') {
                $orderIds = array_values(array_map('intval', (array) ($state['order_ids'] ?? [])));
                if ($orderIds !== [] && !$this->hasActiveLocalJob($companyId, $accountId, $orderIds)) {
                    (new OrderFinancialRecalcJobService())->createForOrderIds(
                        $orderIds,
                        'pending',
                        'financial_gap_scan',
                        (int) ($state['id'] ?? 0)
                    );
                    $enqueued++;
                    $localJobs++;
                }
                continue;
            }
            if ((string) $diagnostic['next_step'] === 'billing_capture') {
                (new SaleFinancialService())->queueFromOrderId(
                    (int) $sale['first_order_id'],
                    'financial_gap_scan',
                    (int) ($state['id'] ?? 0),
                    35,
                    (string) $state['input_version']
                );
                $enqueued++;
                $billingJobs++;
            }
        }
        $nextCursor = count($sales) < $readLimit ? null : $lastCursor;
        $pdo->prepare(
            'INSERT INTO financial_gap_scan_state
                (company_id,meli_account_id,cursor_sale_key,last_read_count,last_enqueued_count,last_scanned_at)
             VALUES (?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE cursor_sale_key=VALUES(cursor_sale_key),
                last_read_count=VALUES(last_read_count),last_enqueued_count=VALUES(last_enqueued_count),
                last_scanned_at=UTC_TIMESTAMP()'
        )->execute([$companyId, $accountId, $nextCursor, count($sales), $enqueued]);
        return [
            'read' => count($sales),
            'enqueued' => $enqueued,
            'local_jobs' => $localJobs,
            'billing_jobs' => $billingJobs,
            'next_cursor' => $nextCursor,
        ];
    }

    /** @param list<int> $orderIds */
    private function hasActiveLocalJob(int $companyId, int $accountId, array $orderIds): bool
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT 1
             FROM order_financial_recalc_job_items i
             JOIN order_financial_recalc_jobs j ON j.id=i.order_financial_recalc_job_id
             JOIN meli_orders o ON o.id=i.meli_order_id
             JOIN meli_accounts a ON a.id=o.meli_account_id
             WHERE j.company_id=? AND j.meli_account_id=?
               AND a.company_id=? AND o.meli_account_id=?
               AND i.meli_order_id IN (' . implode(',', array_fill(0, count($orderIds), '?')) . ')
               AND j.status IN ("pending","running") AND i.status="pending" LIMIT 1'
        );
        $stmt->execute(array_merge([$companyId, $accountId, $companyId, $accountId], $orderIds));
        return $stmt->fetchColumn() !== false;
    }
}
