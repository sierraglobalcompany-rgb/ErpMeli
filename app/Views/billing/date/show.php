<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$money = static fn($v) => $v === null || $v === '' ? 'Pendiente' : '$ ' . number_format((float) $v, 0, ',', '.');
$units = static fn($v) => number_format((float) $v, ((float) $v == (int) $v) ? 0 : 2, ',', '.');
$percent = static fn($v) => $v === null || $v === '' ? 'Pendiente' : number_format((float) $v, 2, ',', '.') . ' %';
$missingCost = (int) ($run['missing_cost_items_count'] ?? 0);
$pendingFinancial = (int) ($run['pending_financial_orders_count'] ?? 0);
$pendingShipping = (int) ($run['pending_shipping_charge_count'] ?? 0);
$requiresRecalc = !empty($run['requires_recalculation']) || ($pendingFinancial + $pendingShipping + $missingCost) > 0;
$preset = (string) ($_GET['columns'] ?? 'resumida');
$customCols = array_filter(array_map('trim', explode(',', (string) ($_GET['cols'] ?? ''))));
$hasCustom = $customCols !== [];
$show = static function (string $key) use ($preset, $customCols, $hasCustom): bool {
    if ($hasCustom) {
        return in_array($key, $customCols, true);
    }
    return match ($preset) {
        'financiera' => in_array($key, ['producto','sku','orders','units','product','fees','buyer_shipping','ml_shipping','shipping_net','net_without','net_after','reconciled','financial_status','actions'], true),
        'costos' => in_array($key, ['producto','sku','orders','units','net_after','unit_cost','total_cost','profit','profit_unit','profit_order','margin','cost_status','actions'], true),
        'tecnica' => true,
        default => in_array($key, ['producto','sku','orders','units','product','avg_unit','net_after','cost_status','total_cost','profit','margin','actions'], true),
    };
};
$columnGroups = [
    'Ventas' => ['producto'=>'Producto','sku'=>'SKU','orders'=>'Órdenes','units'=>'Unidades','product'=>'Producto total','avg_unit'=>'Producto promedio unidad','avg_order'=>'Producto promedio orden'],
    'Mercado Libre' => ['fees'=>'Comisión total','fees_unit'=>'Comisión prom. unidad','fees_order'=>'Comisión prom. orden','buyer_shipping'=>'Envío comprador','ml_shipping'=>'Cargo Mercado Envíos','shipping_net'=>'Envío neto','net_without'=>'Neto sin envío','net_after'=>'Neto después envío','reconciled'=>'Neto ML conciliado','financial_status'=>'Estado conciliación'],
    'Costos' => ['unit_cost'=>'Costo unitario','total_cost'=>'Costo total','profit'=>'Utilidad total','profit_unit'=>'Utilidad prom. unidad','profit_order'=>'Utilidad prom. orden','margin'=>'Margen','cost_status'=>'Estado costo'],
    'Técnicas' => ['account'=>'Cuenta','ml_sku'=>'SKU ML','external_item'=>'ID publicación','external_variation'=>'ID variación','factor'=>'Factor conversión','internal_id'=>'ID producto interno','actions'=>'Acciones'],
];
?>
<div class="page-head">
  <div>
    <a class="muted" href="<?= View::e($base) ?>/billing/date">← Volver a facturación por fechas</a>
    <h1>Facturación por fechas #<?= (int) $run['id'] ?></h1>
    <p><?= View::e($run['date_from'] . ' a ' . $run['date_to']) ?> · <?= View::e(($run['issuer_name'] ?? 'Emisora') . ' → ' . ($run['customer_name'] ?? 'Cliente')) ?> · <?= View::e($run['account_name'] ?: 'Cuenta') ?></p>
  </div>
  <div class="page-actions print-actions">
    <a class="btn" href="<?= View::e($base) ?>/billing/date/export?id=<?= (int) $run['id'] ?>&type=csv<?= $hasCustom ? '&cols=' . View::e(implode(',', $customCols)) : '' ?>">CSV</a>
    <a class="btn" href="<?= View::e($base) ?>/billing/date/export?id=<?= (int) $run['id'] ?>&type=excel<?= $hasCustom ? '&cols=' . View::e(implode(',', $customCols)) : '' ?>">Excel compatible</a>
    <button class="btn" onclick="window.print()">Vista imprimible</button>
    <span class="badge <?= in_array($run['status'], ['aprobado', 'facturado'], true) ? 'green' : 'amber' ?>"><?= View::e($run['status'] ?? 'borrador') ?></span>
  </div>
</div>

