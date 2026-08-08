<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\UiLabelPresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$tab = $tab ?? 'attention';
$progressive = !empty($progressive);
if ($progressive) {
  $sectionUrl = $base . '/notifications/section.json?tab=' . rawurlencode((string) $tab);
  ?>
  <section class="async-section" data-async-section="notifications-center" data-url="<?= View::e($sectionUrl) ?>" aria-live="polite" aria-busy="true">
    <div data-async-skeleton class="panel async-skeleton is-visible">
      <div class="page-head"><div><h1>Centro de notificaciones</h1><p>La pantalla ya abrió. Estamos separando actividad normal de asuntos que requieren atención.</p></div></div>
      <div class="skeleton-table"><span></span><span></span><span></span><span></span></div>
    </div>
    <div data-async-status class="async-section-status" hidden></div>
    <div data-async-content></div>
  </section>
  <noscript><a class="btn primary" href="<?= View::e($base) ?>/notifications?tab=<?= View::e(rawurlencode((string) $tab)) ?>&amp;full=1">Cargar notificaciones</a></noscript>
  <?php return;
}
$healthState = (string) ($health['status'] ?? 'yellow');
$healthClass = $healthState === 'green' ? 'green' : ($healthState === 'red' ? 'red' : 'amber');
$automatic = !empty($health['automatic']);
$pending = (int) ($health['pending'] ?? 0);
$oldest = $health['oldest_pending_at'] ?? null;
$signalMinutes = max(1, (int) (($health['worker_heartbeat_age_seconds'] ?? 0) / 60));
$label = static fn (string $value): string => match ($value) {
    'order_created' => 'Venta incorporada',
    'order_updated' => 'Venta actualizada',
    'shipment_updated' => 'Envío actualizado',
    'question_updated' => 'Pregunta recibida',
    'claim_updated' => 'Reclamo actualizado',
    'item_review_created' => 'Producto enviado a revisión',
    'stock_updated' => 'Stock actualizado',
    'recognized_ignored' => 'Evento informativo',
    default => ucfirst(str_replace('_', ' ', $value)),
};
?>

<div class="page-head notification-page-head">
  <div>
    <h1>Centro de notificaciones</h1>
    <p>Ventas incorporadas automáticamente, asuntos que requieren atención y salud del flujo Mercado Libre.</p>
  </div>
  <div class="page-actions">
    <a class="btn primary" href="<?= View::e($base) ?>/notifications/automation">Configurar automatización</a>
    <?php if (Auth::role() === 'admin' && !Auth::isTemporary()): ?>
      <a class="btn" href="<?= View::e($base) ?>/notifications/technical/events">Detalles técnicos</a>
    <?php endif; ?>
  </div>
</div>

<section class="operation-explainer" aria-label="Estado de notificaciones">
  <div><span>Qué está pasando</span><strong><?= $automatic ? 'Los avisos entran y se atienden por recurso.' : 'El flujo necesita una revisión local.' ?></strong></div>
  <div><span>Qué hará el ERP</span><strong>Conservará cada evento hasta que tenga un resultado verificable.</strong></div>
  <div><span>Qué puede hacer ahora</span><strong><?= count($attention) > 0 ? 'Revise primero los asuntos agrupados por causa.' : 'No necesita intervenir.' ?></strong></div>
</section>

<section class="notification-hero <?= View::e($healthClass) ?>">
  <div class="notification-hero-icon" aria-hidden="true"><?= $automatic ? '✓' : '!' ?></div>
  <div>
    <span class="eyebrow">Sincronización Webhook‑First</span>
    <h2><?= $automatic ? 'Sincronización automática funcionando' : View::e($health['label'] ?? 'Requiere atención') ?></h2>
    <p>
      <?php if ($automatic): ?>
        Mercado Libre avisa los cambios y el ERP actualiza cada recurso por una cola segura, sin volver a descargar bloques completos.
      <?php elseif (empty($health['worker_last_heartbeat_at'])): ?>
        El receptor puede guardar eventos, pero todavía no hay señales del lanzador único. Configure una sola tarea automática para todo el ERP.
      <?php else: ?>
        La cola conserva los eventos. Revise los trabajos detenidos mientras se recupera la automatización.
      <?php endif; ?>
    </p>
  </div>
  <div class="notification-hero-meta">
    <strong><?= $pending ?></strong>
    <span>recursos únicos pendientes</span>
  </div>
