<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class ProductMatchSuggestionService
{
    public function generateForUnlinked(): int
    {
        $rows = (new UnlinkedProductService())->soldUnlinked();
        $pdo = Database::connection();
        $count = 0;
        $find = $pdo->prepare('SELECT id, internal_sku, name FROM internal_products WHERE deleted_at IS NULL AND (internal_sku=:sku OR name LIKE :title) LIMIT 1');
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO product_match_suggestions
             (meli_account_id,meli_order_item_id,internal_product_id,suggestion_type,confidence,reason,status)
             VALUES (:account,:order_item,:internal,:type,:confidence,:reason,"pending")'
        );
        foreach ($rows as $row) {
            $sku = (string) $row['seller_sku'];
            $title = '%' . mb_substr((string) $row['title'], 0, 60) . '%';
            $find->execute(['sku' => $sku, 'title' => $title]);
            $product = $find->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                continue;
            }
            $type = strcasecmp($sku, (string) $product['internal_sku']) === 0 ? 'sku' : 'title';
            $insert->execute([
                'account' => (int) $row['meli_account_id'],
                'order_item' => (int) $row['sample_order_item_id'],
                'internal' => (int) $product['id'],
                'type' => $type,
                'confidence' => $type === 'sku' ? 95 : 60,
                'reason' => $type === 'sku' ? 'SKU coincide con producto interno.' : 'Título similar al producto interno.',
            ]);
            $count += $insert->rowCount();
        }
        return $count;
    }

    public function pending(): array
    {
        return Database::connection()->query(
            'SELECT s.*, a.account_name, p.internal_sku, p.name internal_name, oi.title order_title, oi.seller_sku
             FROM product_match_suggestions s
             JOIN meli_accounts a ON a.id=s.meli_account_id
             LEFT JOIN internal_products p ON p.id=s.internal_product_id
             LEFT JOIN meli_order_items oi ON oi.id=s.meli_order_item_id
             WHERE s.status="pending"
             ORDER BY s.confidence DESC, s.created_at DESC LIMIT 100'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