<?php if ($requiresRecalc): ?>
  <div class="alert warning">
    <strong>Advertencia:</strong>
    <?= $missingCost > 0 ? $missingCost . ' líneas tienen costo/vínculo pendiente. ' : '' ?>
    <?= $pendingFinancial > 0 ? $pendingFinancial . ' líneas tienen conciliación ML pendiente. ' : '' ?>
    <?= $pendingShipping > 0 ? $pendingShipping . ' líneas no tienen cargo Mercado Envíos clasificado. ' : '' ?>
    Este borrador puede requerir recálculo antes de aprobar o facturar.
  </div>
<?php endif; ?>

<section class="report-summary">
  <div class="report-stat"><span>Órdenes</span><strong><?= number_format((int) $run['total_orders'], 0, ',', '.') ?></strong></div>
  <div class="report-stat"><span>Unidades</span><strong><?= $units($run['total_units'] ?? 0) ?></strong></div>
  <div class="report-stat"><span>Producto vendido</span><strong><?= $money($run['product_revenue']) ?></strong></div>
  <div class="report-stat"><span>Envío comprador</span><strong><?= $money($run['buyer_shipping_paid'] ?? $run['shipping_revenue'] ?? 0) ?></strong></div>
  <div class="report-stat"><span>Cargo Mercado Envíos</span><strong><?= $money($run['ml_shipping_charge'] ?? 0) ?></strong></div>
  <div class="report-stat"><span>Neto sin envío</span><strong><?= $money($run['net_without_shipping'] ?? $run['estimated_net']) ?></strong></div>
  <div class="report-stat"><span>Neto después de envío</span><strong><?= $money($run['net_after_shipping'] ?? $run['estimated_net']) ?></strong></div>
  <div class="report-stat"><span>Neto ML conciliado</span><strong><?= $money($run['reconciled_net_amount'] ?? null) ?></strong></div>
  <div class="report-stat"><span>Costos internos</span><strong><?= $missingCost > 0 ? 'Pendiente' : $money($run['total_cost']) ?></strong></div>
  <div class="report-stat"><span>Utilidad</span><strong><?= $missingCost > 0 ? 'Pendiente' : $money($run['estimated_profit']) ?></strong></div>
  <div class="report-stat"><span>Margen</span><strong><?= $missingCost > 0 ? 'Pendiente' : $percent($run['margin_percent']) ?></strong></div>
</section>

<?php if (in_array($run['status'] ?? 'borrador', ['borrador', 'revisado'], true)): ?>
<section class="panel filter-bar">
  <form class="inline-form" method="post" action="<?= View::e($base) ?>/billing/date/recalculate">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="id" value="<?= (int) $run['id'] ?>">
    <label class="check-row"><input type="checkbox" name="queue_financial" value="1"> Encolar financiero pendiente antes de recalcular</label>
    <button class="btn primary">Recalcular este borrador</button>
  </form>
  <form class="inline-form" method="post" action="<?= View::e($base) ?>/billing/date/financial/recalculate">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="id" value="<?= (int) $run['id'] ?>">
    <select class="input" name="mode"><option value="pending">Solo pendientes</option><option value="all">Todo el rango</option><option value="failed">Fallidos</option></select>
    <button class="btn">Recalcular financiero del rango</button>
  </form>
</section>
<?php endif; ?>

<section class="panel filter-bar">
  <form class="inline-form" method="get">
    <input type="hidden" name="id" value="<?= (int) $run['id'] ?>">
    <div class="field"><label>Vista de columnas</label><select class="input" name="columns" onchange="this.form.submit()">
      <?php foreach (['resumida'=>'Resumida','financiera'=>'Financiera ML','costos'=>'Costos','tecnica'=>'Técnica'] as $key=>$label): ?>
        <option value="<?= View::e($key) ?>" <?= $preset === $key ? 'selected' : '' ?>><?= View::e($label) ?></option>
      <?php endforeach; ?>
    </select></div>
    <details class="column-picker"><summary class="btn">Personalizar columnas</summary>
      <div class="column-picker-grid">
        <?php foreach ($columnGroups as $group => $columns): ?><fieldset><legend><?= View::e($group) ?></legend>
          <?php foreach ($columns as $key => $label): ?><label class="check-row"><input type="checkbox" data-column-choice value="<?= View::e($key) ?>" <?= $show($key) ? 'checked' : '' ?>> <?= View::e($label) ?></label><?php endforeach; ?>
        </fieldset><?php endforeach; ?>
      </div>
      <input type="hidden" name="cols" data-column-target value="<?= View::e(implode(',', $customCols)) ?>">
      <button class="btn primary">Aplicar columnas</button>
    </details>
  </form>
</section>

<?php if (in_array($run['status'] ?? 'borrador', ['borrador', 'revisado'], true)): ?>
<section class="panel filter-bar">
  <form class="inline-form" method="post" action="<?= View::e($base) ?>/billing/date/update">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="id" value="<?= (int) $run['id'] ?>">
    <div class="field"><label>Base manual final</label><input class="input" type="number" step="0.01" name="manual_base" value="<?= View::e($run['manual_base'] ?? ($run['net_after_shipping'] ?? $run['estimated_net'])) ?>" required></div>
    <button class="btn primary">Guardar base</button>
  </form>
