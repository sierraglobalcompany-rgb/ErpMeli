<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use PDO;

/**
 * Read model humano para una venta de Mercado Libre.
 *
 * Una venta es un pack cuando existe external_pack_id; de lo contrario es una
 * orden individual. Todas las lecturas parten del alcance autorizado.
 */
final class SaleReadService
{
    public function __construct(
        private readonly BusinessScopeContext $scope = new BusinessScopeContext()
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function accounts(int $companyId = 0): array
    {
        $ids = $this->scope->accountIds(null, $companyId);
        if ($ids === []) {
            return [];
        }
        $sql = 'SELECT a.id,a.company_id,a.account_name,a.status
                FROM meli_accounts a
                WHERE a.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                ORDER BY a.account_name';
        $stmt = Database::connectionFresh()->prepare($sql);
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function list(array $filters): array
    {
        $companyId = max(0, (int) ($filters['company_id'] ?? 0));
        $accountId = max(0, (int) ($filters['account_id'] ?? 0));
        if ($accountId > 0) {
            $this->scope->account($accountId, $companyId);
            $accountIds = [$accountId];
        } else {
            $accountIds = $this->scope->accountIds(null, $companyId);
        }
        $perPage = in_array((int) ($filters['per_page'] ?? 50), [25, 50, 100], true)
            ? (int) $filters['per_page'] : 50;
        $page = max(1, (int) ($filters['page'] ?? 1));
        if ($accountIds === []) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => $perPage];
        }

        [$where, $params] = $this->filters($filters, $accountIds);
        $groupKey = 'o.sale_identity';
        $countCache = (new ReadModelCacheService())->rememberArray(
            'sales-list-count',
            json_encode([
                'company_id' => $companyId,
                'account_ids' => $accountIds,
                'status' => $filters['status'] ?? '',
                'financial_status' => $filters['financial_status'] ?? '',
                'q' => $filters['q'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            30,
            static function () use ($where, $groupKey, $params): array {
                $count = Database::connection()->prepare(
                    'SELECT COUNT(*) FROM (
                        SELECT o.meli_account_id,' . $groupKey . ' sale_id
                        FROM meli_orders o
                        JOIN meli_accounts a ON a.id=o.meli_account_id
                        WHERE ' . $where . '
                        GROUP BY o.meli_account_id,' . $groupKey . '
                     ) grouped_sales'
                );
                $count->execute($params);
                return ['total' => (int) $count->fetchColumn()];
            }
        );
        $total = (int) $countCache['value']['total'];
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $displayDate = 'COALESCE(o.date_created_local,o.date_created)';
        $financialV3 = (new SchemaInspectorService())->hasTable('sale_financial_state');
        $financialJoin = $financialV3
            ? 'LEFT JOIN sale_financial_state sf
                 ON sf.company_id=a.company_id
                AND sf.meli_account_id=o.meli_account_id
                AND sf.sale_key=CONCAT(IF(o.external_pack_id IS NULL,"O:","P:"),' . $groupKey . ')'
            : 'LEFT JOIN meli_sale_financials sf
                 ON sf.company_id=a.company_id
                AND sf.meli_account_id=o.meli_account_id
                AND sf.sale_key=CONCAT(IF(o.external_pack_id IS NULL,"O:","P:"),' . $groupKey . ')';
        $financialStatus = $financialV3 ? 'MAX(sf.official_status)' : 'MAX(sf.reconciliation_status)';
        $netAmount = $financialV3 ? 'MAX(sf.official_net_amount)' : 'MAX(sf.net_amount)';

        // Primera fase: paginar identidades de venta antes de unir productos,
        // packs y finanzas. Así el costo de agregación queda limitado a 50
        // ventas, incluso con historiales grandes.
        $identityStmt = Database::connection()->prepare(
            'SELECT o.meli_account_id,' . $groupKey . ' sale_id,
                    MAX(' . $displayDate . ') sort_date
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id
             WHERE ' . $where . '
             GROUP BY o.meli_account_id,' . $groupKey . '
             ORDER BY sort_date DESC,sale_id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $identityStmt->execute($params);
        $identities = $identityStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($identities === []) {
            return [
                'items' => [],
                'total' => $total,
                'page' => $page,
                'pages' => $pages,
                'per_page' => $perPage,
            ];
        }
        $identityPredicates = [];
        $identityParams = [];
        $position = [];
        foreach ($identities as $index => $identity) {
            $account = (int) ($identity['meli_account_id'] ?? 0);
            $sale = (string) ($identity['sale_id'] ?? '');
            $identityPredicates[] = '(o.meli_account_id=? AND ' . $groupKey . '=?)';
            $identityParams[] = $account;
            $identityParams[] = $sale;
            $position[$account . ':' . $sale] = $index;
        }

        $stmt = Database::connection()->prepare(
            'SELECT o.meli_account_id,a.company_id,a.account_name,
                    ' . $groupKey . ' sale_id,
                    MAX(o.external_pack_id IS NOT NULL) is_pack,
                    COUNT(DISTINCT o.id) orders_count,
                    COUNT(DISTINCT oi.id) products_count,
                    COALESCE(SUM(oi.quantity),0) units_count,
                    COALESCE(SUM(oi.quantity*oi.unit_price),0) products_amount,
                    CASE
                        WHEN COUNT(DISTINCT COALESCE(o.status,""))=1 THEN MAX(o.status)
                        ELSE "mixed"
                    END status,
                    MAX(o.currency_id) currency_id,
                    MAX(' . $displayDate . ') date_created,
                    MAX(p.integrity_status) integrity_status,
                    MAX(p.integrity_message) integrity_message,
                    MAX(p.expected_orders_count) expected_orders_count,
                    MAX(p.linked_orders_count) linked_orders_count,
                    ' . $financialStatus . ' financial_status,
                    ' . $netAmount . ' net_amount
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id
             LEFT JOIN meli_order_items oi ON oi.meli_order_id=o.id
             LEFT JOIN meli_packs p
               ON p.meli_account_id=o.meli_account_id
              AND p.external_pack_id=o.external_pack_id
             ' . $financialJoin . '
             WHERE (' . implode(' OR ', $identityPredicates) . ')
             GROUP BY o.meli_account_id,a.company_id,a.account_name,' . $groupKey
        );
        $stmt->execute($identityParams);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        usort($items, static function (array $left, array $right) use ($position): int {
            $leftKey = (int) $left['meli_account_id'] . ':' . (string) $left['sale_id'];
            $rightKey = (int) $right['meli_account_id'] . ':' . (string) $right['sale_id'];
            return ($position[$leftKey] ?? PHP_INT_MAX) <=> ($position[$rightKey] ?? PHP_INT_MAX);
        });
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /** @return array<string,mixed> */
    public function show(int $accountId, string $saleId): array
    {
        $account = $this->scope->account($accountId);
        if ($saleId === '' || preg_match('/^\d+$/', $saleId) !== 1) {
            throw new HttpException(404, 'No se encontró la venta solicitada.');
        }
        $pdo = Database::connectionFresh();
        $identity = $pdo->prepare(
            'SELECT sale_identity sale_id,
                    MAX(external_pack_id IS NOT NULL) is_pack
             FROM meli_orders
             JOIN meli_accounts sale_account ON sale_account.id=meli_orders.meli_account_id
             WHERE sale_account.company_id=? AND meli_account_id=?
               AND (external_pack_id=? OR external_order_id=?)
             GROUP BY sale_identity
             LIMIT 1'
        );
        $identity->execute([(int) $account['company_id'], $accountId, $saleId, $saleId]);
        $resolved = $identity->fetch(PDO::FETCH_ASSOC);
        if (!is_array($resolved)) {
            throw new HttpException(404, 'No se encontró la venta solicitada.');
        }
        $canonicalSaleId = (string) $resolved['sale_id'];
        $orders = $pdo->prepare(
            'SELECT o.id,o.meli_account_id,o.external_order_id,o.external_pack_id,
                    o.date_created,o.date_created_local,o.status,o.enrichment_status,
                    o.total_amount,o.paid_amount,o.currency_id,o.buyer_id,o.buyer_nickname,
                    o.external_shipping_id,o.synced_at,o.updated_at
             FROM meli_orders o
             JOIN meli_accounts sale_account ON sale_account.id=o.meli_account_id
             WHERE sale_account.company_id=? AND o.meli_account_id=?
               AND o.sale_identity=?
             ORDER BY o.date_created,o.id'
        );
        $orders->execute([(int) $account['company_id'], $accountId, $canonicalSaleId]);
        $orderRows = $orders->fetchAll(PDO::FETCH_ASSOC);
        $orderIds = array_map(static fn(array $row): int => (int) $row['id'], $orderRows);
        $items = $this->children('meli_order_items', $accountId, $orderIds, 'meli_order_id,id');
        $payments = $this->children('meli_payments', $accountId, $orderIds, 'meli_order_id,id');
        $shipments = $this->shipments($accountId, $canonicalSaleId, $orderIds);
        $pack = null;
        if ((int) $resolved['is_pack'] === 1) {
            $packStmt = $pdo->prepare(
                'SELECT p.id,p.meli_account_id,p.external_pack_id,p.external_shipment_id,
                        p.status,p.integrity_status,p.expected_orders_count,p.linked_orders_count,
                        p.orders_fingerprint,p.integrity_message,p.verified_at,p.synced_at,
                        p.created_at,p.updated_at
                 FROM meli_packs p
                 JOIN meli_accounts pack_account ON pack_account.id=p.meli_account_id
                 WHERE pack_account.company_id=? AND p.meli_account_id=? AND p.external_pack_id=?
                 LIMIT 1'
            );
            $packStmt->execute([(int) $account['company_id'], $accountId, $canonicalSaleId]);
            $pack = $packStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $financial = null;
        $financialState = null;
        $financialCompleteness = null;
        $lines = [];
        $allocations = [];
        $financialStmt = $pdo->prepare(
            'SELECT * FROM meli_sale_financials
             WHERE company_id=? AND meli_account_id=? AND sale_key=? LIMIT 1'
        );
        $financialStmt->execute([
            (int) $account['company_id'],
            $accountId,
            ((int) $resolved['is_pack'] === 1 ? 'P:' : 'O:') . $canonicalSaleId,
        ]);
        $financial = $financialStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $saleKey = ((int) $resolved['is_pack'] === 1 ? 'P:' : 'O:') . $canonicalSaleId;
        if ((new SchemaInspectorService())->hasTable('sale_financial_state')) {
            $stateStmt = $pdo->prepare(
                'SELECT * FROM sale_financial_state
                 WHERE company_id=? AND meli_account_id=? AND sale_key=? LIMIT 1'
            );
            $stateStmt->execute([(int) $account['company_id'], $accountId, $saleKey]);
            $financialState = $stateStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $financialCompleteness = (new FinancialCompletenessService())->inspectSale(
                (int) $account['company_id'],
                $accountId,
                $saleKey
            );
        }
        if (is_array($financial)) {
            $linesStmt = $pdo->prepare(
                'SELECT * FROM meli_sale_financial_lines
                 WHERE meli_sale_financial_id=? ORDER BY line_group,line_type,id'
            );
            $linesStmt->execute([(int) $financial['id']]);
            $lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);
            $allocStmt = $pdo->prepare(
                'SELECT a.*,i.title,i.external_item_id,o.external_order_id
                 FROM meli_sale_financial_allocations a
                 JOIN meli_order_items i ON i.id=a.meli_order_item_id
                 JOIN meli_orders o ON o.id=i.meli_order_id
                 JOIN meli_accounts allocation_account ON allocation_account.id=o.meli_account_id
                 WHERE a.meli_sale_financial_id=?
                   AND allocation_account.company_id=? AND o.meli_account_id=?
                 ORDER BY o.external_order_id,i.id,a.id'
            );
            $allocStmt->execute([(int) $financial['id'], (int) $account['company_id'], $accountId]);
            $allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return [
            'account' => $account,
            'sale_id' => $canonicalSaleId,
            'is_pack' => (int) $resolved['is_pack'] === 1,
            'orders' => $orderRows,
            'items' => $items,
            'payments' => $payments,
            'shipments' => $shipments,
            'pack' => $pack,
            'financial' => $financial,
            'financial_state' => $financialState,
            'financial_completeness' => $financialCompleteness,
            'financial_lines' => $lines,
            'allocations' => $allocations,
        ];
    }

    /** @return array{account_id:int,sale_id:string} */
    public function identityForOrder(int $localOrderId): array
    {
        $scope = $this->scope->accountPredicate('o.meli_account_id');
        $stmt = Database::connectionFresh()->prepare(
            'SELECT o.meli_account_id,o.sale_identity sale_id
             FROM meli_orders o
             WHERE o.id=? AND ' . $scope['sql'] . '
             LIMIT 1'
        );
        $stmt->execute(array_merge([$localOrderId], $scope['params']));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new HttpException(404, 'No se encontró la orden solicitada.');
        }
        return ['account_id' => (int) $row['meli_account_id'], 'sale_id' => (string) $row['sale_id']];
    }

    /**
     * @param list<int> $accountIds
     * @return array{0:string,1:list<mixed>}
     */
    private function filters(array $filters, array $accountIds): array
    {
        $where = ['o.meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')'];
        $params = $accountIds;
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $like = '%' . $query . '%';
            $where[] = '(CAST(o.external_pack_id AS CHAR)=?
                OR CAST(o.external_order_id AS CHAR)=?
                OR CAST(o.external_shipping_id AS CHAR)=?
                OR EXISTS (
                    SELECT 1 FROM meli_payments sp
                    WHERE sp.meli_order_id=o.id AND CAST(sp.external_payment_id AS CHAR)=?
                )
                OR EXISTS (
                    SELECT 1 FROM meli_order_items si
                    WHERE si.meli_order_id=o.id
                      AND (si.title LIKE ? OR si.seller_sku LIKE ? OR si.external_item_id LIKE ?)
                )
                OR o.buyer_nickname LIKE ?)';
            array_push($params, $query, $query, $query, $query, $like, $like, $like, $like);
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'o.status=?';
            $params[] = $status;
        }
        $financialStatus = trim((string) ($filters['financial_status'] ?? ''));
        if ($financialStatus === 'complete') {
            $where[] = 'EXISTS (
                SELECT 1 FROM meli_sale_financials fs
                WHERE fs.company_id=a.company_id AND fs.meli_account_id=o.meli_account_id
                  AND fs.sale_key=CONCAT(
                    IF(o.external_pack_id IS NULL,"O:","P:"),
                    COALESCE(o.external_pack_id,o.external_order_id)
                  )
                  AND fs.reconciliation_status="reconciled"
            )';
        } elseif ($financialStatus === 'review') {
            $where[] = 'EXISTS (
                SELECT 1 FROM meli_sale_financials fs
                WHERE fs.company_id=a.company_id AND fs.meli_account_id=o.meli_account_id
                  AND fs.sale_key=CONCAT(
                    IF(o.external_pack_id IS NULL,"O:","P:"),
                    COALESCE(o.external_pack_id,o.external_order_id)
                  )
                  AND fs.reconciliation_status IN ("review","error")
            )';
        } elseif ($financialStatus === 'pending') {
            $where[] = 'NOT EXISTS (
                SELECT 1 FROM meli_sale_financials fs
                WHERE fs.company_id=a.company_id AND fs.meli_account_id=o.meli_account_id
                  AND fs.sale_key=CONCAT(
                    IF(o.external_pack_id IS NULL,"O:","P:"),
                    COALESCE(o.external_pack_id,o.external_order_id)
                  )
                  AND fs.reconciliation_status="reconciled"
            )';
        }
        $dateField = 'o.date_created_local_date';
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            $date = trim((string) ($filters[$key] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }
            $where[] = $dateField . ' ' . $operator . '?';
            $params[] = $key === 'to'
                ? (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')
                : $date;
        }
        return [implode(' AND ', $where), $params];
    }

