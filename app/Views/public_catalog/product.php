<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$publicBase = $base . '/catalogo/' . $catalog['slug'];
$money = static fn($value): string => $value === null ? 'Consultar' : '$ ' . number_format((float) $value, 0, ',', '.');
$pictureUrls = [];
foreach ($pictures as $picture) {
    $url = trim((string) ($picture['display_url'] ?? $picture['secure_url'] ?? $picture['url'] ?? ''));
    if ($url !== '' && !in_array($url, $pictureUrls, true)) {
        $pictureUrls[] = $url;
    }
}
if (!$pictureUrls && !empty($item['thumbnail_url'])) {
    $pictureUrls[] = (string) $item['thumbnail_url'];
}
$mainImage = $pictureUrls[0] ?? null;
$descriptionText = '';
if (isset($description) && is_array($description) && (string) ($description['source_status'] ?? '') === 'confirmed') {
    $descriptionText = trim((string) ($description['plain_text'] ?? $description['description_text'] ?? ''));
}
$backQuery = $_GET;
unset($backQuery['token']);
$backQuery = array_filter($backQuery, static fn($v) => $v !== '' && $v !== null && $v !== 0);
$backUrl = $publicBase . ($backQuery ? '?' . http_build_query($backQuery) : '');
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
        'drop_off' => 'Drop off',
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
    'unknown' => 'no confirmado',
][$status ?? 'unknown'] ?? 'no confirmado';
$showShippingMethods = (int) ($catalog['show_shipping_methods'] ?? 0) === 1;
$showStockDetail = (int) ($catalog['show_stock_detail_public'] ?? 0) === 1;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="<?= (int) ($catalog['allow_indexing'] ?? 0) === 1 ? 'index,follow' : 'noindex,nofollow' ?>">
  <title><?= View::e($item['title_snapshot']) ?> · <?= View::e($catalog['name']) ?></title>
  <link rel="stylesheet" href="<?= View::e(View::asset($base, 'app.css')) ?>&amp;v=2.11.2">
  <link rel="stylesheet" href="<?= View::e(View::asset($base, 'catalog.css')) ?>&amp;v=2.11.2">