</section>
<?php endif; ?>

<?php if (in_array(Auth::role(), ['admin', 'operador'], true)): ?>
<section class="panel filter-bar">
  <form class="inline-form" method="post" action="<?= View::e($base) ?>/billing/date/transition">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="id" value="<?= (int) $run['id'] ?>">
    <div class="field"><label>Nuevo estado</label><select class="input" name="status">
      <?php $status = (string) ($run['status'] ?? 'borrador'); $next = match ($status) {'borrador' => Auth::role() === 'admin' ? ['revisado', 'anulado'] : ['revisado'], 'revisado' => Auth::role() === 'admin' ? ['borrador', 'aprobado', 'anulado'] : ['borrador'], 'aprobado' => Auth::role() === 'admin' ? ['facturado', 'anulado'] : [], default => []}; foreach ($next as $state): ?><option><?= View::e($state) ?></option><?php endforeach; ?>
    </select></div>
    <div class="field"><label>Referencia factura (al facturar)</label><input class="input" name="reference" value="<?= View::e($run['external_invoice_reference'] ?? '') ?>"></div>
    <?php if ($next): ?><button class="btn primary" data-confirm="Confirma el cambio de estado de la facturación por fechas.">Actualizar estado</button><?php endif; ?>
  </form>
</section>
<?php endif; ?>

