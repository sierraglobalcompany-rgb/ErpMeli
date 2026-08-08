<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$publicBase = $base . '/catalogo/' . $catalog['slug'];
$money = static fn($value): string => $value === null ? 'Consultar' : '$ ' . number_format((float) $value, 0, ',', '.');
$cleanQuery = static fn(array $query): array => array_filter($query, static fn($v) => $v !== '' && $v !== null && $v !== 0);
$pageUrl = static function (int $page) use ($publicBase, $filters, $cleanQuery): string {
    $query = $cleanQuery($filters + ['page' => $page]);
    return $publicBase . '?' . http_build_query($query);
};
$queryUrl = static function (array $changes = []) use ($publicBase, $filters, $cleanQuery): string {
    $query = array_merge($filters, $changes);
    unset($query['page']);
    $query = $cleanQuery($query);
    return $publicBase . ($query ? '?' . http_build_query($query) : '');
};
$categoryUrl = static function (string $slug) use ($publicBase, $filters, $cleanQuery): string {
    $query = $filters;
    unset($query['page'], $query['category_slug'], $query['category']);
    $query = $cleanQuery($query);
    return $publicBase . '/categoria/' . rawurlencode($slug) . ($query ? '?' . http_build_query($query) : '');
};
$productUrl = static function (string $itemKey) use ($publicBase, $filters, $cleanQuery): string {
    $query = $filters;
    unset($query['page']);
    $query = $cleanQuery($query);
    return $publicBase . '/producto/' . rawurlencode($itemKey) . ($query ? '?' . http_build_query($query) : '');
};
$statusLabel = static fn(string $status): string => [
    'active' => 'Activo',
    'paused' => 'Pausado',
    'closed' => 'Finalizado',
    'inactive' => 'Inactivo',
    'under_review' => 'En revisión',
][$status] ?? ($status !== '' ? $status : 'Sin estado');
$logisticLabel = static function (array $item): string {
    if ((int) ($item['is_full'] ?? 0) === 1) {
        return 'FULL confirmado';
    }
    $type = trim((string) ($item['logistic_type'] ?? ''));
    $mode = trim((string) ($item['shipping_mode'] ?? ''));
    return match ($type) {
        'fulfillment' => 'FULL confirmado',
        'self_service' => 'Flex',
        'cross_docking' => 'Colecta',
        'xd_drop_off' => 'Places',
        'drop_off' => 'Guía / punto',
        default => $mode !== '' || $type !== '' ? trim($mode . ' ' . $type) : 'Logística no identificada',
    };
};
$shippingLabels = static function (array $item) use ($logisticLabel): array {
    $decoded = json_decode((string) ($item['shipping_methods_json'] ?? '[]'), true);
    if (is_array($decoded) && $decoded !== []) {
        return array_values(array_filter(array_map(static fn($row) => (string) ($row['label'] ?? ''), $decoded)));
    }
    return [$logisticLabel($item)];
};
$stockStatus = static fn(?string $status): string => [
    'confirmed' => 'confirmado',
    'partial' => 'parcial',
    'not_available' => 'no disponible',
    'unknown' => 'sin detalle',
][$status ?? 'unknown'] ?? 'sin detalle';
$advanced = (int) ($catalog['show_public_advanced_filters'] ?? 0) === 1;
$showShippingMethods = (int) ($catalog['show_shipping_methods'] ?? 0) === 1;
$showStockDetail = (int) ($catalog['show_stock_detail_public'] ?? 0) === 1;
$shippingMethodOptions = is_array($shippingMethods ?? null) ? $shippingMethods : [];
$categoryName = static function (array $category): string {
    $name = (string) ($category['display_name'] ?? 'Sin categoría');
    return preg_match('/^[A-Z]{2,4}\d+$/', $name) ? 'Sin categoría' : $name;
};
$filterValue = static fn(string $key): string => (string) ($filters[$key] ?? '');
$activeDataQuality = $filterValue('data_quality');
if ($activeDataQuality === '') {
    if ($filterValue('sku_state') === 'missing') {
        $activeDataQuality = 'missing_sku';
    } elseif ($filterValue('image_state') === 'missing') {
        $activeDataQuality = 'missing_image';
    }
}
$selectGroup = static function (string $title, string $name, array $options, string $selected, string $context): void {
    ?>
    <div class="catalog-filter-section">
      <label for="catalog-<?= View::e($name . '-' . $context) ?>"><?= View::e($title) ?></label>
      <select id="catalog-<?= View::e($name . '-' . $context) ?>" name="<?= View::e($name) ?>">
        <?php foreach ($options as $value => $label): $value = (string) $value; ?>
          <option value="<?= View::e($value) ?>" <?= $selected === $value ? 'selected' : '' ?>><?= View::e((string) $label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php
};
$renderFilters = static function (string $context = 'desktop') use ($advanced, $filters, $companies, $categories, $categoryName, $shippingMethodOptions, $showShippingMethods, $showStockDetail, $selectGroup, $activeDataQuality, $filterValue): void {
    ?>
    <div class="catalog-filter-search">
      <label for="catalog-q-<?= View::e($context) ?>">Buscar</label>
      <input id="catalog-q-<?= View::e($context) ?>" name="q" value="<?= View::e($filters['q'] ?? '') ?>" placeholder="Buscar productos">
    </div>
    <?php if ($advanced): ?>
      <?php $selectGroup('Marketplace', 'source_type', ['' => 'Todos', 'meli' => 'Mercado Libre', 'internal' => 'Bodega interna'], $filterValue('source_type'), $context); ?>
      <div class="catalog-filter-section">
        <label for="catalog-company-<?= View::e($context) ?>">Tienda</label>
        <select id="catalog-company-<?= View::e($context) ?>" name="company_id">
          <option value="0">Todas las tiendas</option>
          <?php foreach (($companies ?? []) as $company): ?>
            <option value="<?= (int) $company['id'] ?>" <?= (int) ($filters['company_id'] ?? 0) === (int) $company['id'] ? 'selected' : '' ?>><?= View::e($company['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="catalog-filter-section">
        <label for="catalog-category-<?= View::e($context) ?>">Categoría</label>
        <select id="catalog-category-<?= View::e($context) ?>" name="category_slug">
          <option value="">Todas las categorías</option>
          <?php foreach ($categories as $category): ?>
            <option value="<?= View::e($category['slug']) ?>" <?= ($filters['category_slug'] ?? '') === $category['slug'] ? 'selected' : '' ?>><?= View::e($categoryName($category)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php $selectGroup('Stock', 'stock', ['' => 'Todos', 'in' => 'Con stock', 'out' => 'Sin stock'], $filterValue('stock'), $context); ?>
      <?php $selectGroup('Estado de publicación', 'status', ['' => 'Todos', 'active' => 'Activos', 'paused' => 'Pausados', 'closed' => 'Finalizados', 'under_review' => 'En revisión'], $filterValue('status'), $context); ?>
      <?php if ($showShippingMethods): ?>
        <?php
        $shippingOptions = ['' => 'Todos'];
        foreach ($shippingMethodOptions as $option) {
            $code = (string) ($option['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $total = (int) ($option['total'] ?? 0);
            $shippingOptions[$code] = (string) ($option['label'] ?? $code) . ($total > 0 ? ' · ' . $total : '');
        }
        $selectGroup('Método de envío', 'shipping_method', $shippingOptions, $filterValue('shipping_method'), $context);
        ?>
      <?php endif; ?>
      <details class="catalog-more-filters" <?= $filterValue('stock_origin') !== '' || $activeDataQuality !== '' || $filterValue('condition') !== '' ? 'open' : '' ?>>
        <summary>Más filtros</summary>
        <div class="catalog-more-filters-body">
          <?php if ($showStockDetail): ?>
            <?php $selectGroup('Origen del stock', 'stock_origin', ['' => 'Todos', 'full' => 'FULL', 'non_full' => 'Bodega/local', 'unknown' => 'Sin detalle'], $filterValue('stock_origin'), $context); ?>
          <?php endif; ?>
          <?php $selectGroup('Calidad de datos', 'data_quality', ['' => 'Todos', 'missing_sku' => 'Sin SKU', 'missing_image' => 'Sin imagen'], $activeDataQuality, $context); ?>
          <?php $selectGroup('Estado del producto', 'condition', ['' => 'Todos', 'new' => 'Nuevo', 'used' => 'Usado'], $filterValue('condition'), $context); ?>
        </div>
      </details>
    <?php endif; ?>
    <div class="catalog-filter-section">
      <label for="catalog-sort-<?= View::e($context) ?>">Ordenar por</label>
      <select id="catalog-sort-<?= View::e($context) ?>" name="sort">
        <option value="relevance">Relevancia</option>
        <option value="price_asc" <?= ($filters['sort'] ?? '') === 'price_asc' ? 'selected' : '' ?>>Menor precio</option>
        <option value="price_desc" <?= ($filters['sort'] ?? '') === 'price_desc' ? 'selected' : '' ?>>Mayor precio</option>
        <option value="stock" <?= ($filters['sort'] ?? '') === 'stock' ? 'selected' : '' ?>>Stock primero</option>
        <option value="sold" <?= ($filters['sort'] ?? '') === 'sold' ? 'selected' : '' ?>>Más vendidos</option>
      </select>
    </div>
    <div class="catalog-filter-actions">
      <a class="catalog-filter-clear" href="<?= View::e(strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '') ?>">Limpiar</a>
      <button>Aplicar filtros</button>
    </div>
    <?php
};
$activeChips = [];
$chipNames = [
    'q' => 'Búsqueda',
    'source_type' => 'Marketplace',
    'company_id' => 'Tienda',
    'category_slug' => 'Categoría',
    'stock' => 'Stock',
    'status' => 'Estado',
    'shipping_method' => 'Envío',
    'stock_origin' => 'Origen stock',
    'data_quality' => 'Calidad',
    'condition' => 'Producto',
    'sort' => 'Orden',
];
$chipLabels = [
    'source_type' => ['meli' => 'Mercado Libre', 'internal' => 'Bodega interna'],
    'stock' => ['in' => 'Con stock', 'out' => 'Sin stock'],
    'status' => ['active' => 'Activos', 'paused' => 'Pausados', 'closed' => 'Finalizados', 'under_review' => 'En revisión'],
    'shipping_method' => ['fulfillment' => 'FULL', 'self_service' => 'Flex', 'cross_docking' => 'Colecta', 'xd_drop_off' => 'Places', 'drop_off' => 'Guía / punto'],
    'stock_origin' => ['full' => 'FULL', 'non_full' => 'Bodega/local', 'unknown' => 'Sin detalle'],
    'data_quality' => ['missing_sku' => 'Sin SKU', 'missing_image' => 'Sin imagen'],
    'condition' => ['new' => 'Nuevo', 'used' => 'Usado'],
    'sort' => ['price_asc' => 'Menor precio', 'price_desc' => 'Mayor precio', 'stock' => 'Stock primero', 'sold' => 'Más vendidos'],
];
foreach ($chipNames as $key => $label) {
    $value = $key === 'data_quality' ? $activeDataQuality : ($filters[$key] ?? '');
    if ($value === '' || $value === null || $value === 0 || ($key === 'sort' && $value === 'relevance')) {
        continue;
    }
    if ($key === 'company_id') {
        foreach (($companies ?? []) as $company) {
            if ((int) $company['id'] === (int) $value) {
                $value = (string) $company['name'];
                break;
            }
        }
    } elseif ($key === 'category_slug') {
        foreach ($categories as $category) {
            if ((string) $category['slug'] === (string) $value) {
                $value = $categoryName($category);
                break;
            }
        }
    } else {
        $value = $chipLabels[$key][(string) $value] ?? (string) $value;
    }
    $activeChips[$key] = $label . ': ' . $value;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="<?= (int) ($catalog['allow_indexing'] ?? 0) === 1 ? 'index,follow' : 'noindex,nofollow' ?>">
  <title><?= View::e($catalog['name']) ?></title>
  <link rel="stylesheet" href="<?= View::e(View::asset($base, 'app.css')) ?>&amp;v=2.11.2">
  <link rel="stylesheet" href="<?= View::e(View::asset($base, 'catalog.css')) ?>&amp;v=2.11.2">
</head>
<body class="catalog-public-body">
  <header class="catalog-public-header">
    <div>
      <h1><?= View::e($catalog['name']) ?></h1>
      <p><?= View::e($catalog['description'] ?: 'Catálogo de productos disponibles') ?></p>
    </div>
    <?php if ((int) ($catalog['allow_public_print'] ?? 0) === 1): ?><a class="catalog-public-print" href="<?= View::e($publicBase) ?>/print?<?= View::e(http_build_query($cleanQuery($filters))) ?>" target="_blank">Imprimir</a><?php endif; ?>
    <div class="catalog-mobile-actions" aria-label="Controles móviles del catálogo">
      <button type="button" data-catalog-mobile-search>Buscar</button>
      <details class="catalog-mobile-panel">
        <summary>Filtros</summary>
        <form method="get" action="<?= View::e($publicBase) ?>" class="catalog-mobile-form catalog-filter-form">
          <?php $renderFilters('mobile'); ?>
        </form>
      </details>
      <details class="catalog-mobile-panel">
        <summary>Categorías</summary>
        <nav class="catalog-mobile-categories" aria-label="Categorías">
          <a class="catalog-category-link <?= empty($filters['category_slug']) ? 'active' : '' ?>" href="<?= View::e($queryUrl(['category_slug' => ''])) ?>">Todas</a>
          <?php foreach ($categories as $category): ?>
            <a class="catalog-category-link <?= ($filters['category_slug'] ?? '') === $category['slug'] ? 'active' : '' ?>" href="<?= View::e($categoryUrl((string) $category['slug'])) ?>">
              <?= View::e($categoryName($category)) ?> <span><?= (int) $category['product_count'] ?></span>
            </a>
          <?php endforeach; ?>
        </nav>
      </details>
    </div>
  </header>
  <main class="catalog-public-shell">
    <aside class="catalog-public-sidebar">
      <form method="get" action="<?= View::e($publicBase) ?>" class="catalog-public-search catalog-filter-form <?= $advanced ? 'catalog-public-search-advanced' : '' ?>">
        <?php $renderFilters('desktop'); ?>
      </form>
      <h2>Categorías</h2>
      <a class="catalog-category-link <?= empty($filters['category_slug']) ? 'active' : '' ?>" href="<?= View::e($queryUrl(['category_slug' => ''])) ?>">Todas</a>
      <?php foreach ($categories as $category): ?>
        <a class="catalog-category-link <?= ($filters['category_slug'] ?? '') === $category['slug'] ? 'active' : '' ?>" href="<?= View::e($categoryUrl((string) $category['slug'])) ?>">
          <?= View::e($categoryName($category)) ?> <span><?= (int) $category['product_count'] ?></span>
        </a>
      <?php endforeach; ?>
    </aside>
    <section class="catalog-public-content">
      <?php if ($activeChips !== []): ?>
        <div class="catalog-active-filters" aria-label="Filtros activos">
          <?php foreach ($activeChips as $key => $label): ?>
            <a href="<?= View::e($queryUrl([$key => '', 'page' => 1])) ?>"><?= View::e($label) ?> <span>×</span></a>
          <?php endforeach; ?>
          <a class="clear" href="<?= View::e($publicBase) ?>">Limpiar todo</a>
        </div>
      <?php endif; ?>
      <div class="catalog-public-count"><?= (int) $items['total'] ?> productos encontrados</div>
      <div class="catalog-public-grid">
        <?php foreach ($items['items'] as $item): ?>
          <article class="catalog-public-card">
            <a class="catalog-public-image" href="<?= View::e($productUrl((string) $item['public_item_key'])) ?>">
              <?php if (!empty($item['thumbnail_url'])): ?><img src="<?= View::e($item['thumbnail_url']) ?>" alt="<?= View::e($item['title_snapshot']) ?>"><?php else: ?><span>Sin imagen</span><?php endif; ?>
            </a>
            <div class="catalog-public-card-body">
              <?php if ((int) ($catalog['show_prices'] ?? 1) === 1): ?>
                <div class="catalog-public-price">
                  <?php if ((float) ($item['price_min'] ?? 0) > 0 && (float) ($item['price_max'] ?? 0) > (float) ($item['price_min'] ?? 0)): ?>
                    Desde <?= View::e($money($item['price_min'])) ?> hasta <?= View::e($money($item['price_max'])) ?>
                  <?php else: ?>
                    <?= View::e($money($item['price_snapshot'])) ?>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <h3><a href="<?= View::e($productUrl((string) $item['public_item_key'])) ?>"><?= View::e($item['title_snapshot']) ?></a></h3>
              <?php if ((int) ($catalog['show_status'] ?? 0) === 1): ?>
                <?php $displayCategory = (string) ($item['category_display_name'] ?? $item['category_name'] ?? 'Sin categoría'); ?>
                <div class="catalog-public-badges">
                  <span><?= ($item['source_type'] ?? 'meli') === 'internal' ? 'Bodega interna' : 'Mercado Libre' ?></span>
                  <span class="<?= ($item['status'] ?? '') === 'active' ? 'ok' : 'warn' ?>"><?= View::e($statusLabel((string) ($item['status'] ?? ''))) ?></span>
                  <span><?= View::e(preg_match('/^[A-Z]{2,4}\d+$/', $displayCategory) ? 'Sin categoría' : $displayCategory) ?></span>
                  <?php if (($item['source_type'] ?? 'meli') === 'meli' && $showShippingMethods): ?><?php foreach ($shippingLabels($item) as $label): ?><span><?= View::e($label) ?></span><?php endforeach; ?><?php endif; ?>
                  <?php if ((int) ($catalog['show_internal_sku'] ?? 0) === 1 && !empty($item['sku_snapshot'])): ?><span>SKU <?= View::e($item['sku_snapshot']) ?></span><?php endif; ?>
                </div>
              <?php endif; ?>
              <div class="catalog-public-meta">
                <?php if ((int) ($catalog['show_stock'] ?? 1) === 1): ?><span><?= (int) $item['stock_available'] > 0 ? 'Stock disponible' : 'Sin stock' ?></span><?php endif; ?>
                <?php if ((int) ($catalog['show_stock'] ?? 1) === 1 && $showStockDetail): ?>
                  <span>FULL <?= $item['stock_full'] === null ? '—' : (int) $item['stock_full'] ?></span>
                  <span>Bodega/local <?= $item['stock_non_full'] === null ? '—' : (int) $item['stock_non_full'] ?></span>
                  <?php if ((int) ($item['stock_unknown'] ?? 0) > 0 || ($item['stock_detail_status'] ?? '') !== 'confirmed'): ?><span>Detalle <?= View::e($stockStatus($item['stock_detail_status'] ?? null)) ?></span><?php endif; ?>
                <?php endif; ?>
                <?php if ((int) $item['has_variations'] === 1): ?><span>Variaciones disponibles</span><?php endif; ?>
              </div>
              <a class="catalog-public-button" href="<?= View::e($productUrl((string) $item['public_item_key'])) ?>">Ver producto</a>
            </div>
          </article>
        <?php endforeach; ?>
        <?php if (!$items['items']): ?><div class="catalog-public-empty">No encontramos productos con esos filtros.</div><?php endif; ?>
      </div>
      <nav class="catalog-public-pagination">
        <?php if ($items['page'] > 1): ?><a href="<?= View::e($pageUrl($items['page'] - 1)) ?>">Anterior</a><?php endif; ?>
        <span>Página <?= (int) $items['page'] ?> de <?= (int) $items['pages'] ?></span>
        <?php if ($items['page'] < $items['pages']): ?><a href="<?= View::e($pageUrl($items['page'] + 1)) ?>">Siguiente</a><?php endif; ?>
      </nav>
    </section>
  </main>
  <script src="<?= View::e(View::asset($base, 'catalog.js')) ?>&amp;v=2.11.2"></script>
</body>
</html>
