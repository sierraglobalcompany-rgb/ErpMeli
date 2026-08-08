<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$money = static fn($v) => '$ ' . number_format((float) $v, 0, ',', '.');
$mainImage = $item['thumbnail'] ?: ($pictures[0]['secure_url'] ?? $pictures[0]['url'] ?? null);
?>
<div class="page-head">
  <div>
    <a class="muted" href="<?= View::e($base) ?>/products/meli">← Volver a Productos ML</a>
    <h1><?= View::e($item['title']) ?></h1>
    <p><?= View::e($item['account_name']) ?> · <?= View::e($item['external_item_id']) ?> · sincronizada <?= View::e($item['synced_at'] ?: '—') ?></p>
  </div>
  <div class="page-actions">
    <?php if (!empty($item['permalink'])): ?>
      <a class="btn" target="_blank" rel="noopener" href="<?= View::e($item['permalink']) ?>">Abrir en Mercado Libre</a>
    <?php endif; ?>
    <form method="post" action="<?= View::e($base) ?>/products/internal/from-item">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="meli_item_id" value="<?= (int) $item['id'] ?>">
      <button class="btn primary">Crear producto interno</button>
    </form>
  </div>
</div>

<section class="detail-grid product-detail-grid">
  <article class="panel detail-block product-media-card">
    <?php if ($mainImage): ?>
      <img class="product-main-image" src="<?= View::e($mainImage) ?>" alt="">
    <?php else: ?>
      <div class="product-main-image image-placeholder">Sin imagen</div>
    <?php endif; ?>
    <?php if ($pictures): ?>
      <div class="product-gallery">
        <?php foreach ($pictures as $picture): $url = $picture['secure_url'] ?: $picture['url']; ?>
          <?php if ($url): ?><img src="<?= View::e($url) ?>" alt=""><?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </article>

  <article class="panel detail-block">
    <h3>Publicación</h3>
    <div class="detail-list">
      <div><span>ID ML</span><strong><?= View::e($item['external_item_id']) ?></strong></div>
      <div><span>SKU</span><strong><?= View::e($item['seller_sku'] ?: '—') ?></strong></div>
      <div><span>Estado</span><strong><?= View::e($item['status'] ?: '—') ?></strong></div>
      <div><span>Condición</span><strong><?= View::e($item['condition'] ?: '—') ?></strong></div>
      <div><span>Tipo publicación</span><strong><?= View::e($item['listing_type_id'] ?: '—') ?></strong></div>
      <div><span>Categoría</span><strong><?= View::e($item['category_id'] ?: '—') ?></strong></div>
    </div>
  </article>

  <article class="panel detail-block">
    <h3>Venta e inventario ML</h3>
    <div class="detail-list">
      <div><span>Precio</span><strong><?= $money($item['price']) ?></strong></div>
      <div><span>Precio base</span><strong><?= $money($item['base_price']) ?></strong></div>
      <div><span>Precio original</span><strong><?= $item['original_price'] !== null ? $money($item['original_price']) : '—' ?></strong></div>
      <div><span>Disponible</span><strong><?= (int) $item['available_quantity'] ?></strong></div>
      <div><span>Vendidos</span><strong><?= (int) $item['sold_quantity'] ?></strong></div>
      <div><span>Vínculos activos</span><strong><?= (int) $item['active_links'] ?></strong></div>
    </div>
  </article>
</section>

<section class="panel table-panel">
  <header class="panel-head"><h2>Variaciones</h2><span class="muted"><?= count($variations) ?> registros</span></header>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>ID variación</th><th>SKU</th><th>Precio</th><th>Disponible</th><th>Vendidos</th><th>Atributos</th></tr></thead>
      <tbody>
      <?php if (!$variations): ?><tr><td colspan="6"><div class="empty">Esta publicación no tiene variaciones importadas.</div></td></tr><?php endif; ?>
      <?php foreach ($variations as $variation): ?>
        <tr>
          <td><?= View::e($variation['external_variation_id']) ?></td>
          <td><?= View::e($variation['seller_sku'] ?: '—') ?></td>
          <td><?= $money($variation['price']) ?></td>
          <td><?= (int) $variation['available_quantity'] ?></td>
          <td><?= (int) $variation['sold_quantity'] ?></td>
          <td><?= View::e($variation['attribute_summary'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel detail-block mt-2">
  <header class="panel-head">
    <h2>Descripción Mercado Libre</h2>
    <span class="muted"><?= is_array($description ?? null) ? View::e($description['source_status'] ?? '—') : 'sin cache' ?></span>
  </header>
  <?php $descriptionText = is_array($description ?? null) ? trim((string) ($description['plain_text'] ?? $description['description_text'] ?? '')) : ''; ?>
  <?php if ($descriptionText !== ''): ?>
    <div class="safe-json"><?= nl2br(View::e($descriptionText)) ?></div>
  <?php elseif (is_array($description ?? null) && !empty($description['safe_error_message'])): ?>
    <div class="alert warning">No se pudo importar la descripción: <?= View::e($description['safe_error_message']) ?></div>
  <?php else: ?>
    <div class="empty">Descripción no importada todavía. Use “Revisar actualizaciones” o sincronice publicaciones para llenar la caché local.</div>
  <?php endif; ?>
</section>

<section class="panel table-panel mt-2">
  <header class="panel-head"><h2>Atributos</h2><span class="muted"><?= count($attributes) ?> registros</span></header>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Atributo</th><th>ID</th><th>Valor</th><th>Valor ID</th></tr></thead>
      <tbody>
      <?php if (!$attributes): ?><tr><td colspan="4"><div class="empty">Sin atributos importados.</div></td></tr><?php endif; ?>
      <?php foreach ($attributes as $attribute): ?>
        <tr>
          <td><?= View::e($attribute['name'] ?: '—') ?></td>
          <td><?= View::e($attribute['attribute_id']) ?></td>
          <td><?= View::e($attribute['value_name'] ?: '—') ?></td>
          <td><?= View::e($attribute['value_id'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="alert info mt-2">
  Las imágenes y datos se muestran desde la información ya importada. ERP Meli no sube, reemplaza ni modifica imágenes en Mercado Libre.
</section>
