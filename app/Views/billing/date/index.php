<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$money = static fn($v) => '$ ' . number_format((float) $v, 0, ',', '.');
$units = static fn($v) => number_format((float) $v, ((float) $v == (int) $v) ? 0 : 2, ',', '.');
$totals = ['orders'=>0,'units'=>0.0,'product'=>0.0,'buyer_shipping'=>0.0,'ml_shipping'=>0.0,'shipping_net'=>0.0,'net_without'=>0.0,'net_after'=>0.0,'profit'=>0.0,'missing'=>0,'pending_financial'=>0,'pending_shipping'=>0];
foreach ($rows as $r) {
    $totals['orders'] += (int) $r['orders_count'];
    $totals['units'] += (float) $r['units_sold'];
    $totals['product'] += (float) $r['product_revenue'];
    $totals['buyer_shipping'] += (float) ($r['buyer_shipping_paid'] ?? $r['shipping_revenue'] ?? 0);
    $totals['ml_shipping'] += (float) ($r['ml_shipping_charge'] ?? 0);
    $totals['shipping_net'] += (float) ($r['shipping_net_amount'] ?? 0);
    $totals['net_without'] += (float) ($r['net_without_shipping'] ?? $r['estimated_net'] ?? 0);
    $totals['net_after'] += (float) ($r['net_after_shipping'] ?? $r['estimated_net'] ?? 0);
    $totals['profit'] += (float) $r['estimated_profit'];
    $totals['missing'] += (int) ($r['missing_cost_count'] ?? 0);
    $totals['pending_financial'] += (int) ($r['pending_financial_count'] ?? 0);
    $totals['pending_shipping'] += (int) ($r['pending_shipping_charge_count'] ?? 0);
}
$activeBillingTab = 'date';
$coverageStatus = (string) ($coverage['status'] ?? 'unknown');
?>
<div class="page-head">
  <div><h1>Facturación por fechas</h1><p>Borrador facturable con emisor/cuenta, cliente y rango exacto.</p></div>
  <div class="page-actions">
    <form method="post" action="<?= View::e($base) ?>/billing/date">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <?php foreach ($filters as $k => $v): ?><input type="hidden" name="<?= View::e($k) ?>" value="<?= View::e((string) $v) ?>"><?php endforeach; ?>
      <?php if ($coverageStatus !== 'complete' && Auth::role() === 'admin'): ?><input type="hidden" name="allow_incomplete" value="1"><?php endif; ?>
      <button class="btn primary">Crear borrador facturable</button>
    </form>
  </div>
</div>
<?php require dirname(__DIR__) . '/_tabs.php'; ?>
<div class="report-help"><strong>Por fechas:</strong> úselo para facturar un rango específico. No emite factura electrónica; “facturado” es una marca manual con referencia externa.</div>
<?php if (!empty($coverage)): ?>
  <div class="alert <?= $coverageStatus === 'complete' ? 'success' : 'warning' ?>">
    <strong>Cobertura de sincronización:</strong> <?= View::e($coverage['message'] ?? '') ?>
    <span class="muted"><?= (int) ($coverage['covered_days'] ?? 0) ?> de <?= (int) ($coverage['total_days'] ?? 0) ?> días cubiertos.</span>
    <?php if ($coverageStatus !== 'complete'): ?>
      <a class="link" href="<?= View::e($base) ?>/sync/account?id=<?= (int) $filters['account_id'] ?>&year=<?= (int) substr((string) $filters['from'], 0, 4) ?>&month=<?= (int) substr((string) $filters['from'], 5, 2) ?>">Encolar sincronización faltante</a>
      <?php if (Auth::role() === 'admin'): ?><br><small>Como administrador puede crear el borrador con advertencia, pero el rango puede estar incompleto.</small><?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php if ($totals['pending_shipping'] > 0 || $totals['pending_financial'] > 0): ?>
  <div class="alert warning">Hay órdenes sin conciliación financiera completa. El cargo Mercado Envíos y el neto después de envío pueden ser estimados.</div>
<?php endif; ?>

