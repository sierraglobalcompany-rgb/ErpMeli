<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$status = (string) ($session['status'] ?? 'active');
$display = (string) ($session['display_state'] ?? 'waiting_launcher');
$jobs = (array) ($session['job_progress'] ?? []);
$resolvedJobs = max(0, (int) ($jobs['resolved'] ?? $session['resolved_items'] ?? 0));
$totalJobs = max(0, (int) ($jobs['total'] ?? $session['total_items'] ?? 0));
$totalUnits = max(0, (int) ($session['total_units'] ?? 0));
$jobPercent = $totalJobs > 0 ? min(100, round($resolvedJobs * 100 / $totalJobs, 1)) : 0;
$work = (array) ($session['current_work_progress'] ?? []);
$rhythm = (array) ($session['rhythm'] ?? []);
$current = (array) ($session['current_item'] ?? []);
$next = (array) ($session['next_item'] ?? []);
$failed = max(0, (int) ($session['failed_items'] ?? 0));
$events = array_slice((array) ($session['events'] ?? []), 0, 10);
$attention = is_array($session['attention'] ?? null) ? $session['attention'] : null;
$stateLabels = [
    'processing' => 'Procesando',
    'ready' => 'Listo para continuar',
    'waiting_interval' => (($session['wait_kind'] ?? '') === 'block_pause' ? 'Pausa entre bloques' : 'Esperando intervalo'),
    'waiting_launcher' => 'Esperando automatización',
    'maintenance' => 'En mantenimiento',
    'pausing' => 'Pausando después del actual',
    'paused' => 'Campaña pausada',
    'returning' => 'Devolviendo pendientes',
    'completed' => 'Campaña completada',
    'completed_with_issues' => 'Terminó con diferencias',
    'error' => 'Necesita intervención',
];
$eventLabels = ['success' => 'Correcto', 'warning' => 'Esperando', 'error' => 'Revisar', 'info' => 'Intento iniciado', 'neutral' => 'Información'];
$etaKnown = $session['estimated_remaining_seconds'] !== null && !empty($session['engine_live']);
$eta = $etaKnown ? max(0, (int) $session['estimated_remaining_seconds']) : 0;
$etaLabel = !$etaKnown
    ? 'Todavía no se puede estimar'
    : ($eta < 60 ? $eta . ' s' : ($eta < 3600 ? (int) ceil($eta / 60) . ' min' : number_format($eta / 3600, 1, ',', '.') . ' h'));
$intervalMs = (int) (($session['wait_kind'] ?? '') === 'block_pause'
    ? ($session['configuration']['block_pause_ms'] ?? 30000)
    : ($session['configuration']['interval_ms'] ?? 2000));
$technicalLink = $attention
    && trim((string) ($attention['queue_key'] ?? '')) !== ''
    && trim((string) ($attention['source_id'] ?? '')) !== ''
    ? $base . '/settings/cron/work?' . http_build_query([
    'queue_key' => (string) ($attention['queue_key'] ?? ''),
    'source_id' => (string) ($attention['source_id'] ?? ''),
]) : '';
$continuesWithAttention = !empty($session['continues_with_attention']);
$processingPaused = !empty($session['processing_paused']);
$reviewErrorsLink = $base . '/settings/manual-processing/session/items?' . http_build_query([
    'id' => (int) $session['id'],
    'status' => 'failed',
]);
?>
<div class="page-head compact-head">
  <div>
    <p class="eyebrow">PROCESAR AHORA</p>
    <h1>Campaña #<?= (int) $session['id'] ?></h1>
    <p>Progreso real, ritmo y resultados en una sola vista.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base . '/settings/manual-processing/session/items?id=' . (int) $session['id']) ?>">Ver todos los trabajos</a>
    <a class="btn" href="<?= View::e($base . '/settings/manual-processing') ?>">Volver</a>
  </div>
</div>