<section class="panel table-panel">
  <header class="panel-head"><h2>Productos del rango</h2><span class="muted"><?= count($items) ?> líneas</span></header>
  <div class="table-scroll"><table class="data-table">
    <thead><tr>
      <?php if ($show('producto')): ?><th>Producto</th><?php endif; ?>
      <?php if ($show('sku')): ?><th>SKU</th><?php endif; ?>
      <?php if ($show('account')): ?><th>Cuenta</th><?php endif; ?>
      <?php if ($show('orders')): ?><th>Órdenes</th><?php endif; ?>
      <?php if ($show('units')): ?><th>Unidades</th><?php endif; ?>
      <?php if ($show('product')): ?><th>Producto total</th><?php endif; ?>
      <?php if ($show('avg_unit')): ?><th>Prom. unidad</th><?php endif; ?>
      <?php if ($show('avg_order')): ?><th>Prom. orden</th><?php endif; ?>
      <?php if ($show('fees')): ?><th>Comisión</th><?php endif; ?>
      <?php if ($show('fees_unit')): ?><th>Comisión/u</th><?php endif; ?>
      <?php if ($show('fees_order')): ?><th>Comisión/orden</th><?php endif; ?>
      <?php if ($show('buyer_shipping')): ?><th>Envío comprador</th><?php endif; ?>
      <?php if ($show('ml_shipping')): ?><th>Cargo ME</th><?php endif; ?>
      <?php if ($show('shipping_net')): ?><th>Envío neto</th><?php endif; ?>
      <?php if ($show('net_without')): ?><th>Neto sin envío</th><?php endif; ?>
      <?php if ($show('net_after')): ?><th>Neto después envío</th><?php endif; ?>
      <?php if ($show('reconciled')): ?><th>Neto ML conciliado</th><?php endif; ?>
      <?php if ($show('unit_cost')): ?><th>Costo unitario</th><?php endif; ?>
      <?php if ($show('total_cost')): ?><th>Costo total</th><?php endif; ?>
      <?php if ($show('profit')): ?><th>Utilidad</th><?php endif; ?>
      <?php if ($show('profit_unit')): ?><th>Utilidad/u</th><?php endif; ?>
      <?php if ($show('profit_order')): ?><th>Utilidad/orden</th><?php endif; ?>
      <?php if ($show('margin')): ?><th>Margen</th><?php endif; ?>
      <?php if ($show('cost_status')): ?><th>Estado costo</th><?php endif; ?>
      <?php if ($show('financial_status')): ?><th>Estado conciliación</th><?php endif; ?>
      <?php if ($show('external_item')): ?><th>Ítem ML</th><?php endif; ?>
      <?php if ($show('external_variation')): ?><th>Variación</th><?php endif; ?>
      <?php if ($show('factor')): ?><th>Factor</th><?php endif; ?>
      <?php if ($show('internal_id')): ?><th>ID interno</th><?php endif; ?>
      <?php if ($show('actions')): ?><th>Acciones</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($items as $item): $costPending = ($item['cost_status'] ?? 'ok') !== 'ok' || (float) ($item['unit_cost'] ?? 0) <= 0; $qty = max(0.0001, (float) $item['units_sold']); $orders = max(1, (int) $item['orders_count']); ?>
      <tr>
        <?php if ($show('producto')): ?><td><?= View::e($item['internal_name'] ?: $item['product_title']) ?></td><?php endif; ?>
        <?php if ($show('sku')): ?><td><?= View::e($item['seller_sku'] ?: '—') ?></td><?php endif; ?>
        <?php if ($show('account')): ?><td><?= View::e($run['account_name'] ?? '') ?></td><?php endif; ?>
        <?php if ($show('orders')): ?><td><?= (int) $item['orders_count'] ?></td><?php endif; ?>
        <?php if ($show('units')): ?><td><?= $units($item['units_sold']) ?></td><?php endif; ?>
        <?php if ($show('product')): ?><td><?= $money($item['product_revenue']) ?></td><?php endif; ?>
        <?php if ($show('avg_unit')): ?><td><?= $money((float) $item['product_revenue'] / $qty) ?></td><?php endif; ?>
        <?php if ($show('avg_order')): ?><td><?= $money((float) $item['product_revenue'] / $orders) ?></td><?php endif; ?>
        <?php if ($show('fees')): ?><td><?= $money($item['marketplace_fees']) ?></td><?php endif; ?>
        <?php if ($show('fees_unit')): ?><td><?= $money((float) $item['marketplace_fees'] / $qty) ?></td><?php endif; ?>
        <?php if ($show('fees_order')): ?><td><?= $money((float) $item['marketplace_fees'] / $orders) ?></td><?php endif; ?>
        <?php if ($show('buyer_shipping')): ?><td><?= $money($item['buyer_shipping_paid'] ?? $item['shipping_revenue'] ?? 0) ?></td><?php endif; ?>
        <?php if ($show('ml_shipping')): ?><td><?= $money($item['ml_shipping_charge'] ?? 0) ?></td><?php endif; ?>
        <?php if ($show('shipping_net')): ?><td><?= $money($item['shipping_net_amount'] ?? 0) ?></td><?php endif; ?>
        <?php if ($show('net_without')): ?><td><?= $money($item['net_without_shipping'] ?? $item['estimated_net']) ?></td><?php endif; ?>
        <?php if ($show('net_after')): ?><td><?= $money($item['net_after_shipping'] ?? $item['estimated_net']) ?></td><?php endif; ?>
        <?php if ($show('reconciled')): ?><td><?= $money($item['reconciled_net_amount'] ?? null) ?></td><?php endif; ?>
        <?php if ($show('unit_cost')): ?><td><?= $costPending ? 'Sin costo' : $money($item['unit_cost']) ?></td><?php endif; ?>
        <?php if ($show('total_cost')): ?><td><?= $costPending ? 'Pendiente' : $money($item['total_cost']) ?></td><?php endif; ?>
        <?php if ($show('profit')): ?><td><?= $costPending ? 'Pendiente' : $money($item['estimated_profit']) ?></td><?php endif; ?>
        <?php if ($show('profit_unit')): ?><td><?= $costPending ? 'Pendiente' : $money((float) $item['estimated_profit'] / $qty) ?></td><?php endif; ?>
        <?php if ($show('profit_order')): ?><td><?= $costPending ? 'Pendiente' : $money((float) $item['estimated_profit'] / $orders) ?></td><?php endif; ?>
        <?php if ($show('margin')): ?><td><?= $costPending ? 'Pendiente' : $percent($item['margin_percent']) ?></td><?php endif; ?>
        <?php if ($show('cost_status')): ?><td><span class="badge <?= $costPending ? 'amber' : 'green' ?>"><?= $costPending ? 'Costo pendiente' : 'OK' ?></span></td><?php endif; ?>
        <?php if ($show('financial_status')): ?><td><span class="badge <?= (int) ($item['pending_financial_count'] ?? 0) > 0 ? 'amber' : 'green' ?>"><?= (int) ($item['pending_financial_count'] ?? 0) > 0 ? 'Pendiente' : 'Calculado' ?></span></td><?php endif; ?>
        <?php if ($show('external_item')): ?><td><?= View::e($item['external_item_id']) ?></td><?php endif; ?>
        <?php if ($show('external_variation')): ?><td><?= View::e((string) ($item['external_variation_id'] ?: '—')) ?></td><?php endif; ?>
        <?php if ($show('factor')): ?><td><?= $units($item['conversion_factor']) ?></td><?php endif; ?>
        <?php if ($show('internal_id')): ?><td><?= (int) ($item['internal_product_id'] ?? 0) ?></td><?php endif; ?>
        <?php if ($show('actions')): ?><td><a class="btn small" href="<?= View::e($base) ?>/billing/date/item-orders?run_id=<?= (int) $run['id'] ?>&item_id=<?= (int) $item['id'] ?>">Ver órdenes</a></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
