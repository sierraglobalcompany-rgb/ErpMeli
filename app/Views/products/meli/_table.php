<?php

use App\Core\Env;
use App\Core\View;
use App\Services\AppSettingsService;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$items = $pageData['items'] ?? [];
$money = static fn ($value): string => '$ ' . number_format((float) $value, 0, ',', '.');
$query = $_GET;
unset($query['full']);
$settings = new AppSettingsService();
$titleClampEnabled = $settings->bool('ui.products_title_clamp_enabled', true);
$titleTooltipEnabled = $titleClampEnabled && $settings->bool('ui.products_title_tooltip_enabled', true);
$titleTooltipHoverDelay = max(500, min(10000, $settings->int('ui.products_title_tooltip_hover_delay_ms', 2000)));
$titleTooltipFocusDelay = max(0, min(10000, $settings->int('ui.products_title_tooltip_focus_delay_ms', 300)));
$statusLabel = static fn (string $status): string => match ($status) {
    'active' => 'Activa',
    'paused' => 'Pausada',
    'under_review' => 'En revisión',
    'closed' => 'Finalizada',
    default => $status !== '' ? ucfirst(str_replace('_', ' ', $status)) : 'Sin comprobar',
};
?>
<section class="panel table-panel async-result-panel">
  <div class="section-result-summary">
    <strong><?= number_format((int) ($pageData['total'] ?? 0), 0, ',', '.') ?> publicaciones</strong>
    <span>Página <?= (int) ($pageData['page'] ?? 1) ?> de <?= (int) ($pageData['pages'] ?? 1) ?></span>
  </div>
  <div class="table-scroll">
    <table class="data-table progressive-table products-ml-table<?= $titleClampEnabled ? ' products-title-clamp' : '' ?>">
      <caption class="sr-only">Publicaciones Mercado Libre importadas</caption>
      <colgroup>
        <col class="products-col-image">
        <col class="products-col-publication">
        <col class="products-col-account">
        <col class="products-col-sku">
        <col class="products-col-number">
        <col class="products-col-number">
        <col class="products-col-number">
        <col class="products-col-status">
        <col class="products-col-link">
        <col class="products-col-actions">
      </colgroup>
      <thead><tr><th>Imagen</th><th>Publicación</th><th>Cuenta</th><th>SKU</th><th>Precio</th><th>Disponible</th><th>Vendidos</th><th>Estado</th><th>Vínculo</th><th>Acciones</th></tr></thead>
      <tbody>
      <?php if (!$items): ?><tr><td colspan="10"><div class="empty">Aún no hay publicaciones importadas para estos filtros.</div></td></tr><?php endif; ?>
      <?php foreach ($items as $index => $item): ?>
        <tr>
          <td>
            <?php if (!empty($item['thumbnail'])): ?>
              <img class="product-thumb" src="<?= View::e($item['thumbnail']) ?>" width="64" height="64" loading="lazy" decoding="async" referrerpolicy="no-referrer" alt="">
            <?php else: ?><span class="product-thumb empty-thumb">Sin imagen</span><?php endif; ?>
          </td>
          <td class="product-publication-cell">
            <a
              class="link product-title-link"
              href="<?= View::e($base) ?>/products/meli/show?id=<?= (int) $item['id'] ?>"
              <?= $titleTooltipEnabled ? 'data-product-title-tooltip' : '' ?>
              <?= $titleTooltipEnabled ? 'data-tooltip-hover-delay="' . $titleTooltipHoverDelay . '"' : '' ?>
              <?= $titleTooltipEnabled ? 'data-tooltip-focus-delay="' . $titleTooltipFocusDelay . '"' : '' ?>
              data-tooltip-text="<?= View::e($item['title']) ?>"
            ><span class="product-title-text"><?= View::e($item['title']) ?></span></a>
            <small class="muted product-external-id"><?= View::e($item['external_item_id']) ?></small>
          </td>
          <td><?= View::e($item['account_name']) ?></td>
          <td><?= View::e($item['seller_sku'] ?: '—') ?></td>
          <td><?= $money($item['price']) ?></td>
          <td><?= (int) $item['available_quantity'] ?></td>
          <td><?= (int) $item['sold_quantity'] ?></td>
          <td><span class="badge <?= ($item['status'] ?? '') === 'active' ? 'green' : 'amber' ?>"><?= View::e($statusLabel((string) ($item['status'] ?? ''))) ?></span></td>
          <td><?= (int) $item['active_links'] > 0 ? '<span class="badge green">Vinculado</span>' : '<span class="badge amber">Sin vínculo</span>' ?></td>
          <td class="actions-cell">
            <a class="btn small" href="<?= View::e($base) ?>/products/meli/show?id=<?= (int) $item['id'] ?>">Detalle</a>
            <?php if ($item['permalink']): ?><a class="btn small" target="_blank" rel="noopener" href="<?= View::e($item['permalink']) ?>">Ver ML</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ((int) ($pageData['pages'] ?? 1) > 1): ?>
    <nav class="pagination" aria-label="Paginación de publicaciones">
      <?php
      $current = (int) $pageData['page'];
      $pages = (int) $pageData['pages'];
      foreach (array_values(array_unique(array_filter([1, $current - 1, $current, $current + 1, $pages], static fn ($page) => $page >= 1 && $page <= $pages))) as $page):
          $pageQuery = array_merge($query, ['page' => $page]);
      ?>
        <a class="btn small <?= $page === $current ? 'primary' : '' ?>" data-async-page href="<?= View::e($base) ?>/products/meli?<?= View::e(http_build_query($pageQuery)) ?>"><?= $page ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>
</section>
