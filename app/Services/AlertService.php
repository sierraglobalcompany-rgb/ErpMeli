<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class AlertService
{
    public function refresh(): int
    {
        $count = 0;
        $count += $this->alertUnlinkedProducts();
        $count += $this->alertMissingCosts();
        $count += $this->alertApiSignals();
        $count += $this->alertOpenCircuits();
        $count += $this->alertPendingQuestions();
        return $count;
    }

    public function open(): array
    {
        [$scopeSql, $scopeParams] = $this->alertScope('al');
        $stmt = Database::connection()->prepare(
            'SELECT al.*, a.account_name, c.name company_name
             FROM operational_alerts al
             LEFT JOIN meli_accounts a ON a.id=al.meli_account_id
             LEFT JOIN companies c ON c.id=COALESCE(al.company_id,a.company_id)
             WHERE al.status="open" AND ' . $scopeSql . '
             ORDER BY FIELD(al.severity,"critical","warning","info"), al.last_seen_at DESC LIMIT 200'
        );
        $stmt->execute($scopeParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int} */
    public function paginateOpen(int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
        $pdo = Database::connection();
        [$scopeSql, $scopeParams] = $this->alertScope('al');
        $count = $pdo->prepare('SELECT COUNT(*) FROM operational_alerts al WHERE al.status="open" AND ' . $scopeSql);
        $count->execute($scopeParams);
        $total = (int) $count->fetchColumn();
        $items = $pdo->prepare(
            'SELECT al.*,a.account_name,c.name company_name
             FROM operational_alerts al
             LEFT JOIN meli_accounts a ON a.id=al.meli_account_id
             LEFT JOIN companies c ON c.id=COALESCE(al.company_id,a.company_id)
             WHERE al.status="open" AND ' . $scopeSql . '
             ORDER BY FIELD(al.severity,"critical","warning","info"),al.last_seen_at DESC
             LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $items->execute($scopeParams);
        return ['items' => $items->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage)), 'per_page' => $perPage];
    }

    /** @param list<int> $ids */
    public function resolveMany(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_slice($ids, 0, 100)))));
        if ($ids === []) {
            return 0;
        }
        [$scopeSql, $scopeParams] = $this->alertScope('operational_alerts');
        $stmt = Database::connection()->prepare(
            'UPDATE operational_alerts SET status="resolved",resolved_at=NOW()
             WHERE status="open" AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
               AND ' . $scopeSql
        );
        $stmt->execute(array_merge($ids, $scopeParams));
        return $stmt->rowCount();
    }

    public function resolve(int $id): void
    {
        [$scopeSql, $scopeParams] = $this->alertScope('operational_alerts');
        $stmt = Database::connection()->prepare(
            'UPDATE operational_alerts SET status="resolved",resolved_at=NOW() WHERE id=? AND ' . $scopeSql
        );
        $stmt->execute(array_merge([$id], $scopeParams));
    }

    private function alertUnlinkedProducts(): int
    {
        $rows = [];
        foreach ((new BusinessScopeContext())->accountIds() as $accountId) {
            $rows = array_merge($rows, (new UnlinkedProductService())->soldUnlinked(['account_id' => $accountId]));
        }
        $count = 0;
        foreach ($rows as $row) {
            $count += $this->upsert((int) $row['meli_account_id'], null, 'producto_sin_vinculo', 'warning', 'Producto vendido sin vínculo', $row['title'] . ' no está vinculado a bodega.', 'order_item', (int) $row['sample_order_item_id'], $row);
            if (($row['seller_sku'] ?? '') === 'SIN-SKU') {
                $count += $this->upsert((int) $row['meli_account_id'], null, 'sin_sku', 'warning', 'Producto vendido sin SKU', $row['title'] . ' no tiene SKU.', 'order_item', (int) $row['sample_order_item_id'], $row);
            }
        }
        return $count;
    }

    private function alertMissingCosts(): int
    {
        $companies = (new BusinessScopeContext())->companyIds();
        if ($companies === []) {
            return 0;
        }
        $stmt = Database::connection()->prepare(
            'SELECT id,company_id,internal_sku,name FROM internal_products
             WHERE company_id IN (' . implode(',', array_fill(0, count($companies), '?')) . ')
               AND deleted_at IS NULL AND status="active" AND manual_cost<=0 LIMIT 200'
        );
        $stmt->execute($companies);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        foreach ($rows as $row) {
            $count += $this->upsert(null, $row['company_id'] ? (int) $row['company_id'] : null, 'sin_costo', 'warning', 'Producto interno sin costo', $row['internal_sku'] . ' - ' . $row['name'] . ' no tiene costo configurado.', 'internal_product', (int) $row['id'], $row);
        }
        return $count;
    }

    private function alertApiSignals(): int
    {
        $accountIds = (new BusinessScopeContext())->accountIds();
        $rows = (new ApiErrorSummaryService())->recentGrouped(50, $accountIds);
        $allowed = array_fill_keys($accountIds, true);
        $count = 0;
        foreach ($rows as $row) {
            if (!isset($allowed[(int) ($row['meli_account_id'] ?? 0)])) {
                continue;
            }
            if ((int) $row['http_status'] === 429) {
                $count += $this->upsert($row['meli_account_id'] ? (int) $row['meli_account_id'] : null, null, '429', 'critical', 'Rate limit Mercado Libre', 'Se recibieron respuestas 429. Reduzca sincronizaciones o espere backoff.', 'api_error', null, $row);
            }
            if ((int) $row['http_status'] === 404 && str_contains((string) $row['endpoint_path'], '/payments/')) {
                $count += $this->upsert($row['meli_account_id'] ? (int) $row['meli_account_id'] : null, null, '404_repetido', 'warning', 'Pago 404 repetido', 'El detalle de pago no está disponible; se conserva resumen de orden.', 'api_error', null, $row);
            }
        }
        return $count;
    }

    private function alertOpenCircuits(): int
    {
        $count = 0;
        $accountIds = (new BusinessScopeContext())->accountIds();
        $allowed = array_fill_keys($accountIds, true);
        foreach ((new ApiGuardService())->openCircuits(100, $accountIds) as $row) {
            if (!isset($allowed[(int) ($row['meli_account_id'] ?? 0)])) {
                continue;
            }
            $count += $this->upsert($row['meli_account_id'] ? (int) $row['meli_account_id'] : null, null, 'api_circuit_open', 'critical', 'Consultas pausadas por seguridad ML', 'Endpoint ' . $row['endpoint_path'] . ' pausado hasta ' . $row['blocked_until'] . '.', 'api_circuit', (int) $row['id'], $row);
            $count += $this->upsert($row['meli_account_id'] ? (int) $row['meli_account_id'] : null, null, 'sync_bloqueada_por_api', 'critical', 'Sincronización bloqueada por API', 'ERP Meli no hará más consultas mientras el circuito esté abierto.', 'api_circuit', (int) $row['id'], $row);
        }
        return $count;
    }

    private function alertPendingQuestions(): int
    {
        $count = 0;
        foreach ((new QuestionSyncService())->pending() as $row) {
            $count += $this->upsert((int) $row['meli_account_id'], (int) $row['company_id'], 'pregunta_pendiente', 'critical', 'Pregunta pendiente Mercado Libre', mb_substr((string) $row['text'], 0, 180), 'meli_question', (int) $row['id'], $row);
        }
        return $count;
    }

    /** @return array{0:string,1:list<int>} */
    private function alertScope(string $alias): array
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Alias de alcance no válido.');
        }
        $context = new BusinessScopeContext();
        $accounts = $context->accountIds();
        $companies = $context->companyIds();
        if ($accounts === [] && $companies === []) {
            return ['1=0', []];
        }
        $parts = [];
        $params = [];
        if ($accounts !== []) {
            $parts[] = $alias . '.meli_account_id IN (' . implode(',', array_fill(0, count($accounts), '?')) . ')';
            $params = array_merge($params, $accounts);
        }
        if ($companies !== []) {
            $parts[] = '(' . $alias . '.meli_account_id IS NULL AND ' . $alias . '.company_id IN ('
                . implode(',', array_fill(0, count($companies), '?')) . '))';
            $params = array_merge($params, $companies);
        }
        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    private function upsert(?int $accountId, ?int $companyId, string $type, string $severity, string $title, string $message, ?string $entityType, ?int $entityId, array $meta): int
    {
        if ($accountId !== null) {
            $account = (new BusinessScopeContext())->account($accountId, $companyId ?? 0);
            $companyId = (int) $account['company_id'];
        } elseif ($companyId === null || !in_array($companyId, (new BusinessScopeContext())->companyIds(), true)) {
            return 0;
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO operational_alerts (meli_account_id,company_id,alert_type,severity,title,message,entity_type,entity_id,metadata_json)
             VALUES (:account,:company,:type,:severity,:title,:message,:entity_type,:entity_id,:meta)
             ON DUPLICATE KEY UPDATE last_seen_at=NOW(), message=VALUES(message), metadata_json=VALUES(metadata_json)'
        );
        $stmt->execute([
            'account' => $accountId,
            'company' => $companyId,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'message' => mb_substr($message, 0, 700),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return $stmt->rowCount() > 0 ? 1 : 0;
    }
}