<section class="panel filter-bar">
  <form method="get" class="inline-form" action="<?= View::e($base) ?>/billing/date">
    <div class="field">
      <label>Empresa emisora / Cuenta Mercado Libre</label>
      <select class="input" name="emitter_account" required>
        <option value="">Seleccione</option>
        <?php foreach ($emitterOptions as $option): ?>
          <option value="<?= View::e($option['value']) ?>" <?= (string) $filters['emitter_account'] === (string) $option['value'] ? 'selected' : '' ?>><?= View::e($option['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Evita combinar una empresa emisora con una cuenta que no le pertenece.</small>
    </div>
    <div class="field"><label>Empresa cliente</label><select class="input" name="customer_company_id" required><option value="0">Seleccione</option><?php foreach ($companies as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $filters['customer_company_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= View::e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Desde</label><input class="input" type="date" name="from" value="<?= View::e($filters['from']) ?>"></div>
    <div class="field"><label>Hasta</label><input class="input" type="date" name="to" value="<?= View::e($filters['to']) ?>"></div>
    <label class="check-row"><input type="checkbox" name="include_returns" value="1" <?= $filters['include_returns'] ? 'checked' : '' ?>> Incluir canceladas/devueltas</label>
    <button class="btn">Previsualizar</button>
  </form>
</section>

<section class="mini-grid">
  <div class="mini-card"><span>Órdenes</span><strong><?= number_format($totals['orders'], 0, ',', '.') ?></strong></div>
  <div class="mini-card"><span>Unidades</span><strong><?= $units($totals['units']) ?></strong></div>
  <div class="mini-card"><span>Producto vendido</span><strong><?= $money($totals['product']) ?></strong></div>
  <div class="mini-card"><span>Envío comprador</span><strong><?= $money($totals['buyer_shipping']) ?></strong></div>
  <div class="mini-card"><span>Cargo Mercado Envíos</span><strong><?= $money($totals['ml_shipping']) ?></strong></div>
  <div class="mini-card"><span>Neto sin envío</span><strong><?= $money($totals['net_without']) ?></strong></div>
  <div class="mini-card"><span>Neto después de envío</span><strong><?= $money($totals['net_after']) ?></strong></div>
  <div class="mini-card"><span>Utilidad</span><strong><?= $totals['missing'] > 0 ? 'Pendiente' : $money($totals['profit']) ?></strong></div>
</section>
<?php if ($totals['missing'] > 0): ?><div class="alert warning">Hay <?= (int) $totals['missing'] ?> líneas sin costo o vínculo. La utilidad no debe tomarse como definitiva.</div><?php endif; ?>

<section class="panel table-panel mt-2">
  <header class="panel-head"><h2>Borradores por fechas guardados</h2></header>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Rango</th><th>Emisora</th><th>Cliente</th><th>Cuenta</th><th>Neto después de envío</th><th>Base manual</th><th>Estado</th></tr></thead><tbody>
    <?php if (!$runs): ?><tr><td colspan="7"><div class="empty">Aún no hay facturaciones por fechas guardadas.</div></td></tr><?php endif; ?>
    <?php foreach ($runs as $run): ?><tr><td><a class="link" href="<?= View::e($base) ?>/billing/date/show?id=<?= (int) $run['id'] ?>"><?= View::e($run['date_from'] . ' a ' . $run['date_to']) ?></a></td><td><?= View::e($run['issuer_name'] ?: '—') ?></td><td><?= View::e($run['customer_name'] ?: '—') ?></td><td><?= View::e($run['account_name'] ?: '—') ?></td><td><?= $money($run['net_after_shipping'] ?? $run['estimated_net']) ?></td><td><?= $money($run['manual_base']) ?></td><td><span class="badge <?= in_array($run['status'], ['aprobado', 'facturado'], true) ? 'green' : 'amber' ?>"><?= View::e($run['status']) ?></span></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>

<section class="panel table-panel mt-2">
  <header class="panel-head"><h2>Previsualización del rango</h2><span class="muted"><?= count($rows) ?> líneas</span></header>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Producto</th><th>Cuenta</th><th>SKU/Ítem</th><th>Órdenes</th><th>Unidades</th><th>Producto</th><th>Envío comprador</th><th>Cargo ME</th><th>Neto sin envío</th><th>Neto después envío</th><th>Costo</th><th>Utilidad</th><th>Margen</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="13"><div class="empty">No hay ventas aprobadas para este filtro.</div></td></tr><?php endif; ?>
    <?php foreach ($rows as $row): $costPending = ($row['cost_status'] ?? 'ok') !== 'ok'; ?><tr>
      <td><?= $row['internal_product_id'] ? View::e($row['product_title']) : '<span class="badge amber">Sin vínculo</span> ' . View::e($row['product_title']) ?></td>
      <td><?= View::e($row['account_name']) ?></td>
      <td><?= View::e(($row['seller_sku'] ?: '—') . ' / ' . $row['external_item_id']) ?></td>
      <td><?= (int) $row['orders_count'] ?></td>
      <td><?= $units($row['units_sold']) ?></td>
      <td><?= $money($row['product_revenue']) ?></td>
      <td><?= $money($row['buyer_shipping_paid'] ?? 0) ?></td>
      <td><?= $money($row['ml_shipping_charge'] ?? 0) ?></td>
      <td><?= $money($row['net_without_shipping'] ?? 0) ?></td>
      <td><?= $money($row['net_after_shipping'] ?? 0) ?></td>
      <td><?= $costPending ? '<span class="badge amber">Costo pendiente</span>' : $money($row['total_cost']) ?></td>
      <td><?= $costPending ? 'Pendiente' : $money($row['estimated_profit']) ?></td>
      <td><span class="badge <?= $costPending ? 'amber' : ((float) $row['margin_percent'] < 0 ? 'red' : 'green') ?>"><?= $costPending ? 'Pendiente' : number_format((float) $row['margin_percent'], 2, ',', '.') . '%' ?></span></td>
    </tr><?php endforeach; ?>
  </tbody></table></div>
</section>