<section
  class="manual-ops"
  data-manual-processing-session="<?= (int) $session['id'] ?>"
  data-manual-controller="directed-cli-v1"
  data-session-status="<?= View::e($status) ?>"
  data-display-state="<?= View::e($display) ?>"
  data-engine-live="<?= !empty($session['engine_live']) ? '1' : '0' ?>"
  data-wait-kind="<?= View::e((string) ($session['wait_kind'] ?? 'interval')) ?>"
  data-interval-ms="<?= max(1, $intervalMs) ?>"
  data-next-action-at="<?= View::e((string) ($session['next_action_at'] ?? '')) ?>"
  data-status-url="<?= View::e($base . '/settings/manual-processing/status.json?campaign_id=' . (int) $session['id']) ?>"
  data-work-detail-base="<?= View::e($base . '/settings/cron/work') ?>"
  data-csrf-token="<?= View::e(Csrf::token()) ?>"
  data-known-version="<?= (int) ($session['version_no'] ?? 0) ?>"
  data-next-event-id="<?= (int) ($session['next_event_id'] ?? 0) ?>"
>
  <?php if ($display === 'maintenance'): ?>
    <aside class="manual-runtime-notice" role="status">
      <div>
        <strong>Procesamiento detenido por mantenimiento</strong>
        <p>No se iniciarán consultas a Mercado Libre. La campaña #<?= (int) $session['id'] ?> conserva sus <?= $totalJobs ?> trabajos y <?= $totalUnits ?> unidades.</p>
      </div>
      <a class="btn" href="<?= View::e($base) ?>/settings/api-health">Ver protección</a>
    </aside>
  <?php elseif (empty($session['engine_live'])): ?>
    <aside class="manual-runtime-notice" role="status">
      <div>
        <strong>Esperando que Hostinger inicie el ERP</strong>
        <p>La campaña #<?= (int) $session['id'] ?> conserva sus <?= $totalJobs ?> trabajos y <?= $totalUnits ?> unidades. No se ha perdido progreso.</p>
      </div>
      <a class="btn" href="<?= View::e($base) ?>/settings/cron">Ver diagnóstico del lanzador</a>
    </aside>
  <?php endif; ?>
  <aside class="manual-runtime-notice" role="status">
    <div>
      <strong>Último ciclo Cron de esta campaña</strong>
      <p>
        Selección: <span data-manual-cron-selected><?= View::e((string) ($session['last_cron_selected_label'] ?? 'Sin selección registrada')) ?></span> ·
        <span data-manual-cron-reason><?= View::e((string) ($session['waiting_reason_label'] ?? 'Aún no hay causa registrada por Cron.')) ?></span>
        <?php if (!empty($session['next_eligible_label'])): ?>
          · Próxima oportunidad: <span data-manual-cron-next><?= View::e((string) $session['next_eligible_label']) ?></span>
        <?php else: ?>
          · <span data-manual-cron-next>El próximo ciclo decidirá si hay recurso listo.</span>
        <?php endif; ?>
      </p>
    </div>
    <a class="btn" href="<?= View::e($base) ?>/settings/cron">Ver Cron</a>
  </aside>
  <div class="manual-ops-main">
    <header class="manual-ops-head">
      <div>
        <span class="manual-engine-state" data-manual-state="<?= View::e($display) ?>"><i aria-hidden="true"></i><b data-manual-status><?= View::e($stateLabels[$display] ?? 'Por comprobar') ?></b></span>
        <h2><?= $totalJobs ?> <?= $totalJobs === 1 ? 'trabajo' : 'trabajos' ?> · <?= $totalUnits ?> <?= $totalUnits === 1 ? 'unidad' : 'unidades' ?></h2>
        <p data-manual-account><?= View::e((string) ($session['account_label'] ?? 'Todas las cuentas')) ?></p>
      </div>
      <p class="manual-last-message" data-manual-message><?= View::e((string) ($session['safe_message'] ?? 'Preparando el siguiente paso.')) ?></p>
    </header>

    <div class="manual-progress-stack">
      <div class="manual-progress-row">
        <div><span>Campaña</span><strong data-manual-job-progress><?= $resolvedJobs ?> de <?= $totalJobs ?> trabajos resueltos</strong></div>
        <b data-manual-percent><?= number_format($jobPercent, 1, ',', '.') ?> %</b>
      </div>
      <div class="manual-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="<?= max(1, $totalJobs) ?>" aria-valuenow="<?= $resolvedJobs ?>" aria-valuetext="<?= View::e($jobPercent . ' % completado') ?>">
        <i data-manual-progress-fill style="transform:scaleX(<?= min(1, max(0, $jobPercent / 100)) ?>)"></i>
      </div>
      <div class="manual-progress-detail">
        <div>
          <span>Trabajo actual</span>
          <strong data-manual-work-progress>
            <?= !empty($work['known']) && (int) ($work['total'] ?? 0) > 0
                ? (int) ($work['resolved'] ?? 0) . ' de ' . (int) $work['total'] . ' unidades'
                : 'Por calcular' ?>
          </strong>
        </div>
        <div>
          <span>Ritmo global</span>
          <strong><b data-manual-call-in-block><?= max(0, (int) ($rhythm['calls'] ?? 0)) ?></b> de <b data-manual-block-size><?= max(1, (int) ($rhythm['size'] ?? 1)) ?></b> transportes HTTP en la ventana actual</strong>
        </div>
      </div>
    </div>

    <dl class="manual-stat-line" aria-label="Resumen de la campaña">
      <div><dt>Completados</dt><dd data-manual-completed><?= (int) ($session['completed_items'] ?? 0) ?></dd></div>
      <div><dt>Pendientes</dt><dd data-manual-pending><?= (int) ($session['open_items'] ?? 0) ?></dd></div>
      <div><dt>En curso</dt><dd data-manual-running><?= (int) ($session['running_items'] ?? 0) ?></dd></div>
      <div><dt>Omitidos</dt><dd data-manual-skipped><?= (int) ($session['skipped_items'] ?? 0) ?></dd></div>
      <div class="<?= $failed > 0 ? 'has-attention' : '' ?>"><dt>Errores</dt><dd data-manual-failed><?= $failed ?></dd></div>
      <div><dt>HTTP confirmados</dt><dd data-manual-outbound><?= (int) ($rhythm['outbound'] ?? 0) ?></dd></div>
    </dl>

    <div class="manual-log-tabs" data-manual-tabs>
      <div role="tablist" aria-label="Información de la campaña">
        <button type="button" role="tab" aria-selected="true" data-manual-tab="activity">Actividad</button>
        <button type="button" role="tab" aria-selected="false" data-manual-tab="todo">Por hacer</button>
        <button type="button" role="tab" aria-selected="false" data-manual-tab="completed">Completados</button>
        <button type="button" role="tab" aria-selected="false" data-manual-tab="errors">Errores <span data-manual-error-count><?= $failed ?></span></button>
      </div>
      <section role="tabpanel" data-manual-panel="activity">
        <ol class="manual-activity-list" data-manual-events aria-live="polite" aria-relevant="additions text">
          <?php foreach ($events as $event): ?>
          <li class="is-<?= View::e((string) $event['severity']) ?>" data-event-id="<?= (int) $event['id'] ?>">
            <time datetime="<?= View::e((string) $event['created_at']) ?>"><?= View::e((string) ($event['display_time'] ?? DateTimePresenter::formatQueue($event['created_at'] ?? null, 'H:i:s'))) ?></time>
            <span><?= View::e((string) $event['safe_message']) ?></span>
            <b><?= View::e($eventLabels[(string) $event['severity']] ?? 'Información') ?></b>
          </li>
          <?php endforeach; ?>
          <?php if ($events === []): ?><li class="is-neutral"><span>La actividad aparecerá cuando comience el primer paso.</span></li><?php endif; ?>
        </ol>
        <a class="text-link" href="<?= View::e($base . '/settings/manual-processing/session/events?id=' . (int) $session['id']) ?>">Abrir bitácora completa</a>
      </section>
      <section role="tabpanel" data-manual-panel="todo" hidden>
        <p><strong data-manual-next-label><?= View::e((string) ($next['human_label'] ?? 'No hay otro trabajo listo')) ?></strong></p>
        <p data-manual-next-detail><?= View::e((string) ($next['content_summary'] ?? 'El sistema comprobará si quedan pendientes.')) ?></p>
      </section>
      <section role="tabpanel" data-manual-panel="completed" hidden>
        <p><strong data-manual-last-result><?= View::e((string) ($session['last_result_message'] ?? 'Aún no hay resultados.')) ?></strong></p>
      </section>
      <section role="tabpanel" data-manual-panel="errors" hidden>
        <p data-manual-error-message><?= View::e((string) ($attention['result_summary'] ?? 'No hay errores accionables.')) ?></p>
        <a class="btn small" data-manual-error-link href="<?= View::e($technicalLink !== '' ? $technicalLink : '#') ?>"<?= $technicalLink === '' ? ' hidden' : '' ?>>Ver qué ocurrió</a>
      </section>
    </div>
  </div>

  <aside class="manual-ops-side">
    <div class="manual-now-card">
      <span>AHORA</span>
      <strong data-manual-current><?= View::e((string) ($current['human_label'] ?? 'Preparando el siguiente paso')) ?></strong>
      <p data-manual-current-detail><?= View::e((string) ($current['content_summary'] ?? 'No se iniciará antes del intervalo permitido.')) ?></p>
    </div>
    <div class="manual-countdown-panel" data-manual-countdown role="timer" aria-label="Tiempo para la siguiente consulta">
      <span>PRÓXIMA CONSULTA</span>
      <strong data-manual-countdown-value>—</strong>
      <small data-manual-countdown-caption><?= !empty($session['engine_live'])
          ? (($session['wait_kind'] ?? '') === 'block_pause' ? 'Pausa segura entre bloques' : 'Esperando que Cron tome la campaña')
          : 'Esperando el próximo ciclo automático' ?></small>
      <div class="manual-time-track" aria-hidden="true"><i data-manual-time-fill></i></div>
    </div>
    <div class="manual-next-card">
      <span>DESPUÉS</span>
      <strong data-manual-side-next><?= View::e((string) ($next['human_label'] ?? 'Mostrar resultado')) ?></strong>
      <p>Tiempo aproximado restante: <b data-manual-eta><?= View::e($etaLabel) ?></b></p>
    </div>

    <?php if ((int) ($session['uncertain_attempts'] ?? 0) > 0): ?>
      <aside class="manual-attention">
        <strong><?= (int) $session['uncertain_attempts'] ?> <?= (int) $session['uncertain_attempts'] === 1 ? 'resultado por comprobar' : 'resultados por comprobar' ?></strong>
        <p>Hostinger interrumpió una ejecución. Lo ya aprobado permanece guardado y el ERP comprobará estos recursos antes de continuar.</p>
      </aside>
    <?php endif; ?>

    <aside class="manual-attention <?= $continuesWithAttention ? 'is-continuing' : '' ?>"
      data-manual-attention
      data-processing-paused="<?= $processingPaused ? '1' : '0' ?>"
      data-continues-with-attention="<?= $continuesWithAttention ? '1' : '0' ?>"
      <?= $attention ? '' : 'hidden' ?>>
      <strong data-manual-attention-title><?= $processingPaused
          ? 'Campaña pausada'
          : ($failed > 0 ? $failed . ($failed === 1 ? ' trabajo requiere revisión' : ' trabajos requieren revisión') : 'Revisión disponible') ?></strong>
      <p data-manual-attention-message><?= $continuesWithAttention
          ? 'Cron continúa con los demás trabajos. Los errores están aislados y no cuentan como completados.'
          : View::e((string) ($attention['result_summary'] ?? '')) ?></p>
      <div class="manual-attention-actions">
        <a class="btn small primary" data-manual-review-errors href="<?= View::e($reviewErrorsLink) ?>">Revisar <?= $failed > 0 ? $failed : '' ?> trabajos</a>
        <a class="text-link" data-manual-attention-link href="<?= View::e($technicalLink !== '' ? $technicalLink : '#') ?>"<?= $technicalLink === '' ? ' hidden' : '' ?>>Abrir el último detalle</a>
      </div>
    </aside>

    <div class="manual-monitor-actions">
      <form method="post" action="<?= View::e($base . '/settings/manual-processing/interactive/pause') ?>" data-manual-action="active" <?= $status !== 'active' ? 'hidden' : '' ?>>
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= (int) $session['id'] ?>">
        <button class="btn" type="submit">Pausar después del actual</button>
      </form>
      <form method="post" action="<?= View::e($base . '/settings/manual-processing/interactive/resume') ?>" data-manual-action="paused" <?= $status !== 'paused' ? 'hidden' : '' ?>>
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= (int) $session['id'] ?>">
        <button class="btn primary" type="submit">Continuar</button>
      </form>
      <form method="post" action="<?= View::e($base . '/settings/manual-processing/interactive/finish') ?>" data-manual-action="open" <?= !in_array($status, ['active', 'pausing', 'paused'], true) ? 'hidden' : '' ?>>
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= (int) $session['id'] ?>">
        <button class="btn subtle" type="submit">Finalizar y devolver pendientes</button>
      </form>
    </div>
  </aside>
</section>
