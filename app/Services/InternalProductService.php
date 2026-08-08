<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use RuntimeException;

final class InternalProductService
{
    public function list(array $filters = []): array
    {
        return $this->paginate($filters, 1, 300)['items'];
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int} */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100, 300], true) ? $perPage : 50;
        $where = ['p.deleted_at IS NULL'];
        $params = [];
        $companies = (new BusinessScopeContext())->companyIds();
        if (!empty($filters['company_id'])) {
            $requestedCompany = (int) $filters['company_id'];
            $companies = in_array($requestedCompany, $companies, true) ? [$requestedCompany] : [];
        }
        if ($companies === []) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => $perPage];
        }
        $companyKeys = [];
        foreach ($companies as $index => $companyId) {
            $key = 'scope_company_' . $index;
            $companyKeys[] = ':' . $key;
            $params[$key] = $companyId;
        }
        $where[] = '(p.company_id IN (' . implode(',', $companyKeys) . ') OR p.company_id IS NULL)';
        if (!empty($filters['company_id'])) {
            $where[] = '(p.company_id = :selected_company OR p.company_id IS NULL)';
            $params['selected_company'] = (int) $filters['company_id'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(p.internal_sku LIKE :q_sku OR p.name LIKE :q_name)';
            $term = '%' . trim((string) $filters['q']) . '%';
            $params['q_sku'] = $term;
            $params['q_name'] = $term;
        }
        $readiness = (string) ($filters['readiness'] ?? '');
        if ($readiness === 'missing_cost') {
            $where[] = 'COALESCE(p.manual_cost,0)<=0';
        } elseif ($readiness === 'missing_sku') {
            $where[] = 'TRIM(COALESCE(p.internal_sku,""))=""';
        } elseif ($readiness === 'missing_link') {
            $where[] = 'NOT EXISTS (
                SELECT 1 FROM product_meli_links readiness_link
                WHERE readiness_link.internal_product_id=p.id AND readiness_link.status="active"
            )';
        } elseif ($readiness === 'margin_incomplete') {
            $where[] = 'COALESCE(p.default_profit_percent,0)<=0';
        }
        $count = Database::connection()->prepare('SELECT COUNT(*) FROM internal_products p WHERE ' . implode(' AND ', $where));
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = Database::connection()->prepare(
            'SELECT p.id,p.company_id,p.internal_sku,p.name,p.unit,p.manual_cost,p.default_profit_percent,
                    p.manual_purchase_value,p.suggested_purchase_value,p.status,p.updated_at,c.name company_name,
                    COUNT(DISTINCT l.id) linked_items
             FROM internal_products p
             LEFT JOIN companies c ON c.id = p.company_id
             LEFT JOIN product_meli_links l ON l.internal_product_id = p.id AND l.status = "active"
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY p.id
             ORDER BY p.updated_at DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage,
        ];
    }

    /** @return array<string,int> */
    public function readinessSummary(int $companyId = 0): array
    {
        $where = ['p.deleted_at IS NULL'];
        $params = [];
        $companies = (new BusinessScopeContext())->companyIds();
        if ($companyId > 0) {
            $companies = in_array($companyId, $companies, true) ? [$companyId] : [];
        }
        if ($companies === []) {
            return ['total' => 0, 'missing_cost' => 0, 'missing_sku' => 0, 'missing_link' => 0, 'margin_incomplete' => 0];
        }
        $where[] = '(p.company_id IN (' . implode(',', array_fill(0, count($companies), '?')) . ') OR p.company_id IS NULL)';
        $params = $companies;
        $stmt = Database::connection()->prepare(
            'SELECT
                COUNT(*) total,
                SUM(COALESCE(p.manual_cost,0)<=0) missing_cost,
                SUM(TRIM(COALESCE(p.internal_sku,""))="") missing_sku,
                SUM(COALESCE(p.default_profit_percent,0)<=0) margin_incomplete,
                SUM(NOT EXISTS (
                    SELECT 1 FROM product_meli_links l
                    WHERE l.internal_product_id=p.id AND l.status="active"
                )) missing_link
             FROM internal_products p
             WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => (int) ($row['total'] ?? 0),
            'missing_cost' => (int) ($row['missing_cost'] ?? 0),
            'missing_sku' => (int) ($row['missing_sku'] ?? 0),
            'missing_link' => (int) ($row['missing_link'] ?? 0),
            'margin_incomplete' => (int) ($row['margin_incomplete'] ?? 0),
        ];
    }

    public function create(array $data, string $source = 'manual'): int
    {
        $this->assertCanWrite();
        $sku = trim((string) ($data['internal_sku'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($sku === '' || $name === '') {
            throw new RuntimeException('SKU interno y nombre son obligatorios.');
        }
        $companyId = $this->nullableInt($data['company_id'] ?? null);
        if ($companyId !== null && !in_array($companyId, (new BusinessScopeContext())->companyIds(), true)) {
            throw new RuntimeException('La empresa seleccionada no está disponible para este usuario.');
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO internal_products
             (company_id, internal_sku, name, accounting_name, description, unit, tax_rate, manual_cost, default_profit_percent, min_profit_percent, manual_purchase_value, suggested_purchase_value, image_url, status, notes)
             VALUES (:company,:sku,:name,:accounting,:description,:unit,:tax,:cost,:profit,:min_profit,:purchase,:suggested,:image,:status,:notes)'
        );
        $stmt->execute([
            'company' => $companyId,
            'sku' => $sku,
            'name' => $name,
            'accounting' => trim((string) ($data['accounting_name'] ?? '')) ?: null,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'unit' => trim((string) ($data['unit'] ?? 'unidad')) ?: 'unidad',
            'tax' => (float) ($data['tax_rate'] ?? 0),
            'cost' => (float) ($data['manual_cost'] ?? 0),
            'profit' => (float) ($data['default_profit_percent'] ?? 10),
            'min_profit' => (float) ($data['min_profit_percent'] ?? 0),
            'purchase' => (float) ($data['manual_purchase_value'] ?? 0),
            'suggested' => (float) ($data['suggested_purchase_value'] ?? 0),
            'image' => trim((string) ($data['image_url'] ?? '')) ?: null,
            'status' => in_array(($data['status'] ?? 'active'), ['active', 'inactive'], true) ? $data['status'] : 'active',
            'notes' => trim((string) ($data['notes'] ?? '')) ?: ('Creado desde ' . $source),
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public function createFromMeliItem(int $meliItemId): int
    {
        $scope = (new BusinessScopeContext())->accountPredicate('i.meli_account_id');
        $stmt = Database::connection()->prepare(
            'SELECT i.*, a.company_id
             FROM meli_items i
             JOIN meli_accounts a ON a.id=i.meli_account_id
             WHERE i.id=? AND ' . $scope['sql']
        );
        $stmt->execute(array_merge([$meliItemId], $scope['params']));
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new RuntimeException('Publicación no encontrada.');
        }
        return $this->create([
            'company_id' => $item['company_id'],
            'internal_sku' => $item['seller_sku'] ?: $item['external_item_id'],
            'name' => $item['title'],
            'manual_purchase_value' => 0,
            'image_url' => $item['thumbnail'] ?? null,
        ], 'publicación ML');
    }

    public function createFromOrderItem(int $orderItemId): int
    {
        $scope = (new BusinessScopeContext())->accountPredicate('oi.meli_account_id');
        $stmt = Database::connection()->prepare(
            'SELECT oi.*, a.company_id
             FROM meli_order_items oi
             JOIN meli_accounts a ON a.id=oi.meli_account_id
             WHERE oi.id=? AND ' . $scope['sql']
        );
        $stmt->execute(array_merge([$orderItemId], $scope['params']));
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new RuntimeException('Ítem vendido no encontrado.');
        }
        return $this->create([
            'company_id' => $item['company_id'],
            'internal_sku' => $item['seller_sku'] ?: $item['external_item_id'],
            'name' => $item['title'],
        ], 'orden');
    }

    private function assertCanWrite(): void
    {
        Auth::requireRole('admin', 'operador');
        if (Auth::isTemporary()) {
            throw new RuntimeException('Los usuarios temporales no pueden modificar bodega ni vínculos.');
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }
}
