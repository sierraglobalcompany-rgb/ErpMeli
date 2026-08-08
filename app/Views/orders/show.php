<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$money = static fn($v) => $v === null ? 'Pendiente' : '$ ' . number_format((float) $v, 0, ',', '.');
$percent = static fn($v) => $v === null ? 'Pendiente' : number_format((float) $v, 2, ',', '.') . ' %';
$returnTo = $returnTo ?? '/orders';
$meliSaleUrl = 'https://www.mercadolibre.com.co/ventas/' . rawurlencode((string) $order['external_order_id']) . '/detalle';
$status = (string) ($financial['reconciliation_status'] ?? 'pending');
$billingImported = $financial && (($financial['billing_import_status'] ?? '') === 'imported' || ($financial['financial_source'] ?? '') === 'billing_order_details');
$billingNeeded = $financial && !$billingImported && in_array((string) ($financial['billing_import_status'] ?? ''), ['billing_needed','pending','unavailable'], true);
$buyerShippingValue = $billingNeeded && (float) ($financial['buyer_shipping_paid'] ?? 0) <= 0 ? null : ($financial['buyer_shipping_paid'] ?? null);
$taxTotal = $financial ? (float) ($financial['tax_withholding_amount'] ?? $financial['withholdings_amount'] ?? 0) : 0.0;
$netLabel = $billingImported ? 'Neto ML conciliado' : 'Neto local parcial';
$netValue = $billingImported ? ($financial['billing_reconciled_net_amount'] ?? $financial['ml_net_amount'] ?? null) : ($financial['local_estimated_net_amount'] ?? $financial['ml_net_amount'] ?? null);
$statusClass = match ($status) {
    'matched', 'calculated' => 'green',
    'difference', 'error' => 'red',
    'manual_review', 'queued' => 'amber',
    default => 'gray',
};
$canOperate = in_array(Auth::role(), ['admin', 'operador'], true) && !Auth::isTemporary();
?>
<div class="page-head">
  <div>
    <a class="muted" href="<?= View::e($base . $returnTo) ?>">← Volver</a>
    <h1>Orden <?= View::e($order['external_order_id']) ?></h1>
    <p><?= View::e($order['account_name']) ?> · sincronizada <?= View::e($order['synced_at']) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($meliSaleUrl) ?>" target="_blank" rel="noopener">Ver pedido en Mercado Libre</a>
    <span class="badge <?= $order['status'] === 'cancelled' ? 'red' : 'green' ?>"><?= View::e($order['status']) ?></span>
  </div>
</div>

<?php if (!$financial): ?>
  <div class="alert warning">Esta orden aún no tiene conciliación financiera. Use “Recalcular financiero” para calcularla con datos locales seguros.</div>
<?php else: ?>
  <div class="alert <?= $statusClass === 'red' ? 'danger' : ($statusClass === 'green' ? 'success' : 'warning') ?>">
    <strong>Conciliación:</strong> <?= View::e($status) ?> · <?= View::e($financial['safe_message'] ?: 'Sin observaciones.') ?>
  </div>
  <?php if ($billingNeeded): ?>
    <div class="alert warning">El neto visible es parcial: faltan datos de billing como envío pagado por comprador, impuestos o retenciones. Al ejecutar el recálculo financiero, el mismo job intentará completar billing automáticamente.</div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($canOperate): ?>
<section class="panel filter-bar">
  <div class="inline-form">
    <form method="post" action="<?= View::e($base) ?>/orders/refresh">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
      <button class="btn">Actualizar orden</button>
    </form>
    <form method="post" action="<?= View::e($base) ?>/orders/financial/queue">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
      <button class="btn">Encolar financiero</button>
    </form>
    <form method="post" action="<?= View::e($base) ?>/orders/financial/recalculate">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
      <button class="btn primary">Recalcular financiero</button>
    </form>
    <form method="post" action="<?= View::e($base) ?>/orders/financial/manual-review">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
      <input type="hidden" name="message" value="Revisión manual solicitada desde detalle de orden.">
      <button class="btn">Marcar revisión manual</button>
    </form>
  </div>
</section>
<?php endif; ?>

