<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class ShipmentQueryService
{
    public function list(array $filters = []): array
    {
        if (!empty($filters['export'])) {
            $filters['page'] = 1;
            $filters['per_page'] = 1000;
        }
        return $this->page($filters)['items'];
    }

    /** @return array{items:array,total:int,page:int,pages:int,per_page:int} */
    public function page(array $filters = []): array
    {
        $hasUserFilter = $this->hasUserFilter($filters);
        [$where, $params] = $this->where($filters);
        if (!$hasUserFilter) {
            $where[] = 's.synced_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)';
        }
        $perPage = $this->perPage((int) ($filters['per_page'] ?? 50));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pdo = Database::connection();
        $count = $pdo->prepare('SELECT COUNT(*) FROM meli_shipments s WHERE ' . implode(' AND ', $where));
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $orderBy = ($filters['date_field'] ?? 'synced') === 'estimated'
            ? 's.estimated_delivery DESC, s.synced_at DESC'
            : 's.synced_at DESC';
        $stmt = $pdo->prepare(
            'SELECT s.id,s.meli_account_id,s.external_shipment_id,s.meli_order_id,s.meli_pack_id,
                    s.status,s.substatus,s.logistic_type,s.shipping_mode,s.tracking_number,s.carrier,
                    s.estimated_delivery,s.gross_cost,s.seller_cost,s.buyer_cost,s.discounts,
                    s.synced_at,s.updated_at,a.account_name,o.external_order_id
             FROM meli_shipments s
             JOIN meli_accounts a ON a.id=s.meli_account_id
             LEFT JOIN meli_orders o ON o.id=s.meli_order_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ' . $orderBy . ',s.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    public function options(): array
    {
        $accountIds = (new BusinessScopeContext())->accountIds();
        $scopeKey = hash('sha256', implode(',', $accountIds));
        $cached = (new ReadModelCacheService())->rememberArray('shipment-options', 'v3-' . $scopeKey, 300, function () use ($accountIds): array {
            $pdo = Database::connection();
            if ($accountIds === []) {
                return ['accounts' => [], 'logistics_groups' => $this->logisticsGroups(), 'logistics' => [], 'statuses' => [], 'substatuses' => []];
            }
            $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
            $accounts = $pdo->prepare(
                'SELECT id,account_name FROM meli_accounts WHERE id IN (' . $placeholders . ') AND status IN ("conectado","connected") ORDER BY account_name'
            );
            $accounts->execute($accountIds);
            return [
                'accounts' => $accounts->fetchAll(PDO::FETCH_ASSOC),
                'logistics_groups' => $this->logisticsGroups(),
                'logistics' => $this->distinctRecent('logistic_type', $accountIds),
                'statuses' => $this->distinctRecent('status', $accountIds),
                'substatuses' => $this->distinctRecent('substatus', $accountIds),
            ];
        });
        return $cached['value'];
    }

    public function logisticsGroups(): array
    {
        return [
            'fulfillment' => ['label' => 'Full / fulfillment', 'types' => ['fulfillment']],
            'self_service' => ['label' => 'Flex / self_service', 'types' => ['self_service']],
            'cross_docking' => ['label' => 'Colecta / cross_docking', 'types' => ['cross_docking']],
            'xd_drop_off' => ['label' => 'Places / xd_drop_off', 'types' => ['xd_drop_off']],
            'drop_off' => ['label' => 'Drop off', 'types' => ['drop_off']],
        ];
    }

    private function where(array $filters): array
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
            $where[] = 's.meli_account_id IN (' . implode(',', $names) . ')';
        }
        if (($filters['preset'] ?? '') === 'pending_today') {
            $today = date('Y-m-d');
            $filters['date_field'] = 'estimated';
            $filters['from'] = $filters['from'] ?: $today;
            $filters['to'] = $filters['to'] ?: $today;
            $where[] = 'COALESCE(s.status, "") NOT IN ("delivered", "cancelled", "not_delivered")';
        }
        $groups = $this->logisticsGroups();
        $group = (string) ($filters['logistics_group'] ?? '');
        if ($group !== '' && isset($groups[$group])) {
            $placeholders = [];
            foreach ($groups[$group]['types'] as $index => $type) {
                $key = 'logistics_group_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $type;
            }
            $where[] = 's.logistic_type IN (' . implode(',', $placeholders) . ')';
        }
        foreach (['logistic_type' => 's.logistic_type', 'status' => 's.status', 'substatus' => 's.substatus'] as $key => $column) {
            if (($filters[$key] ?? '') !== '') {
                $where[] = $column . '=:' . $key;
                $params[$key] = (string) $filters[$key];
            }
        }
        $dateColumn = ($filters['date_field'] ?? 'synced') === 'estimated' ? 's.estimated_delivery' : 's.synced_at';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['from'] ?? ''))) {
            $where[] = $dateColumn . '>=:from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['to'] ?? ''))) {
            $where[] = $dateColumn . '<:to';
            $params['to'] = date('Y-m-d H:i:s', strtotime((string) $filters['to'] . ' +1 day'));
        }
        return [$where, $params];
    }

    private function hasUserFilter(array $filters): bool
    {
        foreach (['account_id', 'logistics_group', 'logistic_type', 'status', 'substatus', 'preset', 'from', 'to'] as $key) {
            if (!empty($filters[$key])) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    /** @param list<int> $accountIds */
    private function distinctRecent(string $column, array $accountIds): array
    {
        if (!in_array($column, ['logistic_type', 'status', 'substatus'], true)) {
            return [];
        }
        if ($accountIds === []) {
            return [];
        }
        $sql = 'SELECT DISTINCT ' . $column . '
                FROM meli_shipments
                WHERE ' . $column . ' IS NOT NULL
                  AND ' . $column . '<>""
                  AND meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')
                  AND synced_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 DAY)
                ORDER BY ' . $column . '
                LIMIT 100';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($accountIds);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function perPage(int $requested): int
    {
        if ($requested === 1000) {
            return 1000;
        }
        return in_array($requested, [25, 50, 100], true) ? $requested : 50;
    }
}
