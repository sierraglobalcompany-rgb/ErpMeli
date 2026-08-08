<?php

use App\Core\Env;
use App\Core\View;
use App\Services\UiLabelPresenter;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$money = static fn ($value): string => '$ ' . number_format((float) $value, 0, ',', '.');
?>
<section class="metrics">
 <?php foreach ([
  ['Cobros aprobados', $money($metrics['sales'] ?? 0), 'Dentro del rango seleccionado'],
  [!empty($metrics['net_is_partial']) ? 'Neto estimado' : 'Neto conciliado', $money($metrics['net'] ?? 0), !empty($metrics['net_is_partial']) ? 'Hay órdenes pendientes de conciliación' : 'Información financiera completa'],
  ['Órdenes del periodo', number_format((int) ($metrics['orders_period_total'] ?? 0), 0, ',', '.'), number_format(count($orders), 0, ',', '.') . ' visibles en la lista'],
  ['Conciliación pendiente', number_format((int) ($metrics['financial_pending_count'] ?? 0), 0, ',', '.'), number_format((int) ($metrics['financial_complete_count'] ?? 0), 0, ',', '.') . ' órdenes conciliadas'],
 ] as [$label, $value, $note]): ?>
   <article class="metric-card"><div><div class="metric-label"><?= View::e($label) ?></div><div class="metric-value"><?= View::e($value) ?></div><div class="metric-change"><?= View::e($note) ?></div></div></article>
 <?php endforeach; ?>
</section>
<section class="panel table-panel mt-2">
  <header class="panel-head"><h2>Órdenes recientes</h2><a class="link" href="<?= View::e($base) ?>/orders">Ver todas las órdenes</a></header>
  <div class="table-scroll">
    <table class="data-table" data-responsive="cards">
      <caption class="sr-only">Órdenes recientes</caption>
      <thead><tr><th>ID de orden</th><th>Cuenta</th><th>Fecha</th><th>Comprador</th><th>Total</th><th>Estado</th><th>Envío</th><th>Pago</th></tr></thead>
      <tbody>
      <?php if (!$orders): ?><tr><td colspan="8"><div class="empty"><?= (int) ($metrics['accounts'] ?? 0) === 0 ? 'Conecte una cuenta de Mercado Libre para comenzar.' : 'No hay órdenes dentro del rango seleccionado.' ?></div></td></tr><?php endif; ?>
      <?php foreach ($orders as $order): ?>
        <tr>
          <td data-label="Orden"><a class="link" href="<?= View::e($base) ?>/orders/show?id=<?= (int) $order['id'] ?>"><?= View::e($order['external_order_id']) ?></a></td>
          <td data-label="Cuenta"><?= View::e($order['account_name']) ?></td>
          <td data-label="Fecha"><?= View::e($order['date_created']) ?></td>
          <td data-label="Comprador"><?= View::e($order['buyer_nickname'] ?: '—') ?></td>
          <td data-label="Total"><?= $money($order['total_amount']) ?></td>
          <td data-label="Estado"><span class="badge"><?= View::e(UiLabelPresenter::status($order['status'] ?? null)) ?></span></td>
          <td data-label="Envío"><?= View::e(UiLabelPresenter::status($order['shipping_status'] ?? null)) ?></td>
          <td data-label="Pago"><span class="badge <?= ($order['payment_status'] === 'approved' ? 'green' : 'amber') ?>"><?= View::e(UiLabelPresenter::status($order['payment_status'] ?? null)) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