</section>

<?php if ($tab === 'attention'): ?>
  <section class="metrics notification-metrics">
    <article class="metric-card"><div><div class="metric-label">Reclamos</div><div class="metric-value"><?= (int) ($summary['claims'] ?? 0) ?></div><div class="metric-change">Pueden requerir respuesta en Mercado Libre</div></div></article>
    <article class="metric-card"><div><div class="metric-label">Preguntas</div><div class="metric-value"><?= (int) ($summary['questions'] ?? 0) ?></div><div class="metric-change">Solo lectura en el ERP</div></div></article>
    <article class="metric-card"><div><div class="metric-label">Trabajos con problema</div><div class="metric-value"><?= count($attention) ?></div><div class="metric-change">Pausados, vencidos o con error</div></div></article>
    <article class="metric-card"><div><div class="metric-label">Dentro del tiempo objetivo</div><div class="metric-value"><?= ($health['sla_percent'] ?? null) === null ? '—' : (int) $health['sla_percent'] . ' %' ?></div><div class="metric-change"><?= View::e($health['sla_label'] ?? 'Objetivo ' . (int) ($health['target_sla_seconds'] ?? 120) . ' segundos') ?></div></div></article>
  </section>

  <?php if (!$notifications && !$attention): ?>
    <section class="panel notification-empty">
      <div class="notification-empty-mark">✓</div>
      <h2>No hay asuntos que requieran atención</h2>
      <p>Las ventas y actualizaciones normales se muestran en “Actividad reciente”.</p>
    </section>
  <?php endif; ?>

  <?php if ($notifications): ?>
    <section class="panel notification-list">
      <header class="panel-head"><div><h2>Avisos para revisar</h2><p>Acciones humanas pendientes.</p></div></header>
      <?php foreach ($notifications as $notification): ?>
        <article class="notification-row <?= !empty($notification['is_read']) ? 'is-read' : '' ?>">
          <span class="notification-kind <?= View::e($notification['severity'] ?? 'info') ?>"><?= View::e(ucfirst((string) $notification['type'])) ?></span>
          <div class="notification-row-content">
            <h3><?= View::e($notification['title']) ?></h3>
            <p><?= View::e($notification['message'] ?: 'Sin detalle adicional.') ?></p>
            <small><?= View::e($notification['account_name'] ?: 'Sin cuenta') ?> · <?= View::e($notification['last_occurred_at'] ?? $notification['created_at']) ?></small>
          </div>
          <div class="notification-row-actions">
            <?php if (!empty($notification['action_url'])): ?><a class="btn primary" href="<?= View::e($base . $notification['action_url']) ?>">Abrir</a><?php endif; ?>
            <form method="post" action="<?= View::e($base) ?>/notifications/read">
              <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
              <input type="hidden" name="id" value="<?= (int) $notification['id'] ?>">
              <button type="submit" class="btn">Marcar revisada</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <?php if ($attention): ?>
    <section class="panel notification-list">
      <header class="panel-head"><div><h2>Trabajos detenidos o vencidos</h2><p>No se perdieron: pueden continuar desde su checkpoint.</p></div></header>
      <?php foreach ($attention as $work): ?>
        <article class="notification-row">
          <span class="notification-kind warning"><?= View::e($label((string) $work['resource_type'])) ?></span>
          <div class="notification-row-content">
            <h3><?= View::e($work['account_name'] ?: 'Cuenta no asociada') ?></h3>
            <p><?= View::e($work['last_error_message'] ?: 'Este recurso excedió el tiempo esperado y sigue pendiente.') ?></p>
            <small>Estado: <?= View::e(UiLabelPresenter::status($work['status'] ?? null)) ?> · Diagnóstico <?= View::e($work['correlation_id']) ?></small>
          </div>
          <?php if (in_array($work['status'], ['error', 'quarantined'], true)): ?>
            <form method="post" action="<?= View::e($base) ?>/notifications/work/retry">
              <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
              <input type="hidden" name="work_id" value="<?= (int) $work['id'] ?>">
              <button type="submit" class="btn">Reintentar</button>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

