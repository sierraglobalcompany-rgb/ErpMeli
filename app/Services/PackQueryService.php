<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class PackQueryService
{
    public function list(array $filters = []): array
    {
        $accountIds = (new BusinessScopeContext())->accountIds();
        $requested = (int) ($filters['account_id'] ?? 0);
        if ($requested > 0) {
            $accountIds = in_array($requested, $accountIds, true) ? [$requested] : [];
        }
        $where = [];
        $params = [];
        if ($accountIds === []) {
            $where[] = '1=0';
        } else {
            $names = [];
            foreach ($accountIds as $index => $accountId) {
                $name = 'scope_account_' . $index;
                $names[] = ':' . $name;
                $params[$name] = $accountId;
            }
            $where[] = 'p.meli_account_id IN (' . implode(',', $names) . ')';
        }
        $perPage = in_array((int) ($filters['per_page'] ?? 50), [25, 50, 100], true) ? (int) $filters['per_page'] : 50;
        $page = max(1, (int) ($filters['page'] ?? 1));
        $stmt = Database::connection()->prepare(
            'SELECT p.*, a.account_name, COUNT(po.meli_order_id) orders_count
             FROM meli_packs p
             JOIN meli_accounts a ON a.id=p.meli_account_id
             LEFT JOIN meli_pack_orders po ON po.meli_pack_id=p.id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY p.id ORDER BY p.synced_at DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
