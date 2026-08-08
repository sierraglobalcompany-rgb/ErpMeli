<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$percent = (float) ($status['percent'] ?? 0);
$tz = DateTimePresenter::timezone();
$delayDefault = (int) $settings->defaultEnqueueDelayMinutes();
$nowLocal = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d H:i');
$scheduleFields = static function (string $prefix = '') use ($delayDefault, $settings): void {
?>
  <div class="field">
    <label>Cuándo encolar</label>
    <select class="input" name="schedule_mode" data-schedule-mode>
      <option value="none">Solo crear bloques, sin encolar</option>
      <option value="now">Encolar ahora</option>
      <option value="delay" selected>Encolar en <?= $delayDefault === 0 ? 'este momento' : $delayDefault . ' minutos' ?></option>
      <option value="custom">Programar fecha/hora específica</option>
    </select>
  </div>
  <div class="field">
    <label>Espera</label>
    <select class="input" name="schedule_delay_minutes">
      <option value="0" <?= $delayDefault===0?'selected':'' ?>>Ahora</option>
      <option value="5" <?= $delayDefault===5?'selected':'' ?>>5 minutos</option>
      <option value="30" <?= $delayDefault===30?'selected':'' ?>>30 minutos</option>
      <option value="60" <?= $delayDefault===60?'selected':'' ?>>1 hora</option>
    </select>
  </div>
  <?php if ($settings->allowCustomSchedule()): ?>
  <div class="field">
    <label>Fecha/hora local</label>
    <input class="input" type="datetime-local" name="schedule_at">
  </div>
  <?php endif; ?>
<?php
};
$chunkScheduleFields = static function () use ($settings): void {
?>
  <select class="input compact-input schedule-mode-input" name="schedule_mode" data-schedule-mode>
    <option value="now">Ahora</option>
    <option value="delay" selected>En 5 min</option>
    <?php if ($settings->allowCustomSchedule()): ?><option value="custom">Fecha/hora</option><?php endif; ?>
  </select>
  <select class="input compact-input schedule-delay-input" name="schedule_delay_minutes" data-schedule-delay>
    <option value="5">5 min</option>
    <option value="30">30 min</option>
    <option value="60">1 hora</option>
  </select>
  <?php if ($settings->allowCustomSchedule()): ?><input class="input compact-datetime schedule-datetime-input" type="datetime-local" name="schedule_at" data-schedule-at><?php endif; ?>
<?php
};
?>
<div class="page-head">
  <div><a class="muted" href="<?= View::e($base) ?>/sync?account_id=<?= (int)$accountId ?>&year=<?= (int)$year ?>">← Volver a sincronizaciones</a><h1>Sincronización por cuenta</h1><p>Divida el mes en bloques, programe cuándo inicia y monitoree el avance.</p></div>
  <div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/sync/diagnostics?account_id=<?= (int)$accountId ?>&year=<?= (int)$year ?>&month=<?= (int)$month ?>">Diagnóstico</a><a class="btn" href="<?= View::e($base) ?>/settings#sync">Configuración sync</a></div>
</div>

<section class="alert info">Hora local ERP: <?= View::e($nowLocal) ?> · Zona horaria: <?= View::e($tz) ?>. Las fechas programadas se guardan en UTC y se muestran en esta zona.</section>

