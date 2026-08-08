<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class UnlinkedProductService
{
    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int} */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
        [$from, $where, $params] = $this->queryParts($filters);
        $count = Database::connection()->prepare(
            'SELECT COUNT(*) FROM (
               SELECT oi.meli_account_id,oi.external_item_id,oi.external_variation_id
               ' . $from . ' WHERE ' . implode(' AND ', $where) . '
               GROUP BY oi.meli_account_id,oi.external_item_id,oi.external_variation_id
             ) grouped'
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $stmt = Database::connection()->prepare(
            'SELECT oi.meli_account_id, a.account_name, oi.external_item_id, oi.external_variation_id,
                    COALESCE(NULLIF(oi.seller_sku,""), "SIN-SKU") seller_sku,
                    MAX(oi.title) title, SUM(oi.quantity) units_sold, COUNT(DISTINCT oi.meli_order_id) orders_count,
                    MAX(oi.id) sample_order_item_id, MAX(o.date_created) last_sale_at
             ' . $from . ' WHERE ' . implode(' AND ', $where) . '
             GROUP BY oi.meli_account_id,a.account_name,oi.external_item_id,oi.external_variation_id,
                      COALESCE(NULLIF(oi.seller_sku,""), "SIN-SKU")
             ORDER BY last_sale_at DESC
             LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $stmt->execute($params);
        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage,
        ];
    }

    public function soldUnlinked(array $filters = []): array
    {
        [$from, $where, $params] = $this->queryParts($filters);
        $stmt = Database::connection()->prepare(
            'SELECT oi.meli_account_id, a.account_name, oi.external_item_id, oi.external_variation_id,
                    COALESCE(NULLIF(oi.seller_sku,""), "SIN-SKU") seller_sku,
                    MAX(oi.title) title, SUM(oi.quantity) units_sold, COUNT(DISTINCT oi.meli_order_id) orders_count,
                    MAX(oi.id) sample_order_item_id, MAX(o.date_created) last_sale_at
             ' . $from . ' WHERE ' . implode(' AND ', $where) . '
             GROUP BY oi.meli_account_id, a.account_name, oi.external_item_id, oi.external_variation_id, COALESCE(NULLIF(oi.seller_sku,""), "SIN-SKU")
             ORDER BY last_sale_at DESC LIMIT 300'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0:string,1:list<string>,2:array<string,mixed>} */
    private function queryParts(array $filters): array
    {
        $where = ['l.id IS NULL', 'ig.id IS NULL'];
        $params = [];
        if (!empty($filters['account_id'])) {
            $where[] = 'oi.meli_account_id = :account';
            $params['account'] = (int) $filters['account_id'];
        }
        $from = 'FROM meli_order_items oi
             JOIN meli_orders o ON o.id=oi.meli_order_id
             JOIN meli_accounts a ON a.id=oi.meli_account_id
             LEFT JOIN meli_items mi ON mi.meli_account_id=oi.meli_account_id AND mi.external_item_id=oi.external_item_id
             LEFT JOIN product_meli_links l ON l.meli_account_id=oi.meli_account_id
                AND l.meli_item_id=mi.id
                AND l.meli_variation_id=COALESCE(oi.external_variation_id,0)
                AND l.status="active"
             LEFT JOIN product_unlinked_ignores ig ON ig.meli_account_id=oi.meli_account_id
                AND ig.external_item_id=oi.external_item_id
                AND COALESCE(ig.external_variation_id,0)=COALESCE(oi.external_variation_id,0)';
        return [$from, $where, $params];
    }
}
