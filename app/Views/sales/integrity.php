<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
$base = rtrim(Env::get('APP_URL', ''), '/');
$statusLabels = [
    'provisional' => 'Reconstruida localmente',
    'pending' => 'Verificando',
    'complete' => 'Completa',
    'partial' => 'Falta información',
    'review' => 'Requiere revisión',
];
?>
<div class="page-head"><div><a class="back-link" href="<?= View::e($base) ?>/sales">← Volver a ventas</a><h1>Integridad de ventas agrupadas</h1><p>Reconstruya primero con datos locales y verifique después, sin repetir órdenes vigentes.</p></div><form method="post" action="<?= View::e($base) ?>/sales/integrity/reconcile"><input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="account_id" value="<?= (int) $accountId ?>"><button class="btn primary" type="submit">Reconstruir relaciones locales</button></form></div>
<div class="integrity-strip" aria-label="Resumen de integridad"><div><span>Ventas agrupadas</span><strong><?= (int) $summary['sales'] ?></strong></div><div><span>Completas</span><strong><?= (int) $summary['complete'] ?></strong></div><div><span>Faltan órdenes</span><strong><?= (int) $summary['missing_orders'] ?></strong></div><div><span>Trabajos pendientes</span><strong><?= (int) $summary['pending_jobs'] ?></strong></div></div>
<section class="panel sale-section"><div class="panel-head"><div><h2>Ventas que requieren seguimiento</h2><p>Los packs completos quedan al final. La verificación remota se procesa gradualmente por CLI.</p></div></div><?php if ($packs === []): ?><div class="empty"><strong>No hay packs reconstruidos.</strong><p>Ejecute la reconstrucción local para agrupar las órdenes históricas que ya conserva el ERP.</p></div><?php else: ?><div class="sale-rows"><?php foreach ($packs as $pack): $tone = $pack['integrity_status'] === 'complete' ? 'green' : ($pack['integrity_status'] === 'review' ? 'red' : 'amber'); ?><a class="sale-row" href="<?= View::e($base) ?>/sales/integrity/show?account_id=<?= (int) $pack['meli_account_id'] ?>&amp;sale_id=<?= View::e($pack['external_pack_id']) ?>"><div class="sale-row-main"><span class="sale-row-id">Venta #<?= View::e($pack['external_pack_id']) ?></span><strong><?= (int) $pack['linked_orders_count'] ?> órdenes enlazadas<?= $pack['expected_orders_count'] !== null ? ' de ' . (int) $pack['expected_orders_count'] : '' ?></strong><small><?= View::e($pack['account_name']) ?></small></div><div class="sale-row-finance"><span>Situación</span><strong><?= (int) $pack['missing_orders'] > 0 ? (int) $pack['missing_orders'] . ' órdenes faltantes' : 'Sin faltantes conocidos' ?></strong><small><?= View::e($pack['integrity_message'] ?: 'Pendiente de comprobar') ?></small></div><div class="sale-row-state"><span class="badge <?= View::e($tone) ?>"><?= View::e($statusLabels[(string) $pack['integrity_status']] ?? 'Por comprobar') ?></span><small>Abrir →</small></div></a><?php endforeach; ?></div><?php endif; ?></section>
