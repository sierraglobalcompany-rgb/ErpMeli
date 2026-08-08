<?php
use App\Core\Env;
use App\Core\View;
$base = rtrim(Env::get('APP_URL', ''), '/');
?>
<div class="page-head"><div><a class="back-link" href="<?= View::e($base) ?>/sales/show?account_id=<?= (int) $sale['account']['id'] ?>&amp;sale_id=<?= View::e($sale['sale_id']) ?>">← Volver a la venta</a><h1>Órdenes API de la venta #<?= View::e($sale['sale_id']) ?></h1><p>Información técnica para soporte. La operación diaria debe usar el número de venta.</p></div></div>
<section class="panel table-panel"><div class="table-scroll"><table class="data-table"><caption>Órdenes API enlazadas a la venta</caption><thead><tr><th>Orden API</th><th>Fecha</th><th>Estado</th><th>Total propio</th><th>Enriquecimiento</th></tr></thead><tbody><?php foreach ($sale['orders'] as $order): ?><tr><td><strong><?= View::e($order['external_order_id']) ?></strong></td><td><?= View::e($order['date_created'] ?? 'Por comprobar') ?></td><td><?= View::e($order['status'] ?? 'Por comprobar') ?></td><td><?= number_format((float) $order['total_amount'], 0, ',', '.') ?></td><td><?= View::e($order['enrichment_status'] ?? 'Por comprobar') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