<section class="detail-grid">
  <article class="panel detail-block">
    <h3>Venta / comprador</h3>
    <div class="detail-list">
      <div><span>Producto vendido</span><strong><?= $money($financial['product_sold_amount'] ?? $order['total_amount']) ?></strong></div>
      <div><span>Envío pagado por comprador</span><strong><?= $money($buyerShippingValue) ?></strong></div>
      <div><span>Total cobrado al comprador</span><strong><?= $money($financial['buyer_paid_total'] ?? $order['paid_amount']) ?></strong></div>
      <div><span>Ayuda</span><small>Incluye producto + envío pagado por el comprador. No representa el neto recibido por el vendedor.</small></div>
    </div>
  </article>
  <article class="panel detail-block">
    <h3>Cargos Mercado Libre</h3>
    <div class="detail-list">
      <div><span>Comisión / cargo venta</span><strong><?= $money($financial['sale_fee_amount'] ?? null) ?></strong></div>
      <div><span>% comisión estimado</span><strong><?= $percent($financial['sale_fee_rate'] ?? null) ?></strong></div>
      <div><span>Cargo Mercado Envíos</span><strong><?= $money($financial['ml_shipping_charge'] ?? null) ?></strong></div>
      <div><span>Envío neto</span><strong><?= $money($financial['shipping_net_amount'] ?? null) ?></strong></div>
      <div><span>Retención fuente</span><strong><?= $money(($financial['tax_retention_source_amount'] ?? null) !== null ? -(float) $financial['tax_retention_source_amount'] : null) ?></strong></div>
      <div><span>ReteIVA</span><strong><?= $money(($financial['tax_reteiva_amount'] ?? null) !== null ? -(float) $financial['tax_reteiva_amount'] : null) ?></strong></div>
      <div><span>Total impuestos/retenciones</span><strong><?= $taxTotal > 0 ? $money(-$taxTotal) : ($billingNeeded ? 'Pendiente billing' : $money(0)) ?></strong></div>
    </div>
  </article>
  <article class="panel detail-block">
    <h3>Resultado ML / ERP</h3>
    <div class="detail-list">
      <div><span><?= View::e($netLabel) ?></span><strong><?= $money($netValue) ?></strong></div>
      <?php if ($billingImported && ($financial['local_estimated_net_amount'] ?? null) !== null): ?>
        <div><span>Neto local parcial anterior</span><strong><?= $money($financial['local_estimated_net_amount']) ?></strong></div>
        <div><span>Diferencia por billing</span><strong><?= $money($financial['reconciliation_difference'] ?? null) ?></strong></div>
      <?php endif; ?>
      <div><span>Costo interno</span><strong><?= $money($financial['internal_cost_amount'] ?? null) ?></strong></div>
      <div><span>Utilidad real ERP</span><strong><?= $money($financial['erp_profit_amount'] ?? null) ?></strong></div>
      <div><span>Margen</span><strong><?= $percent($financial['erp_margin_percent'] ?? null) ?></strong></div>
      <?php if (($financial['missing_cost_items_count'] ?? 0) > 0): ?>
        <div><span>Pendientes</span><strong><?= (int) $financial['missing_cost_items_count'] ?> ítems sin vínculo/costo</strong></div>
      <?php endif; ?>
    </div>
  </article>
</section>

<section class="panel table-panel mt-2">
  <header class="panel-head"><h2>Productos</h2></header>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>SKU</th><th>Producto</th><th>Cantidad</th><th>Precio unitario</th><th>Comisión</th></tr></thead>
      <tbody>
      <?php foreach ($items as $i): ?>
        <tr>
          <td><?= View::e($i['seller_sku'] ?: 'Sin SKU') ?></td>
          <td><?= View::e($i['title']) ?></td>
          <td><?= number_format((float) $i['quantity'], (float) $i['quantity'] == (int) $i['quantity'] ? 0 : 2, ',', '.') ?></td>
          <td><?= $money($i['unit_price']) ?></td>
          <td><?= $money($i['sale_fee']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="detail-grid mt-2">
  <article class="panel detail-block">
    <h3>Pagos</h3>
    <?php foreach ($payments as $p): ?>
      <div class="detail-list">
        <div><span><?= View::e($p['external_payment_id']) ?></span><strong><?= View::e($p['status']) ?></strong></div>
        <div><span>Monto transacción</span><strong><?= $money($p['transaction_amount']) ?></strong></div>
        <div><span>Aprobado</span><strong><?= View::e($p['date_approved'] ?: '—') ?></strong></div>
      </div>
    <?php endforeach; ?>
  </article>
  <article class="panel detail-block">
    <h3>Envío</h3>
    <?php foreach ($shipments as $s): ?>
      <div class="detail-list">
        <div><span><?= View::e($s['external_shipment_id']) ?></span><strong><?= View::e($s['status']) ?></strong></div>
        <div><span>Logística</span><strong><?= View::e($s['logistic_type'] ?: '—') ?></strong></div>
        <div><span>Tracking</span><strong><?= View::e($s['tracking_number'] ?: '—') ?></strong></div>
        <div><span>Costo vendedor</span><strong><?= $money($s['seller_cost']) ?></strong></div>
      </div>
    <?php endforeach; ?>
  </article>
  <article class="panel detail-block">
    <h3>Comprador / auditoría</h3>
    <div class="detail-list">
      <div><span>ID comprador</span><strong><?= View::e($order['buyer_id'] ?: '—') ?></strong></div>
      <div><span>Nickname</span><strong><?= View::e($order['buyer_nickname'] ?: '—') ?></strong></div>
      <div><span>Creada</span><strong><?= View::e($order['date_created'] ?: '—') ?></strong></div>
      <div><span>Archivo raw</span><strong><?= View::e(Auth::role() === 'admin' ? $order['raw_path'] : 'Protegido') ?></strong></div>
    </div>
  </article>
</section>

<section class="panel table-panel mt-2">
  <header class="panel-head"><h2>Líneas financieras guardadas</h2><span class="muted">Solo fuentes seguras/locales o confirmadas</span></header>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Tipo</th><th>Clasificación</th><th>Descripción</th><th>Fuente</th><th>Valor</th></tr></thead>
      <tbody>
      <?php if (!$billingDetails): ?><tr><td colspan="5"><div class="empty">Aún no hay líneas financieras. Recalcule o encole la conciliación.</div></td></tr><?php endif; ?>
      <?php foreach ($billingDetails as $detail): ?>
        <tr>
          <td><?= View::e($detail['detail_type'] . ' / ' . ($detail['detail_subtype'] ?: '—')) ?></td>
          <td><?= View::e($detail['classification'] ?? '—') ?></td>
          <td><?= View::e($detail['description'] ?: '—') ?></td>
          <td><span class="badge"><?= View::e($detail['source_status']) ?></span></td>
          <td><?= $money($detail['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
