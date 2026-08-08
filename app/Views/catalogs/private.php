<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$shippingLabels = static function (array $item): array {
    $decoded = json_decode((string) ($item['shipping_methods_json'] ?? '[]'), true);
    if (is_array($decoded) && $decoded !== []) {
        return array_values(array_filter(array_map(static fn($row) => (string) ($row['label'] ?? ''), $decoded)));
    }
    return [(int) ($item['is_full'] ?? 0) === 1 ? 'FULL confirmado' : 'Logística no identificada'];
};
$primaryLogistic = static function (array $item): string {
    $type = trim((string) ($item['logistic_type'] ?? ''));
    $mode = trim((string) ($item['shipping_mode'] ?? ''));
    if ($type === '' && $mode === '') {
        return 'No identificado';
    }
    return trim(($mode !== '' ? $mode . ' / ' : '') . ($type !== '' ? $type : 'sin logistic_type'));
};
$shippingConfidence = static function (array $item): string {
    $decoded = json_decode((string) ($item['shipping_methods_json'] ?? '[]'), true);
    if (is_array($decoded) && $decoded !== []) {
        foreach ($decoded as $row) {
            if (($row['confidence'] ?? '') === 'Confirmado por API local') {
                return 'Confirmado por API local';
            }
        }
    }
    return 'No identificado';
};
$stockStatus = static fn(?string $status): string => [
    'confirmed' => 'Confirmado',
    'partial' => 'Parcial',
    'not_available' => 'No disponible',
    'unknown' => 'No confirmado',
][$status ?? 'unknown'] ?? 'No confirmado';
$shippingMethodOptions = is_array($shippingMethods ?? null) ? $shippingMethods : [];
$renderShippingOptions = static function (array $options, string $selected): void {
    foreach ($options as $option) {
        $code = (string) ($option['code'] ?? '');
        if ($code === '') {
            continue;
        }
        $label = (string) ($option['label'] ?? $code);
        $total = (int) ($option['total'] ?? 0);
        $suffix = $total > 0 ? ' (' . $total . ')' : ' (sin productos)';
        ?><option value="<?= View::e($code) ?>" <?= $selected === $code ? 'selected' : '' ?>><?= View::e($label . $suffix) ?></option><?php
    }
};
?>
<div class="page-head">
  <div>
    <a class="muted" href="<?= View::e($base) ?>/catalogs">← Volver a catálogos</a>
    <h1>Catálogo privado</h1>
    <p>Vista interna visual para revisar publicaciones, stock, logística, SKU e imágenes.</p>
  </div>
  <div class="page-actions">
    <?php if ($catalog): ?>
      <a class="btn" href="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>">Administrar</a>
      <a class="btn" href="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>/export?<?= View::e(http_build_query($filters)) ?>">Exportar CSV</a>
    <?php endif; ?>
  </div>
</div>

