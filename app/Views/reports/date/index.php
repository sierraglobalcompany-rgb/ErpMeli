<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$money = static fn($v) => '$ ' . number_format((float) $v, 0, ',', '.');
$totals = ['orders' => 0, 'units' => 0, 'net' => 0, 'profit' => 0];
foreach ($rows as $r) {
    $totals['orders'] += (int) $r['orders_count'];
    $totals['units'] += (float) $r['units_sold'];
    $totals['net'] += (float) $r['estimated_net'];
    $totals['profit'] += (float) $r['estimated_profit'];
}
$activeReportTab = 'date';
?>
<div class="page-head">
  <div>
    <h1>Reporte por fechas</h1>
    <p>Consolidado por producto interno con rango Desde/Hasta, separando producto, envío, comisiones, descuentos y utilidad.</p>
  </div>
  <div class="page-actions">
    <form method="post" action="<?= View::e($base) ?>/reports/date">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <?php foreach ($filters as $k => $v): ?><input type="hidden" name="<?= View::e($k) ?>" value="<?= View::e((string) $v) ?>"><?php endforeach; ?>
      <button class="btn primary">Guardar reporte por fechas</button>
    </form>
  </div>
</div>
<?php require dirname(__DIR__) . '/_tabs.php'; ?>
<div class="report-help"><strong>Por fechas:</strong> úsalo cuando el periodo no sea exactamente mensual. Para cierres formales entre empresas, usa <strong>Mensual</strong>.</div>
<section class="panel filter-bar"><form method="get" class="inline-form">
  <div class="field"><label>Empresa</label><select class="input" name="company_id"><option value="0">Todas</option><?php foreach ($companies as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $filters['company_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= View::e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>Cuenta</label><select class="input" name="account_id"><option value="0">Todas</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $filters['account_id'] === (int) $a['id'] ? 'selected' : '' ?>><?= View::e($a['account_name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>Desde</label><input class="input" type="date" name="from" value="<?= View::e($filters['from']) ?>"></div>
  <div class="field"><label>Hasta</label><input class="input" type="date" name="to" value="<?= View::e($filters['to']) ?>"></div>
  <label class="check-row"><input type="checkbox" name="include_returns" value="1" <?= $filters['include_returns'] ? 'checked' : '' ?>> Incluir canceladas/devueltas</label>
  <button class="btn">Generar vista</button>
</form></section>
<section class="mini-grid">
  <div class="mini-card"><span>Ventas</span><strong><?= number_format($totals['orders'], 0, ',', '.') ?></strong></div>
  <div class="mini-card"><span>Unidades</span><strong><?= number_format($totals['units'], 0, ',', '.') ?></strong></div>
  <div class="mini-card"><span>Neto estimado</span><strong><?= $money($totals['net']) ?></strong></div>
</section>
<section class="panel table-panel mt-2"><div class="table-scroll"><table class="data-table"><thead><tr><th>Producto interno</th><th>Cuenta</th><th>SKU/Ítem</th><th>Ventas</th><th>Unidades</th><th>Internas</th><th>Producto</th><th>Envío</th><th>Comisión</th><th>Neto</th><th>Costo</th><th>Utilidad</th><th>Margen</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="13"><div class="empty">No hay ventas aprobadas para este filtro.</div></td></tr><?php endif; ?>
<?php foreach ($rows as $row): ?><tr><td><?= $row['internal_product_id'] ? View::e((string) $row['internal_product_id']) : '<span class="badge amber">Sin vínculo</span>' ?></td><td><?= View::e($row['account_name']) ?></td><td><?= View::e(($row['seller_sku'] ?: '—') . ' / ' . $row['external_item_id']) ?></td><td><?= (int) $row['orders_count'] ?></td><td><?= number_format((float) $row['units_sold'], 2, ',', '.') ?></td><td><?= number_format((float) $row['internal_units'], 2, ',', '.') ?></td><td><?= $money($row['product_revenue']) ?></td><td><?= $money($row['shipping_revenue']) ?></td><td><?= $money($row['marketplace_fees']) ?></td><td><?= $money($row['estimated_net']) ?></td><td><?= $money($row['total_cost']) ?></td><td><?= $money($row['estimated_profit']) ?></td><td><span class="badge <?= (float) $row['margin_percent'] < 0 ? 'red' : 'green' ?>"><?= number_format((float) $row['margin_percent'], 2, ',', '.') ?>%</span></td></tr><?php endforeach; ?>
</tbody></table></div></section>
