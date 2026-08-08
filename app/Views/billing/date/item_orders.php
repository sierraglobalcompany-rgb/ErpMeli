<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$money = static fn($v) => $v === null || $v === '' ? 'Pendiente' : '$ ' . number_format((float) $v, 0, ',', '.');
$units = static fn($v) => number_format((float) $v, ((float) $v == (int) $v) ? 0 : 2, ',', '.');
$rows = $orders['rows'] ?? [];
$page = (int) ($orders['page'] ?? 1);
$perPage = (int) ($orders['per_page'] ?? 50);
$total = (int) ($orders['total'] ?? 0);
?>
<div class="page-head">
  <div>
    <a class="muted" href="<?= View::e($base) ?>/billing/date/show?id=<?= (int) $run['id'] ?>">← Volver al borrador</a>
    <h1>Órdenes del producto</h1>
    <p><?= View::e($item['internal_name'] ?: $item['product_title']) ?> · <?= number_format($total, 0, ',', '.') ?> órdenes</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/billing/date/item-orders/export?run_id=<?= (int) $run['id'] ?>&item_id=<?= (int) $item['id'] ?>">CSV</a>
  </div>
</div>

<section class="panel table-panel">
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Orden</th><th>Fecha</th><th>Cuenta</th><th>Producto</th><th>Unidades</th><th>Producto</th><th>Comisión</th><th>Envío comprador</th><th>Cargo ME</th><th>Neto sin envío</th><th>Neto después envío</th><th>Costo</th><th>Utilidad</th><th>Estado</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="14"><div class="empty">No hay órdenes guardadas para esta línea. Recalcule el borrador para generar el detalle.</div></td></tr><?php endif; ?>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><a class="link" href="<?= View::e($base) ?>/orders/show?id=<?= (int) $row['meli_order_id'] ?>"><?= View::e($row['external_order_id']) ?></a></td>
        <td><?= View::e((string) ($row['order_date'] ?? '—')) ?></td>
        <td><?= View::e($row['account_name'] ?: '—') ?></td>
        <td><?= View::e($row['product_title']) ?></td>
        <td><?= $units($row['quantity']) ?></td>
        <td><?= $money($row['product_amount']) ?></td>
        <td><?= $money($row['sale_fee_amount']) ?></td>
        <td><?= $money($row['buyer_shipping_paid']) ?></td>
        <td><?= $money($row['ml_shipping_charge']) ?></td>
        <td><?= $money($row['net_without_shipping']) ?></td>
        <td><?= $money($row['net_after_shipping']) ?></td>
        <td><?= $row['cost_status'] === 'ok' ? $money($row['total_cost']) : 'Pendiente' ?></td>
        <td><?= $row['cost_status'] === 'ok' ? $money($row['profit_amount']) : 'Pendiente' ?></td>
        <td><span class="badge <?= $row['cost_status'] === 'ok' && $row['financial_status'] !== 'pending' ? 'green' : 'amber' ?>"><?= View::e($row['financial_status'] . ' / ' . $row['cost_status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<?php if ($total > $perPage): ?>
  <div class="pagination">
    <?php if ($page > 1): ?><a class="btn" href="<?= View::e($base) ?>/billing/date/item-orders?run_id=<?= (int) $run['id'] ?>&item_id=<?= (int) $item['id'] ?>&page=<?= $page - 1 ?>">Anterior</a><?php endif; ?>
    <span class="muted">Página <?= $page ?> de <?= (int) ceil($total / $perPage) ?></span>
    <?php if ($page * $perPage < $total): ?><a class="btn" href="<?= View::e($base) ?>/billing/date/item-orders?run_id=<?= (int) $run['id'] ?>&item_id=<?= (int) $item['id'] ?>&page=<?= $page + 1 ?>">Siguiente</a><?php endif; ?>
  </div>
<?php endif; ?>