<section class="panel filter-bar">
  <form class="filters" method="get">
    <div class="field">
      <label>Catálogo</label>
      <select class="input" name="catalog_id">
        <?php foreach ($catalogs as $option): ?>
          <option value="<?= (int) $option['id'] ?>" <?= (int) ($catalogId ?? 0) === (int) $option['id'] ? 'selected' : '' ?>><?= View::e($option['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Buscar</label><input class="input" name="q" value="<?= View::e($filters['q'] ?? '') ?>" placeholder="Título, SKU o ID ML"></div>
    <div class="field"><label>Fuente</label><select class="input" name="source_type"><option value="">Todas</option><option value="meli" <?= ($filters['source_type'] ?? '') === 'meli' ? 'selected' : '' ?>>Mercado Libre</option><option value="internal" <?= ($filters['source_type'] ?? '') === 'internal' ? 'selected' : '' ?>>Bodega interna</option></select></div>
    <div class="field"><label>Empresa</label><select class="input" name="company_id"><option value="0">Todas</option><?php foreach (($companies ?? []) as $company): ?><option value="<?= (int) $company['id'] ?>" <?= (int) ($filters['company_id'] ?? 0) === (int) $company['id'] ? 'selected' : '' ?>><?= View::e($company['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Categoría</label><select class="input" name="category"><option value="">Todas</option><?php foreach ($categories as $cat): ?><option value="<?= View::e($cat['slug']) ?>" <?= ($filters['category_slug'] ?? '') === $cat['slug'] ? 'selected' : '' ?>><?= View::e($cat['display_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Stock</label><select class="input" name="stock"><option value="">Todos</option><option value="in" <?= ($filters['stock'] ?? '') === 'in' ? 'selected' : '' ?>>Con stock</option><option value="out" <?= ($filters['stock'] ?? '') === 'out' ? 'selected' : '' ?>>Sin stock</option></select></div>
    <div class="field"><label>FULL</label><select class="input" name="full"><option value="">Todos</option><option value="yes" <?= ($filters['full'] ?? '') === 'yes' ? 'selected' : '' ?>>FULL</option><option value="no" <?= ($filters['full'] ?? '') === 'no' ? 'selected' : '' ?>>No identificado</option></select></div>
    <div class="field"><label>Métodos disponibles</label><select class="input" name="shipping_method"><option value="">Todos</option><?php $renderShippingOptions($shippingMethodOptions, (string) ($filters['shipping_method'] ?? '')); ?></select></div>
    <div class="field"><label>Origen stock</label><select class="input" name="stock_origin"><option value="">Todos</option><option value="full" <?= ($filters['stock_origin'] ?? '') === 'full' ? 'selected' : '' ?>>Stock FULL</option><option value="non_full" <?= ($filters['stock_origin'] ?? '') === 'non_full' ? 'selected' : '' ?>>Stock no FULL/local</option><option value="unknown" <?= ($filters['stock_origin'] ?? '') === 'unknown' ? 'selected' : '' ?>>Stock sin detalle</option></select></div>
    <div class="field"><label>SKU</label><select class="input" name="sku_state"><option value="">Todos</option><option value="missing" <?= ($filters['sku_state'] ?? '') === 'missing' ? 'selected' : '' ?>>Sin SKU</option></select></div>
    <div class="field"><label>Imagen</label><select class="input" name="image_state"><option value="">Todos</option><option value="missing" <?= ($filters['image_state'] ?? '') === 'missing' ? 'selected' : '' ?>>Sin imagen</option></select></div>
    <div class="field"><label>Vínculo</label><select class="input" name="link_state"><option value="">Todos</option><option value="missing" <?= ($filters['link_state'] ?? '') === 'missing' ? 'selected' : '' ?>>Sin vínculo bodega</option></select></div>
    <button class="btn primary">Filtrar</button>
  </form>
</section>

<?php if (!$catalog): ?>
  <div class="panel empty">Cree un catálogo para usar esta vista.</div>
<?php else: ?>
  <section class="metrics catalog-metrics">
    <?php foreach (['total'=>'Total','active'=>'Activos','paused'=>'Pausados','out_stock'=>'Sin stock','full_items'=>'FULL','without_sku'=>'Sin SKU','without_image'=>'Sin imagen','without_link'=>'Sin vínculo'] as $key=>$label): ?>
      <div class="metric-card compact"><div><div class="metric-label"><?= View::e($label) ?></div><div class="metric-value"><?= (int) ($metrics[$key] ?? 0) ?></div></div></div>
    <?php endforeach; ?>
  </section>
  <section class="panel panel-body">
    <div class="catalog-private-grid">
      <?php foreach ($items['items'] as $item): ?>
        <article class="catalog-private-card">
          <div class="catalog-thumb">
            <?php if (!empty($item['thumbnail_url'])): ?><img src="<?= View::e($item['thumbnail_url']) ?>" alt=""><?php else: ?><span>Sin imagen</span><?php endif; ?>
          </div>
          <div class="catalog-private-info">
            <h3><?= View::e($item['title_snapshot']) ?></h3>
            <p class="muted">
              <?= ($item['source_type'] ?? 'meli') === 'internal' ? 'Bodega interna' : 'ID ML ' . View::e($item['external_item_id']) ?>
              · <?= View::e($item['account_name'] ?: ($item['company_name'] ?: 'Sin empresa')) ?>
            </p>
            <div class="catalog-tags">
              <span class="badge blue"><?= ($item['source_type'] ?? 'meli') === 'internal' ? 'Interno' : 'Mercado Libre' ?></span>
              <span class="badge <?= ($item['status'] ?? '') === 'active' ? 'green' : 'amber' ?>"><?= View::e($item['status'] ?? '—') ?></span>
              <span class="badge blue"><?= View::e($item['category_display_name'] ?? $item['category_name'] ?? 'Sin categoría') ?></span>
              <?php if (($item['source_type'] ?? 'meli') === 'meli'): ?><?php foreach ($shippingLabels($item) as $label): ?><span class="badge"><?= View::e($label) ?></span><?php endforeach; ?><?php endif; ?>
              <?php if ((int) $item['has_variations'] === 1): ?><span class="badge amber">Variaciones disponibles</span><?php endif; ?>
              <?php if (empty($item['thumbnail_url'])): ?><span class="badge red">Sin imagen</span><?php endif; ?>
              <?php if (empty($item['sku_snapshot'])): ?><span class="badge amber">Sin SKU</span><?php endif; ?>
            </div>
            <div class="mini-grid catalog-mini">
              <div><span>SKU</span><strong><?= View::e($item['sku_snapshot'] ?: '—') ?></strong></div>
              <div><span>Precio</span><strong>$ <?= number_format((float) ($item['price_snapshot'] ?? 0), 0, ',', '.') ?></strong></div>
              <div><span>Stock</span><strong><?= (int) $item['stock_available'] ?></strong></div>
              <?php if (($item['source_type'] ?? 'meli') === 'meli'): ?>
                <div><span>FULL</span><strong><?= $item['stock_full'] === null ? '—' : (int) $item['stock_full'] ?></strong></div>
                <div><span>No FULL/local</span><strong><?= $item['stock_non_full'] === null ? '—' : (int) $item['stock_non_full'] ?></strong></div>
                <div><span>Principal</span><strong><?= View::e($primaryLogistic($item)) ?></strong></div>
                <div><span>Disponibles</span><strong><?= View::e(implode(', ', $shippingLabels($item))) ?></strong></div>
                <div><span>Confianza envío</span><strong><?= View::e($shippingConfidence($item)) ?></strong></div>
                <div><span>Confianza stock</span><strong><?= View::e($stockStatus($item['stock_detail_status'] ?? null)) ?></strong></div>
              <?php endif; ?>
            </div>
          </div>
          <div class="catalog-card-actions">
            <?php if (!empty($item['permalink'])): ?><a class="btn small" href="<?= View::e($item['permalink']) ?>" target="_blank" rel="noopener">Mercado Libre</a><?php endif; ?>
            <a class="btn small" href="<?= View::e($base) ?>/catalogo/<?= View::e($catalog['slug']) ?>/producto/<?= View::e($item['public_item_key']) ?>" target="_blank" rel="noopener">Ficha</a>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$items['items']): ?><div class="empty">No hay productos con estos filtros.</div><?php endif; ?>
    </div>
    <div class="pagination mt-2">
      <span class="muted">Página <?= (int) $items['page'] ?> de <?= (int) $items['pages'] ?> · <?= (int) $items['total'] ?> productos</span>
    </div>
  </section>
<?php endif; ?>
