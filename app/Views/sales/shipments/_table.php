<?php

use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;
use App\Services\UiLabelPresenter;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$shipments = $pageData['items'] ?? [];
$money = static fn ($value): string => '$ ' . number_format((float) $value, 0, ',', '.');
$query = $_GET;
unset($query['full']);
$returnTo = '/shipments' . ($query ? '?' . http_build_query($query) : '');
$logisticsLabel = static fn (?string $type): string => match ($type) {
    'fulfillment' => 'Full',
    'self_service' => 'Flex',
    'cross_docking' => 'Colecta',
    'xd_drop_off' => 'Places',
    'drop_off' => 'Drop off',
    default => $type ?: '—',
};
$canPrintLabel = static fn (array $shipment): bool =>
    ($shipment['shipping_mode'] ?? '') === 'me2'
    && ($shipment['status'] ?? '') === 'ready_to_ship'
    && in_array(($shipment['substatus'] ?? ''), ['ready_to_print', 'printed'], true)
    && ($shipment['logistic_type'] ?? '') !== 'fulfillment';
?>
<section class="panel table-panel async-result-panel">
  <div class="section-result-summary">
    <strong><?= number_format((int) ($pageData['total'] ?? 0), 0, ',', '.') ?> envíos</strong>
    <span>Página <?= (int) ($pageData['page'] ?? 1) ?> de <?= (int) ($pageData['pages'] ?? 1) ?></span>
  </div>
  <div class="table-scroll">
    <table class="data-table progressive-table" data-responsive="cards">
      <caption class="sr-only">Envíos filtrados</caption>
      <thead><tr><th>Envío</th><th>Cuenta</th><th>Orden</th><th>Estado</th><th>Subestado</th><th>Grupo</th><th>Logística API</th><th>Modo</th><th>Tracking</th><th>Guía</th><th>Costo vendedor</th><th>Sync</th></tr></thead>
      <tbody>
      <?php if (!$shipments): ?><tr><td colspan="12"><div class="empty">Sin envíos sincronizados para estos filtros. La vista inicial considera los últimos 30 días.</div></td></tr><?php endif; ?>
      <?php foreach ($shipments as $shipment): ?>
        <tr>
          <td data-label="Envío"><?= View::e($shipment['external_shipment_id']) ?></td>
          <td data-label="Cuenta"><?= View::e($shipment['account_name']) ?></td>
          <td data-label="Orden">
            <?php if (!empty($shipment['meli_order_id'])): ?>
              <a class="link" href="<?= View::e($base) ?>/orders/show?id=<?= (int) $shipment['meli_order_id'] ?>&return_to=<?= rawurlencode($returnTo) ?>"><?= View::e($shipment['external_order_id'] ?: 'Orden') ?></a>
            <?php else: ?><?= View::e($shipment['external_order_id'] ?: '—') ?><?php endif; ?>
          </td>
          <td data-label="Estado"><span class="badge"><?= View::e(UiLabelPresenter::status($shipment['status'] ?? null)) ?></span></td>
          <td data-label="Subestado"><?= View::e(UiLabelPresenter::status($shipment['substatus'] ?? null)) ?></td>
          <td data-label="Método"><?= View::e($logisticsLabel($shipment['logistic_type'] ?? null)) ?></td>
          <td data-label="Logística API"><code><?= View::e($shipment['logistic_type'] ?: '—') ?></code></td>
          <td data-label="Modo"><code><?= View::e($shipment['shipping_mode'] ?: '—') ?></code></td>
          <td data-label="Seguimiento"><?= View::e($shipment['tracking_number'] ?: '—') ?></td>
          <td data-label="Guía"><span class="badge <?= $canPrintLabel($shipment) ? 'amber' : '' ?>"><?= $canPrintLabel($shipment) ? 'Elegible, pendiente confirmar CO' : 'No disponible' ?></span></td>
          <td data-label="Costo vendedor"><?= $money($shipment['seller_cost']) ?></td>
          <td data-label="Sincronización"><?= View::e(DateTimePresenter::format($shipment['synced_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ((int) ($pageData['pages'] ?? 1) > 1): ?>
    <nav class="pagination" aria-label="Paginación de envíos">
      <?php
      $current = (int) $pageData['page'];
      $pages = (int) $pageData['pages'];
      foreach (array_values(array_unique(array_filter([1, $current - 1, $current, $current + 1, $pages], static fn ($page) => $page >= 1 && $page <= $pages))) as $page):
          $pageQuery = array_merge($query, ['page' => $page]);
      ?>
        <a class="btn small <?= $page === $current ? 'primary' : '' ?>" data-async-page href="<?= View::e($base) ?>/shipments?<?= View::e(http_build_query($pageQuery)) ?>"><?= $page ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>
</section>