<section class="panel filter-bar">
  <form class="inline-form" method="get" action="<?= View::e($base) ?>/sync/account">
    <div class="field"><label>Cuenta</label><select class="input" name="id"><?php foreach($accounts as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $accountId===(int)$a['id']?'selected':'' ?>><?= View::e($a['account_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Año</label><input class="input" type="number" name="year" value="<?= (int)$year ?>"></div>
    <div class="field"><label>Mes</label><input class="input" type="number" min="1" max="12" name="month" value="<?= (int)$month ?>"></div>
    <button class="btn">Abrir</button>
  </form>
</section>

<section class="panel filter-bar" id="sync-plan">
  <form class="inline-form" method="post" action="<?= View::e($base) ?>/sync/plan">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="account_id" value="<?= (int)$accountId ?>">
    <input type="hidden" name="year" value="<?= (int)$year ?>">
    <input type="hidden" name="month" value="<?= (int)$month ?>">
    <div class="field"><label>Dividir mes</label><select class="input" name="chunk_mode"><option value="daily" <?= $settings->chunkMode()==='daily'?'selected':'' ?>>Por día (recomendado)</option><option value="weekly" <?= $settings->chunkMode()==='weekly'?'selected':'' ?>>Por semanas</option><option value="parts" <?= $settings->chunkMode()==='parts'?'selected':'' ?>>Por número de partes</option></select></div>
    <div class="field"><label>Partes si aplica</label><input class="input" type="number" min="1" max="31" name="chunk_parts" value="<?= (int)$settings->chunkParts() ?>"></div>
    <?php $scheduleFields(); ?>
    <button class="btn primary">Crear / actualizar bloques</button>
  </form>
  <?php if ($batch): ?>
  <form method="post" action="<?= View::e($base) ?>/sync/batch/delete" class="inline-form mt-2" data-confirm="¿Eliminar la planificación de este mes? No se eliminarán órdenes ya importadas.">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="batch_id" value="<?= (int)$batch['id'] ?>">
    <input type="hidden" name="account_id" value="<?= (int)$accountId ?>">
    <input type="hidden" name="year" value="<?= (int)$year ?>">
    <button class="btn danger">Eliminar planificación del mes</button>
  </form>
  <?php endif; ?>
</section>

<section class="panel sync-monitor" data-sync-monitor data-status-url="<?= View::e($base) ?>/sync/status?account_id=<?= (int)$accountId ?>&year=<?= (int)$year ?>&month=<?= (int)$month ?>" data-refresh="<?= (int)$settings->monitorRefreshSeconds() ?>">
  <header class="panel-head"><h2>Monitor de avance</h2><span class="muted">Zona horaria: <?= View::e($tz) ?></span></header>
  <div class="panel-body">
    <div class="progress-wrap"><div class="progress-bar"><span data-sync-progress-bar style="width:<?= $percent ?>%"></span></div><strong data-sync-progress-text><?= number_format($percent, 1, ',', '.') ?>%</strong></div>
    <div class="mini-grid mt-2">
      <div class="mini-card"><span>Bloques completos</span><strong data-sync-complete><?= (int)($status['chunks_complete'] ?? 0) ?> / <?= (int)($status['chunks_total'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Órdenes procesadas</span><strong data-sync-processed><?= (int)($status['processed'] ?? 0) ?> / <?= (int)($status['estimated'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>En ejecución</span><strong data-sync-running><?= (int)($status['chunks_running'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Con error</span><strong data-sync-errors><?= (int)($status['chunks_error'] ?? 0) ?></strong></div>
    </div>
    <div class="page-actions mt-2">
      <?php if ($settings->manualProcessEnabled()): ?>
      <a class="btn primary" href="<?= View::e($base) ?>/settings/manual-processing?scope=sales&amp;origin=sync_monitor&amp;account_id=<?= (int) $accountId ?>">Procesar ventas ahora</a>
      <?php else: ?><span class="muted">Proceso manual desactivado en Configuración.</span><?php endif; ?>
      <span class="muted" data-sync-updated>Actualización automática activa.</span>
    </div>
  </div>
</section>

<?php if ($diagnostic): ?>
<section class="alert info mt-2">Diagnóstico reciente: promedio <?= View::e((string)$diagnostic['average_daily_orders']) ?> órdenes/día, estimado mensual <?= (int)$diagnostic['estimated_month_orders'] ?>. Recomendación: <?= View::e($diagnostic['recommended_mode']==='weekly'?'semanal':'por partes') ?> <?= (int)$diagnostic['recommended_parts'] ?>.</section>
<?php endif; ?>

<section class="panel table-panel mt-2">
  <header class="panel-head"><h2>Bloques del mes <?= (int)$month ?>/<?= (int)$year ?></h2></header>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>#</th><th>Rango</th><th>Estado</th><th>Procesadas</th><th>Total remoto</th><th>Próxima ejecución</th><th>Intentos</th><th>Error</th><th>Acciones</th></tr></thead>
    <tbody>
      <?php if (!$chunks): ?><tr><td colspan="9"><div class="empty">Este mes aún no está dividido. Cree los bloques para poder sincronizarlo por partes.</div></td></tr><?php endif; ?>
      <?php foreach($chunks as $chunk): ?><tr>
        <td><?= (int)$chunk['sequence_no'] ?></td>
        <td><?= View::e(substr($chunk['date_from'],0,10) . ' a ' . substr($chunk['date_to'],0,10)) ?></td>
        <td><span class="badge <?= $chunk['status']==='complete'?'green':($chunk['status']==='error'?'red':($chunk['status']==='cancelled'?'amber':'blue')) ?>"><?= View::e($chunk['status']) ?></span></td>
        <td><?= (int)$chunk['processed_count'] ?></td>
        <td><?= $chunk['estimated_total'] === null ? '—' : (int)$chunk['estimated_total'] ?></td>
        <td><?= View::e(DateTimePresenter::formatQueue($chunk['next_run_at'])) ?></td>
        <td><?= (int)$chunk['attempt_count'] ?></td>
        <td>
          <?php if (!empty($chunk['last_error'])): ?>
            <div><strong><?= View::e($chunk['error_type'] ?: 'error') ?></strong><?= !empty($chunk['error_http_status']) ? ' · HTTP ' . (int)$chunk['error_http_status'] : '' ?></div>
            <div><?= View::e($chunk['last_error']) ?></div>
            <?php if (!empty($chunk['error_recommendation'])): ?><small class="muted"><?= View::e($chunk['error_recommendation']) ?></small><?php endif; ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="actions-cell sync-actions-cell">
          <div class="sync-row-actions">
          <?php if (in_array($chunk['status'], ['pending','partial','error','queued'], true)): ?>
          <form method="post" action="<?= View::e($base) ?>/sync/chunk/enqueue" class="chunk-schedule-form sync-schedule-form">
            <input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="chunk_id" value="<?= (int)$chunk['id'] ?>"><input type="hidden" name="account_id" value="<?= (int)$accountId ?>"><input type="hidden" name="year" value="<?= (int)$year ?>"><input type="hidden" name="month" value="<?= (int)$month ?>">
            <?php $chunkScheduleFields(); ?>
            <button class="btn small"><?= $chunk['status']==='error'?'Reintentar':'Encolar' ?></button>
          </form>
          <?php endif; ?>
          <div class="sync-secondary-actions">
          <?php if ($chunk['status'] === 'queued' || $chunk['status'] === 'partial' || $chunk['status'] === 'pending'): ?>
          <form method="post" action="<?= View::e($base) ?>/sync/chunk/cancel"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="chunk_id" value="<?= (int)$chunk['id'] ?>"><input type="hidden" name="account_id" value="<?= (int)$accountId ?>"><input type="hidden" name="year" value="<?= (int)$year ?>"><input type="hidden" name="month" value="<?= (int)$month ?>"><button class="btn small">Cancelar</button></form>
          <?php endif; ?>
          <?php if (in_array($chunk['status'], ['cancelled','error'], true)): ?>
          <form method="post" action="<?= View::e($base) ?>/sync/chunk/reactivate"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="chunk_id" value="<?= (int)$chunk['id'] ?>"><input type="hidden" name="account_id" value="<?= (int)$accountId ?>"><input type="hidden" name="year" value="<?= (int)$year ?>"><input type="hidden" name="month" value="<?= (int)$month ?>"><button class="btn small">Reactivar</button></form>
          <?php endif; ?>
          <?php if ($chunk['status'] !== 'running'): ?>
          <form method="post" action="<?= View::e($base) ?>/sync/chunk/delete" data-confirm="¿Eliminar este bloque? No se eliminarán órdenes ya importadas."><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="chunk_id" value="<?= (int)$chunk['id'] ?>"><input type="hidden" name="account_id" value="<?= (int)$accountId ?>"><input type="hidden" name="year" value="<?= (int)$year ?>"><input type="hidden" name="month" value="<?= (int)$month ?>"><button class="btn small danger">Eliminar</button></form>
          <?php endif; ?>
          <a class="btn small" href="<?= View::e($base) ?>/orders?account_id=<?= (int)$accountId ?>&from=<?= View::e(substr($chunk['date_from'],0,10)) ?>&to=<?= View::e(substr($chunk['date_to'],0,10)) ?>">Órdenes</a>
          </div>
          </div>
        </td>
      </tr><?php endforeach; ?>
    </tbody>
  </table></div>
</section>
