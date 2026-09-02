<?php
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;
$base = rtrim((string) Env::get('APP_URL', ''), '/');
?>
<div class="page-head"><div><h1>Devoluciones / Reclamos</h1><p>Solo lectura. Se relacionan con órdenes si el recurso de la API lo permite.</p></div></div>
<section class="panel filter-bar">
  <p class="muted">La búsqueda general de reclamos está retirada porque no tiene consumidor vigente. Los reclamos exactos notificados conservan su ruta segura.</p>
</section>
<section class="panel filter-bar">
  <form class="filters" method="get" action="<?= View::e($base) ?>/claims">
    <div class="field"><label>Cuenta</label><select class="input" name="account_id"><option value="0">Todas</option><?php foreach($options['accounts'] as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$filters['account_id']===(int)$a['id']?'selected':'' ?>><?= View::e($a['account_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Tipo</label><select class="input" name="type"><option value="">Todos</option><?php foreach($options['types'] as $value): ?><option value="<?= View::e($value) ?>" <?= $filters['type']===$value?'selected':'' ?>><?= View::e($value) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Etapa</label><select class="input" name="stage"><option value="">Todas</option><?php foreach($options['stages'] as $value): ?><option value="<?= View::e($value) ?>" <?= $filters['stage']===$value?'selected':'' ?>><?= View::e($value) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Estado</label><select class="input" name="status"><option value="">Todos</option><?php foreach($options['statuses'] as $value): ?><option value="<?= View::e($value) ?>" <?= $filters['status']===$value?'selected':'' ?>><?= View::e($value) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Razón</label><select class="input" name="reason_id"><option value="">Todas</option><?php foreach($options['reasons'] as $reason): ?><option value="<?= View::e($reason['reason_id']) ?>" <?= $filters['reason_id']===$reason['reason_id']?'selected':'' ?>><?= View::e($reason['reason_name'] ?: $reason['reason_id']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Desde</label><input class="input" type="date" name="from" value="<?= View::e($filters['from']) ?>"></div>
    <div class="field"><label>Hasta</label><input class="input" type="date" name="to" value="<?= View::e($filters['to']) ?>"></div>
    <div class="field"><label>&nbsp;</label><button class="btn primary">Filtrar</button></div>
  </form>
</section>
<section class="panel table-panel"><div class="table-scroll"><table class="data-table"><thead><tr><th>Reclamo</th><th>Cuenta</th><th>Orden</th><th>Tipo</th><th>Etapa</th><th>Estado</th><th>Razón</th><th>Abierto</th></tr></thead><tbody><?php if(!$claims):?><tr><td colspan="8"><div class="empty">Sin reclamos para estos filtros.</div></td></tr><?php endif;?><?php foreach($claims as $c):?><tr><td><?= View::e($c['external_claim_id']) ?></td><td><?= View::e($c['account_name']) ?></td><td><?= View::e($c['linked_order'] ?: $c['external_order_id'] ?: '—') ?></td><td><?= View::e($c['type'] ?: '—') ?></td><td><?= View::e($c['stage'] ?: '—') ?></td><td><span class="badge"><?= View::e($c['status'] ?: '—') ?></span></td><td><?= View::e($c['reason_name'] ?: $c['reason_id'] ?: '—') ?></td><td><?= View::e(DateTimePresenter::format($c['opened_at'])) ?></td></tr><?php endforeach;?></tbody></table></div></section>
