<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;
use App\Services\UiLabelPresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$progressive = !empty($progressive);
if ($progressive) {
  $query = http_build_query(array_filter([
    'account_id' => (int) ($accountId ?? 0) ?: null,
    'year' => (int) ($year ?? date('Y')),
  ], static fn (mixed $value): bool => $value !== null));
  $sectionUrl = $base . '/sync/section.json?' . $query;
  ?>
  <section class="async-section" data-async-section="sync-overview" data-url="<?= View::e($sectionUrl) ?>" aria-live="polite" aria-busy="true">
    <div data-async-skeleton class="panel async-skeleton is-visible">
      <div class="page-head"><div><h1>Sincronizaciones</h1><p>La pantalla ya abrió. Estamos comprobando agenda, bloques y finanzas sin consultar Mercado Libre.</p></div></div>
      <div class="skeleton-table"><span></span><span></span><span></span><span></span></div>
    </div>
    <div data-async-status class="async-section-status" hidden></div>
    <div data-async-content></div>
  </section>
  <noscript><a class="btn primary" href="<?= View::e($base) ?>/sync?<?= View::e($query) ?>&amp;full=1">Cargar sincronizaciones</a></noscript>
  <?php return;
}
$statusLabel = static fn(string $s): string => UiLabelPresenter::status($s);
$next = ($globalStatus['upcoming'] ?? [])[0] ?? null;
$nowLocal = (new DateTimeImmutable('now', new DateTimeZone(DateTimePresenter::timezone())))->format('Y-m-d H:i');
?>
<div class="page-head">
  <div><h1>Sincronizaciones</h1><p>Planifique importaciones por cuenta, mes y bloques seguros para no saturar Mercado Libre.</p></div>
  <div class="page-actions">
    <a class="btn primary" href="<?= View::e($base) ?>/settings/manual-processing?scope=sales&amp;origin=sync_monitor&amp;account_id=<?= (int) $accountId ?>&amp;year=<?= (int) $year ?>">Procesar ahora</a>
    <a class="btn" href="<?= View::e($base) ?>/settings#sync">Configuración de sincronización</a>
    <a class="btn" href="<?= View::e($base) ?>/sync/schedule?account_id=<?= (int)$accountId ?>">Agenda cron</a>
    <a class="btn" href="<?= View::e($base) ?>/sync/audit?account_id=<?= (int)$accountId ?>&year=<?= (int)$year ?>">Auditoría de ventas</a>
    <a class="btn" href="<?= View::e($base) ?>/sync/recurring">Programación automática</a>
    <a class="btn" href="<?= View::e($base) ?>/sync/guardrails">Protección API</a>
    <a class="btn" href="<?= View::e($base) ?>/sync/diagnostics">Diagnóstico de volumen</a>
  </div>
</div>

<section class="operation-explainer" aria-label="Estado de sincronización">
  <div><span>Qué está pasando</span><strong><?= View::e((string) ($cron['label'] ?? 'Comprobando el lanzador único')) ?></strong></div>
  <div><span>Qué hará el ERP</span><strong>Continuará los recursos elegibles sin repetir los ya comprobados.</strong></div>
  <div><span>Qué puede hacer ahora</span><strong><?= !empty($globalStatus['latest_error']) ? 'Revise el recurso señalado; el resto puede continuar.' : 'Elija una cuenta para ver su progreso real.' ?></strong></div>
</section>

