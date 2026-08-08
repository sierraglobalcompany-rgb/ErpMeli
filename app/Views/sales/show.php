<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$money = static fn(mixed $value, string $currency = 'COP'): string =>
    ($currency === 'COP' ? '$' : $currency . ' ') . number_format((float) $value, 0, ',', '.');
$itemsByOrder = [];
foreach ($sale['items'] as $item) {
    $itemsByOrder[(int) $item['meli_order_id']][] = $item;
}
$currency = (string) ($sale['orders'][0]['currency_id'] ?? 'COP');
$productsAmount = 0.0;
$units = 0;
foreach ($sale['items'] as $item) {
    $productsAmount += (float) $item['unit_price'] * (int) $item['quantity'];
    $units += (int) $item['quantity'];
}
$pack = $sale['pack'];
$integrity = (string) ($pack['integrity_status'] ?? ($sale['is_pack'] ? 'provisional' : 'complete'));
$integrityPresentation = match ($integrity) {
    'complete' => ['Venta completa', 'green', 'Todas las órdenes esperadas están enlazadas.'],
    'partial' => ['Falta información', 'amber', (string) ($pack['integrity_message'] ?? 'Falta una orden del paquete.')],
    'review' => ['Requiere revisión', 'red', (string) ($pack['integrity_message'] ?? 'La relación del paquete necesita revisión.')],
    'pending' => ['Verificando el paquete', 'blue', 'El lanzador comprobará las órdenes que componen esta venta.'],
    default => ['Reconstruida localmente', 'amber', 'Las órdenes locales ya están agrupadas; falta verificar el paquete con Mercado Libre.'],
};
[$integrityTitle, $integrityTone, $integrityMessage] = $integrityPresentation;
$financial = $sale['financial'];
$financialState = $sale['financial_state'] ?? null;
$completeness = (array) ($sale['financial_completeness'] ?? []);
$financialStatus = (string) ($financialState['official_status'] ?? 'missing');
$financialApproved = is_array($financialState)
    && $financialStatus === 'complete'
    && $financialState['official_net_amount'] !== null;
$financialAvailable = (new \App\Services\SchemaInspectorService())->hasTable('sale_financial_state')
    && (new \App\Services\SchemaInspectorService())->hasTable('sale_financial_reconciliation_jobs');
$financialWaiting = is_array($completeness['active_job'] ?? null);
$nextAction = $integrity !== 'complete'
    ? 'Primero complete la verificación de las órdenes del paquete.'
    : ($financialStatus === 'complete'
        ? 'La venta está conciliada con información oficial.'
        : ($financialWaiting
            ? 'La información financiera oficial ya está programada.'
            : 'Programe un paso exacto para obtener el neto oficial.'));
$externalUrl = 'https://www.mercadolibre.com.co/ventas/' . rawurlencode((string) $sale['sale_id']) . '/detalle';
?>
<div class="page-head sale-detail-head">
  <div>
    <a class="back-link" href="<?= View::e($base) ?>/sales">← Volver a ventas</a>
    <h1>Venta #<?= View::e($sale['sale_id']) ?></h1>
    <p><?= count($sale['items']) ?> <?= count($sale['items']) === 1 ? 'producto' : 'productos' ?> · <?= $units ?> <?= $units === 1 ? 'unidad' : 'unidades' ?> · <?= count($sale['orders']) ?> <?= count($sale['orders']) === 1 ? 'orden API' : 'órdenes API' ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($externalUrl) ?>" target="_blank" rel="noopener noreferrer">Abrir en Mercado Libre</a>
  </div>
</div>

<section class="sale-conclusion is-<?= View::e($integrityTone) ?>" aria-labelledby="sale-conclusion-title">
  <div>
    <span class="badge <?= View::e($integrityTone) ?>"><?= View::e($integrityTitle) ?></span>
    <h2 id="sale-conclusion-title"><?= View::e($nextAction) ?></h2>
    <p><?= View::e($integrityMessage) ?></p>
  </div>
  <?php if ($integrity === 'complete' && $financialAvailable && !$financialWaiting && in_array(Auth::role(), ['admin', 'operador'], true) && $financialStatus !== 'complete'): ?>
    <form method="post" action="<?= View::e($base) ?>/sales/financial/queue">
      <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
      <input type="hidden" name="account_id" value="<?= (int) $sale['account']['id'] ?>">
      <input type="hidden" name="sale_id" value="<?= View::e($sale['sale_id']) ?>">
      <button class="btn primary" type="submit">Programar un paso financiero</button>
    </form>
  <?php elseif ($integrity !== 'complete'): ?>
    <a class="btn primary" href="<?= View::e($base) ?>/sales/integrity?account_id=<?= (int) $sale['account']['id'] ?>">Revisar integridad</a>
  <?php endif; ?>
