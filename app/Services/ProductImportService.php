<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use RuntimeException;

final class ProductImportService
{
    public function candidates(int $accountId = 0, int $companyId = 0, string $q = ''): array
    {
        return $this->paginateCandidates($accountId, $companyId, $q, 1, 300)['items'];
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int} */
    public function paginateCandidates(int $accountId = 0, int $companyId = 0, string $q = '', int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100, 300], true) ? $perPage : 50;
        $where = ['1=1'];
        $params = [];
        if ($accountId > 0) {
            $where[] = 'i.meli_account_id=:account';
            $params['account'] = $accountId;
        }
        if ($q !== '') {
            $where[] = '(i.external_item_id=:exact OR i.title LIKE :q OR i.seller_sku LIKE :q)';
            $params['exact'] = $q;
            $params['q'] = '%' . $q . '%';
        }
        $pdo = Database::connection();
        $count = $pdo->prepare('SELECT COUNT(*) FROM meli_items i WHERE ' . implode(' AND ', $where));
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $stmt = $pdo->prepare(
            'SELECT i.*, a.account_name, c.name source_company_name,
                    s.internal_product_id imported_product_id,
                    p.name imported_product_name,
                    p.company_id imported_company_id
             FROM meli_items i
             JOIN meli_accounts a ON a.id=i.meli_account_id
             LEFT JOIN companies c ON c.id=a.company_id
             LEFT JOIN product_import_sources s ON s.source_meli_account_id=i.meli_account_id
                  AND s.external_item_id=i.external_item_id AND s.external_variation_id=0
             LEFT JOIN internal_products p ON p.id=s.internal_product_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY i.updated_at DESC
             LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($companyId > 0) {
            foreach ($rows as &$row) {
                $row['already_imported_to_target'] = (int) ($row['imported_company_id'] ?? 0) === $companyId;
            }
        }
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage)), 'per_page' => $perPage];
    }

    public function importItems(array $meliItemIds, int $targetCompanyId, bool $overwrite = false): array
    {
        $this->assertCanWrite();
        if ($targetCompanyId <= 0) {
            throw new RuntimeException('Seleccione la empresa/bodega destino.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $meliItemIds))));
        if ($ids === []) {
            throw new RuntimeException('Seleccione al menos una publicación para importar.');
        }
        $summary = ['created' => 0, 'existing' => 0, 'updated' => 0];
        foreach ($ids as $id) {
            $result = $this->importOne($id, $targetCompanyId, $overwrite);
            $summary[$result]++;
        }
        return $summary;
    }

    private function importOne(int $meliItemId, int $targetCompanyId, bool $overwrite): string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT i.*, a.company_id source_company_id
             FROM meli_items i JOIN meli_accounts a ON a.id=i.meli_account_id
             WHERE i.id=:id'
        );
        $stmt->execute(['id' => $meliItemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new RuntimeException('Una de las publicaciones seleccionadas no existe en Productos ML.');
        }
        $reference = $this->reference($item);
        $existing = $pdo->prepare(
            'SELECT s.*, p.name,p.internal_sku
             FROM product_import_sources s
             JOIN internal_products p ON p.id=s.internal_product_id
             WHERE s.source_meli_account_id=:account AND s.external_item_id=:external AND s.external_variation_id=0
             LIMIT 1'
        );
        $existing->execute(['account' => $item['meli_account_id'], 'external' => $item['external_item_id']]);
        $source = $existing->fetch(PDO::FETCH_ASSOC);
        if ($source) {
            if ($overwrite) {
                $pdo->prepare(
                    'UPDATE internal_products
                     SET company_id=:company, source_reference=:reference, image_url=COALESCE(image_url,:image), source_snapshot_json=:snapshot
                     WHERE id=:id'
                )->execute([
                    'company' => $targetCompanyId,
                    'reference' => $reference,
                    'image' => $item['thumbnail'] ?? null,
                    'snapshot' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'id' => (int) $source['internal_product_id'],
                ]);
                return 'updated';
            }
            return 'existing';
        }

        $pdo->beginTransaction();
        try {
            $internalSku = $this->uniqueSku($reference, $targetCompanyId);
            $insert = $pdo->prepare(
                'INSERT INTO internal_products
                 (company_id,source_meli_account_id,source_meli_item_id,source_meli_variation_id,internal_sku,source_reference,name,accounting_name,unit,manual_cost,default_profit_percent,manual_purchase_value,suggested_purchase_value,image_url,status,notes,source_snapshot_json)
                 VALUES (:company,:account,:item,0,:sku,:reference,:name,:accounting,"unidad",0,10,0,0,:image,"active",:notes,:snapshot)'
            );
            $insert->execute([
                'company' => $targetCompanyId,
                'account' => (int) $item['meli_account_id'],
                'item' => $meliItemId,
                'sku' => $internalSku,
                'reference' => $reference,
                'name' => (string) $item['title'],
                'accounting' => (string) $item['title'],
                'image' => $item['thumbnail'] ?? null,
                'notes' => 'Importado desde publicación Mercado Libre ' . $item['external_item_id'] . '. Precio ML de referencia: ' . number_format((float) $item['price'], 2, '.', ''),
                'snapshot' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $productId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO product_import_sources
                 (internal_product_id,company_id,source_meli_account_id,source_meli_item_id,source_meli_variation_id,external_item_id,external_variation_id,source_reference,source_title,source_price,source_available_quantity,snapshot_json,imported_by)
                 VALUES (:product,:company,:account,:item,0,:external,0,:reference,:title,:price,:available,:snapshot,:user)'
            )->execute([
                'product' => $productId,
                'company' => $targetCompanyId,
                'account' => (int) $item['meli_account_id'],
                'item' => $meliItemId,
                'external' => (string) $item['external_item_id'],
                'reference' => $reference,
                'title' => (string) $item['title'],
                'price' => (float) $item['price'],
                'available' => (int) $item['available_quantity'],
                'snapshot' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'user' => Auth::id(),
            ]);
            $pdo->commit();
            AuditService::record('import_meli_item_to_internal', 'internal_products', 'products', $productId, (int) $item['meli_account_id'], null, ['external_item_id' => $item['external_item_id'], 'company_id' => $targetCompanyId]);
            return 'created';
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function reference(array $item): string
    {
        $reference = trim((string) ($item['seller_sku'] ?? ''));
        if ($reference === '') {
            $reference = trim((string) ($item['external_item_id'] ?? ''));
        }
        return mb_substr($reference !== '' ? $reference : 'ML-' . (string) ($item['id'] ?? uniqid()), 0, 160);
    }

    private function uniqueSku(string $baseSku, int $companyId): string
    {
        $baseSku = mb_substr(preg_replace('/\s+/', '-', trim($baseSku)) ?: 'ML', 0, 100);
        $sku = $baseSku;
        $counter = 2;
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM internal_products WHERE internal_sku=:sku');
        while (true) {
            $stmt->execute(['sku' => $sku]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $sku;
            }
            $sku = mb_substr($baseSku . '-' . $companyId . '-' . $counter, 0, 120);
            $counter++;
        }
    }

    private function assertCanWrite(): void
    {
        Auth::requireRole('admin', 'operador');
        if (Auth::isTemporary()) {
            throw new RuntimeException('Los usuarios temporales no pueden importar ni modificar bodega.');
        }
    }
}
