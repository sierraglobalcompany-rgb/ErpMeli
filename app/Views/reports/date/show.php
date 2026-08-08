<?php
use App\Core\Env;
use App\Core\View;
$base = rtrim((string) Env::get('APP_URL', ''), '/');
$money = static fn($v) => '$ ' . number_format((float) $v, 0, ',', '.');
?>
<div class="page-head"><div><h1>Reporte guardado #<?= (int)$run['id'] ?></h1><p><?= View::e($run['date_from'].' a '.$run['date_to']) ?> · <?= View::e($run['account_name'] ?: 'Todas las cuentas') ?></p></div><div class="page-actions print-actions"><a class="btn" href="<?= View::e($base) ?>/reports/date/export?id=<?= (int)$run['id'] ?>&type=csv">CSV</a><a class="btn" href="<?= View::e($base) ?>/reports/date/export?id=<?= (int)$run['id'] ?>&type=excel">Excel compatible</a><button class="btn" onclick="window.print()">Vista imprimible</button></div></div>
<section class="report-summary">
  <div class="report-stat"><span>Ventas</span><strong><?= (int)$run['total_orders'] ?></strong></div>
  <div class="report-stat"><span>Producto</span><strong><?= $money($run['product_revenue']) ?></strong></div>
  <div class="report-stat"><span>Envío</span><strong><?= $money($run['shipping_revenue']) ?></strong></div>
  <div class="report-stat"><span>Neto</span><strong><?= $money($run['estimated_net']) ?></strong></div>
  <div class="report-stat"><span>Utilidad</span><strong><?= $money($run['estimated_profit']) ?></strong></div>
  <div class="report-stat"><span>Margen</span><strong><?= number_format((float)$run['margin_percent'],2,',','.') ?>%</strong></div>
</section>
<section class="panel table-panel"><div class="table-scroll"><table class="data-table"><thead><tr><th>Producto</th><th>SKU</th><th>Unidades</th><th>Internas</th><th>Neto</th><th>Costo</th><th>Utilidad</th><th>Margen</th></tr></thead><tbody><?php foreach($items as $item):?><tr><td><?= View::e($item['internal_name'] ?: $item['product_title']) ?></td><td><?= View::e($item['seller_sku'] ?: '—') ?></td><td><?= View::e($item['units_sold']) ?></td><td><?= View::e($item['internal_units']) ?></td><td><?= $money($item['estimated_net']) ?></td><td><?= $money($item['total_cost']) ?></td><td><?= $money($item['estimated_profit']) ?></td><td><?= number_format((float)$item['margin_percent'],2,',','.') ?>%</td></tr><?php endforeach;?></tbody></table></div></section>
