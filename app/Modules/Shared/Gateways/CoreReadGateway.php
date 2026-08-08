<?php

declare(strict_types=1);

namespace App\Modules\Shared\Gateways;

use App\Core\Database;
use App\Modules\Shared\Contracts\AccountReadGateway;
use App\Modules\Shared\Contracts\CatalogReadGateway;
use App\Modules\Shared\Contracts\ItemReadGateway;
use App\Modules\Shared\Contracts\OrderReadGateway;
use App\Modules\Shared\Contracts\ShipmentReadGateway;
use PDO;

final class CoreReadGateway implements AccountReadGateway, ItemReadGateway, OrderReadGateway, ShipmentReadGateway, CatalogReadGateway
{
    public function activeAccounts(?int $accountId = null): array
    {
        $sql = "SELECT id,company_id,account_name,meli_user_id,nickname,site_id,country_id,status,last_sync_at
                FROM meli_accounts WHERE status='conectado'";
        $params = [];
        if ($accountId !== null) {
            $sql .= ' AND id=?';
            $params[] = $accountId;
        }
        $sql .= ' ORDER BY account_name,id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function items(int $accountId, int $limit = 50, int $offset = 0): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT external_item_id,title,seller_sku,category_id,price,available_quantity,sold_quantity,status,listing_type_id,synced_at
             FROM meli_items WHERE meli_account_id=? ORDER BY updated_at DESC,id DESC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $accountId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(3, max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findItem(int $accountId, string $externalItemId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT external_item_id,title,seller_sku,category_id,price,available_quantity,sold_quantity,status,listing_type_id,synced_at
             FROM meli_items WHERE meli_account_id=? AND external_item_id=? LIMIT 1'
        );
        $stmt->execute([$accountId, $externalItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function countItems(int $accountId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM meli_items WHERE meli_account_id=?');
        $stmt->execute([$accountId]);
        return (int) $stmt->fetchColumn();
    }

    public function itemsByIds(int $accountId, array $externalItemIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => trim((string) $id), $externalItemIds),
            static fn (string $id): bool => $id !== ''
        )));
        if ($ids === []) {
            return [];
        }
        $ids = array_slice($ids, 0, 200);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare(
            'SELECT external_item_id,title,seller_sku,category_id,price,available_quantity,sold_quantity,status,listing_type_id,synced_at
             FROM meli_items WHERE meli_account_id=? AND external_item_id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$accountId], $ids));
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['external_item_id']] = $row;
        }
        return $result;
    }

    public function topCategories(int $accountId, int $limit = 5): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT category_id,COUNT(*) AS total
             FROM meli_items
             WHERE meli_account_id=? AND category_id IS NOT NULL AND category_id<>''
             GROUP BY category_id
             ORDER BY total DESC,category_id ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $accountId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(20, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return array_map(
            static fn (array $row): array => ['category_id' => (string) $row['category_id'], 'total' => (int) $row['total']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function recentOrders(int $accountId, int $limit = 50): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT external_order_id,external_pack_id,date_created,status,total_amount,paid_amount,currency_id,external_shipping_id,synced_at
             FROM meli_orders WHERE meli_account_id=? ORDER BY date_created DESC,id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $accountId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function dailyOrderTotals(int $accountId, string $fromUtc, string $toUtc): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT DATE(o.date_created) AS observed_on,
                    COUNT(DISTINCT o.id) AS orders_count,
                    COALESCE(SUM(oi.quantity),0) AS units_sold
             FROM meli_orders o
             LEFT JOIN meli_order_items oi ON oi.meli_order_id=o.id
             WHERE o.meli_account_id=?
               AND o.date_created>=?
               AND o.date_created<?
               AND COALESCE(o.status,'')<>'cancelled'
             GROUP BY DATE(o.date_created)
             ORDER BY observed_on ASC"
        );
        $stmt->execute([$accountId, $fromUtc, $toUtc]);
        return array_map(
            static fn (array $row): array => [
                'observed_on' => (string) $row['observed_on'],
                'orders_count' => (int) $row['orders_count'],
                'units_sold' => (int) $row['units_sold'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function recentShipments(int $accountId, int $limit = 50): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT external_shipping_id,meli_order_id,status,substatus,logistic_type,date_created,date_updated
             FROM meli_shipments WHERE meli_account_id=? ORDER BY date_updated DESC,id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $accountId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findShipment(int $accountId, string $externalShipmentId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT external_shipping_id,meli_order_id,status,substatus,logistic_type,date_created,date_updated
             FROM meli_shipments WHERE meli_account_id=? AND external_shipping_id=? LIMIT 1'
        );
        $stmt->execute([$accountId, $externalShipmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function enabledCatalogs(?int $accountId = null): array
    {
        $sql = 'SELECT id,name,slug,visibility,account_scope,meli_account_id,last_refresh_status,last_refreshed_at
                FROM catalogs WHERE is_enabled=1';
        $params = [];
        if ($accountId !== null) {
            $sql .= " AND (account_scope='all' OR meli_account_id=?)";
            $params[] = $accountId;
        }
        $stmt = Database::connection()->prepare($sql . ' ORDER BY name,id');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