<section class="panel filter-bar">
  <form class="inline-form" method="get" action="<?= View::e($base) ?>/sync">
    <div class="field"><label>Cuenta Mercado Libre</label><select class="input" name="account_id" required><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= $accountId===(int)$account['id']?'selected':'' ?>><?= View::e(($account['company_name'] ? $account['company_name'] . ' · ' : '') . $account['account_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Año</label><input class="input" type="number" min="2020" max="<?= date('Y') + 1 ?>" name="year" value="<?= (int) $year ?>"></div>
    <button class="btn primary">Ver calendario</button>
  </form>
</section>

<?php if ($accountId > 0): ?>
<section class="panel sync-monitor" data-sync-schedule-monitor data-schedule-url="<?= View::e($base) ?>/sync/schedule.json?account_id=<?= (int)$accountId ?>" data-refresh="<?= (int)$settings->monitorRefreshSeconds() ?>">
  <header class="panel-head"><h2>Monitor general de sincronización</h2><a class="link" href="<?= View::e($base) ?>/sync/schedule?account_id=<?= (int)$accountId ?>">Ver agenda completa</a></header>
  <div class="panel-body">
    <div class="progress-wrap"><div class="progress-bar"><span data-schedule-progress-bar style="width:<?= (float)($globalStatus['percent'] ?? 0) ?>%"></span></div><strong data-schedule-progress-text><?= number_format((float)($globalStatus['percent'] ?? 0),1,',','.') ?>%</strong></div>
    <div class="mini-grid mt-2">
      <div class="mini-card"><span>Bloques completos</span><strong data-schedule-complete><?= (int)($globalStatus['chunks_complete'] ?? 0) ?> / <?= (int)($globalStatus['chunks_total'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Órdenes procesadas</span><strong data-schedule-processed><?= (int)($globalStatus['processed'] ?? 0) ?> / <?= (int)($globalStatus['estimated'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>En ejecución</span><strong data-schedule-running><?= (int)($globalStatus['chunks_running'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Atrasados/listos</span><strong data-schedule-overdue><?= (int)($globalStatus['chunks_overdue'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Con error</span><strong data-schedule-errors><?= (int)($globalStatus['chunks_error'] ?? 0) ?></strong></div>
    </div>
    <div class="quick-sync-status mt-2">
      <span><strong>Zona horaria:</strong> <?= View::e(DateTimePresenter::timezone()) ?></span>
      <span><strong>Hora local ERP:</strong> <?= View::e($nowLocal) ?></span>
      <span><strong>Cron:</strong> <?= View::e($cron['label'] ?? 'Sin señales') ?></span>
      <span><strong>Próxima ejecución:</strong> <?= View::e($next ? DateTimePresenter::formatQueue($next['next_run_at']) : 'Sin bloques') ?></span>
      <span><strong>Capacidad:</strong> <a href="<?= View::e($base) ?>/settings/cron/rhythm">Presupuesto de llamadas API</a></span>
      <span><strong>Continuación:</strong> <?= (int)$settings->continuationDelayMinutes() ?> min</span>
    </div>
    <?php if (!empty($globalStatus['latest_error'])): $err = $globalStatus['latest_error']; ?>
    <div class="alert danger mt-2" role="alert">
      <strong>Qué pasó:</strong>
      <?= View::e(UiLabelPresenter::safeOperationMessage(
          (string) ($err['last_error'] ?? ''),
          isset($err['diagnostic_id']) ? (string) $err['diagnostic_id'] : null
      )) ?>
      <?php if (!empty($err['error_recommendation'])): ?><br><span><?= View::e((string)$err['error_recommendation']) ?></span><?php endif; ?>
      <br><small>El resto de los pendientes conserva su estado. Revise este recurso antes de reintentarlo.</small>
    </div>
    <?php endif; ?>
    <div class="page-actions mt-2">
      <?php if ($settings->manualProcessEnabled()): ?>
      <a class="btn primary" href="<?= View::e($base) ?>/settings/manual-processing?scope=sales&amp;origin=sync_monitor&amp;account_id=<?= (int) $accountId ?>&amp;year=<?= (int) $year ?>">Procesar ventas ahora</a>
      <a class="btn" href="<?= View::e($base) ?>/settings/manual-processing?scope=sales&amp;origin=legacy_resume&amp;account_id=<?= (int) $accountId ?>">Revisar pendientes</a>
      <a class="btn" href="<?= View::e($base) ?>/settings/manual-processing?scope=sales&amp;origin=legacy_retry&amp;account_id=<?= (int) $accountId ?>">Revisar errores</a>
      <a class="btn" href="<?= View::e($base) ?>/settings/cron/queue?type=orders_sync&amp;account_id=<?= (int) $accountId ?>">Ver vencidos y resolver uno</a>
      <?php else: ?><span class="muted">Proceso manual desactivado en Configuración.</span><?php endif; ?>
    </div>
    <?php if (!empty($overdue)): ?>
    <h3 class="mt-2">Bloques atrasados/listos</h3>
    <div class="table-scroll"><table class="data-table"><tbody data-schedule-overdue-list>
      <?php foreach($overdue as $item): ?><tr><td><?= View::e($item['account_name']) ?></td><td><?= View::e(substr($item['date_from'],0,10).' a '.substr($item['date_to'],0,10)) ?></td><td><?= View::e(DateTimePresenter::formatQueue($item['next_run_at'])) ?></td><td><span class="badge amber"><?= View::e($statusLabel($item['status'])) ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <h3 class="mt-2"><a class="link" href="<?= View::e($base) ?>/sync/schedule?account_id=<?= (int)$accountId ?>">Próximas 3 actualizaciones</a></h3>
    <div class="table-scroll"><table class="data-table"><tbody data-schedule-upcoming>
      <?php foreach(($globalStatus['upcoming'] ?? []) as $item): ?><tr><td><?= View::e($item['account_name']) ?></td><td><?= View::e(substr($item['date_from'],0,10).' a '.substr($item['date_to'],0,10)) ?></td><td><?= View::e(DateTimePresenter::formatQueue($item['next_run_at'])) ?></td><td><?= View::e($statusLabel($item['status'])) ?></td></tr><?php endforeach; ?>
      <?php if(empty($globalStatus['upcoming'])): ?><tr><td><div class="empty">No hay bloques próximos.</div></td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
</section>

<section class="panel sync-monitor mt-2" data-financial-recalc-monitor data-financial-status-url="<?= View::e($base) ?>/financial-recalc/status.json?account_id=<?= (int)$accountId ?>">
  <header class="panel-head">
    <h2>Procesamiento financiero</h2>
    <a class="link" href="<?= View::e($base) ?>/financial-recalc?account_id=<?= (int)$accountId ?>">Ver procesamiento financiero completo</a>
  </header>
  <div class="panel-body">
    <?php $financialPercent = (float)($financialQueue['percent'] ?? 0); ?>
    <div class="progress-wrap"><div class="progress-bar"><span data-financial-progress-bar style="width:<?= $financialPercent ?>%"></span></div><strong data-financial-progress-text><?= number_format($financialPercent,1,',','.') ?>%</strong></div>
    <div class="mini-grid mt-2">
      <div class="mini-card"><span>Jobs pendientes</span><strong data-financial-pending><?= (int)($financialQueue['pending_jobs'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Ejecutando</span><strong data-financial-running><?= (int)($financialQueue['running_jobs'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Órdenes recalculadas</span><strong data-financial-processed><?= (int)($financialQueue['complete_items'] ?? 0) ?> / <?= (int)($financialQueue['total_items'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Pendientes</span><strong data-financial-active><?= (int)($financialQueue['active_items'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Con error</span><strong data-financial-errors><?= (int)($financialQueue['failed_items'] ?? 0) ?></strong></div>
    </div>
    <div class="alert info mt-2">Este flujo es distinto a la sincronización de órdenes. Aquí se recalculan datos financieros locales de órdenes ya descargadas.</div>
    <div class="page-actions mt-2">
      <a class="btn primary" href="<?= View::e($base) ?>/settings/manual-processing?scope=finance">Procesar finanzas ahora</a>
      <a class="btn" href="<?= View::e($base) ?>/financial-recalc/errors?account_id=<?= (int)$accountId ?>">Ver errores</a>
    </div>
    <?php if (!empty($financialJobs)): ?>
    <h3 class="mt-2">Últimos jobs financieros</h3>
    <div class="table-scroll"><table class="data-table">
      <tbody data-financial-job-list>
      <?php foreach ($financialJobs as $job): ?><tr>
        <td>#<?= (int)$job['id'] ?></td>
        <td><?= View::e($job['account_name'] ?: 'Todas / local') ?></td>
        <td><?= View::e(substr((string)$job['date_from'],0,10) ?: '—') ?> → <?= View::e(substr((string)$job['date_to'],0,10) ?: '—') ?></td>
        <td><?= (int)$job['processed_items'] ?> / <?= (int)$job['total_items'] ?><?php if ((int)$job['error_items'] > 0): ?> · <span class="badge red"><?= (int)$job['error_items'] ?> errores</span><?php endif; ?></td>
        <td><span class="badge <?= $job['status']==='complete'?'green':($job['status']==='error'?'red':'amber') ?>"><?= View::e($statusLabel((string)$job['status'])) ?></span></td>
        <td><a class="btn small" href="<?= View::e($base) ?>/financial-recalc/show?id=<?= (int)$job['id'] ?>">Ver detalle</a></td>
      </tr><?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</section>

<section class="metrics">
  <article class="metric-card"><div><div class="metric-label">División por defecto</div><div class="metric-value"><?= View::e($settings->chunkMode()==='weekly'?'Semanal':'Por partes') ?></div></div></article>
  <article class="metric-card"><div><div class="metric-label">Llamadas API por ciclo</div><div class="metric-value"><a class="link" href="<?= View::e($base) ?>/settings/cron/rhythm">Ver presupuesto</a></div></div></article>
  <article class="metric-card"><div><div class="metric-label">Inicio sugerido</div><div class="metric-value"><?= (int) $settings->defaultEnqueueDelayMinutes() ?> min</div></div></article>
  <article class="metric-card"><div><div class="metric-label">Protección API</div><div class="metric-value"><a class="link" href="<?= View::e($base) ?>/sync/guardrails">Ver</a></div></div></article>
</section>

<section class="panel table-panel">
  <header class="panel-head"><h2>Meses de <?= (int) $year ?></h2></header>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Mes</th><th>Rango</th><th>Estado</th><th>Bloques</th><th>Órdenes procesadas</th><th>Acciones</th></tr></thead>
    <tbody><?php foreach ($months as $month): ?><tr>
      <td><strong><?= View::e($month['label']) ?></strong></td>
      <td><?= View::e($month['from'] . ' a ' . $month['to']) ?></td>
      <td><span class="badge <?= $month['status']==='complete'?'green':($month['status']==='error'?'red':'amber') ?>"><?= View::e($statusLabel($month['status'])) ?></span></td>
      <td><?= (int) $month['complete_chunks'] ?> / <?= (int) $month['total_chunks'] ?></td>
      <td><?= (int) $month['processed_count'] ?></td>
      <td><a class="btn small" href="<?= View::e($base) ?>/sync/account?id=<?= (int) $accountId ?>&year=<?= (int) $year ?>&month=<?= (int) $month['month'] ?>">Abrir</a></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
</section>

<section class="panel table-panel mt-2">
  <header class="panel-head"><h2>Ejecuciones recientes</h2></header>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Rango</th><th>Estado</th><th>Procesadas</th><th>Offset</th><th>Inicio</th><th>Error</th></tr></thead><tbody>
    <?php if (!$runs): ?><tr><td colspan="6"><div class="empty">Aún no hay ejecuciones de sincronización para esta cuenta.</div></td></tr><?php endif; ?>
    <?php foreach ($runs as $run): ?><tr><td><?= View::e(substr($run['date_from'],0,10) . ' a ' . substr($run['date_to'],0,10)) ?></td><td><?= View::e($statusLabel((string) $run['status'])) ?></td><td><?= (int)$run['processed_count'] ?></td><td><?= (int)$run['cursor_offset_before'] ?> → <?= (int)$run['cursor_offset_after'] ?></td><td><?= View::e(DateTimePresenter::formatQueue($run['started_at'])) ?></td><td><?= View::e(!empty($run['error_message']) ? UiLabelPresenter::safeOperationMessage((string) $run['error_message'], isset($run['diagnostic_id']) ? (string) $run['diagnostic_id'] : null) : '—') ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php else: ?><div class="alert warning">Conecte o seleccione una cuenta Mercado Libre para planificar sincronizaciones.</div><?php endif; ?>