<?php elseif ($tab === 'activity'): ?>
  <section class="metrics notification-metrics">
    <article class="metric-card"><div><div class="metric-label">Ventas incorporadas hoy</div><div class="metric-value"><?= (int) ($health['orders_created_today'] ?? 0) ?></div><div class="metric-change">Órdenes nuevas creadas desde eventos</div></div></article>
    <article class="metric-card"><div><div class="metric-label">Ventas actualizadas hoy</div><div class="metric-value"><?= (int) ($health['orders_updated_today'] ?? 0) ?></div><div class="metric-change">Cambios aplicados desde eventos</div></div></article>
    <article class="metric-card"><div><div class="metric-label">Consultas repetidas evitadas</div><div class="metric-value"><?= (int) ($health['deduplicated_events'] ?? 0) ?></div><div class="metric-change">Eventos agrupados por recurso</div></div></article>
  </section>
  <section class="panel notification-list">
    <header class="panel-head">
      <div><h2>Actividad reciente</h2><p>Resultados normales del procesamiento automático; no son alertas.</p></div>
    </header>
    <?php if (!$activity): ?>
      <div class="empty">Todavía no hay recursos procesados por la cola Webhook‑First.</div>
    <?php endif; ?>
    <?php foreach ($activity as $item): ?>
      <article class="notification-row is-read">
        <span class="notification-kind info"><?= View::e($label((string) ($item['last_result'] ?? 'actividad'))) ?></span>
        <div class="notification-row-content">
          <h3><?= View::e($item['account_name'] ?: 'Sin cuenta') ?></h3>
          <p><?= (int) ($item['occurrence_count'] ?? 1) > 1 ? 'Se agruparon ' . (int) $item['occurrence_count'] . ' avisos del mismo recurso.' : 'Procesado desde una notificación de Mercado Libre.' ?></p>
          <small><?= View::e($item['last_processed_at'] ?: 'Pendiente de fecha') ?> · <?= (int) ($item['processing_ms'] ?? 0) ?> ms · <?= View::e($item['correlation_id']) ?></small>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