</section>

<div class="sale-detail-layout">
<div class="sale-detail-main">
    <section class="panel sale-section">
      <div class="panel-head"><div><h2>Productos de la venta</h2><p>Todos los productos y cantidades reunidos bajo el número visible de Mercado Libre.</p></div></div>
      <div class="sale-product-list">
        <?php foreach ($sale['items'] as $item): ?>
          <article class="sale-product">
            <div class="sale-product-qty"><?= (int) $item['quantity'] ?>×</div>
            <div><strong><?= View::e($item['title']) ?></strong><small><?= View::e($item['seller_sku'] ?: $item['external_item_id']) ?> · Orden API <?= View::e(array_column($sale['orders'], 'external_order_id', 'id')[(int) $item['meli_order_id']] ?? '') ?></small></div>
            <div class="sale-product-price"><strong><?= View::e($money((float) $item['unit_price'] * (int) $item['quantity'], $currency)) ?></strong><small><?= View::e($money($item['unit_price'], $currency)) ?> por unidad</small></div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="panel sale-section">
      <div class="panel-head"><div><h2>Resumen financiero</h2><p>La proyección local y el resultado oficial son estados independientes.</p></div></div>
      <?php if (!is_array($financialState)): ?>
        <div class="sale-empty-inline"><strong>Sin proyección financiera</strong><p>La orden existe, pero todavía no se ha materializado su estado financiero V3.</p></div>
      <?php else: ?>
        <dl class="sale-money-list">
          <div><dt>Total de productos</dt><dd><?= View::e($money($financialState['products_amount'], $currency)) ?></dd></div>
          <div><dt>Neto provisional local</dt><dd><?= $financialState['provisional_net_amount'] === null ? 'Pendiente' : View::e($money($financialState['provisional_net_amount'], $currency)) ?></dd></div>
          <div class="sale-money-total"><dt>Neto oficial Mercado Libre</dt><dd><?= $financialApproved ? View::e($money($financialState['official_net_amount'], $currency)) : 'Pendiente' ?></dd></div>
        </dl>
      <?php endif; ?>
      <?php if (is_array($financialState) && !$financialApproved): ?>
        <div class="sale-empty-inline">
          <strong>Información financiera todavía no aprobada</strong>
          <p>El valor provisional ayuda a operar, pero no sustituye la evidencia oficial de Billing.</p>
        </div>
      <?php elseif ($financialApproved && is_array($financial)): ?>
        <dl class="sale-money-list">
          <div><dt>Productos</dt><dd><?= View::e($money($financial['products_amount'], $currency)) ?></dd></div>
          <div><dt>Cargos por venta</dt><dd>−<?= View::e($money($financial['sale_fee_amount'], $currency)) ?></dd></div>
          <div><dt>Cargo bruto de envío</dt><dd>−<?= View::e($money($financial['shipping_charge_amount'], $currency)) ?></dd></div>
          <div><dt>Impuestos y retenciones</dt><dd>−<?= View::e($money($financial['taxes_amount'], $currency)) ?></dd></div>
          <?php if ((float) $financial['discounts_amount'] > 0): ?><div><dt>Descuentos oficiales</dt><dd>+<?= View::e($money($financial['discounts_amount'], $currency)) ?></dd></div><?php endif; ?>
          <?php if ((float) $financial['credits_amount'] > 0): ?><div><dt>Créditos y bonificaciones</dt><dd>+<?= View::e($money($financial['credits_amount'], $currency)) ?></dd></div><?php endif; ?>
          <?php if ((float) $financial['adjustments_amount'] > 0): ?><div><dt>Ajustes y devoluciones</dt><dd>−<?= View::e($money($financial['adjustments_amount'], $currency)) ?></dd></div><?php endif; ?>
          <div class="sale-money-total"><dt>Neto conciliado con cargos oficiales</dt><dd><?= View::e($money($financialState['official_net_amount'], $currency)) ?></dd></div>
        </dl>
      <?php endif; ?>
    </section>

    <?php if ($financialApproved && $sale['allocations'] !== []): ?>
      <section class="panel sale-section">
        <div class="panel-head"><div><h2>Distribución calculada por el ERP</h2><p>Reparte cargos compartidos para analizar rentabilidad. No es un valor entregado por Mercado Libre.</p></div></div>
        <div class="table-scroll"><table class="data-table"><caption>Distribución interna por producto</caption><thead><tr><th>Producto</th><th>Bruto</th><th>Envío asignado</th><th>Impuestos asignados</th><th>Neto analítico</th></tr></thead><tbody><?php foreach ($sale['allocations'] as $allocation): ?><tr><td><?= View::e($allocation['title']) ?><small class="muted">Orden <?= View::e($allocation['external_order_id']) ?></small></td><td><?= View::e($money($allocation['gross_amount'], $currency)) ?></td><td><?= View::e($money($allocation['shipping_allocated'], $currency)) ?></td><td><?= View::e($money($allocation['tax_allocated'], $currency)) ?></td><td><strong><?= View::e($money($allocation['net_allocated'], $currency)) ?></strong></td></tr><?php endforeach; ?></tbody></table></div>
      </section>
    <?php endif; ?>

    <section class="panel sale-section">
      <div class="panel-head"><div><h2>Envío compartido</h2><p>Se muestra una sola vez para toda la venta.</p></div></div>
      <?php if ($sale['shipments'] === []): ?><div class="sale-empty-inline"><strong>Envío pendiente de completar</strong><p>La venta puede seguir siendo válida; falta enlazar la información logística del paquete.</p></div><?php else: ?><div class="sale-shipment-list"><?php foreach ($sale['shipments'] as $shipment): ?><div><strong>Envío <?= View::e($shipment['external_shipment_id']) ?></strong><span><?= View::e($shipment['status'] ?: 'Sin estado') ?> · <?= View::e($shipment['logistic_type'] ?: 'Tipo por comprobar') ?></span></div><?php endforeach; ?></div><?php endif; ?>
    </section>
