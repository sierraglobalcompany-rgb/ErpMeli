<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\ValueObjects\DateRange;
use PDO;

final class DashboardReadModelService
{
    /** @var array<string,array<string,mixed>> */
    private static array $requestCache = [];
    /** @var list<int>|null */
    private ?array $authorizedAccountIds = null;

    /**
     * @return array{metrics:array<string,int|float|bool>,orders:array,syncs:array,accounts:array,companies:array,context:array<string,string>}
     */
    public function load(int $accountId, int $companyId, DateRange $range): array
    {
        $key = hash('sha256', json_encode([
            Auth::id(),
            $accountId,
            $companyId,
            $range->fromUtc->format(DATE_ATOM),
            $range->toUtc->format(DATE_ATOM),
        ]) ?: '');
        if (isset(self::$requestCache[$key])) {
            return self::$requestCache[$key];
        }

        $ttl = max(15, min(30, (new AppSettingsService())->int('dashboard.cache_seconds', 20)));
        $cached = (new ReadModelCacheService())->rememberArray(
            'dashboard-read-model',
            $key,
            $ttl,
            fn(): array => $this->loadFresh($accountId, $companyId, $range)
        );
        return self::$requestCache[$key] = $cached['value'];
    }

    /**
     * @return array{metrics:array<string,int|float|bool>,orders:array,syncs:array,accounts:array,companies:array,context:array<string,string>}
     */
    private function loadFresh(int $accountId, int $companyId, DateRange $range): array
    {
        $key = hash('sha256', json_encode([
            Auth::id(),
            $accountId,
            $companyId,
            $range->fromUtc->format(DATE_ATOM),
            $range->toUtc->format(DATE_ATOM),
        ]) ?: '');
        if (isset(self::$requestCache[$key])) {
            return self::$requestCache[$key];
        }
        $apcuKey = 'erp_dashboard_' . $key;
        if (function_exists('apcu_fetch') && (bool) ini_get('apc.enabled')) {
            $hit = false;
            $cached = apcu_fetch($apcuKey, $hit);
            if ($hit && is_array($cached)) {
                return self::$requestCache[$key] = $cached;
            }
        }

        $pdo = Database::connection();
        $this->authorizedAccountIds = (new BusinessScopeContext())->accountIds(null, $companyId);
        if ($accountId > 0) {
            $this->authorizedAccountIds = in_array($accountId, $this->authorizedAccountIds, true)
                ? [$accountId]
                : [];
        }
        $bindings = [];
        $salesFilter = $this->accountFilter('a', $accountId, $companyId, 'sales', $bindings);
        $ordersTotalFilter = $this->accountFilter('a', $accountId, $companyId, 'orders_total', $bindings);
        $netFilter = $this->accountFilter('a', $accountId, $companyId, 'financial_net', $bindings);
        $financialCompleteFilter = $this->accountFilter('a', $accountId, $companyId, 'financial_complete', $bindings);
        $financialPendingFilter = $this->accountFilter('a', $accountId, $companyId, 'financial_pending', $bindings);
        $pendingFilter = $this->accountFilter('a', $accountId, $companyId, 'pending', $bindings);
        $accountsFilter = $this->accountFilter('a', $accountId, $companyId, 'accounts', $bindings);
        $bindings += [
            'sales_from' => $range->fromUtc->format('Y-m-d H:i:s'),
            'sales_to' => $range->toUtc->format('Y-m-d H:i:s'),
            'orders_total_from' => $range->fromUtc->format('Y-m-d H:i:s'),
            'orders_total_to' => $range->toUtc->format('Y-m-d H:i:s'),
            'financial_net_from' => $range->fromUtc->format('Y-m-d H:i:s'),
            'financial_net_to' => $range->toUtc->format('Y-m-d H:i:s'),
            'financial_complete_from' => $range->fromUtc->format('Y-m-d H:i:s'),
            'financial_complete_to' => $range->toUtc->format('Y-m-d H:i:s'),
            'financial_pending_from' => $range->fromUtc->format('Y-m-d H:i:s'),
            'financial_pending_to' => $range->toUtc->format('Y-m-d H:i:s'),
            'pending_from' => $range->fromUtc->format('Y-m-d H:i:s'),
            'pending_to' => $range->toUtc->format('Y-m-d H:i:s'),
        ];
        $metricStmt = $pdo->prepare(
            'SELECT
              (SELECT COALESCE(SUM(p.transaction_amount),0)
               FROM meli_payments p
               JOIN meli_accounts a ON a.id=p.meli_account_id
               WHERE p.status="approved"
                 AND p.date_approved>=:sales_from AND p.date_approved<:sales_to' . $salesFilter . ') sales,
              (SELECT COUNT(*)
               FROM meli_orders o
               JOIN meli_accounts a ON a.id=o.meli_account_id
               WHERE o.date_created>=:orders_total_from AND o.date_created<:orders_total_to' . $ordersTotalFilter . ') orders_period_total,
              (SELECT COALESCE(SUM(COALESCE(f.billing_reconciled_net_amount,f.ml_net_amount,f.local_estimated_net_amount,0)),0)
               FROM meli_order_financials f
               JOIN meli_orders o ON o.id=f.meli_order_id
               JOIN meli_accounts a ON a.id=o.meli_account_id
               WHERE o.date_created>=:financial_net_from AND o.date_created<:financial_net_to' . $netFilter . ') net,
              (SELECT COUNT(*)
               FROM meli_orders o
               JOIN meli_accounts a ON a.id=o.meli_account_id
               LEFT JOIN meli_order_financials f ON f.meli_order_id=o.id
               WHERE o.date_created>=:financial_complete_from AND o.date_created<:financial_complete_to' . $financialCompleteFilter . '
                 AND f.id IS NOT NULL
                 AND f.reconciliation_status IN ("calculated","matched")) financial_complete_count,
              (SELECT COUNT(*)
               FROM meli_orders o
               JOIN meli_accounts a ON a.id=o.meli_account_id
               LEFT JOIN meli_order_financials f ON f.meli_order_id=o.id
               WHERE o.date_created>=:financial_pending_from AND o.date_created<:financial_pending_to' . $financialPendingFilter . '
                 AND (f.id IS NULL OR f.reconciliation_status NOT IN ("calculated","matched"))) financial_pending_count,
              (SELECT COUNT(*)
               FROM meli_orders o
               JOIN meli_accounts a ON a.id=o.meli_account_id
               WHERE o.status NOT IN ("paid","cancelled")
                 AND o.date_created>=:pending_from AND o.date_created<:pending_to' . $pendingFilter . ') pending,
              (SELECT COUNT(*)
               FROM meli_accounts a
               WHERE a.status="conectado"' . $accountsFilter . ') accounts'
        );
        $metricStmt->execute($bindings);
        $metricRow = $metricStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $orderBindings = [
            'order_from' => $range->fromUtc->format('Y-m-d H:i:s'),
            'order_to' => $range->toUtc->format('Y-m-d H:i:s'),
        ];
        $orderFilter = $this->accountFilter('a', $accountId, $companyId, 'orders', $orderBindings);
        $orderStmt = $pdo->prepare(
            'SELECT o.id,o.external_order_id,o.date_created,o.buyer_nickname,o.total_amount,o.status,
                    a.account_name,
                    (SELECT p.status FROM meli_payments p
                     WHERE p.meli_order_id=o.id
                     ORDER BY (p.status="approved") DESC,p.date_approved DESC,p.id DESC LIMIT 1) payment_status,
                    (SELECT s.status FROM meli_shipments s
                     WHERE s.meli_order_id=o.id
                     ORDER BY s.updated_at DESC,s.id DESC LIMIT 1) shipping_status
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id
             WHERE o.date_created>=:order_from AND o.date_created<:order_to' . $orderFilter . '
             ORDER BY o.date_created DESC LIMIT 8'
        );
        $orderStmt->execute($orderBindings);

        $syncBindings = [];
        $syncFilter = $this->accountFilter('a', $accountId, $companyId, 'sync', $syncBindings);
        $syncStmt = $pdo->prepare(
            'SELECT l.id,l.sync_type,l.processed_count,l.status,l.started_at,a.account_name
             FROM meli_sync_logs l
             LEFT JOIN meli_accounts a ON a.id=l.meli_account_id
             WHERE 1=1' . $syncFilter . '
             ORDER BY l.started_at DESC LIMIT 5'
        );
        $syncStmt->execute($syncBindings);

        $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
        $financialPending = (int) ($metricRow['financial_pending_count'] ?? 0);
        $metrics = [
            'sales' => (float) ($metricRow['sales'] ?? 0),
            'net' => (float) ($metricRow['net'] ?? 0),
            'pending' => (int) ($metricRow['pending'] ?? 0),
            'accounts' => (int) ($metricRow['accounts'] ?? 0),
            'orders_period_total' => (int) ($metricRow['orders_period_total'] ?? 0),
            'orders_visible_count' => count($orders),
            'financial_complete_count' => (int) ($metricRow['financial_complete_count'] ?? 0),
            'financial_pending_count' => $financialPending,
            'net_is_partial' => $financialPending > 0,
        ];

        $result = [
            'metrics' => $metrics,
            'orders' => $orders,
            'syncs' => $syncStmt->fetchAll(PDO::FETCH_ASSOC),
            'accounts' => (new RequestContextService())->options()['accounts'],
            'companies' => (new CompanyOptionService())->active(),
            'context' => [
                'from_utc' => $range->fromUtc->format(DATE_ATOM),
                'to_utc' => $range->toUtc->format(DATE_ATOM),
            ],
        ];
        self::$requestCache[$key] = $result;
        if (function_exists('apcu_store') && (bool) ini_get('apc.enabled')) {
            $ttl = max(15, min(30, (new AppSettingsService())->int('dashboard.cache_seconds', 20)));
            apcu_store($apcuKey, $result, $ttl);
        }
        return $result;
    }

    /**
     * @param array<string,int|string> $bindings
     */
    private function accountFilter(string $alias, int $accountId, int $companyId, string $suffix, array &$bindings): string
    {
        $authorized = $this->authorizedAccountIds
            ?? (new BusinessScopeContext())->accountIds(null, $companyId);
        if ($authorized === []) {
            return ' AND 1=0';
        }
        $placeholders = [];
        foreach ($authorized as $index => $authorizedAccountId) {
            $key = 'account_' . $suffix . '_' . $index;
            $bindings[$key] = $authorizedAccountId;
            $placeholders[] = ':' . $key;
        }
        return ' AND ' . $alias . '.id IN (' . implode(',', $placeholders) . ')';
    }
}
