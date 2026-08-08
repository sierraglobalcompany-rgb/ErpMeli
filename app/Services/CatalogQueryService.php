<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class CatalogQueryService
{
    public function categories(int $catalogId, bool $publicOnly = false): array
    {
        if (!$this->hasTable('catalog_categories')) {
            return [];
        }
        $where = ['catalog_id=:catalog', 'product_count>0'];
        if ($publicOnly) {
            $where[] = 'is_visible=1';
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM catalog_categories
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY is_featured DESC, sort_order ASC, display_name ASC'
        );
        $stmt->execute(['catalog' => $catalogId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function publicCategories(array $catalog): array
    {
        $categories = $this->categories((int) $catalog['id'], true);
        $visible = [];
        foreach ($categories as $category) {
            $total = $this->publicItems($catalog, [
                'category_slug' => (string) $category['slug'],
                'per_page' => 1,
            ])['total'] ?? 0;
            if ((int) $total < 1) {
                continue;
            }
            $category['product_count'] = (int) $total;
            $visible[] = $category;
        }
        return $visible;
    }

    public function publicItems(array $catalog, array $filters = []): array
    {
        return $this->items($catalog, $filters, true);
    }

    public function privateItems(array $catalog, array $filters = []): array
    {
        return $this->items($catalog, $filters, false);
    }

    public function shippingMethodOptions(array $catalog, array $filters = [], bool $public = true): array
    {
        if ((string) ($catalog['source_type'] ?? 'meli') === 'internal') {
            return [];
        }

        $current = (string) ($filters['shipping_method'] ?? '');
        $baseFilters = $filters;
        $baseFilters['shipping_method'] = '';
        $options = [];
        foreach ($this->shippingMethodLabels() as $code => $label) {
            $probeFilters = $baseFilters;
            $probeFilters['shipping_method'] = $code;
            $total = (int) (($public ? $this->publicItems($catalog, $probeFilters + ['per_page' => 1]) : $this->privateItems($catalog, $probeFilters + ['per_page' => 1]))['total'] ?? 0);
            if ($total > 0 || $current === $code) {
                $options[$code] = ['code' => $code, 'label' => $label, 'total' => $total];
            }
        }
        return $options;
    }

    public function findPublicItem(array $catalog, string $externalItemId): ?array
    {
        $this->extraWhere = ['meli' => [], 'internal' => []];
        if (str_starts_with($externalItemId, 'interno-') && in_array(($catalog['source_type'] ?? 'meli'), ['internal', 'combined'], true)) {
            $params = $this->internalParams($catalog, [], true) + ['catalog_outer' => (int) $catalog['id'], 'external' => $externalItemId];
            $stmt = Database::connection()->prepare(
                'SELECT * FROM (' . $this->internalSelectSql(true) . ') ci
                 WHERE ci.catalog_id=:catalog_outer AND ci.public_item_key=:external
                 LIMIT 1'
            );
            $stmt->execute($params);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($item) ? $item : null;
        }

        $params = $this->meliParams($catalog, [], true) + ['catalog_outer' => (int) $catalog['id'], 'external' => $externalItemId];
        $stmt = Database::connection()->prepare(
            'SELECT * FROM (' . $this->meliSelectSql(true) . ') ci
             WHERE ci.catalog_id=:catalog_outer AND ci.external_item_id=:external
             LIMIT 1'
        );
        $stmt->execute($params);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item || !$this->isAllowedPublicStatus($catalog, (string) ($item['status'] ?? ''), (int) ($item['stock_available'] ?? 0))) {
            return null;
        }
        return $item;
    }

    public function variations(int $meliItemId): array
    {
        if ($meliItemId < 1) {
            return [];
        }
        $stmt = Database::connection()->prepare('SELECT * FROM meli_item_variations WHERE meli_item_id=:item ORDER BY id ASC');
        $stmt->execute(['item' => $meliItemId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pictures(int $meliItemId): array
    {
        if ($meliItemId < 1) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT *, COALESCE(NULLIF(secure_url,""), NULLIF(url,"")) display_url
             FROM meli_item_pictures
             WHERE meli_item_id=:item
             ORDER BY position ASC, id ASC'
        );
        $stmt->execute(['item' => $meliItemId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $seen = [];
        $pictures = [];
        foreach ($rows as $row) {
            $url = trim((string) ($row['display_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $canonical = preg_replace('/-[A-Z]\.(jpe?g|png|webp)(\?.*)?$/i', '', $url) ?: $url;
            $rank = preg_match('/-O\.(jpe?g|png|webp)(\?.*)?$/i', $url) ? 3
                : (preg_match('/-F\.(jpe?g|png|webp)(\?.*)?$/i', $url) ? 2
                    : (preg_match('/-I\.(jpe?g|png|webp)(\?.*)?$/i', $url) ? 1 : 2));
            if (!isset($seen[$canonical])) {
                $seen[$canonical] = ['index' => count($pictures), 'rank' => $rank];
                $row['image_quality'] = $rank === 1 ? 'thumbnail_only' : 'original';
                $pictures[] = $row;
                continue;
            }
            if ($rank > (int) $seen[$canonical]['rank']) {
                $index = (int) $seen[$canonical]['index'];
                $row['image_quality'] = 'original';
                $pictures[$index] = $row;
                $seen[$canonical]['rank'] = $rank;
            }
        }
        return $pictures;
    }

    public function attributes(int $meliItemId): array
    {
        if ($meliItemId < 1) {
            return [];
        }
        $stmt = Database::connection()->prepare('SELECT * FROM meli_item_attributes WHERE meli_item_id=:item ORDER BY name ASC');
        $stmt->execute(['item' => $meliItemId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function description(int $meliItemId): ?array
    {
        if ($meliItemId < 1 || !$this->hasTable('meli_item_descriptions')) {
            return null;
        }
        $stmt = Database::connection()->prepare(
            'SELECT *
             FROM meli_item_descriptions
             WHERE meli_item_id=:item
             LIMIT 1'
        );
        $stmt->execute(['item' => $meliItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function privateMetrics(int $catalogId): array
    {
        return (new CatalogService())->metrics($catalogId);
    }

    private function items(array $catalog, array $filters, bool $public): array
    {
        $settings = new AppSettingsService();
        $requestedPerPage = (int) ($filters['per_page'] ?? $settings->int($public ? 'catalog.public_page_size' : 'catalog.private_page_size', $public ? 24 : 48));
        $maxPerPage = array_key_exists('per_page', $filters) ? 5000 : 96;
        $perPage = max(1, min($maxPerPage, $requestedPerPage));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        [$unionSql, $params] = $this->unionSql($catalog, $filters, $public);
        $countStmt = Database::connection()->prepare('SELECT COUNT(*) FROM (' . $unionSql . ') ci');
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = Database::connection()->prepare(
            'SELECT * FROM (' . $unionSql . ') ci
             ORDER BY ' . $this->orderBy((string) ($filters['sort'] ?? 'relevance')) . '
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
            'per_page' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    private function unionSql(array $catalog, array $filters, bool $public): array
    {
        $source = (string) ($catalog['source_type'] ?? 'meli');
        $branches = [];
        $params = [];
        $this->extraWhere = ['meli' => [], 'internal' => []];
        if ($this->hasTable('catalog_items') && in_array($source, ['meli', 'combined'], true) && (($filters['source_type'] ?? '') === '' || ($filters['source_type'] ?? '') === 'meli')) {
            $params += $this->meliParams($catalog, $filters, $public);
            $branches[] = $this->meliSelectSql($public);
        }
        if ($this->hasTable('catalog_internal_items') && in_array($source, ['internal', 'combined'], true) && (($filters['source_type'] ?? '') === '' || ($filters['source_type'] ?? '') === 'internal')) {
            $params += $this->internalParams($catalog, $filters, $public);
            $branches[] = $this->internalSelectSql($public);
        }
        if ($branches === []) {
            $branches[] = $this->emptySelectSql();
        }
        return [implode(' UNION ALL ', $branches), $params];
    }

    private function meliSelectSql(bool $public): string
    {
        $where = $this->meliWhere($public);
        $where = array_merge($where, $this->extraWhere['meli'] ?? []);
        $stockFull = $this->selectColumn('catalog_items', 'ci', 'stock_full', 'stock_full', 'NULL');
        $stockNonFull = $this->selectColumn('catalog_items', 'ci', 'stock_non_full', 'stock_non_full', 'NULL');
        $stockUnknown = $this->selectColumn('catalog_items', 'ci', 'stock_unknown', 'stock_unknown', 'NULL');
        $stockDetailStatus = $this->selectColumn('catalog_items', 'ci', 'stock_detail_status', 'stock_detail_status', "'unknown'");
        $logisticType = $this->selectColumn('catalog_items', 'ci', 'logistic_type', 'logistic_type', 'NULL');
        $shippingMode = $this->selectColumn('catalog_items', 'ci', 'shipping_mode', 'shipping_mode', 'NULL');
        $shippingMethodsJson = $this->selectColumn('catalog_items', 'ci', 'shipping_methods_json', 'shipping_methods_json', "'[]'");
        $isFull = $this->selectColumn('catalog_items', 'ci', 'is_full', 'is_full', '0');
        $fullDetectionStatus = $this->selectColumn('catalog_items', 'ci', 'full_detection_status', 'full_detection_status', "'unknown'");
        $conditionSnapshot = $this->selectColumn('catalog_items', 'ci', 'condition_snapshot', 'condition_snapshot', 'NULL');
        return 'SELECT
                    "meli" source_type,
                    ci.id id,
                    ci.id catalog_item_id,
                    NULL catalog_internal_item_id,
                    ci.catalog_id,
                    ci.meli_item_id,
                    NULL internal_product_id,
                    ci.meli_account_id,
                    a.company_id,
                    ci.external_item_id,
                    ci.external_item_id public_item_key,
                    ci.title_snapshot,
                    ci.sku_snapshot,
                    ci.price_snapshot,
                    ci.price_min,
                    ci.price_max,
                    ci.currency_id,
                    ci.thumbnail_url,
                    ci.permalink,
                    ci.category_id,
                    ci.category_name,
                    ci.category_slug,
                    ci.stock_available,
                    ' . $stockFull . ',
                    ' . $stockNonFull . ',
                    ' . $stockUnknown . ',
                    ' . $stockDetailStatus . ',
                    ci.sold_quantity,
                    ci.status,
                    ' . $conditionSnapshot . ',
                    ci.listing_type_id,
                    ' . $logisticType . ',
                    ' . $shippingMode . ',
                    ' . $shippingMethodsJson . ',
                    ' . $isFull . ',
                    ' . $fullDetectionStatus . ',
                    ci.has_variations,
                    ci.variations_count,
                    ci.is_active,
                    ci.is_paused,
                    ci.is_out_of_stock,
                    ci.is_visible,
                    ci.sort_order,
                    ci.manual_sort_order,
                    ci.source_updated_at,
                    ci.updated_at,
                    a.account_name,
                    comp.name company_name,
                    COALESCE(cc.display_name, ci.category_name, "Sin categoría") category_display_name,
                    link.active_link_id
                FROM catalog_items ci
                JOIN meli_accounts a ON a.id=ci.meli_account_id
                LEFT JOIN companies comp ON comp.id=a.company_id
                LEFT JOIN catalog_categories cc ON cc.catalog_id=ci.catalog_id AND cc.slug=ci.category_slug
                LEFT JOIN (
                    SELECT meli_item_id, MAX(id) active_link_id
                    FROM product_meli_links
                    WHERE status="active"
                    GROUP BY meli_item_id
                ) link ON link.meli_item_id=ci.meli_item_id
                WHERE ' . implode(' AND ', $where);
    }

    private function internalSelectSql(bool $public): string
    {
        $where = $this->internalWhere($public);
        $where = array_merge($where, $this->extraWhere['internal'] ?? []);
        return 'SELECT
                    "internal" source_type,
                    cii.id id,
                    NULL catalog_item_id,
                    cii.id catalog_internal_item_id,
                    cii.catalog_id,
                    NULL meli_item_id,
                    cii.internal_product_id,
                    NULL meli_account_id,
                    cii.company_id,
                    CONCAT("interno-", cii.internal_product_id) external_item_id,
                    CONCAT("interno-", cii.internal_product_id) public_item_key,
                    cii.title_snapshot,
                    cii.sku_snapshot,
                    cii.price_snapshot,
                    cii.price_snapshot price_min,
                    cii.price_snapshot price_max,
                    cii.currency_id,
                    cii.thumbnail_url,
                    NULL permalink,
                    cii.category_id,
                    cii.category_name,
                    cii.category_slug,
                    cii.stock_available,
                    NULL stock_full,
                    cii.stock_available stock_non_full,
                    NULL stock_unknown,
                    "confirmed" stock_detail_status,
                    0 sold_quantity,
                    cii.status,
                    NULL condition_snapshot,
                    NULL listing_type_id,
                    NULL logistic_type,
                    NULL shipping_mode,
                    "[]" shipping_methods_json,
                    0 is_full,
                    "unknown" full_detection_status,
                    0 has_variations,
                    0 variations_count,
                    cii.is_active,
                    CASE WHEN cii.status="paused" THEN 1 ELSE 0 END is_paused,
                    cii.is_out_of_stock,
                    cii.is_visible,
                    cii.sort_order,
                    cii.manual_sort_order,
                    cii.source_updated_at,
                    cii.updated_at,
                    NULL account_name,
                    comp.name company_name,
                    COALESCE(cc.display_name, cii.category_name, "Bodega interna") category_display_name,
                    NULL active_link_id
                FROM catalog_internal_items cii
                LEFT JOIN companies comp ON comp.id=cii.company_id
                LEFT JOIN catalog_categories cc ON cc.catalog_id=cii.catalog_id AND cc.slug=cii.category_slug
                WHERE ' . implode(' AND ', $where);
    }

    private function meliWhere(bool $public): array
    {
        $where = ['ci.catalog_id=:meli_catalog'];
        if ($public) {
            if ($this->hasColumn('catalog_items', 'visibility_override')) {
                $where[] = 'COALESCE(ci.visibility_override,"")<>"hidden"';
            }
            $where[] = 'COALESCE(cc.is_visible,1)=1';
        }
        return $where;
    }

    private function internalWhere(bool $public): array
    {
        $where = ['cii.catalog_id=:internal_catalog'];
        if ($public) {
            $where[] = 'cii.is_visible=1';
            $where[] = 'COALESCE(cc.is_visible,1)=1';
            $where[] = 'cii.status="active"';
        }
        return $where;
    }

    private function meliParams(array $catalog, array $filters, bool $public): array
    {
        $params = ['meli_catalog' => (int) $catalog['id']];
        $where = [];
        if ($public) {
            $statuses = $this->publicStatuses($catalog);
            $statusPlaceholders = [];
            foreach ($statuses as $index => $status) {
                $key = 'public_status_' . $index;
                $statusPlaceholders[] = ':' . $key;
                $params[$key] = $status;
            }
            $this->extraWhere['meli'][] = str_replace(
                '__PUBLIC_STATUS_FILTER__',
                'ci.status IN (' . implode(',', $statusPlaceholders ?: [':public_status_0']) . ')',
                '__PUBLIC_STATUS_FILTER__'
            );
            if ($statusPlaceholders === []) {
                $params['public_status_0'] = 'active';
            }
            if ((int) ($catalog['include_out_of_stock'] ?? 0) !== 1) {
                $where[] = 'ci.stock_available>0';
            }
        }
        $this->appendSharedFilters($where, $params, $filters, 'ci', 'meli');
        if ($where !== []) {
            $this->appendWhereToLastBranch('meli', array_merge($this->extraWhere['meli'] ?? [], $where));
        }
        return $params;
    }

    private function internalParams(array $catalog, array $filters, bool $public): array
    {
        $params = ['internal_catalog' => (int) $catalog['id']];
        $where = [];
        $this->appendSharedFilters($where, $params, $filters, 'cii', 'internal');
        if ($public && (int) ($catalog['include_out_of_stock'] ?? 0) !== 1) {
            $where[] = '(cii.stock_available IS NULL OR cii.stock_available>0)';
        }
        if ($where !== []) {
            $this->appendWhereToLastBranch('internal', $where);
        }
        return $params;
    }

    private array $extraWhere = ['meli' => [], 'internal' => []];

    private function appendWhereToLastBranch(string $branch, array $where): void
    {
        $this->extraWhere[$branch] = $where;
    }

    private function appendSharedFilters(array &$where, array &$params, array $filters, string $alias, string $prefix): void
    {
        if (!empty($filters['account_id']) && $prefix === 'meli') {
            $where[] = $alias . '.meli_account_id=:' . $prefix . '_account';
            $params[$prefix . '_account'] = (int) $filters['account_id'];
        }
        if (!empty($filters['company_id'])) {
            $where[] = ($prefix === 'meli' ? 'a.company_id' : $alias . '.company_id') . '=:' . $prefix . '_company';
            $params[$prefix . '_company'] = (int) $filters['company_id'];
        }
        if (!empty($filters['category_slug'])) {
            $where[] = $alias . '.category_slug=:' . $prefix . '_category_slug';
            $params[$prefix . '_category_slug'] = (string) $filters['category_slug'];
        }
        if (!empty($filters['status'])) {
            $where[] = $alias . '.status=:' . $prefix . '_status';
            $params[$prefix . '_status'] = (string) $filters['status'];
        }
        $condition = (string) ($filters['condition'] ?? '');
        if ($condition !== '') {
            if ($prefix === 'meli' && in_array($condition, ['new', 'used'], true)) {
                $where[] = $this->hasColumn('catalog_items', 'condition_snapshot')
                    ? $alias . '.condition_snapshot=:' . $prefix . '_condition'
                    : '1=0';
                $params[$prefix . '_condition'] = $condition;
            } else {
                $where[] = '1=0';
            }
        }
        if (($filters['stock'] ?? '') === 'in') {
            $where[] = 'COALESCE(' . $alias . '.stock_available,1)>0';
        } elseif (($filters['stock'] ?? '') === 'out') {
            $where[] = 'COALESCE(' . $alias . '.stock_available,0)<=0';
        }
        if ($prefix === 'meli') {
            if (($filters['full'] ?? '') === 'yes') {
                $where[] = $this->hasColumn('catalog_items', 'is_full') ? $alias . '.is_full=1' : '1=0';
            } elseif (($filters['full'] ?? '') === 'no') {
                $where[] = $this->hasColumn('catalog_items', 'is_full') ? $alias . '.is_full=0' : '1=1';
            }
            $shippingMethod = (string) ($filters['shipping_method'] ?? '');
            if (in_array($shippingMethod, ['fulfillment', 'self_service', 'cross_docking', 'xd_drop_off', 'drop_off'], true)) {
                $shippingParts = [];
                foreach ($this->shippingMethodNeedles($shippingMethod) as $index => $needle) {
                    $logisticKey = $prefix . '_shipping_method_logistic_' . $index;
                    $modeKey = $prefix . '_shipping_method_mode_' . $index;
                    $likeKey = $prefix . '_shipping_method_like_' . $index;
                    if ($this->hasColumn('catalog_items', 'logistic_type')) {
                        $shippingParts[] = $alias . '.logistic_type=:' . $logisticKey;
                        $params[$logisticKey] = $needle;
                    }
                    if ($this->hasColumn('catalog_items', 'shipping_mode')) {
                        $shippingParts[] = $alias . '.shipping_mode=:' . $modeKey;
                        $params[$modeKey] = $needle;
                    }
                    if ($this->hasColumn('catalog_items', 'shipping_methods_json')) {
                        $shippingParts[] = $alias . '.shipping_methods_json LIKE :' . $likeKey;
                        $params[$likeKey] = '%' . $needle . '%';
                    }
                }
                $where[] = $shippingParts !== [] ? '(' . implode(' OR ', $shippingParts) . ')' : '1=0';
            }
            $stockOrigin = (string) ($filters['stock_origin'] ?? '');
            if ($stockOrigin === 'full') {
                $where[] = $this->hasColumn('catalog_items', 'stock_full') ? 'COALESCE(' . $alias . '.stock_full,0)>0' : '1=0';
            } elseif ($stockOrigin === 'non_full') {
                $where[] = $this->hasColumn('catalog_items', 'stock_non_full') ? 'COALESCE(' . $alias . '.stock_non_full,0)>0' : '1=0';
            } elseif ($stockOrigin === 'unknown') {
                $parts = [];
                if ($this->hasColumn('catalog_items', 'stock_unknown')) {
                    $parts[] = 'COALESCE(' . $alias . '.stock_unknown,0)>0';
                }
                if ($this->hasColumn('catalog_items', 'stock_detail_status')) {
                    $parts[] = $alias . '.stock_detail_status IN ("unknown","partial","not_available")';
                }
                $where[] = $parts !== [] ? '(' . implode(' OR ', $parts) . ')' : '1=1';
            }
        } elseif (($filters['stock_origin'] ?? '') === 'non_full') {
            $where[] = 'COALESCE(' . $alias . '.stock_available,0)>0';
        } elseif (($filters['stock_origin'] ?? '') !== '') {
            $where[] = '1=0';
        }
        if (($filters['sku_state'] ?? '') === 'missing') {
            $where[] = '(' . $alias . '.sku_snapshot IS NULL OR ' . $alias . '.sku_snapshot="")';
        }
        if (($filters['image_state'] ?? '') === 'missing') {
            $where[] = '(' . $alias . '.thumbnail_url IS NULL OR ' . $alias . '.thumbnail_url="")';
        }
        if ($prefix === 'meli' && ($filters['link_state'] ?? '') === 'missing') {
            $where[] = 'link.active_link_id IS NULL';
        }
        if (($filters['q'] ?? '') !== '') {
            $needle = '%' . trim((string) $filters['q']) . '%';
            if ($prefix === 'internal') {
                $where[] = '(' . $alias . '.title_snapshot LIKE :' . $prefix . '_q_title OR ' . $alias . '.sku_snapshot LIKE :' . $prefix . '_q_sku OR CAST(' . $alias . '.internal_product_id AS CHAR) LIKE :' . $prefix . '_q_external)';
            } else {
                $where[] = '(' . $alias . '.title_snapshot LIKE :' . $prefix . '_q_title OR ' . $alias . '.sku_snapshot LIKE :' . $prefix . '_q_sku OR ' . $alias . '.external_item_id LIKE :' . $prefix . '_q_external)';
            }
            $params[$prefix . '_q_title'] = $needle;
            $params[$prefix . '_q_sku'] = $needle;
            $params[$prefix . '_q_external'] = $needle;
        }
    }

    private function emptySelectSql(): string
    {
        return 'SELECT "empty" source_type, NULL id, NULL catalog_item_id, NULL catalog_internal_item_id, NULL catalog_id,
                NULL meli_item_id, NULL internal_product_id, NULL meli_account_id, NULL company_id, NULL external_item_id,
                NULL public_item_key, NULL title_snapshot, NULL sku_snapshot, NULL price_snapshot, NULL price_min,
                NULL price_max, NULL currency_id, NULL thumbnail_url, NULL permalink, NULL category_id,
                NULL category_name, NULL category_slug, NULL stock_available, NULL stock_full, NULL stock_non_full,
                NULL stock_unknown, NULL stock_detail_status, NULL sold_quantity, NULL status, NULL condition_snapshot,
                NULL listing_type_id, NULL logistic_type, NULL shipping_mode, NULL shipping_methods_json, 0 is_full, NULL full_detection_status,
                0 has_variations, 0 variations_count, 0 is_active, 0 is_paused, 0 is_out_of_stock, 0 is_visible,
                0 sort_order, NULL manual_sort_order, NULL source_updated_at, NULL updated_at, NULL account_name,
                NULL company_name, NULL category_display_name, NULL active_link_id WHERE 1=0';
    }

    private function selectColumn(string $table, string $alias, string $column, string $selectAlias, string $fallback): string
    {
        return $this->hasColumn($table, $column)
            ? $alias . '.' . $column . ' ' . $selectAlias
            : $fallback . ' ' . $selectAlias;
    }

    private function hasTable(string $table): bool
    {
        if (!array_key_exists($table, $this->tableCache)) {
            $this->tableCache[$table] = (new SchemaInspectorService())->hasTable($table);
        }
        return $this->tableCache[$table];
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (!array_key_exists($table, $this->columnCache)) {
            $this->columnCache[$table] = (new SchemaInspectorService())->columns($table);
        }
        return array_key_exists($column, $this->columnCache[$table]);
    }

    private function publicStatuses(array $catalog): array
    {
        $decoded = json_decode((string) ($catalog['public_statuses_json'] ?? ''), true);
        if (!is_array($decoded) || $decoded === []) {
            return ['active'];
        }
        return array_values(array_filter(array_map('strval', $decoded)));
    }

    private function shippingMethodLabels(): array
    {
        return [
            'fulfillment' => 'FULL',
            'self_service' => 'Flex',
            'cross_docking' => 'Colecta',
            'xd_drop_off' => 'Places',
            'drop_off' => 'Envío con guía',
        ];
    }

    private function shippingMethodNeedles(string $method): array
    {
        return match ($method) {
            'fulfillment' => ['fulfillment', 'FULL', 'full'],
            'self_service' => ['self_service', 'Flex', 'flex'],
            'cross_docking' => ['cross_docking', 'Colecta', 'colecta'],
            'xd_drop_off' => ['xd_drop_off', 'Places', 'places'],
            'drop_off' => ['drop_off', 'Envío con guía', 'Drop off', 'drop off'],
            default => [$method],
        };
    }

    private function isAllowedPublicStatus(array $catalog, string $status, int $stock): bool
    {
        if (!in_array($status, $this->publicStatuses($catalog), true)) {
            return false;
        }
        return (int) ($catalog['include_out_of_stock'] ?? 0) === 1 || $stock > 0;
    }

    private function orderBy(string $sort): string
    {
        return match ($sort) {
            'price_asc' => 'ci.price_snapshot ASC, ci.title_snapshot ASC',
            'price_desc' => 'ci.price_snapshot DESC, ci.title_snapshot ASC',
            'stock' => 'ci.stock_available DESC, ci.title_snapshot ASC',
            'sold' => 'ci.sold_quantity DESC, ci.title_snapshot ASC',
            'recent' => 'ci.source_updated_at DESC, ci.updated_at DESC',
            default => 'COALESCE(ci.manual_sort_order, ci.sort_order) ASC, ci.title_snapshot ASC',
        };
    }

    /** @var array<string, bool> */
    private array $tableCache = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $columnCache = [];
}
