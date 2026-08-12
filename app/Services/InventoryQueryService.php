<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use PDO;

final class InventoryQueryService
{
    public function __construct(private readonly AuthorizedBusinessScope $scope = new AuthorizedBusinessScope()) {}

    /** @return array{items:list<array<string,mixed>>,page:int,pages:int,per_page:int,total:int} */
    public function balances(array $filters, int $page = 1, int $perPage = 50): array
    {
        [$where, $params] = $this->balanceFilters($filters);
        $page = max(1, $page);
        $perPage = in_array($perPage, [25,50,100], true) ? $perPage : 50;
        $count = Database::connection()->prepare(
            'SELECT COUNT(*)
             FROM internal_products p
             JOIN inventory_warehouses w ON w.company_id=p.company_id AND w.status="active"
             LEFT JOIN inventory_balances b
               ON b.company_id=p.company_id AND b.warehouse_id=w.id AND b.internal_product_id=p.id
             WHERE ' . implode(' AND ', $where)
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $stmt = Database::connection()->prepare(
            'SELECT p.id internal_product_id,p.internal_sku,p.name product_name,p.unit,
                    w.id warehouse_id,w.code warehouse_code,w.name warehouse_name,w.is_default,
                    c.id company_id,c.name company_name,
                    COALESCE(b.on_hand,0) on_hand,COALESCE(b.reserved,0) reserved,
                    COALESCE(b.available,0) available,COALESCE(b.average_unit_cost,0) average_unit_cost,
                    ROUND(COALESCE(b.on_hand,0)*COALESCE(b.average_unit_cost,0),2) inventory_value,
                    CASE
                      WHEN b.internal_product_id IS NULL THEN "not_initialized"
                      WHEN b.available<0 THEN "invalid"
                      WHEN b.available=0 THEN "out_of_stock"
                      ELSE "available"
                    END stock_status
             FROM internal_products p
             JOIN companies c ON c.id=p.company_id AND c.status=1
             JOIN inventory_warehouses w ON w.company_id=p.company_id AND w.status="active"
             LEFT JOIN inventory_balances b
               ON b.company_id=p.company_id AND b.warehouse_id=w.id AND b.internal_product_id=p.id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY c.name,w.is_default DESC,w.name,p.name,p.id
             LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $stmt->execute($params);
        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'total' => $total,
        ];
    }

    /** @return array{items:list<array<string,mixed>>,page:int,pages:int,per_page:int,total:int} */
    public function movements(array $filters, int $page = 1, int $perPage = 50): array
    {
        [$where, $params] = $this->movementFilters($filters);
        $page = max(1, $page);
        $perPage = in_array($perPage, [25,50,100], true) ? $perPage : 50;
        $count = Database::connection()->prepare(
            'SELECT COUNT(*) FROM inventory_movements m WHERE ' . implode(' AND ', $where)
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $stmt = Database::connection()->prepare(
            'SELECT m.*,p.internal_sku,p.name product_name,w.code warehouse_code,w.name warehouse_name,
                    c.name company_name,a.account_name,u.name actor_name
             FROM inventory_movements m
             JOIN internal_products p ON p.id=m.internal_product_id AND p.company_id=m.company_id
             JOIN inventory_warehouses w ON w.id=m.warehouse_id AND w.company_id=m.company_id
             JOIN companies c ON c.id=m.company_id
             LEFT JOIN meli_accounts a ON a.id=m.meli_account_id AND a.company_id=m.company_id
             LEFT JOIN users u ON u.id=m.actor_user_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY m.created_at DESC,m.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $stmt->execute($params);
        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'total' => $total,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function openReviews(int $companyId = 0): array
    {
        [$companySql, $params] = $this->companyPredicate('r.company_id', $companyId, 'review_company');
        $accountIds = $this->scope->accountIds(null, $companyId);
        if ($accountIds === []) {
            return [];
        }
        $slots = [];
        foreach ($accountIds as $i => $id) {
            $key = 'review_account_' . $i;
            $slots[] = ':' . $key;
            $params[$key] = $id;
        }
        $stmt = Database::connection()->prepare(
            'SELECT r.*,c.name company_name,a.account_name,p.internal_sku,p.name product_name
             FROM inventory_reviews r
             JOIN companies c ON c.id=r.company_id
             JOIN meli_accounts a ON a.id=r.meli_account_id AND a.company_id=r.company_id
             LEFT JOIN internal_products p ON p.id=r.internal_product_id AND p.company_id=r.company_id
             WHERE ' . $companySql . ' AND r.meli_account_id IN (' . implode(',', $slots) . ')
               AND r.state="open"
             ORDER BY r.created_at,r.id LIMIT 100'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function companies(): array
    {
        $ids = $this->scope->companyIds();
        if ($ids === []) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT id,name FROM companies WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
             AND status=1 AND deleted_at IS NULL ORDER BY name,id'
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function products(int $companyId): array
    {
        [$sql, $params] = $this->companyPredicate('company_id', $companyId, 'manual_product_company');
        $stmt = Database::connection()->prepare(
            'SELECT id,company_id,internal_sku,name,unit
             FROM internal_products WHERE ' . $sql . ' AND deleted_at IS NULL AND status="active"
             ORDER BY name,id LIMIT 500'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function accounts(int $companyId = 0): array
    {
        $ids = $this->scope->accountIds(null, $companyId);
        if ($ids === []) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT a.id,a.company_id,a.account_name,c.name company_name
             FROM meli_accounts a
             JOIN companies c ON c.id=a.company_id
             WHERE a.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
             ORDER BY c.name,a.account_name,a.id'
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0:list<string>,1:array<string,mixed>} */
    private function balanceFilters(array $filters): array
    {
        [$companySql, $params] = $this->companyPredicate('p.company_id', (int) ($filters['company_id'] ?? 0), 'balance_company');
        $where = [$companySql, 'p.deleted_at IS NULL', 'p.status="active"'];
        $warehouseId = (int) ($filters['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            $where[] = 'w.id=:balance_warehouse';
            $params['balance_warehouse'] = $warehouseId;
        }
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(p.internal_sku LIKE :balance_sku OR p.name LIKE :balance_name)';
            $params['balance_sku'] = '%' . $query . '%';
            $params['balance_name'] = '%' . $query . '%';
        }
        return [$where, $params];
    }

    /** @return array{0:list<string>,1:array<string,mixed>} */
    private function movementFilters(array $filters): array
    {
        [$companySql, $params] = $this->companyPredicate('m.company_id', (int) ($filters['company_id'] ?? 0), 'movement_company');
        $where = [$companySql];
        foreach (['warehouse_id' => 'movement_warehouse', 'internal_product_id' => 'movement_product'] as $field => $key) {
            if ((int) ($filters[$field] ?? 0) > 0) {
                $where[] = 'm.' . $field . '=:' . $key;
                $params[$key] = (int) $filters[$field];
            }
        }
        $accountId = (int) ($filters['account_id'] ?? 0);
        if ($accountId > 0) {
            if (!in_array($accountId, $this->scope->accountIds(), true)) {
                throw new HttpException(404, 'No se encontró la cuenta solicitada.');
            }
            $where[] = 'm.meli_account_id=:movement_account';
            $params['movement_account'] = $accountId;
        }
        $type = trim((string) ($filters['movement_type'] ?? ''));
        if ($type !== '') {
            $where[] = 'm.movement_type=:movement_type';
            $params['movement_type'] = $type;
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            $value = trim((string) ($filters[$field] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $key = 'movement_' . $field;
                $where[] = 'm.created_at ' . $operator . ' :' . $key;
                $params[$key] = $field === 'to' ? $value . ' 23:59:59.999' : $value . ' 00:00:00';
            }
        }
        return [$where, $params];
    }

    /** @return array{0:string,1:array<string,int>} */
    private function companyPredicate(string $column, int $requested, string $prefix): array
    {
        $ids = $this->scope->companyIds();
        if ($requested > 0) {
            if (!in_array($requested, $ids, true)) {
                throw new HttpException(404, 'No se encontró la empresa solicitada.');
            }
            return [$column . '=:' . $prefix, [$prefix => $requested]];
        }
        if ($ids === []) {
            return ['1=0', []];
        }
        $params = [];
        $slots = [];
        foreach ($ids as $i => $id) {
            $key = $prefix . '_' . $i;
            $slots[] = ':' . $key;
            $params[$key] = $id;
        }
        return [$column . ' IN (' . implode(',', $slots) . ')', $params];
    }
}
