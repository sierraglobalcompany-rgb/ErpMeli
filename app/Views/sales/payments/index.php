<?php
use App\Core\Auth;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$money = static fn ($v) => '$ ' . number_format((float) $v, 0, ',', '.');
$detailStatuses = $options['detail_statuses'] ?? ['summary'];
$diagnostics = is_array($diagnostics ?? null) ? $diagnostics : [];
$isAdmin = Auth::role() === 'admin';
?>
<div class="page-head">
  <div>
    <h1>Pagos</h1>
    <p>Resumen filtrable de pagos guardados desde órdenes. El detalle externo de pagos está desactivado por seguridad.</p>
  </div>
</div>

<?php if (!empty($error)): ?><div class="alert danger"><?= View::e($error) ?></div><?php endif; ?>
<?php if (empty($diagnostics['table_exists'])): ?>
  <div class="alert warning">Falta la tabla <code>meli_payments</code>. Ejecute las migraciones pendientes desde Configuración → Actualizador.</div>
<?php elseif (!empty($diagnostics['missing_required'])): ?>
  <div class="alert warning">La tabla de pagos existe, pero le faltan columnas base: <?= View::e(implode(', ', $diagnostics['missing_required'])) ?>.</div>
<?php elseif (!empty($diagnostics['missing_detail'])): ?>
  <div class="alert info">Pagos cargará en modo resumen. Faltan columnas de detalle: <?= View::e(implode(', ', $diagnostics['missing_detail'])) ?>.</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<section class="panel">
  <h2>Diagnóstico de pagos</h2>
  <div class="metrics compact">
    <article class="metric-card"><div><div class="metric-label">Tabla meli_payments</div><div class="metric-value"><?= !empty($diagnostics['table_exists']) ? 'OK' : 'Falta' ?></div></div></article>
    <article class="metric-card"><div><div class="metric-label">Fuente</div><div class="metric-value"><?= View::e($diagnostics['source'] ?? 'orders_summary') ?></div></div></article>
    <article class="metric-card"><div><div class="metric-label">Detalle externo</div><div class="metric-value"><?= !empty($diagnostics['expand_details_enabled']) ? 'Activo' : 'Apagado' ?></div></div></article>
    <article class="metric-card"><div><div class="metric-label">Última migración pagos</div><div class="metric-value"><?= View::e($diagnostics['latest_payment_migration'] ?? '—') ?></div></div></article>
  </div>
  <?php if (!empty($diagnostics['last_error'])): ?><p class="muted">Último error seguro: <?= View::e($diagnostics['last_error']) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<section class="panel filter-bar">
  <form class="filters" method="get" action="<?= View::e($base) ?>/payments">
    <div class="field"><label>Cuenta</label><select class="input" name="account_id"><option value="0">Todas</option><?php foreach($options['accounts'] as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$filters['account_id']===(int)$a['id']?'selected':'' ?>><?= View::e($a['account_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Estado</label><select class="input" name="status"><option value="">Todos</option><?php foreach($options['statuses'] as $value): ?><option value="<?= View::e($value) ?>" <?= $filters['status']===$value?'selected':'' ?>><?= View::e($value) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Método</label><select class="input" name="payment_method_id"><option value="">Todos</option><?php foreach($options['methods'] as $value): ?><option value="<?= View::e($value) ?>" <?= $filters['payment_method_id']===$value?'selected':'' ?>><?= View::e($value) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Tipo</label><select class="input" name="payment_type"><option value="">Todos</option><?php foreach($options['types'] as $value): ?><option value="<?= View::e($value) ?>" <?= $filters['payment_type']===$value?'selected':'' ?>><?= View::e($value) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Detalle</label><select class="input" name="detail_status"><option value="">Todos</option><?php foreach($detailStatuses as $value): ?><option value="<?= View::e($value) ?>" <?= $filters['detail_status']===$value?'selected':'' ?>><?= View::e($value) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Fecha por</label><select class="input" name="date_field"><option value="approved" <?= $filters['date_field']==='approved'?'selected':'' ?>>Aprobación</option><option value="synced" <?= $filters['date_field']==='synced'?'selected':'' ?>>Sincronización</option></select></div>
    <div class="field"><label>Desde</label><input class="input" type="date" name="from" value="<?= View::e($filters['from']) ?>"></div>
    <div class="field"><label>Hasta</label><input class="input" type="date" name="to" value="<?= View::e($filters['to']) ?>"></div>
    <div class="field"><label>ID pago u orden</label><input class="input" name="q" value="<?= View::e($filters['q']) ?>"></div>
    <div class="field"><label>Ordenar</label><select class="input" name="sort"><option value="date" <?= $filters['sort']==='date'?'selected':'' ?>>Fecha</option><option value="status" <?= $filters['sort']==='status'?'selected':'' ?>>Estado</option><option value="method" <?= $filters['sort']==='method'?'selected':'' ?>>Método</option><option value="amount" <?= $filters['sort']==='amount'?'selected':'' ?>>Monto</option><option value="fee" <?= $filters['sort']==='fee'?'selected':'' ?>>Comisión</option></select></div>
    <div class="field"><label>Dirección</label><select class="input" name="dir"><option value="DESC" <?= strtoupper($filters['dir'])==='DESC'?'selected':'' ?>>Desc</option><option value="ASC" <?= strtoupper($filters['dir'])==='ASC'?'selected':'' ?>>Asc</option></select></div>
    <div class="field"><label>&nbsp;</label><button class="btn primary">Filtrar pagos</button></div>
  </form>
</section>

<section class="metrics">
  <article class="metric-card"><div><div class="metric-label">Pagos filtrados</div><div class="metric-value"><?= (int)$totals['count_rows'] ?></div></div></article>
  <article class="metric-card"><div><div class="metric-label">Monto</div><div class="metric-value"><?= $money($totals['amount']) ?></div></div></article>
  <article class="metric-card"><div><div class="metric-label">Comisiones</div><div class="metric-value"><?= $money($totals['fees']) ?></div></div></article>
  <article class="metric-card"><div><div class="metric-label">No aprobados</div><div class="metric-value"><?= (int)$totals['not_approved'] ?></div></div></article>
</section>

<section class="panel table-panel">
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Pago</th><th>Cuenta</th><th>Orden</th><th>Estado</th><th>Método</th><th>Tipo</th><th>Monto</th><th>Comisión</th><th>Detalle</th><th>Aprobado</th></tr></thead>
      <tbody>
      <?php if (!$payments): ?><tr><td colspan="10"><div class="empty">Sin pagos para estos filtros.</div></td></tr><?php endif; ?>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td><?= View::e($p['external_payment_id']) ?></td>
          <td><?= View::e($p['account_name']) ?></td>
          <td><?= View::e($p['external_order_id'] ?: '—') ?></td>
          <td><span class="badge <?= $p['status']==='approved'?'green':($p['status']==='rejected'||$p['status']==='cancelled'?'red':'amber') ?>"><?= View::e($p['status'] ?: '—') ?></span></td>
          <td><?= View::e($p['payment_method_id'] ?: '—') ?></td>
          <td><?= View::e($p['payment_type'] ?: '—') ?></td>
          <td><?= $money($p['transaction_amount']) ?></td>
          <td><?= $money($p['marketplace_fee']) ?></td>
          <td><span class="badge <?= ($p['detail_status'] ?? '')==='unavailable'?'amber':(($p['detail_status'] ?? '')==='error'?'red':'green') ?>"><?= View::e($p['detail_status'] ?? 'summary') ?></span></td>
          <td><?= View::e(DateTimePresenter::format($p['date_approved'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
