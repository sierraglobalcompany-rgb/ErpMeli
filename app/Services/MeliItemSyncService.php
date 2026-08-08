<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use PDO;
use Throwable;

final class MeliItemSyncService
{
    private MeliApiClient $api;

    public function __construct(private readonly int $accountId)
    {
        $this->api = new MeliApiClient($accountId);
    }

    public function sync(int $limit = 50): int
    {
        $jobs = new MeliItemSyncJobService();
        if ($jobs->isAvailable()) {
            $result = $jobs->processAccount($this->accountId, $limit, microtime(true) + 20);
            return $result['processed'];
        }

        $pdo = Database::connection();
        $lock = new SyncLockService();
        $runs = new SyncRunService();
        $lockId = $lock->acquire($this->accountId, 'items', null, null, 20);
        $runId = $runs->start($this->accountId, 'items');
        $count = 0;
        $syncType = 'items';
        try {
            $sellerId = $this->sellerId();
            $settings = new AppSettingsService();
            $mode = $this->resolvedSearchMode($sellerId, $settings);
            $syncType = $mode === 'scan' ? 'items_scan' : 'items';
            $cursorStmt = $pdo->prepare('SELECT cursor_value FROM meli_sync_offsets WHERE meli_account_id=:account AND sync_type=:sync_type LIMIT 1');
            $cursorStmt->execute(['account' => $this->accountId, 'sync_type' => $syncType]);
            $cursorValue = (string) ($cursorStmt->fetchColumn() ?: '');

            if ($mode === 'scan') {
                $query = ['search_type' => 'scan'];
                if ($cursorValue !== '') {
                    $query['scroll_id'] = $cursorValue;
                }
                $page = $this->api->get('/users/' . $sellerId . '/items/search', $query, ['job_type' => 'items_sync', 'bulk' => true]);
                $nextCursor = (string) ($page['scroll_id'] ?? '');
            } else {
                $offset = max(0, (int) $cursorValue);
                $page = $this->api->get('/users/' . $sellerId . '/items/search', ['offset' => $offset, 'limit' => $limit], ['job_type' => 'items_sync', 'bulk' => true]);
                $nextCursor = (string) ($offset + count(is_array($page['results'] ?? null) ? $page['results'] : []));
            }
            $ids = is_array($page['results'] ?? null) ? $page['results'] : [];
            foreach ($ids as $externalId) {
                try {
                    $detail = $this->fetchRemoteItem((string) $externalId);
                    $description = null;
                    if ($this->inlineDescriptionsEnabled()) {
                        $description = (new MeliItemDescriptionService($this->accountId))->fetchRemoteSnapshot((string) $externalId);
                    }
                    $this->persistItem($detail, $description);
                    $count++;
                } catch (Throwable $e) {
                    Logger::write('warning', 'No se pudo importar una publicación ML.', ['account_id' => $this->accountId, 'item_id' => $externalId, 'error' => $e->getMessage()]);
                }
            }
            $pdo->prepare('INSERT INTO meli_sync_offsets (meli_account_id,sync_type,cursor_value,last_synced_at,status) VALUES (:account,:sync_type,:cursor,NOW(),"complete") ON DUPLICATE KEY UPDATE cursor_value=VALUES(cursor_value),last_synced_at=NOW(),status="complete",error_message=NULL')
                ->execute(['account' => $this->accountId, 'sync_type' => $syncType, 'cursor' => $nextCursor]);
            $pdo->prepare('UPDATE meli_accounts SET last_sync_at=NOW(), last_error=NULL WHERE id=:id')->execute(['id' => $this->accountId]);
            $runs->succeed($runId, $count);
            return $count;
        } catch (Throwable $e) {
            $pdo->prepare('INSERT INTO meli_sync_offsets (meli_account_id,sync_type,status,error_message) VALUES (:account,:sync_type,"error",:error) ON DUPLICATE KEY UPDATE status="error",error_message=VALUES(error_message)')
                ->execute(['account' => $this->accountId, 'sync_type' => $syncType, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            $runs->fail($runId, $count, $e->getMessage());
            throw $e;
        } finally {
            $lock->release($lockId);
        }
    }

    public function fetchRemoteItem(string $externalId): array
    {
        return $this->api->get('/items/' . rawurlencode($externalId), [], ['job_type' => 'items_sync']);
    }

    public function persistApprovedItem(array $item, ?array $description = null): int
    {
        return $this->persistItem($item, $description);
    }

    public function sellerId(): int
    {
        $stmt = Database::connection()->prepare('SELECT meli_user_id FROM meli_accounts WHERE id=:id');
        $stmt->execute(['id' => $this->accountId]);
        return (int) $stmt->fetchColumn();
    }

    private function persistItem(array $item, ?array $description = null): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO meli_items
             (meli_account_id,external_item_id,user_product_id,title,seller_sku,category_id,catalog_product_id,official_store_id,quality_score,price,base_price,original_price,`condition`,available_quantity,sold_quantity,status,permalink,thumbnail,listing_type_id,logistic_type,shipping_mode,raw_json,synced_at)
             VALUES (:account,:external,:user_product,:title,:sku,:category,:catalog,:store,:quality,:price,:base,:original,:condition,:available,:sold,:status,:permalink,:thumbnail,:listing,:logistic,:shipping_mode,:raw,NOW())
             ON DUPLICATE KEY UPDATE user_product_id=VALUES(user_product_id),title=VALUES(title),seller_sku=VALUES(seller_sku),category_id=VALUES(category_id),catalog_product_id=VALUES(catalog_product_id),official_store_id=VALUES(official_store_id),quality_score=VALUES(quality_score),price=VALUES(price),base_price=VALUES(base_price),original_price=VALUES(original_price),`condition`=VALUES(`condition`),available_quantity=VALUES(available_quantity),sold_quantity=VALUES(sold_quantity),status=VALUES(status),permalink=VALUES(permalink),thumbnail=VALUES(thumbnail),listing_type_id=VALUES(listing_type_id),logistic_type=VALUES(logistic_type),shipping_mode=VALUES(shipping_mode),raw_json=VALUES(raw_json),synced_at=NOW(),id=LAST_INSERT_ID(id)'
        );
        $shipping = is_array($item['shipping'] ?? null) ? $item['shipping'] : [];
        $stmt->execute([
            'account' => $this->accountId,
            'external' => (string) ($item['id'] ?? ''),
            'user_product' => $this->userProductId($item),
            'title' => (string) ($item['title'] ?? 'Sin título'),
            'sku' => $this->sellerSku($item),
            'category' => $item['category_id'] ?? null,
            'catalog' => $item['catalog_product_id'] ?? null,
            'store' => $item['official_store_id'] ?? null,
            'quality' => $item['health'] ?? $item['quality_score'] ?? null,
            'price' => (float) ($item['price'] ?? 0),
            'base' => $item['base_price'] ?? null,
            'original' => $item['original_price'] ?? null,
            'condition' => $item['condition'] ?? null,
            'available' => (int) ($item['available_quantity'] ?? 0),
            'sold' => (int) ($item['sold_quantity'] ?? 0),
            'status' => $item['status'] ?? null,
            'permalink' => $item['permalink'] ?? null,
            'thumbnail' => $item['thumbnail'] ?? null,
            'listing' => $item['listing_type_id'] ?? null,
            'logistic' => $shipping['logistic_type'] ?? $shipping['logistic_type_id'] ?? null,
            'shipping_mode' => $shipping['mode'] ?? null,
            'raw' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $itemId = (int) $pdo->lastInsertId();
        $this->persistVariations($itemId, $item['variations'] ?? []);
        $this->persistPictures($itemId, $item['pictures'] ?? []);
        $this->persistAttributes($itemId, $item['attributes'] ?? []);
        if ($description !== null) {
            (new MeliItemDescriptionService($this->accountId))->persistSnapshot(
                $itemId,
                $this->accountId,
                (string) ($item['id'] ?? ''),
                $description
            );
        } elseif ($this->inlineDescriptionsEnabled()) {
            try {
                (new MeliItemDescriptionService($this->accountId))->syncByItemId($itemId, (string) ($item['id'] ?? ''));
            } catch (Throwable $e) {
                Logger::write('warning', 'No se pudo importar la descripción de una publicación ML.', [
                    'account_id' => $this->accountId,
                    'item_id' => (string) ($item['id'] ?? ''),
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $itemId;
    }

    private function persistVariations(int $itemId, array $variations): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO meli_item_variations (meli_item_id,external_variation_id,user_product_id,seller_sku,price,available_quantity,sold_quantity,attribute_summary,raw_json)
             VALUES (:item,:external,:user_product,:sku,:price,:available,:sold,:summary,:raw)
             ON DUPLICATE KEY UPDATE user_product_id=VALUES(user_product_id),seller_sku=VALUES(seller_sku),price=VALUES(price),available_quantity=VALUES(available_quantity),sold_quantity=VALUES(sold_quantity),attribute_summary=VALUES(attribute_summary),raw_json=VALUES(raw_json)'
        );
        foreach ($variations as $variation) {
            $stmt->execute([
                'item' => $itemId,
                'external' => (int) ($variation['id'] ?? 0),
                'user_product' => $this->userProductId($variation),
                'sku' => $this->sellerSku($variation),
                'price' => (float) ($variation['price'] ?? 0),
                'available' => (int) ($variation['available_quantity'] ?? 0),
                'sold' => (int) ($variation['sold_quantity'] ?? 0),
                'summary' => implode(', ', array_filter(
                    array_map(static fn($a): string => trim((string) ($a['name'] ?? '') . ': ' . (string) ($a['value_name'] ?? ''), ' :'), $variation['attribute_combinations'] ?? []),
                    static fn(string $summary): bool => $summary !== ''
                )),
                'raw' => json_encode($variation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    private function persistPictures(int $itemId, array $pictures): void
    {
        Database::connection()->prepare('DELETE FROM meli_item_pictures WHERE meli_item_id=:item')->execute(['item' => $itemId]);
        $stmt = Database::connection()->prepare('INSERT INTO meli_item_pictures (meli_item_id,url,secure_url,position) VALUES (:item,:url,:secure,:position)');
        foreach (array_values($pictures) as $position => $picture) {
            $stmt->execute(['item' => $itemId, 'url' => $picture['url'] ?? null, 'secure' => $picture['secure_url'] ?? null, 'position' => $position]);
        }
    }

    private function persistAttributes(int $itemId, array $attributes): void
    {
        Database::connection()->prepare('DELETE FROM meli_item_attributes WHERE meli_item_id=:item')->execute(['item' => $itemId]);
        $stmt = Database::connection()->prepare('INSERT INTO meli_item_attributes (meli_item_id,attribute_id,name,value_id,value_name,raw_json) VALUES (:item,:attribute,:name,:value_id,:value_name,:raw)');
        foreach ($attributes as $attribute) {
            $stmt->execute([
                'item' => $itemId,
                'attribute' => $attribute['id'] ?? 'unknown',
                'name' => $attribute['name'] ?? null,
                'value_id' => $attribute['value_id'] ?? null,
                'value_name' => $attribute['value_name'] ?? null,
                'raw' => json_encode($attribute, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    private function sellerSku(array $row): ?string
    {
        foreach ($row['attributes'] ?? [] as $attribute) {
            if (in_array($attribute['id'] ?? '', ['SELLER_SKU', 'SKU'], true)) {
                return $attribute['value_name'] ?? null;
            }
        }
        return $row['seller_custom_field'] ?? null;
    }

    private function userProductId(array $row): ?string
    {
        foreach (['user_product_id', 'user_product'] as $key) {
            if (!empty($row[$key]) && is_scalar($row[$key])) {
                return (string) $row[$key];
            }
        }
        if (isset($row['user_product']) && is_array($row['user_product']) && !empty($row['user_product']['id'])) {
            return (string) $row['user_product']['id'];
        }
        return null;
    }

    private function resolvedSearchMode(int $sellerId, AppSettingsService $settings): string
    {
        $mode = $settings->get('items.search_mode', 'auto') ?: 'auto';
        if ($mode === 'offset' || !$settings->bool('items.scan_enabled', true)) {
            return 'offset';
        }
        if ($mode === 'scan') {
            return 'scan';
        }
        try {
            $threshold = max(100, $settings->int('items.scan_threshold', 1000));
            $page = $this->api->get('/users/' . $sellerId . '/items/search', ['offset' => 0, 'limit' => 1], ['job_type' => 'items_sync']);
            $total = (int) ($page['paging']['total'] ?? 0);
            return $total >= $threshold ? 'scan' : 'offset';
        } catch (Throwable) {
            return 'offset';
        }
    }

    private function inlineDescriptionsEnabled(): bool
    {
        return false;
    }
}
