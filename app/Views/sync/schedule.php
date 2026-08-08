<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
?>
<div class="page-head"><div><a class="muted" href="<?= View::e($base) ?>/sync">← Volver a sincronizaciones</a><h1>Agenda de sincronización</h1><p>Orden de bloques pendientes que procesará el cron.</p></div><div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/settings#sync">Configuración sync</a><a class="btn" href="<?= View::e($base) ?>/sync/guardrails">Protección API</a></div></div>
<section class="panel filter-bar">
  <form class="inline-form" method="get" action="<?= View::e($base) ?>/sync/schedule">
    <div class="field"><label>Cuenta</label><select class="input" name="account_id"><option value="0">Todas</option><?php foreach($accounts as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $accountId===(int)$a['id']?'selected':'' ?>><?= View::e($a['account_name']) ?></option><?php endforeach; ?></select></div>
    <button class="btn">Ver agenda</button>
  </form>
</section>
<?php if (!empty($overdue)): ?>
<section class="alert warning">
  <strong>Hay <?= count($overdue) ?> bloques atrasados/listos.</strong>
  <form class="inline-form mt-2" method="post" action="<?= View::e($base) ?>/sync/overdue/reschedule" data-confirm="Reprogramará los bloques vencidos filtrados para que cron los procese nuevamente.">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="account_id" value="<?= (int)$accountId ?>">
    <input type="hidden" name="return_to_schedule" value="1">
    <select class="input compact-input" name="schedule_mode" data-schedule-mode><option value="delay">En...</option><option value="now">Ahora</option><?php if (($settings ?? null) && $settings->allowCustomSchedule()): ?><option value="custom">Fecha/hora</option><?php endif; ?></select>
    <select class="input compact-input" name="schedule_delay_minutes"><option value="5">5 min</option><option value="10">10 min</option><option value="20">20 min</option><option value="30">30 min</option></select>
    <?php if (($settings ?? null) && $settings->allowCustomSchedule()): ?><input class="input compact-datetime" type="datetime-local" name="schedule_at"><?php endif; ?>
    <button class="btn small">Reprogramar vencidos filtrados</button>
  </form>
  El cron debería procesarlos si está activo y no hay bloqueos API. Desde Sincronizaciones puede ejecutar atrasados respetando pausas.
</section>
<?php endif; ?>
<section class="panel table-panel">
  <header class="panel-head"><h2>Bloques pendientes</h2><span class="muted">Zona horaria: <?= View::e(DateTimePresenter::timezone()) ?></span></header>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Cuenta</th><th>Mes</th><th>#</th><th>Rango</th><th>Estado</th><th>Próxima ejecución</th><th>Intentos</th><th>Error</th><th>Reprogramar</th><th>Acciones</th></tr></thead><tbody>
    <?php if(!$items): ?><tr><td colspan="10"><div class="empty">No hay bloques pendientes en cola.</div></td></tr><?php endif; ?>
    <?php foreach($items as $chunk): ?><tr>
      <td><?= View::e($chunk['account_name']) ?></td>
      <td><?= (int)$chunk['period_month'] ?>/<?= (int)$chunk['period_year'] ?></td>
      <td><?= (int)$chunk['sequence_no'] ?></td>
      <td><?= View::e(substr($chunk['date_from'],0,10).' a '.substr($chunk['date_to'],0,10)) ?></td>
      <td><span class="badge <?= $chunk['status']==='partial'?'amber':'blue' ?>"><?= View::e($chunk['status']) ?></span></td>
      <td><?= View::e(DateTimePresenter::formatQueue($chunk['next_run_at'])) ?></td>
      <td><?= (int)$chunk['attempt_count'] ?></td>
      <td><?php if(!empty($chunk['last_error'])): ?><strong><?= View::e($chunk['error_type'] ?: 'error') ?></strong><?= !empty($chunk['error_http_status']) ? ' · HTTP ' . (int)$chunk['error_http_status'] : '' ?><br><?= View::e($chunk['last_error']) ?><?php if(!empty($chunk['error_recommendation'])): ?><br><small class="muted"><?= View::e($chunk['error_recommendation']) ?></small><?php endif; ?><?php else: ?>—<?php endif; ?></td>
      <td>
        <form class="inline-form compact-form" method="post" action="<?= View::e($base) ?>/sync/chunk/reschedule">
          <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
          <input type="hidden" name="chunk_id" value="<?= (int)$chunk['id'] ?>">
          <input type="hidden" name="account_id" value="<?= (int)$chunk['meli_account_id'] ?>">
          <input type="hidden" name="year" value="<?= (int)$chunk['period_year'] ?>">
          <input type="hidden" name="month" value="<?= (int)$chunk['period_month'] ?>">
          <input type="hidden" name="return_to_schedule" value="1">
          <select class="input compact-input" name="schedule_mode" data-schedule-mode><option value="delay">En...</option><option value="now">Ahora</option><?php if (($settings ?? null) && $settings->allowCustomSchedule()): ?><option value="custom">Fecha/hora</option><?php endif; ?></select>
          <select class="input compact-input" name="schedule_delay_minutes"><option value="5">5 min</option><option value="30">30 min</option><option value="60">1 hora</option></select>
          <?php if (($settings ?? null) && $settings->allowCustomSchedule()): ?><input class="input compact-datetime" type="datetime-local" name="schedule_at"><?php endif; ?>
          <button class="btn small">Guardar</button>
        </form>
      </td>
      <td class="actions-cell">
        <form method="post" action="<?= View::e($base) ?>/sync/chunk/cancel"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="chunk_id" value="<?= (int)$chunk['id'] ?>"><input type="hidden" name="account_id" value="<?= (int)$chunk['meli_account_id'] ?>"><input type="hidden" name="year" value="<?= (int)$chunk['period_year'] ?>"><input type="hidden" name="month" value="<?= (int)$chunk['period_month'] ?>"><input type="hidden" name="return_to_schedule" value="1"><button class="btn small">Cancelar</button></form>
        <form method="post" action="<?= View::e($base) ?>/sync/chunk/delete" data-confirm="¿Eliminar este bloque pendiente?"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="chunk_id" value="<?= (int)$chunk['id'] ?>"><input type="hidden" name="account_id" value="<?= (int)$chunk['meli_account_id'] ?>"><input type="hidden" name="year" value="<?= (int)$chunk['period_year'] ?>"><input type="hidden" name="month" value="<?= (int)$chunk['period_month'] ?>"><input type="hidden" name="return_to_schedule" value="1"><button class="btn small danger">Eliminar</button></form>
      </td>
    </tr><?php endforeach; ?>
  </tbody></table></div>
</section>
