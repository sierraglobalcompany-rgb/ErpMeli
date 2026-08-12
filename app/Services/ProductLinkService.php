<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use RuntimeException;

final class ProductLinkService
{
    public function __construct(private readonly AuthorizedBusinessScope $scope = new AuthorizedBusinessScope()) {}

    public function activeLinks(array $filters = []): array
    {
        $where = ['l.status = "active"'];
        $params = [];
        [$scopeSql, $scopeParams] = $this->accountScope('l.meli_account_id', (int) ($filters['account_id'] ?? 0), 'link_scope');
        $where[] = $scopeSql;
        $params += $scopeParams;
        if (!empty($filters['q'])) {
            $where[] = '(p.internal_sku LIKE :link_sku OR p.name LIKE :link_name OR i.title LIKE :link_title OR i.seller_sku LIKE :link_meli_sku OR i.external_item_id LIKE :link_item)';
            $term = '%' . trim((string) $filters['q']) . '%';
            foreach (['link_sku', 'link_name', 'link_title', 'link_meli_sku', 'link_item'] as $key) {
                $params[$key] = $term;
            }
        }
        $stmt = Database::connection()->prepare(
            'SELECT l.*, p.internal_sku, p.name internal_name, p.manual_cost, p.image_url,
                    i.external_item_id, i.title, i.seller_sku, i.thumbnail, i.price, i.status item_status,
                    a.account_name
             FROM product_meli_links l
             JOIN meli_accounts a ON a.id=l.meli_account_id
             JOIN internal_products p ON p.id=l.internal_product_id AND p.company_id=a.company_id
             JOIN meli_items i ON i.id=l.meli_item_id AND i.meli_account_id=l.meli_account_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY l.updated_at DESC LIMIT 50'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function internalProducts(string $query = ''): array
    {
        [$companySql, $params] = $this->companyScope('company_id', 'product_company');
        $where = ['deleted_at IS NULL', $companySql];
        if ($query !== '') {
            $where[] = '(internal_sku LIKE :internal_sku OR name LIKE :internal_name OR accounting_name LIKE :accounting_name)';
            $term = '%' . $query . '%';
            $params += ['internal_sku' => $term, 'internal_name' => $term, 'accounting_name' => $term];
        }
        $stmt = Database::connection()->prepare(
            'SELECT id, internal_sku, name, accounting_name, manual_cost, image_url, status
             FROM internal_products
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY updated_at DESC LIMIT 80'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function meliItems(array $filters = []): array
    {
        [$scopeSql, $params] = $this->accountScope('i.meli_account_id', (int) ($filters['account_id'] ?? 0), 'item_scope');
        $where = [$scopeSql];
        if (!empty($filters['q'])) {
            $where[] = '(i.title LIKE :item_title OR i.seller_sku LIKE :item_sku OR i.external_item_id LIKE :external_item)';
            $term = '%' . trim((string) $filters['q']) . '%';
            $params['item_title'] = $term;
            $params['item_sku'] = $term;
            $params['external_item'] = $term;
        }
        if (($filters['link_state'] ?? '') === 'unlinked') {
            $where[] = 'l.active_link_id IS NULL';
        } elseif (($filters['link_state'] ?? '') === 'linked') {
            $where[] = 'l.active_link_id IS NOT NULL';
        }
        $stmt = Database::connection()->prepare(
            'SELECT i.id,i.meli_account_id,i.external_item_id,i.title,i.seller_sku,i.thumbnail,i.price,i.status,
                    a.account_name,l.active_link_id
             FROM meli_items i
             JOIN meli_accounts a ON a.id=i.meli_account_id
             LEFT JOIN (
                SELECT meli_account_id,meli_item_id, MAX(id) active_link_id
                FROM product_meli_links
                WHERE status="active"
                GROUP BY meli_account_id,meli_item_id
             ) l ON l.meli_account_id=i.meli_account_id AND l.meli_item_id=i.id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY i.updated_at DESC LIMIT 50'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function link(int $internalProductId, int $meliItemId, int $variationId = 0, float $factor = 1.0, string $source = 'manual'): void
    {
        $this->assertCanWrite();
        if ($internalProductId < 1 || $meliItemId < 1) {
            throw new RuntimeException('Seleccione producto interno y publicación.');
        }
        $factor = round($factor, 4);
        if ($factor < 0.0001) {
            throw new RuntimeException('La cantidad de bodega que descuenta cada venta debe ser mayor que cero.');
        }
        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            [$scopeSql, $scopeParams] = $this->accountScope('i.meli_account_id', 0, 'link_item_scope');
            $item = $pdo->prepare(
                'SELECT i.*,a.company_id
                 FROM meli_items i
                 JOIN meli_accounts a ON a.id=i.meli_account_id
                 WHERE i.id=:id AND ' . $scopeSql . ' FOR UPDATE'
            );
            $item->execute(['id' => $meliItemId] + $scopeParams);
            $meli = $item->fetch(PDO::FETCH_ASSOC);
            if (!$meli) {
                throw new \App\Core\HttpException(404, 'No se encontró la publicación solicitada.');
            }
            [$companySql, $companyParams] = $this->companyScope('company_id', 'link_product_company');
            $product = $pdo->prepare(
                'SELECT * FROM internal_products
                 WHERE id=:id AND deleted_at IS NULL AND status="active"
                   AND ' . $companySql . ' AND company_id=:item_company FOR UPDATE'
            );
            $product->execute(['id' => $internalProductId, 'item_company' => (int) $meli['company_id']] + $companyParams);
            if (!$product->fetch(PDO::FETCH_ASSOC)) {
                throw new \App\Core\HttpException(404, 'No se encontró el producto interno solicitado.');
            }
            $exists = $pdo->prepare('SELECT id FROM product_meli_links WHERE meli_account_id=:account AND meli_item_id=:item AND meli_variation_id=:variation AND status="active" LIMIT 1 FOR UPDATE');
            $exists->execute(['account' => $meli['meli_account_id'], 'item' => $meliItemId, 'variation' => $variationId]);
            if ($exists->fetchColumn()) {
                throw new RuntimeException('Esta publicación/variación ya tiene un vínculo activo.');
            }
            $stmt = $pdo->prepare(
                'INSERT INTO product_meli_links
                 (internal_product_id,meli_account_id,meli_item_id,meli_variation_id,meli_variation_external_id,meli_title_snapshot,meli_sku_snapshot,item_snapshot_json,conversion_factor,status,link_source)
                 VALUES (:internal,:account,:item,:variation,:variation_external,:title,:sku,:snapshot,:factor,"active",:source)'
            );
            $stmt->execute([
                'internal' => $internalProductId,
                'account' => (int) $meli['meli_account_id'],
                'item' => $meliItemId,
                'variation' => max(0, $variationId),
                'variation_external' => $variationId > 0 ? $variationId : null,
                'title' => $meli['title'],
                'sku' => $meli['seller_sku'],
                'snapshot' => json_encode($meli, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'factor' => $factor,
                'source' => in_array($source, ['manual', 'from_item', 'from_order', 'suggested'], true) ? $source : 'manual',
            ]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function updateFactor(int $linkId, float $factor): void
    {
        $this->assertCanWrite();
        $factor = round($factor, 4);
        if ($factor < 0.0001) {
            throw new RuntimeException('La cantidad de bodega que descuenta cada venta debe ser mayor que cero.');
        }
        $this->assertLinkAuthorized($linkId);
        Database::connection()->prepare('UPDATE product_meli_links SET conversion_factor=:factor WHERE id=:id AND status="active"')
            ->execute(['factor' => $factor, 'id' => $linkId]);
    }

    public function unlink(int $linkId): void
    {
        $this->assertCanWrite();
        $this->assertLinkAuthorized($linkId);
        Database::connection()->prepare('UPDATE product_meli_links SET status="inactive", deactivated_at=NOW() WHERE id=:id')->execute(['id' => $linkId]);
    }

    private function assertLinkAuthorized(int $linkId): void
    {
        [$scopeSql, $params] = $this->accountScope('l.meli_account_id', 0, 'existing_link_scope');
        $stmt = Database::connection()->prepare(
            'SELECT l.id FROM product_meli_links l
             JOIN meli_accounts a ON a.id=l.meli_account_id
             JOIN internal_products p ON p.id=l.internal_product_id AND p.company_id=a.company_id
             JOIN meli_items i ON i.id=l.meli_item_id AND i.meli_account_id=l.meli_account_id
             WHERE l.id=:id AND ' . $scopeSql . ' LIMIT 1'
        );
        $stmt->execute(['id' => $linkId] + $params);
        if (!$stmt->fetchColumn()) {
            throw new \App\Core\HttpException(404, 'No se encontró el vínculo solicitado.');
        }
    }

    /** @return array{0:string,1:array<string,int>} */
    private function accountScope(string $column, int $requestedAccountId, string $prefix): array
    {
        $accountIds = $this->scope->accountIds();
        if ($requestedAccountId > 0) {
            if (!in_array($requestedAccountId, $accountIds, true)) {
                throw new \App\Core\HttpException(404, 'No se encontró la cuenta solicitada.');
            }
            return [$column . '=:' . $prefix, [$prefix => $requestedAccountId]];
        }
        if ($accountIds === []) {
            return ['1=0', []];
        }
        $names = [];
        $params = [];
        foreach ($accountIds as $index => $accountId) {
            $name = $prefix . '_' . $index;
            $names[] = ':' . $name;
            $params[$name] = $accountId;
        }
        return [$column . ' IN (' . implode(',', $names) . ')', $params];
    }

    /** @return array{0:string,1:array<string,int>} */
    private function companyScope(string $column, string $prefix): array
    {
        $companyIds = $this->scope->companyIds();
        if ($companyIds === []) {
            return ['1=0', []];
        }
        $names = [];
        $params = [];
        foreach ($companyIds as $index => $companyId) {
            $name = $prefix . '_' . $index;
            $names[] = ':' . $name;
            $params[$name] = $companyId;
        }
        return [$column . ' IN (' . implode(',', $names) . ')', $params];
    }

    private function assertCanWrite(): void
    {
        Auth::requireRole('admin', 'operador');
        if (Auth::isTemporary()) {
            throw new RuntimeException('Los usuarios temporales no pueden modificar vínculos.');
        }
    }
}