</div>

  <aside class="sale-detail-aside">
    <section class="panel sale-facts">
      <h2>Resumen</h2>
      <dl>
        <div><dt>Cuenta</dt><dd><?= View::e($sale['account']['account_name']) ?></dd></div>
        <div><dt>Total productos</dt><dd><?= View::e($money($productsAmount, $currency)) ?></dd></div>
        <div><dt>Órdenes enlazadas</dt><dd><?= count($sale['orders']) ?><?= is_array($pack) && $pack['expected_orders_count'] !== null ? ' de ' . (int) $pack['expected_orders_count'] : '' ?></dd></div>
        <div><dt>Comercial</dt><dd><?= View::e((string) ($completeness['commercial'] ?? 'missing')) ?></dd></div>
        <div><dt>Logística</dt><dd><?= View::e((string) ($completeness['logistics'] ?? 'missing')) ?></dd></div>
        <div><dt>Provisional</dt><dd><?= View::e((string) ($completeness['provisional'] ?? 'missing')) ?></dd></div>
        <div><dt>Oficial</dt><dd><?= View::e((string) ($completeness['official'] ?? 'missing')) ?></dd></div>
      </dl>
    </section>
    <details class="panel sale-technical">
      <summary>Órdenes API y detalles técnicos</summary>
      <div>
        <?php foreach ($sale['orders'] as $order): ?>
          <a class="<?= (string) $order['external_order_id'] === $highlightOrder ? 'is-highlighted' : '' ?>" href="<?= View::e($base) ?>/sales/orders?account_id=<?= (int) $sale['account']['id'] ?>&amp;sale_id=<?= View::e($sale['sale_id']) ?>">
            Orden <?= View::e($order['external_order_id']) ?> · <?= View::e($order['status']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </details>
  </aside>
</div>