<?php else: ?>
  <section class="metrics notification-metrics">
    <article class="metric-card"><div><div class="metric-label">Receptor</div><div class="metric-value"><span class="badge green">Disponible</span></div><div class="metric-change">Recibidos hoy: <?= (int) ($health['received_today'] ?? 0) ?></div></div></article>
    <article class="metric-card"><div><div class="metric-label">Lanzador único</div><div class="metric-value"><span class="badge <?= $automatic ? 'green' : 'red' ?>"><?= $automatic ? 'Automático' : 'Sin confirmar' ?></span></div><div class="metric-change"><?= !empty($health['worker_last_heartbeat_at']) ? 'Última señal: ' . View::e($health['worker_last_heartbeat_at']) : 'Sin señales recientes' ?></div></div></article>
    <article class="metric-card"><div><div class="metric-label">Cola única</div><div class="metric-value"><?= $pending ?></div><div class="metric-change">Eventos repetidos agrupados: <?= (int) ($health['deduplicated_events'] ?? 0) ?></div></div></article>
    <article class="metric-card"><div><div class="metric-label">Spool de emergencia</div><div class="metric-value"><?= (int) ($health['spool_pending'] ?? 0) ?></div><div class="metric-change">Eventos esperando reingreso a MySQL</div></div></article>
  </section>

  <section class="panel notification-health-panel">
    <header class="panel-head">
      <div>
        <h2>Estado de la automatización</h2>
        <p>El receptor guarda rápido; el lanzador único consulta después cada recurso exacto.</p>
      </div>
    </header>
    <div class="notification-health-grid">
      <div><span>Trabajo más antiguo</span><strong><?= View::e($oldest ?: 'Sin pendientes') ?></strong></div>
      <div><span>Procesados hoy</span><strong><?= (int) ($health['processed_today'] ?? 0) ?></strong></div>
      <div><span>Errores reales</span><strong><?= (int) ($health['errors'] ?? 0) ?></strong></div>
      <div><span>Informativos ignorados</span><strong><?= (int) ($health['ignored'] ?? 0) ?></strong></div>
      <div><span>Recursos en cuarentena</span><strong><?= (int) ($health['quarantined'] ?? 0) ?></strong></div>
      <div><span>Última señal</span><strong><?= !empty($health['worker_last_heartbeat_at']) ? $signalMinutes . ' min' : 'No registrada' ?></strong></div>
      <div><span>Próxima señal esperada</span><strong><?= View::e($health['next_worker_expected_at'] ?? 'Pendiente') ?></strong></div>
      <div><span>Última reconciliación</span><strong><?= View::e($health['last_reconcile_at'] ?? 'No ejecutada') ?></strong></div>
      <div><span>Recepción p95</span><strong><?= isset($health['receiver_p95_ms']) ? (int) $health['receiver_p95_ms'] . ' ms' : 'Sin muestra' ?></strong></div>
      <div><span>Evento a dato local p95</span><strong><?= isset($health['event_to_local_p95_seconds']) ? (int) $health['event_to_local_p95_seconds'] . ' s' : 'Sin muestra' ?></strong></div>
    </div>
    <?php if (!$automatic): ?>
      <div class="alert warning">
        Las notificaciones se procesan mediante el lanzador único <code><?= View::e($notificationCronCommand ?? 'php jobs/process_sync_queue.php') ?></code>. No configure una segunda tarea.
      </div>
    <?php endif; ?>
    <div class="notification-actions">
      <a class="btn primary" href="<?= View::e($base) ?>/settings/manual-processing?scope=sales&amp;origin=notifications">Abrir Procesar ahora</a>
      <span class="form-help">El control antiguo «Procesar siguiente lote» fue retirado: esta pantalla ya no ejecuta una cola completa.</span>
      <form method="post" action="<?= View::e($base) ?>/notifications/work/pause" data-confirm="Los eventos seguirán guardándose, pero no se consultarán hasta reanudar.">
        <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
        <button type="submit" class="btn">Pausar cola</button>
      </form>
      <form method="post" action="<?= View::e($base) ?>/notifications/work/resume">
        <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
        <button type="submit" class="btn">Reanudar cola</button>
      </form>
    </div>
  </section>

  <?php if (Auth::role() === 'admin' && !Auth::isTemporary()): ?>
    <section class="panel notification-recovery">
      <header class="panel-head">
        <div>
          <h2>Recuperación del historial</h2>
          <p>Primero clasifica localmente y agrupa recursos. Solo después, con su aprobación, encola lo que falta.</p>
        </div>
        <form method="post" action="<?= View::e($base) ?>/notifications/backfill/analyze">
          <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
          <button type="submit" class="btn">Analizar historial sin API</button>
        </form>
      </header>
      <?php if (!$backfillRuns): ?><div class="empty">No se ha ejecutado una recuperación histórica.</div><?php endif; ?>
      <?php foreach ($backfillRuns as $run): ?>
        <article class="notification-recovery-run">
          <div>
            <strong>Recuperación #<?= (int) $run['id'] ?></strong>
            <span class="badge <?= in_array($run['status'], ['complete', 'ready'], true) ? 'green' : ($run['status'] === 'error' ? 'red' : 'amber') ?>"><?= View::e(UiLabelPresenter::status($run['status'] ?? null)) ?></span>
            <p>Analizados <?= (int) $run['analyzed_count'] ?> de <?= (int) $run['source_total'] ?> · Ya satisfechos <?= (int) $run['satisfied_local_count'] ?> · Encolados <?= (int) $run['queued_count'] ?> · Informativos <?= (int) $run['ignored_count'] ?></p>
          </div>
          <div class="row-actions">
            <?php if (in_array($run['status'], ['ready', 'paused'], true)): ?>
              <form method="post" action="<?= View::e($base) ?>/notifications/backfill/start">
                <input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="run_id" value="<?= (int) $run['id'] ?>">
                <button type="submit" class="btn primary">Iniciar recuperación segura</button>
              </form>
            <?php endif; ?>
            <?php if (in_array($run['status'], ['analyzing', 'running'], true)): ?>
              <form method="post" action="<?= View::e($base) ?>/notifications/backfill/pause">
                <input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="run_id" value="<?= (int) $run['id'] ?>">
                <button type="submit" class="btn">Pausar</button>
              </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
<?php endif; ?>
