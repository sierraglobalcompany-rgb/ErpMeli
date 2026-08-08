<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

$base = rtrim((string) \App\Core\Env::get('APP_URL', ''), '/');
$rows = is_array($items['items'] ?? null) ? $items['items'] : [];
$money = static function (mixed $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    return '$ ' . number_format((float) $value, 0, ',', '.');
};
$pageUrl = static function (int $page) use ($base, $catalog, $filters): string {
    $query = [
        'q' => $filters['q'] ?? '',
        'category' => $filters['category_slug'] ?? '',
        'account_id' => $filters['account_id'] ?? 0,
        'company_id' => $filters['company_id'] ?? 0,
        'source_type' => $filters['source_type'] ?? '',
        'status' => $filters['status'] ?? '',
        'stock' => $filters['stock'] ?? '',
        'full' => $filters['full'] ?? '',
        'shipping_method' => $filters['shipping_method'] ?? '',
        'stock_origin' => $filters['stock_origin'] ?? '',
        'sku_state' => $filters['sku_state'] ?? '',
        'image_state' => $filters['image_state'] ?? '',
        'sort' => $filters['sort'] ?? 'relevance',
        'page' => $page,
    ];
    return $base . '/catalogs/' . (int) $catalog['id'] . '?' . http_build_query($query);
};
?>
<div class="section-result-summary">
  <strong><?= (int) ($items['total'] ?? 0) ?> productos</strong>
  <span>Página <?= (int) ($items['page'] ?? 1) ?> de <?= (int) ($items['pages'] ?? 1) ?></span>
</div>
<div class="catalog-private-grid">
  <?php foreach ($rows as $index => $item): ?>
    <?php
    $shipping = json_decode((string) ($item['shipping_methods_json'] ?? '[]'), true);
    $shipping = is_array($shipping) ? array_values(array_filter(array_map('strval', $shipping))) : [];
    ?>
    <article class="catalog-private-card">
      <div class="catalog-thumb">
        <?php if (!empty($item['thumbnail_url'])): ?>
          <img src="<?= View::e((string) $item['thumbnail_url']) ?>" width="160" height="160" loading="<?= $index < 4 ? 'eager' : 'lazy' ?>" decoding="async" alt="">
        <?php else: ?><span>Sin imagen</span><?php endif; ?>
      </div>
      <div class="catalog-private-info">
        <h3><?= View::e((string) ($item['title_snapshot'] ?? 'Producto sin título')) ?></h3>
        <p class="muted">
          <?= ($item['source_type'] ?? 'meli') === 'internal' ? 'Bodega interna' : 'ID ML ' . View::e((string) ($item['external_item_id'] ?? '—')) ?>
          · <?= View::e((string) ($item['account_name'] ?: ($item['company_name'] ?: 'Sin tienda'))) ?>
        </p>
        <div class="catalog-tags">
          <span class="badge blue"><?= ($item['source_type'] ?? 'meli') === 'internal' ? 'Interno' : 'Mercado Libre' ?></span>
          <span class="badge <?= ($item['status'] ?? '') === 'active' ? 'green' : 'amber' ?>"><?= View::e((string) ($item['status'] ?? '—')) ?></span>
          <span class="badge blue"><?= View::e((string) ($item['category_display_name'] ?? $item['category_name'] ?? 'Sin categoría')) ?></span>
          <?php foreach ($shipping as $method): ?><span class="badge"><?= View::e($method) ?></span><?php endforeach; ?>
        </div>
        <div class="mini-grid catalog-mini">
          <div><span>SKU</span><strong><?= View::e((string) ($item['sku_snapshot'] ?: '—')) ?></strong></div>
          <div><span>Precio</span><strong><?= View::e($money($item['price_snapshot'] ?? null)) ?></strong></div>
          <div><span>Stock</span><strong><?= (int) ($item['stock_available'] ?? 0) ?></strong></div>
          <div><span>Detalle</span><strong><?= View::e((string) ($item['stock_detail_status'] ?? 'No identificado')) ?></strong></div>
        </div>
      </div>
      <div class="catalog-card-actions">
        <?php if (!empty($item['permalink'])): ?><a class="btn small" href="<?= View::e((string) $item['permalink']) ?>" target="_blank" rel="noopener">Mercado Libre</a><?php endif; ?>
        <a class="btn small" href="<?= View::e($base) ?>/catalogo/<?= View::e((string) $catalog['slug']) ?>/producto/<?= View::e((string) $item['public_item_key']) ?>" target="_blank" rel="noopener">Ficha</a>
        <form method="post" action="<?= View::e($base) ?>/catalogs/items/toggle">
          <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
          <input type="hidden" name="catalog_id" value="<?= (int) $catalog['id'] ?>">
          <input type="hidden" name="catalog_item_id" value="<?= (int) $item['id'] ?>">
          <input type="hidden" name="source_type" value="<?= View::e((string) ($item['source_type'] ?? 'meli')) ?>">
          <input type="hidden" name="visible" value="<?= (int) ($item['is_visible'] ?? 0) === 1 ? 0 : 1 ?>">
          <button type="submit" class="btn small <?= (int) ($item['is_visible'] ?? 0) === 1 ? 'danger' : '' ?>"><?= (int) ($item['is_visible'] ?? 0) === 1 ? 'Ocultar' : 'Mostrar' ?></button>
        </form>
      </div>
    </article>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><div class="empty">No hay productos con estos filtros.</div><?php endif; ?>
</div>
<?php if ((int) ($items['pages'] ?? 1) > 1): ?>
  <nav class="pagination" aria-label="Paginación de productos">
    <?php if ((int) $items['page'] > 1): ?><a class="btn small" data-async-page href="<?= View::e($pageUrl((int) $items['page'] - 1)) ?>">Anterior</a><?php endif; ?>
    <span class="muted">Página <?= (int) $items['page'] ?> de <?= (int) $items['pages'] ?></span>
    <?php if ((int) $items['page'] < (int) $items['pages']): ?><a class="btn small" data-async-page href="<?= View::e($pageUrl((int) $items['page'] + 1)) ?>">Siguiente</a><?php endif; ?>
  </nav>
<?php endif; ?>
