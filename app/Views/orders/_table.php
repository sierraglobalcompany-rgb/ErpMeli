<?php

use App\Core\Env;
use App\Core\View;
use App\Services\UiLabelPresenter;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$orders = $pageData['items'] ?? [];
$money = static fn ($value): string => '$ ' . number_format((float) $value, 0, ',', '.');
$query = $_GET;
unset($query['full']);
$returnTo = '/orders' . ($query ? '?' . http_build_query($query) : '');
?>
<section class="panel table-panel async-result-panel">
  <div class="section-result-summary">
    <strong><?= number_format((int) ($pageData['total'] ?? 0), 0, ',', '.') ?> órdenes</strong>
    <span>Página <?= (int) ($pageData['page'] ?? 1) ?> de <?= (int) ($pageData['pages'] ?? 1) ?></span>
  </div>
  <div class="table-scroll">
    <table class="data-table progressive-table" data-responsive="cards">
      <caption class="sr-only">Órdenes filtradas</caption>
      <thead><tr><th>Orden</th><th>Cuenta</th><th>Fecha</th><th>Comprador</th><th>Total</th><th>Estado</th><th>Pago</th><th>Envío</th><th>Cobertura</th></tr></thead>
      <tbody>
      <?php if (!$orders): ?><tr><td colspan="9"><div class="empty">No hay órdenes para estos filtros.</div></td></tr><?php endif; ?>
      <?php foreach ($orders as $order): ?>
        <tr>
          <td data-label="Orden"><a class="link" href="<?= View::e($base) ?>/orders/show?id=<?= (int) $order['id'] ?>&return_to=<?= rawurlencode($returnTo) ?>"><?= View::e($order['external_order_id']) ?></a></td>
          <td data-label="Cuenta"><?= View::e($order['account_name']) ?></td>
          <td data-label="Fecha"><?= View::e($order['date_created']) ?></td>
          <td data-label="Comprador"><?= View::e($order['buyer_nickname'] ?: '—') ?></td>
          <td data-label="Total"><?= $money($order['total_amount']) ?></td>
          <td data-label="Estado"><span class="badge <?= $order['status'] === 'cancelled' ? 'red' : 'green' ?>"><?= View::e(UiLabelPresenter::status($order['status'] ?? null)) ?></span></td>
          <td data-label="Pago"><?= View::e(UiLabelPresenter::status($order['payment_status'] ?? null)) ?></td>
          <td data-label="Envío"><?= View::e(UiLabelPresenter::status($order['shipping_status'] ?? null)) ?></td>
          <td data-label="Cobertura"><span class="badge <?= ($order['enrichment_status'] ?? '') === 'complete' ? 'green' : 'amber' ?>"><?= View::e(UiLabelPresenter::status($order['enrichment_status'] ?: 'basic')) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ((int) ($pageData['pages'] ?? 1) > 1): ?>
    <nav class="pagination" aria-label="Paginación de órdenes">
      <?php
      $current = (int) $pageData['page'];
      $pages = (int) $pageData['pages'];
      foreach (array_values(array_unique(array_filter([1, $current - 1, $current, $current + 1, $pages], static fn ($page) => $page >= 1 && $page <= $pages))) as $page):
          $pageQuery = array_merge($query, ['page' => $page]);
      ?>
        <a class="btn small <?= $page === $current ? 'primary' : '' ?>" data-async-page href="<?= View::e($base) ?>/orders?<?= View::e(http_build_query($pageQuery)) ?>"><?= $page ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>
</section>