</head>
<body class="catalog-public-body">
  <header class="catalog-public-header">
    <div>
      <a class="catalog-back" href="<?= View::e($backUrl) ?>">← Volver al catálogo</a>
      <h1><?= View::e($catalog['name']) ?></h1>
    </div>
  </header>
  <main class="catalog-product-page">
    <section class="catalog-product-media">
      <?php if ($mainImage): ?>
        <button type="button" class="catalog-main-image-button" data-catalog-lightbox-open aria-label="Ampliar imagen de <?= View::e($item['title_snapshot']) ?>">
          <img data-catalog-main-image src="<?= View::e($mainImage) ?>" alt="<?= View::e($item['title_snapshot']) ?>" decoding="async" fetchpriority="high">
        </button>
      <?php else: ?><div class="catalog-public-placeholder">Sin imagen</div><?php endif; ?>
      <?php if ($pictureUrls): ?>
        <div class="catalog-product-gallery" data-catalog-gallery>
          <?php foreach (array_slice($pictureUrls, 0, 10) as $index => $pictureUrl): ?>
            <button type="button" class="catalog-gallery-thumb <?= $index === 0 ? 'active' : '' ?>" data-catalog-gallery-image="<?= View::e($pictureUrl) ?>" aria-label="Ver imagen <?= $index + 1 ?>" aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>">
              <img src="<?= View::e($pictureUrl) ?>" alt="" loading="lazy" decoding="async">
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <section class="catalog-product-info">
      <div class="catalog-public-meta">
        <?php $categoryName = (string) ($item['category_display_name'] ?? $item['category_name'] ?? 'Sin categoría'); ?>
        <span><?= View::e(preg_match('/^[A-Z]{2,4}\d+$/', $categoryName) ? 'Sin categoría' : $categoryName) ?></span>
        <?php if ((int) ($catalog['show_full_badge'] ?? 1) === 1 && (int) $item['is_full'] === 1): ?><span class="full">FULL</span><?php endif; ?>
        <?php if ((int) $item['has_variations'] === 1): ?><span>Variaciones disponibles</span><?php endif; ?>
      </div>
      <?php if ((int) ($catalog['show_status'] ?? 0) === 1): ?>
        <div class="catalog-public-badges">
          <span><?= ($item['source_type'] ?? 'meli') === 'internal' ? 'Bodega interna' : 'Mercado Libre' ?></span>
          <span class="<?= ($item['status'] ?? '') === 'active' ? 'ok' : 'warn' ?>"><?= View::e($statusLabel((string) ($item['status'] ?? ''))) ?></span>
          <?php if (($item['source_type'] ?? 'meli') === 'meli' && $showShippingMethods): ?><?php foreach ($shippingLabels($item) as $label): ?><span><?= View::e($label) ?></span><?php endforeach; ?><?php endif; ?>
        </div>
      <?php endif; ?>
      <h2><?= View::e($item['title_snapshot']) ?></h2>
      <?php if ((int) ($catalog['show_prices'] ?? 1) === 1): ?><div class="catalog-product-price"><?= View::e($money($item['price_snapshot'])) ?></div><?php endif; ?>
      <?php if ((int) ($catalog['show_stock'] ?? 1) === 1): ?><p class="catalog-stock"><?= (int) $item['stock_available'] > 0 ? 'Stock disponible' : 'Sin stock' ?></p><?php endif; ?>
      <?php if ((int) ($catalog['show_stock'] ?? 1) === 1 && $showStockDetail): ?>
        <div class="catalog-public-meta">
          <span>Stock FULL: <?= $item['stock_full'] === null ? '—' : (int) $item['stock_full'] ?></span>
          <span>Stock no FULL/local: <?= $item['stock_non_full'] === null ? '—' : (int) $item['stock_non_full'] ?></span>
          <?php if ((int) ($item['stock_unknown'] ?? 0) > 0 || ($item['stock_detail_status'] ?? '') !== 'confirmed'): ?><span>Detalle <?= View::e($stockStatus($item['stock_detail_status'] ?? null)) ?></span><?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ((int) ($catalog['show_internal_sku'] ?? 0) === 1 && !empty($item['sku_snapshot'])): ?><p class="muted">SKU: <?= View::e($item['sku_snapshot']) ?></p><?php endif; ?>
      <?php if (($item['source_type'] ?? 'meli') === 'meli' && (int) ($catalog['show_meli_link'] ?? 1) === 1 && !empty($item['permalink'])): ?><a class="catalog-public-button" href="<?= View::e($item['permalink']) ?>" target="_blank" rel="noopener">Ver en Mercado Libre</a><?php endif; ?>
      <?php if ($descriptionText !== ''): ?>
        <section class="catalog-product-description">
          <h3>Descripción</h3>
          <div><?= nl2br(View::e($descriptionText)) ?></div>
        </section>
      <?php endif; ?>
      <?php if ($variations): ?>
        <h3>Variaciones disponibles</h3>
        <div class="table-scroll">
          <table class="catalog-public-table">
            <thead><tr><th>Variación</th><th>SKU</th><th>Precio</th><th>Stock</th></tr></thead>
            <tbody>
            <?php foreach ($variations as $variation): ?>
              <tr>
                <td><?= View::e($variation['attribute_summary'] ?: 'Variación ' . $variation['external_variation_id']) ?></td>
                <td><?= View::e($variation['seller_sku'] ?: '—') ?></td>
                <td><?= View::e($money($variation['price'])) ?></td>
                <td><?= (int) $variation['available_quantity'] ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <?php if ($attributes): ?>
        <h3>Características</h3>
        <div class="catalog-attributes">
          <?php foreach (array_slice($attributes, 0, 16) as $attribute): ?><div><span><?= View::e($attribute['name']) ?></span><strong><?= View::e($attribute['value_name'] ?? '—') ?></strong></div><?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>
  <script src="<?= View::e(View::asset($base, 'app.js')) ?>&amp;v=2.11.2"></script>
  <script src="<?= View::e(View::asset($base, 'catalog.js')) ?>&amp;v=2.11.2"></script>
</body>
</html>
