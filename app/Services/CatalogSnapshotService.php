<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class CatalogSnapshotService
{
    public function refresh(int $catalogId): array
    {
        $pdo = Database::connection();
        $catalog = (new CatalogService())->find($catalogId);
        if (!$catalog) {
            throw new \RuntimeException('Catálogo no encontrado.');
        }

        $sourceType = (string) ($catalog['source_type'] ?? 'meli');
        $meliRows = in_array($sourceType, ['meli', 'combined'], true) ? $this->sourceItems($catalog) : [];
        $categoryMap = (new MeliCategoryService())->resolveMany(array_map(static fn(array $row): array => [
            'category_id' => (string) ($row['category_id'] ?? ''),
            'account_id' => (int) ($row['meli_account_id'] ?? 0),
        ], $meliRows));
        $internalRows = in_array($sourceType, ['internal', 'combined'], true) ? $this->sourceInternalItems($catalog) : [];

        $inserted = 0;
        $internalInserted = 0;
        $categories = [];
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE catalogs SET last_refresh_status="updating", last_refresh_message=NULL, last_refresh_diagnostic_id=NULL WHERE id=:id')->execute(['id' => $catalogId]);

            if (in_array($sourceType, ['meli', 'combined'], true)) {
                $itemStmt = $pdo->prepare(
                    'INSERT INTO catalog_items
                     (catalog_id,meli_item_id,meli_account_id,external_item_id,external_variation_id,title_snapshot,sku_snapshot,price_snapshot,price_min,price_max,currency_id,thumbnail_url,permalink,category_id,category_name,category_slug,stock_available,stock_full,stock_non_full,stock_unknown,stock_detail_status,sold_quantity,status,condition_snapshot,listing_type_id,logistic_type,shipping_mode,shipping_methods_json,is_full,full_detection_status,has_variations,variations_count,is_active,is_paused,is_out_of_stock,is_visible,sort_order,last_seen_in_source_at,source_updated_at)
                     VALUES
                     (:catalog,:meli_item,:account,:external,NULL,:title,:sku,:price,:price_min,:price_max,:currency,:thumbnail,:permalink,:category_id,:category_name,:category_slug,:stock,:stock_full,:stock_non_full,:stock_unknown,:stock_detail_status,:sold,:status,:condition,:listing,:logistic,:shipping_mode,:shipping_methods_json,:is_full,:full_status,:has_variations,:variations_count,:is_active,:is_paused,:is_out_of_stock,:is_visible,:sort_order,NOW(),:source_updated)
                     ON DUPLICATE KEY UPDATE
                        title_snapshot=VALUES(title_snapshot), sku_snapshot=VALUES(sku_snapshot), price_snapshot=VALUES(price_snapshot),
                        price_min=VALUES(price_min), price_max=VALUES(price_max), currency_id=VALUES(currency_id),
                        thumbnail_url=VALUES(thumbnail_url), permalink=VALUES(permalink), category_id=VALUES(category_id),
                        category_name=VALUES(category_name), category_slug=VALUES(category_slug), stock_available=VALUES(stock_available),
                        stock_full=VALUES(stock_full), stock_non_full=VALUES(stock_non_full), stock_unknown=VALUES(stock_unknown),
                        stock_detail_status=VALUES(stock_detail_status), shipping_methods_json=VALUES(shipping_methods_json),
                        sold_quantity=VALUES(sold_quantity), status=VALUES(status), condition_snapshot=VALUES(condition_snapshot), listing_type_id=VALUES(listing_type_id),
                        logistic_type=VALUES(logistic_type), shipping_mode=VALUES(shipping_mode), is_full=VALUES(is_full),
                        full_detection_status=VALUES(full_detection_status), has_variations=VALUES(has_variations),
                        variations_count=VALUES(variations_count), is_active=VALUES(is_active), is_paused=VALUES(is_paused),
                        is_out_of_stock=VALUES(is_out_of_stock),
                        is_visible=CASE
                            WHEN visibility_override="hidden" THEN 0
                            WHEN visibility_override="visible" THEN 1
                            ELSE VALUES(is_visible)
                        END,
                        sort_order=COALESCE(manual_sort_order, VALUES(sort_order)),
                        last_seen_in_source_at=NOW(), source_updated_at=VALUES(source_updated_at)'
                );
                foreach ($meliRows as $index => $row) {
                    $snapshot = $this->snapshot($row, $catalog, $index, $categoryMap);
                    $itemStmt->execute($snapshot['params']);
                    $inserted++;
                    $this->addCategory($categories, $snapshot['params']['category_id'], $snapshot['params']['category_name'], $snapshot['params']['category_slug']);
                }
            }

            if (in_array($sourceType, ['internal', 'combined'], true)) {
                $internalStmt = $pdo->prepare(
                    'INSERT INTO catalog_internal_items
                     (catalog_id,internal_product_id,company_id,title_snapshot,sku_snapshot,price_snapshot,currency_id,thumbnail_url,category_id,category_name,category_slug,stock_available,status,is_active,is_out_of_stock,is_visible,sort_order,last_seen_in_source_at,source_updated_at)
                     VALUES
                     (:catalog,:internal_product,:company,:title,:sku,:price,:currency,:thumbnail,:category_id,:category_name,:category_slug,:stock,:status,:is_active,:is_out_of_stock,:is_visible,:sort_order,NOW(),:source_updated)
                     ON DUPLICATE KEY UPDATE
                        company_id=VALUES(company_id), title_snapshot=VALUES(title_snapshot), sku_snapshot=VALUES(sku_snapshot),
                        price_snapshot=VALUES(price_snapshot), currency_id=VALUES(currency_id), thumbnail_url=VALUES(thumbnail_url),
                        category_id=VALUES(category_id), category_name=VALUES(category_name), category_slug=VALUES(category_slug),
                        stock_available=VALUES(stock_available), status=VALUES(status), is_active=VALUES(is_active),
                        is_out_of_stock=VALUES(is_out_of_stock),
                        is_visible=CASE
                            WHEN visibility_override="hidden" THEN 0
                            WHEN visibility_override="visible" THEN 1
                            ELSE VALUES(is_visible)
                        END,
                        sort_order=COALESCE(manual_sort_order, VALUES(sort_order)),
                        last_seen_in_source_at=NOW(), source_updated_at=VALUES(source_updated_at)'
                );
                foreach ($internalRows as $index => $row) {
                    $snapshot = $this->snapshotInternal($row, $catalog, $index + count($meliRows));
                    $internalStmt->execute($snapshot['params']);
                    $internalInserted++;
                    $this->addCategory($categories, $snapshot['params']['category_id'], $snapshot['params']['category_name'], $snapshot['params']['category_slug']);
                }
            }

            $this->refreshCategories($pdo, $catalogId, $categories);
            $message = 'Catálogo actualizado desde datos locales: ' . $inserted . ' publicaciones ML, ' . $internalInserted . ' productos internos.';
            $pdo->prepare('UPDATE catalogs SET last_refreshed_at=NOW(), last_refresh_status="updated", last_refresh_message=:message, last_refresh_diagnostic_id=NULL WHERE id=:id')
                ->execute(['message' => $message, 'id' => $catalogId]);
            $pdo->commit();
            return ['items' => $inserted + $internalInserted, 'meli_items' => $inserted, 'internal_items' => $internalInserted, 'categories' => count($categories)];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $safe = SafeErrorPresenter::report($e, 'No fue posible actualizar el catálogo desde los datos locales.', [
                'module' => 'catalog_snapshot',
                'catalog_id' => $catalogId,
            ]);
            Database::connection()->prepare(
                'UPDATE catalogs
                 SET last_refresh_status="error",last_refresh_message=:message,last_refresh_diagnostic_id=:diagnostic
                 WHERE id=:id'
            )->execute([
                'message' => mb_substr($safe['message'], 0, 500),
                'diagnostic' => $safe['reference'],
                'id' => $catalogId,
            ]);
            throw $e;
        }
    }

    public function resolveCategoriesForCatalog(int $catalogId): array
    {
        $catalog = (new CatalogService())->find($catalogId);
        if (!$catalog) {
            throw new \RuntimeException('Catálogo no encontrado.');
        }
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT ci.category_id, ci.meli_account_id
             FROM catalog_items ci
             WHERE ci.catalog_id=:catalog AND ci.category_id IS NOT NULL AND ci.category_id<>""'
        );
        $stmt->execute(['catalog' => $catalogId]);
        $pairs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $map = (new MeliCategoryService())->resolveMany(array_map(static fn(array $row): array => [
            'category_id' => (string) $row['category_id'],
            'account_id' => (int) $row['meli_account_id'],
        ], $pairs));

        $updated = 0;
        $pending = 0;
        $pdo = Database::connection();
        $update = $pdo->prepare('UPDATE catalog_items SET category_name=:name, category_slug=:slug WHERE catalog_id=:catalog AND category_id=:category_id');
        foreach ($pairs as $pair) {
            $categoryId = (string) $pair['category_id'];
            $name = isset($map[$categoryId]) ? ((new MeliCategoryService())->leafName($map[$categoryId]) ?: 'Sin categoría') : 'Sin categoría';
            if ($name === 'Sin categoría') {
                $pending++;
            } else {
                $updated++;
            }
            $update->execute([
                'name' => $name,
                'slug' => $this->slug($name),
                'catalog' => $catalogId,
                'category_id' => $categoryId,
            ]);
        }
        $this->rebuildCategoriesFromSnapshots($pdo, $catalogId);
        return ['resolved' => $updated, 'pending' => $pending];
    }

    private function sourceItems(array $catalog): array
    {
        $where = ['1=1'];
        $params = [];
        if (($catalog['account_scope'] ?? 'all') === 'single_account' && !empty($catalog['meli_account_id'])) {
            $where[] = 'i.meli_account_id=:account';
            $params['account'] = (int) $catalog['meli_account_id'];
        }
        if (($catalog['source_type'] ?? 'meli') === 'combined') {
            $where[] = 'active_link.active_link_id IS NULL';
        }
        $stmt = Database::connection()->prepare(
            'SELECT i.*, a.account_name,
                    COALESCE(v.variations_count,0) variations_count,
                    v.price_min variation_price_min,
                    v.price_max variation_price_max,
                    v.stock_total variation_stock_total,
                    v.sold_total variation_sold_total,
                    v.first_sku variation_first_sku
             FROM meli_items i
             JOIN meli_accounts a ON a.id=i.meli_account_id
             LEFT JOIN (
                SELECT meli_item_id,
                       COUNT(*) variations_count,
                       MIN(price) price_min,
                       MAX(price) price_max,
                       SUM(available_quantity) stock_total,
                       SUM(sold_quantity) sold_total,
                       MIN(NULLIF(seller_sku, "")) first_sku
                FROM meli_item_variations
                GROUP BY meli_item_id
             ) v ON v.meli_item_id=i.id
             LEFT JOIN (
                SELECT meli_item_id, MAX(id) active_link_id
                FROM product_meli_links
                WHERE status="active"
                GROUP BY meli_item_id
             ) active_link ON active_link.meli_item_id=i.id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY i.updated_at DESC, i.id DESC'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function sourceInternalItems(array $catalog): array
    {
        $where = ['p.deleted_at IS NULL'];
        $params = [];
        if (($catalog['company_scope'] ?? 'all') === 'single_company' && !empty($catalog['company_id'])) {
            $where[] = 'p.company_id=:company';
            $params['company'] = (int) $catalog['company_id'];
        }
        $stmt = Database::connection()->prepare(
            'SELECT p.*, c.name company_name
             FROM internal_products p
             LEFT JOIN companies c ON c.id=p.company_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.updated_at DESC, p.id DESC'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function snapshot(array $row, array $catalog, int $index, array $categoryMap): array
    {
        $raw = json_decode((string) ($row['raw_json'] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];
        $categoryId = (string) ($row['category_id'] ?? ($raw['category_id'] ?? ''));
        $categoryName = $this->categoryName($raw, $categoryId, $categoryMap);
        $categorySlug = $this->slug($categoryName);
        $variations = (int) ($row['variations_count'] ?? 0);
        $priceMin = $variations > 0 && $row['variation_price_min'] !== null ? (float) $row['variation_price_min'] : (float) ($row['price'] ?? 0);
        $priceMax = $variations > 0 && $row['variation_price_max'] !== null ? (float) $row['variation_price_max'] : (float) ($row['price'] ?? 0);
        $stock = $variations > 0 && $row['variation_stock_total'] !== null ? (int) $row['variation_stock_total'] : (int) ($row['available_quantity'] ?? 0);
        $sold = $variations > 0 && $row['variation_sold_total'] !== null ? (int) $row['variation_sold_total'] : (int) ($row['sold_quantity'] ?? 0);
        $logistic = $row['logistic_type'] ?? $raw['shipping']['logistic_type'] ?? $raw['shipping']['logistic_type_id'] ?? null;
        $mode = $row['shipping_mode'] ?? $raw['shipping']['mode'] ?? null;
        $shippingMethods = $this->shippingMethods($logistic !== null ? (string) $logistic : null, $mode !== null ? (string) $mode : null, $raw);
        $methodCodes = array_map(static fn(array $method): string => (string) ($method['code'] ?? ''), $shippingMethods);
        $full = in_array('fulfillment', $methodCodes, true);
        $fullStatus = $full ? 'confirmed' : (($logistic === null && $mode === null && $shippingMethods === []) ? 'unknown' : 'not_full');
        $stockBreakdown = $this->stockBreakdown((int) $row['id'], $stock, $full, $logistic !== null || $mode !== null);
        $status = (string) ($row['status'] ?? '');
        $defaultVisible = $status === 'active' && ((int) ($catalog['include_out_of_stock'] ?? 1) === 1 || $stock > 0);
        return [
            'params' => [
                'catalog' => (int) $catalog['id'],
                'meli_item' => (int) $row['id'],
                'account' => (int) $row['meli_account_id'],
                'external' => (string) $row['external_item_id'],
                'title' => (string) $row['title'],
                'sku' => $row['seller_sku'] ?: ($row['variation_first_sku'] ?: null),
                'price' => (float) ($row['price'] ?? 0),
                'price_min' => $priceMin,
                'price_max' => $priceMax,
                'currency' => $raw['currency_id'] ?? null,
                'thumbnail' => $row['thumbnail'] ?? null,
                'permalink' => $row['permalink'] ?? null,
                'category_id' => $categoryId ?: null,
                'category_name' => $categoryName,
                'category_slug' => $categorySlug,
                'stock' => $stock,
                'stock_full' => $stockBreakdown['full'],
                'stock_non_full' => $stockBreakdown['non_full'],
                'stock_unknown' => $stockBreakdown['unknown'],
                'stock_detail_status' => $stockBreakdown['status'],
                'sold' => $sold,
                'status' => $status ?: null,
                'condition' => $row['condition'] ?? $raw['condition'] ?? null,
                'listing' => $row['listing_type_id'] ?? null,
                'logistic' => $logistic,
                'shipping_mode' => $mode,
                'shipping_methods_json' => json_encode($shippingMethods, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_full' => $full ? 1 : 0,
                'full_status' => $fullStatus,
                'has_variations' => $variations > 0 ? 1 : 0,
                'variations_count' => $variations,
                'is_active' => $status === 'active' ? 1 : 0,
                'is_paused' => $status === 'paused' ? 1 : 0,
                'is_out_of_stock' => $stock <= 0 ? 1 : 0,
                'is_visible' => $defaultVisible ? 1 : 0,
                'sort_order' => $index + 1,
                'source_updated' => $row['synced_at'] ?? $row['updated_at'] ?? null,
            ],
        ];
    }

    private function snapshotInternal(array $row, array $catalog, int $index): array
    {
        $status = (string) ($row['status'] ?? 'active');
        $stock = null;
        $defaultVisible = $status === 'active';
        return [
            'params' => [
                'catalog' => (int) $catalog['id'],
                'internal_product' => (int) $row['id'],
                'company' => !empty($row['company_id']) ? (int) $row['company_id'] : null,
                'title' => (string) ($row['name'] ?? ''),
                'sku' => $row['internal_sku'] ?: null,
                'price' => null,
                'currency' => 'COP',
                'thumbnail' => $row['image_url'] ?? null,
                'category_id' => 'internal',
                'category_name' => 'Bodega interna',
                'category_slug' => 'bodega-interna',
                'stock' => $stock,
                'status' => $status ?: null,
                'is_active' => $status === 'active' ? 1 : 0,
                'is_out_of_stock' => 0,
                'is_visible' => $defaultVisible ? 1 : 0,
                'sort_order' => $index + 1,
                'source_updated' => $row['updated_at'] ?? null,
            ],
        ];
    }

    private function stockBreakdown(int $meliItemId, int $fallbackStock, bool $isFull, bool $hasLogistic): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT location_type, SUM(quantity) quantity
             FROM meli_item_stock_locations
             WHERE meli_item_id=:item
             GROUP BY location_type'
        );
        $stmt->execute(['item' => $meliItemId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows !== []) {
            $full = 0;
            $nonFull = 0;
            $unknown = 0;
            foreach ($rows as $row) {
                $type = strtolower((string) ($row['location_type'] ?? ''));
                $quantity = (int) ($row['quantity'] ?? 0);
                if ($type !== '' && (str_contains($type, 'fulfillment') || str_contains($type, 'full'))) {
                    $full += $quantity;
                } elseif ($type !== '') {
                    $nonFull += $quantity;
                } else {
                    $unknown += $quantity;
                }
            }
            return [
                'full' => $full,
                'non_full' => $nonFull,
                'unknown' => $unknown,
                'status' => $unknown > 0 ? 'partial' : 'confirmed',
            ];
        }

        if ($fallbackStock <= 0) {
            return ['full' => 0, 'non_full' => 0, 'unknown' => 0, 'status' => 'confirmed'];
        }
        if ($isFull) {
            return ['full' => $fallbackStock, 'non_full' => 0, 'unknown' => 0, 'status' => 'confirmed'];
        }
        if ($hasLogistic) {
            return ['full' => 0, 'non_full' => $fallbackStock, 'unknown' => 0, 'status' => 'confirmed'];
        }
        return ['full' => null, 'non_full' => null, 'unknown' => $fallbackStock, 'status' => 'unknown'];
    }

    private function shippingMethods(?string $logisticType, ?string $shippingMode, array $raw): array
    {
        $shipping = is_array($raw['shipping'] ?? null) ? $raw['shipping'] : [];
        $signals = [];
        foreach ([$logisticType, $shippingMode] as $value) {
            if ($value !== null && trim($value) !== '') {
                $signals[] = ['value' => (string) $value, 'source' => 'principal'];
            }
        }
        foreach ($this->shippingSignals($shipping) as $signal) {
            $signals[] = ['value' => $signal, 'source' => 'shipping'];
        }

        $methods = [];
        foreach ($signals as $signal) {
            $code = $this->shippingCode((string) $signal['value']);
            if ($code === null || isset($methods[$code])) {
                continue;
            }
            $methods[$code] = [
                'code' => $code,
                'label' => $this->shippingLabel($code),
                'raw' => (string) $signal['value'],
                'source' => (string) $signal['source'],
                'confidence' => 'Confirmado por API local',
            ];
        }

        $unknown = trim((string) ($logisticType ?: $shippingMode));
        if ($methods === [] && $unknown !== '') {
            return [[
                'code' => $unknown,
                'label' => 'Logística no identificada',
                'raw' => trim((string) $shippingMode . ' ' . (string) $logisticType),
                'source' => 'principal',
                'confidence' => 'No identificado',
            ]];
        }

        return array_values($methods);
    }

    private function shippingSignals(array $shipping): array
    {
        $signals = [];
        $walk = static function ($value) use (&$walk, &$signals): void {
            if (is_array($value)) {
                foreach ($value as $key => $child) {
                    if (is_string($key) && $key !== '') {
                        $signals[] = $key;
                    }
                    $walk($child);
                }
                return;
            }
            if (is_scalar($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    $signals[] = $text;
                }
            }
        };
        $walk($shipping);
        return array_values(array_unique($signals));
    }

    private function shippingCode(string $value): ?string
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', trim($value)));
        if ($normalized === '') {
            return null;
        }
        if (str_contains($normalized, 'fulfillment') || preg_match('/(^|_)full($|_)/', $normalized)) {
            return 'fulfillment';
        }
        if (str_contains($normalized, 'self_service') || preg_match('/(^|_)flex($|_)/', $normalized)) {
            return 'self_service';
        }
        if (str_contains($normalized, 'cross_docking')) {
            return 'cross_docking';
        }
        if (str_contains($normalized, 'xd_drop_off') || preg_match('/(^|_)places($|_)/', $normalized)) {
            return 'xd_drop_off';
        }
        if (str_contains($normalized, 'drop_off')) {
            return 'drop_off';
        }
        return null;
    }

    private function shippingLabel(string $code): string
    {
        return match ($code) {
            'fulfillment' => 'FULL',
            'self_service' => 'Flex',
            'cross_docking' => 'Colecta',
            'xd_drop_off' => 'Places',
            'drop_off' => 'Envío con guía / punto autorizado',
            default => 'Logística no identificada',
        };
    }

    private function addCategory(array &$categories, ?string $externalId, string $name, string $slug): void
    {
        $slug = $slug ?: 'sin-categoria';
        if (!isset($categories[$slug])) {
            $categories[$slug] = [
                'external_category_id' => $externalId,
                'name' => $name ?: 'Sin categoría',
                'slug' => $slug,
                'count' => 0,
            ];
        }
        $categories[$slug]['count']++;
    }

    private function rebuildCategoriesFromSnapshots(PDO $pdo, int $catalogId): void
    {
        $stmt = $pdo->prepare(
            'SELECT category_id, category_name, category_slug, SUM(total) product_count
             FROM (
                SELECT category_id, category_name, category_slug, COUNT(*) total
                FROM catalog_items
                WHERE catalog_id=:catalog_meli
                GROUP BY category_id, category_name, category_slug
                UNION ALL
                SELECT category_id, category_name, category_slug, COUNT(*) total
                FROM catalog_internal_items
                WHERE catalog_id=:catalog_internal
                GROUP BY category_id, category_name, category_slug
             ) categories
             GROUP BY category_id, category_name, category_slug'
        );
        $stmt->execute(['catalog_meli' => $catalogId, 'catalog_internal' => $catalogId]);
        $categories = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $slug = (string) ($row['category_slug'] ?? 'sin-categoria');
            $categories[$slug] = [
                'external_category_id' => $row['category_id'] ?? null,
                'name' => (string) ($row['category_name'] ?? 'Sin categoría'),
                'slug' => $slug,
                'count' => (int) ($row['product_count'] ?? 0),
            ];
        }
        $this->refreshCategories($pdo, $catalogId, $categories);
    }

    private function refreshCategories(PDO $pdo, int $catalogId, array $categories): void
    {
        $pdo->prepare('UPDATE catalog_categories SET product_count=0 WHERE catalog_id=:catalog')->execute(['catalog' => $catalogId]);
        $stmt = $pdo->prepare(
            'INSERT INTO catalog_categories (catalog_id,external_category_id,name,display_name,slug,product_count,is_visible)
             VALUES (:catalog,:external,:name,:display_name,:slug,:count,1)
             ON DUPLICATE KEY UPDATE
                external_category_id=VALUES(external_category_id),
                name=VALUES(name),
                display_name=CASE WHEN renamed_at IS NULL THEN VALUES(display_name) ELSE display_name END,
                product_count=VALUES(product_count)'
        );
        foreach ($categories as $category) {
            $stmt->execute([
                'catalog' => $catalogId,
                'external' => $category['external_category_id'] ?: null,
                'name' => $category['name'],
                'display_name' => $category['name'],
                'slug' => $category['slug'],
                'count' => (int) $category['count'],
            ]);
        }
    }

    private function categoryName(array $raw, string $categoryId, array $categoryMap): string
    {
        $path = $raw['category']['path_from_root'] ?? $raw['path_from_root'] ?? null;
        if (is_array($path) && $path !== []) {
            $last = end($path);
            if (is_array($last) && !empty($last['name'])) {
                return (string) $last['name'];
            }
        }
        if (isset($categoryMap[$categoryId])) {
            $name = (new MeliCategoryService())->leafName($categoryMap[$categoryId]);
            if ($name) {
                return $name;
            }
        }
        if (!empty($raw['category_name']) && !$this->looksLikeMeliCategoryId((string) $raw['category_name'])) {
            return (string) $raw['category_name'];
        }
        if ($categoryId !== '' && !$this->looksLikeMeliCategoryId($categoryId)) {
            return $categoryId;
        }
        return 'Sin categoría';
    }

    private function looksLikeMeliCategoryId(string $value): bool
    {
        return (bool) preg_match('/^[A-Z]{2,4}\d+$/', trim($value));
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: 'sin-categoria';
        return trim($value, '-') ?: 'sin-categoria';
    }
}
