<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;
$base = rtrim(Env::get('APP_URL',''),'/');
?>
<div class="page-head">
  <div><h1>Preguntas Mercado Libre</h1><p>Solo lectura: prioridad alta para detectar preguntas pendientes sin responder desde el ERP.</p></div>
</div>
<section class="panel filter-bar">
  <form class="inline-form" method="get" action="<?= View::e($base) ?>/questions">
    <div class="field"><label>Cuenta Mercado Libre</label><select class="input" name="account_id"><option value="0">Todas</option><?php foreach($accounts as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$filters['account_id']===(int)$a['id']?'selected':'' ?>><?= View::e($a['account_name']) ?></option><?php endforeach; ?></select></div>
    <button class="btn">Filtrar</button>
  </form>
  <form class="inline-form mt-2" method="post" action="<?= View::e($base) ?>/questions/sync" data-confirm="Revisará preguntas solo si el endpoint está confirmado y la sincronización está activada.">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="account_id" value="<?= (int)$filters['account_id'] ?>">
    <button class="btn primary">Revisar preguntas ahora</button>
  </form>
</section>
<section class="alert info">El ERP no responde preguntas ni envía mensajes a Mercado Libre. Solo las lista y genera alertas internas o correo opcional.</section>
<section class="panel table-panel">
  <header class="panel-head"><h2>Preguntas pendientes</h2></header>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Cuenta</th><th>Pregunta</th><th>Publicación</th><th>Estado</th><th>Fecha</th><th>Notificación</th></tr></thead><tbody>
    <?php if(!$questions): ?><tr><td colspan="6"><div class="empty">No hay preguntas pendientes sincronizadas.</div></td></tr><?php endif; ?>
    <?php foreach($questions as $q): ?><tr>
      <td><?= View::e($q['account_name']) ?></td>
      <td><?= View::e(mb_substr((string)$q['text'],0,140)) ?></td>
      <td><?= View::e($q['external_item_id'] ?: '—') ?></td>
      <td><span class="badge amber"><?= View::e($q['status'] ?: 'pendiente') ?></span></td>
      <td><?= View::e(DateTimePresenter::format($q['asked_at'])) ?></td>
      <td><span class="badge blue">Interna</span></td>
    </tr><?php endforeach; ?>
  </tbody></table></div>
</section>