    /** @param list<int> $orderIds @return list<array<string,mixed>> */
    private function children(string $table, int $accountId, array $orderIds, string $orderBy): array
    {
        if ($orderIds === []) {
            return [];
        }
        if (!in_array($table, ['meli_order_items', 'meli_payments'], true)) {
            throw new \InvalidArgumentException('Tabla hija no permitida.');
        }
        $columns = $table === 'meli_order_items'
            ? 'id,meli_order_id,meli_account_id,external_item_id,external_variation_id,title,
               seller_sku,quantity,unit_price,full_unit_price'
            : 'id,meli_account_id,meli_order_id,external_payment_id,status,status_detail,
               payment_method_id,payment_type,transaction_amount,shipping_cost,coupon_amount,
               total_paid_amount,marketplace_fee,date_approved,detail_status';
        $stmt = Database::connectionFresh()->prepare(
            'SELECT ' . $columns . ' FROM ' . $table . '
             WHERE meli_account_id=?
               AND meli_order_id IN (' . implode(',', array_fill(0, count($orderIds), '?')) . ')
             ORDER BY ' . $orderBy
        );
        $stmt->execute(array_merge([$accountId], $orderIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<int> $orderIds @return list<array<string,mixed>> */
    private function shipments(int $accountId, string $saleId, array $orderIds): array
    {
        $params = [$accountId, $saleId];
        $where = 's.meli_account_id=? AND (
            s.meli_pack_id IN (
                SELECT id FROM meli_packs WHERE meli_account_id=? AND external_pack_id=?
            )';
        array_splice($params, 1, 0, [$accountId]);
        if ($orderIds !== []) {
            $where .= ' OR s.meli_order_id IN (' . implode(',', array_fill(0, count($orderIds), '?')) . ')';
            $params = array_merge($params, $orderIds);
        }
        $where .= ')';
        $stmt = Database::connectionFresh()->prepare(
            'SELECT DISTINCT s.id,s.meli_account_id,s.external_shipment_id,s.meli_order_id,
                    s.meli_pack_id,s.status,s.substatus,s.logistic_type,s.shipping_mode,
                    s.tracking_number,s.carrier,s.estimated_delivery,s.gross_cost,
                    s.seller_cost,s.buyer_cost,s.discounts,s.synced_at
             FROM meli_shipments s WHERE ' . $where . ' ORDER BY s.id'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
