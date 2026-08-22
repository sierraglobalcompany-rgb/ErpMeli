<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$latest = $cron['latest_automatic'] ?? null;
$latestManual = $cron['latest_manual'] ?? null;
$cronRuntime = $cron['runtime'] ?? null;
$timeDiagnostic = $timeDiagnostic ?? [];
$healthy = (string) ($cron['state'] ?? '') === 'ok';
$warnings = $timeDiagnostic['warnings'] ?? [];
$notices = $timeDiagnostic['notices'] ?? [];
$runtimeMismatch = ($cron['runtime_matches_web'] ?? null) === false;
$releaseIntegrity = $releaseIntegrity ?? ['ok' => false, 'state' => 'unknown', 'errors' => []];
$integrityOk = !empty($releaseIntegrity['ok']);
$integrityState = (string) ($releaseIntegrity['state'] ?? 'unknown');
$problem = in_array((string) ($cron['state'] ?? ''), ['error', 'stale', 'interrupted'], true)
    || $runtimeMismatch
    || !$integrityOk
    || $warnings !== [];
$notificationState = (string) ($notificationCron['state'] ?? 'missing');
$notificationProcessing = $notificationProcessing ?? [];
$notificationPending = (int) ($notificationProcessing['pending'] ?? 0);
$notificationErrors = (int) ($notificationProcessing['errors'] ?? 0);
$notificationCompleted = (int) ($notificationProcessing['complete'] ?? 0);
$notificationOrdersToday = (int) ($notificationProcessing['orders_created_today'] ?? 0)
    + (int) ($notificationProcessing['orders_updated_today'] ?? 0);
$automationSummary = $automationSummary ?? [];
$runtimeStatus = is_array($runtimeStatus ?? null) ? $runtimeStatus : [];
$cronNarrative = is_array($cronNarrative ?? null) ? $cronNarrative : null;
$bootstrapAttempt = is_array($bootstrapAttempt ?? null) ? $bootstrapAttempt : null;
$bootstrapStopped = $bootstrapAttempt
    && (string) ($bootstrapAttempt['stage'] ?? '') === 'stopped_before_queues'
    && (string) ($bootstrapAttempt['result_state'] ?? '') === 'error';
$notificationProcessingState = $notificationPending === 0 && $notificationErrors === 0
    ? 'empty'
    : ($notificationPending > 0 && $notificationOrdersToday === 0 ? 'stalled' : ($notificationPending > 0 ? 'recovering' : 'attention'));
$collationRecovery = $collationRecovery ?? ['available' => false, 'status' => 'migration_pending'];
$databaseSession = $databaseSession ?? [];
$probeLatest = $probeCron['latest_manual'] ?? $probeCron['latest'] ?? null;
$resultLabel = static fn (?string $state): string => match ($state) {
    'success' => 'Completada',
    'empty' => 'Finalizada sin trabajo pendiente',
    'error' => 'Error',
    'interrupted' => 'Interrumpida',
    'running' => 'Ejecutándose',
    default => 'Sin información',
};
$automationTab = 'summary';
require __DIR__ . '/_automation_nav.php';
?>
<?php if (empty($embedded)): ?>
<div class="page-head"><div><span class="eyebrow">Automatización</span><h1>Centro de Automatización</h1><p>Compruebe cuándo trabajó, qué procesará y si necesita intervenir.</p></div><div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/settings/api-workload">Capacidad y ritmo</a><a class="btn" href="<?= View::e($base) ?>/settings/cron/next">Ver próxima ejecución</a></div></div>
<?php endif; ?>

<section class="human-status-hero <?= $problem ? 'is-warning' : ($healthy ? 'is-success' : '') ?>"
         data-cron-health
         data-status-url="<?= View::e($base) ?>/settings/cron/status.json">
  <div class="human-status-mark" aria-hidden="true"><?= $problem ? '!' : ($healthy ? '✓' : '…') ?></div>
  <div>
    <span class="eyebrow">Automatización general</span>
    <h2 data-cron-health-label><?= View::e((string) ($runtimeStatus['label'] ?? ($bootstrapStopped ? 'Automatización detenida antes de abrir las colas' : ($cron['label'] ?? 'Sin información')))) ?></h2>
    <p data-cron-health-message><?= View::e(!empty($runtimeStatus['message']) ? (string) $runtimeStatus['message'] : ($bootstrapStopped
        ? 'Hostinger sí ejecutó el lanzador. El ERP se detuvo durante la comprobación de la instalación y no confirmó consultas hacia Mercado Libre.'
        : (string) ($cron['message'] ?? 'No hay información reciente del cron.'))) ?></p>
    <?php if ($bootstrapAttempt): ?>
      <p class="operation-note">
        Última invocación: <?= View::e(DateTimePresenter::formatQueue($bootstrapAttempt['started_at'] ?? null, 'd/m H:i:s')) ?> ·
        etapa: <?= View::e(match ((string) ($bootstrapAttempt['stage'] ?? '')) {
            'invoked' => 'Invocada',
            'validating_installation' => 'Validando instalación',
            'preparing_queues' => 'Preparando colas',
            'processing' => 'Procesando',
            'finished' => 'Finalizada',
            'stopped_before_queues' => 'Detenida antes de las colas',
            default => 'Sin identificar',
        }) ?>
      </p>
    <?php endif; ?>
    <span class="muted" data-cron-test-status aria-live="polite"></span>
  </div>
  <form method="post" action="<?= View::e($base) ?>/settings/cron/test" data-cron-test-form data-process-overlay="1">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <input type="hidden" name="_json" value="1">
    <button class="btn primary" type="submit" data-cron-test-button>Comprobar instalación</button>
  </form>
</section>

<?php if ($cronNarrative): ?>
<section class="panel mt-2" aria-labelledby="cron-narrative-title">
  <header class="panel-head compact-panel-head">
    <div>
      <h2 id="cron-narrative-title"><?= View::e((string) ($cronNarrative['label'] ?? 'Último ciclo Cron')) ?></h2>
      <p><?= View::e((string) ($cronNarrative['message'] ?? 'Sin detalle disponible.')) ?></p>
    </div>
    <?php if (!empty($cronNarrative['finished_label'])): ?>
      <span class="badge green"><?= View::e((string) $cronNarrative['finished_label']) ?></span>
    <?php endif; ?>
  </header>
  <div class="settings-summary-grid">
    <div><small>Cierre del ciclo</small><strong><?= View::e((string) ($cronNarrative['end_reason_label'] ?? 'Sin causa')) ?></strong></div>
    <?php if (!empty($cronNarrative['manual_campaign'])): ?>
      <?php $manualTrace = (array) $cronNarrative['manual_campaign']; ?>
      <div><small>Campaña dirigida</small><strong>#<?= (int) ($manualTrace['campaign_id'] ?? 0) ?></strong></div>
      <div><small>Última selección</small><strong><?= View::e((string) ($manualTrace['last_selected_label'] ?? 'Sin dato')) ?></strong></div>
      <div><small>Próxima oportunidad</small><strong><?= View::e((string) ($manualTrace['next_label'] ?? 'Cuando Cron vuelva a evaluar')) ?></strong></div>
    <?php else: ?>
      <div><small>Campaña dirigida</small><strong>Sin campaña activa detectada</strong></div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<section class="panel mt-2 <?= $integrityOk ? '' : 'panel-warning' ?>" aria-labelledby="release-integrity-title">
  <header class="panel-head">
    <div>
      <h2 id="release-integrity-title"><?= $integrityOk ? 'Instalación íntegra' : ($integrityState === 'schema_pending' ? 'Migración de integridad pendiente' : 'Instalación mezclada') ?></h2>
      <p>
        <?php if ($integrityOk): ?>
          Los archivos, Queue V4 y el esquema pertenecen a la misma release.
        <?php elseif ($integrityState === 'schema_pending'): ?>
          Los archivos coinciden, pero debe completar la migración requerida antes de ejecutar el lanzador único.
        <?php else: ?>
          Hay archivos ausentes, antiguos o copiados en una carpeta incorrecta. La automatización quedó bloqueada preventivamente.
        <?php endif; ?>
      </p>
    </div>
    <a class="btn" href="<?= View::e($base) ?>/settings/cron?integrity=1">Comprobar integridad</a>
  </header>
  <div class="panel-body">
    <div class="settings-summary-grid">
      <div><small>Versión de archivos</small><strong><?= View::e((string) ($releaseIntegrity['file_version'] ?? 'desconocida')) ?></strong></div>
      <div><small>Versión del manifiesto</small><strong><?= View::e((string) ($releaseIntegrity['version'] ?? 'desconocida')) ?></strong></div>
      <div><small>Build activo</small><strong><code><?= View::e((string) ($releaseIntegrity['build_id'] ?? 'sin identificar')) ?></code></strong></div>
      <div><small>Migración requerida</small><strong><?= View::e((string) ($releaseIntegrity['minimum_migration'] ?? 'sin identificar')) ?></strong></div>
    </div>
    <?php if (!$integrityOk): ?>
      <?php if ($integrityState === 'schema_pending'): ?>
        <div class="alert warning mt-2">
          <strong>Cron recibe señal, pero está esperando actualización.</strong>
          Complete la migración <?= View::e((string) ($releaseIntegrity['minimum_migration'] ?? 'pendiente')) ?> antes de ejecutar campañas, canarios o colas.
          <p class="mt-2"><a class="btn primary" href="<?= View::e($base) ?>/actualizar.php">Abrir actualizador seguro</a></p>
        </div>
      <?php endif; ?>
      <div class="human-list mt-2">
        <?php foreach (array_slice($releaseIntegrity['errors'] ?? [], 0, 10) as $integrityError): ?>
          <article class="human-list-item">
            <div>
              <strong><?= View::e(match ((string) ($integrityError['code'] ?? '')) {
                  'component_mismatch' => 'Archivo diferente a la release',
                  'component_not_declared' => 'Componente no declarado',
                  'migration_pending' => 'Migración pendiente',
                  'schema_missing_table', 'schema_missing_columns' => 'Esquema incompleto',
                  'nested_release_detected' => 'Carpeta de subida anidada',
                  'version_mismatch' => 'Versiones de archivos diferentes',
                  default => 'Integridad pendiente de corregir',
              }) ?></strong>
              <p><?= View::e((string) ($integrityError['component'] ?? 'release')) ?><?= !empty($integrityError['path']) ? ' · ' . View::e((string) $integrityError['path']) : '' ?></p>
            </div>
            <span class="badge red">Bloqueado</span>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="metrics">
  <article class="metric-card compact"><div><div class="metric-label">Última ejecución automática</div><div class="metric-value"><?= $latest ? View::e(DateTimePresenter::formatQueue($latest['finished_at'] ?: $latest['started_at'], 'd/m H:i')) : 'Sin registro' ?></div><div class="metric-change"><?= $latest ? 'CLI · ' . View::e($resultLabel((string) ($latest['result_state'] ?? $latest['status']))) : 'Las pruebas web no cuentan' ?></div></div></article>
  <article class="metric-card compact"><div><div class="metric-label">Frecuencia observada</div><div class="metric-value"><?= !empty($cron['observed_interval_seconds']) ? View::e((string) max(1, (int) round((int) $cron['observed_interval_seconds'] / 60))) . ' min' : 'Por medir' ?></div><div class="metric-change"><?= (int) ($cron['automatic_streak'] ?? 0) ?> / <?= (int) ($cron['required_streak'] ?? 2) ?> señales verificadas</div></div></article>
  <article class="metric-card compact"><div><div class="metric-label">Próxima señal esperada</div><div class="metric-value"><?= !empty($cron['next_expected_at']) ? View::e(DateTimePresenter::formatQueue($cron['next_expected_at'], 'd/m H:i')) : 'Por confirmar' ?></div><div class="metric-change">Según la frecuencia real observada</div></div></article>
  <article class="metric-card compact"><div><div class="metric-label">Resultado de trabajo</div><div class="metric-value"><?= !empty($cron['is_empty']) ? 'Cola vacía' : ((int) ($latest['orders_count'] ?? 0) . ' órdenes') ?></div><div class="metric-change"><?= (int) ($latest['processed_chunks'] ?? 0) ?> bloques · <?= count($overdue ?? []) ?> vencidos</div></div></article>
</section>

<?php if (!empty($automationSummary['available'])): ?>
<section class="metrics automation-overview-metrics">
  <article class="metric-card compact"><div><div class="metric-label">Trabajos pendientes</div><div class="metric-value"><?= (int) ($automationSummary['pending'] ?? 0) ?></div><div class="metric-change"><a href="<?= View::e($base) ?>/settings/cron/queue">Ver cola completa</a></div></div></article>
  <article class="metric-card compact"><div><div class="metric-label">Trabajo más antiguo</div><div class="metric-value"><?= !empty($automationSummary['oldest']) ? View::e(DateTimePresenter::formatQueue($automationSummary['oldest'],'d/m H:i')) : 'Sin pendientes' ?></div><div class="metric-change">Prioridad y antigüedad</div></div></article>
  <article class="metric-card compact"><div><div class="metric-label">Esperando presupuesto</div><div class="metric-value"><?= (int) ($automationSummary['waiting_budget'] ?? 0) ?></div><div class="metric-change">Conservan su posición</div></div></article>
  <article class="metric-card compact"><div><div class="metric-label">Errores activos</div><div class="metric-value"><?= (int) ($automationSummary['errors'] ?? 0) ?></div><div class="metric-change"><a href="<?= View::e($base) ?>/settings/cron/attention">Abrir soluciones guiadas</a></div></div></article>
</section>
<?php endif; ?>

<?php if ($warnings !== [] || $runtimeMismatch): ?>
<section class="panel"><header class="panel-head"><div><h2>Requiere atención</h2><p>Corrija estos puntos antes de depender del cron.</p></div></header><div class="human-list">
<?php foreach ($warnings as $warning): ?><article class="human-list-item"><div><strong>Revisión horaria</strong><p><?= View::e((string) $warning) ?></p></div></article><?php endforeach; ?>
<?php if ($runtimeMismatch): ?><article class="human-list-item"><div><strong>PHP web y cron no coinciden</strong><p>Actualice el comando de Hostinger para usar la misma rama PHP del sitio.</p></div></article><?php endif; ?>
</div></section>
<?php endif; ?>

<?php if ($notices !== []): ?>
<section class="alert info" aria-label="Información horaria">
  <?php foreach ($notices as $notice): ?><p><?= View::e((string) $notice) ?></p><?php endforeach; ?>
</section>
<?php endif; ?>

<section class="panel mt-2">
  <header class="panel-head"><div><h2>Procesos automáticos</h2><p>Use primero el diagnóstico corto. La prueba de Hostinger espera a que termine el proceso seleccionado.</p></div></header>
  <div class="human-list">
    <article class="human-list-item">
      <div>
        <strong>Diagnóstico CLI rápido · solo para probar</strong>
        <p><?= $probeLatest ? 'Último diagnóstico: ' . View::e($resultLabel((string) ($probeLatest['result_state'] ?? $probeLatest['status']))) . '.' : 'Todavía no se ha ejecutado desde Hostinger.' ?> No procesa colas ni verifica la automatización.</p>
        <code><?= View::e($probeCronCommand ?? '') ?></code>
      </div>
      <span class="badge <?= $probeLatest && (string) ($probeLatest['status'] ?? '') === 'success' ? 'green' : 'amber' ?>"><?= $probeLatest ? 'Probado' : 'Pendiente' ?></span>
    </article>
    <article class="human-list-item">
      <div>
        <strong>Lanzador único del ERP</strong>
        <p><?= View::e((string) ($cron['label'] ?? 'Sin señales')) ?>. <?= $latest ? 'Última señal CLI ' . View::e(DateTimePresenter::formatQueue($latest['finished_at'] ?: $latest['started_at'])) . '.' : 'Todavía no hay señal CLI.' ?></p>
        <code><?= View::e($cronCommand ?? '') ?></code>
      </div>
      <span class="badge <?= $healthy ? 'green' : ($problem ? 'red' : 'amber') ?>"><?= View::e((string) ($cron['label'] ?? 'Pendiente')) ?></span>
    </article>
    <article class="human-list-item">
      <div>
        <strong>Notificaciones Webhook‑First · dentro del lanzador único</strong>
        <p>No configure una segunda tarea. Las ventas y notificaciones nuevas tienen prioridad dentro del proceso general.</p>
        <p class="muted">
          Procesamiento:
          <?= $notificationPending ?> pendientes ·
          <?= $notificationErrors ?> con error ·
          <?= $notificationCompleted ?> completados ·
          <?= $notificationOrdersToday ?> órdenes incorporadas o actualizadas hoy.
        </p>
        <?php if (!empty($notificationRecovery)): ?>
          <p class="muted">
            Recuperación del conflicto transaccional:
            <?= (int) ($notificationRecovery['recovered'] ?? 0) ?> recuperados ·
            <?= (int) ($notificationRecovery['remaining'] ?? 0) ?> pendientes de revisión.
          </p>
        <?php endif; ?>
      </div>
      <span class="badge <?= !empty($runtimeStatus['has_recent_signal']) ? 'green' : 'amber' ?>">Dentro del lanzador único</span>
    </article>
    <article class="human-list-item">
      <div>
        <strong>Resultado del procesamiento</strong>
        <p><?= View::e(match ($notificationProcessingState) {
            'empty' => 'Sin pendientes: el lanzador único no tiene recursos accionables.',
            'recovering' => 'Recuperando atrasos: hay actividad y recursos pendientes.',
            'stalled' => 'Activo sin progreso: el proceso responde, pero todavía no incorpora órdenes.',
            default => 'Hay errores que requieren revisión administrativa.',
        }) ?></p>
      </div>
      <span class="badge <?= $notificationProcessingState === 'empty' ? 'green' : ($notificationProcessingState === 'recovering' ? 'amber' : 'red') ?>">
        <?= View::e(match ($notificationProcessingState) {
            'empty' => 'Sin pendientes',
            'recovering' => 'Recuperando atrasos',
            'stalled' => 'Activo sin progreso',
            default => 'Requiere atención',
        }) ?>
      </span>
    </article>
  </div>
  <?php if (!empty($notificationFailures)): ?>
    <details class="technical-details mt-2">
      <summary><span>Ver recursos de notificaciones que necesitan revisión</span><span aria-hidden="true">⌄</span></summary>
      <div class="technical-details-body">
        <div class="table-scroll">
          <table class="data-table">
            <caption>Últimos recursos que no pudieron completarse</caption>
            <thead><tr><th>Cuenta</th><th>Recurso</th><th>Etapa</th><th>Motivo seguro</th><th>Diagnóstico</th></tr></thead>
            <tbody>
            <?php foreach ($notificationFailures as $failure): ?>
              <tr>
                <td data-label="Cuenta"><?= View::e($failure['account_name'] ?? '—') ?></td>
                <td data-label="Recurso"><?= View::e($failure['resource_type'] ?? '—') ?></td>
                <td data-label="Etapa"><?= View::e($failure['last_error_stage'] ?? '—') ?></td>
                <td data-label="Motivo seguro"><?= View::e($failure['last_error_message'] ?? '—') ?></td>
                <td data-label="Diagnóstico"><code><?= View::e($failure['last_error_diagnostic_id'] ?? '—') ?></code></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </details>
    <form class="inline-form mt-2" method="post" action="<?= View::e($base) ?>/settings/cron/notifications/recover-known-errors"
          data-confirm="¿Reprogramar únicamente los recursos cuyo diagnóstico confirma el conflicto interno de transacción?">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <button class="btn" type="submit">Reintentar errores transaccionales confirmados</button>
    </form>
  <?php endif; ?>
</section>

<section class="panel mt-2" aria-labelledby="collation-recovery-title">
  <header class="panel-head">
    <div>
      <h2 id="collation-recovery-title">Recuperación segura de notificaciones</h2>
      <p>Primero prueba un recurso por cuenta afectada. Si esa prueba pasa, los recursos restantes se liberan por lotes seguros.</p>
    </div>
    <span class="badge <?= !empty($databaseSession['matches_expected']) ? 'green' : 'red' ?>">
      <?= !empty($databaseSession['matches_expected']) ? 'Conexión Unicode correcta' : 'Collation por corregir' ?>
    </span>
  </header>
  <div class="panel-body"
       data-notification-recovery
       data-status-url="<?= View::e($base) ?>/settings/cron/notifications/recovery/status.json">
    <?php $recoveryStatus = (string) ($collationRecovery['status'] ?? 'idle'); ?>
    <div class="settings-summary-grid">
      <div><small>Estado</small><strong data-recovery-state><?= View::e(match ($recoveryStatus) {
          'canary_running' => 'Probando canario seguro',
          'canary_passed' => 'Prueba aprobada',
          'canary_failed' => 'Canario con error',
          'recovering' => 'Recuperando atrasos',
          'paused' => 'Pausada',
          'blocked' => 'Bloqueada por collation',
          'complete' => 'Terminada',
          'no_candidates' => 'Sin candidatos',
          'migration_pending' => 'Migración pendiente',
          default => 'Sin recuperación activa',
      }) ?></strong></div>
      <div><small>Total afectado</small><strong data-recovery-candidates><?= (int) ($collationRecovery['candidate_count'] ?? 0) ?></strong></div>
      <div><small>Prueba</small><strong data-recovery-complete><?= (int) ($collationRecovery['canary_complete_count'] ?? 0) ?> de <?= (int) ($collationRecovery['canary_count'] ?? 0) ?></strong></div>
      <div><small>Por recuperar</small><strong><?= (int) ($collationRecovery['pending_recovery_count'] ?? $collationRecovery['frozen_count'] ?? 0) ?></strong></div>
      <div><small>Fallidos</small><strong data-recovery-failed><?= (int) ($collationRecovery['failed_count'] ?? 0) ?></strong></div>
    </div>
    <p class="muted mt-2" data-recovery-message><?= View::e((string) ($collationRecovery['safe_message'] ?? $collationRecovery['message'] ?? 'Todavía no se ha preparado una recuperación.')) ?></p>
    <div class="page-actions mt-2">
      <?php if (in_array($recoveryStatus, ['idle','complete','no_candidates','canary_failed','blocked'], true)): ?>
        <form method="post" action="<?= View::e($base) ?>/settings/cron/notifications/recovery/canary"
              data-confirm="¿Probar un recurso por cada cuenta afectada antes de liberar el backlog?">
          <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
          <button class="btn primary" type="submit">Probar canarios</button>
        </form>
      <?php endif; ?>
      <?php if (in_array($recoveryStatus, ['canary_passed','paused'], true)): ?>
        <form method="post" action="<?= View::e($base) ?>/settings/cron/notifications/recovery/start"
              data-confirm="¿Iniciar la recuperación gradual en lotes seguros?">
          <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
          <input type="hidden" name="run_id" value="<?= (int) ($collationRecovery['id'] ?? 0) ?>">
          <button class="btn primary" type="submit"><?= $recoveryStatus === 'paused' ? 'Continuar recuperación' : 'Recuperar backlog' ?></button>
        </form>
      <?php endif; ?>
      <?php if (in_array($recoveryStatus, ['canary_running','recovering'], true)): ?>
        <form method="post" action="<?= View::e($base) ?>/settings/cron/notifications/recovery/pause"
              data-confirm="¿Pausar después del lote que ya está en proceso?">
          <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
          <input type="hidden" name="run_id" value="<?= (int) ($collationRecovery['id'] ?? 0) ?>">
          <button class="btn" type="submit">Pausar después del lote</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="panel mt-2">
  <header class="panel-head"><div><h2>Recuperar bloques vencidos</h2><p>Reprograma trabajos atrasados sin borrar órdenes ni reiniciar colas.</p></div></header>
  <div class="panel-body">
    <?php if (empty($overdue)): ?><div class="empty-state"><strong>No hay bloques vencidos</strong><span>El cron no necesita recuperación en este momento.</span></div>
    <?php else: ?>
      <p>Hay <strong><?= count($overdue) ?></strong> bloques listos para reprogramar. La acción cambia únicamente la próxima hora de ejecución.</p>
      <form class="inline-form mt-2" method="post" action="<?= View::e($base) ?>/settings/cron/reschedule-overdue" data-confirm="¿Reprogramar los bloques vencidos para que cron los retome?">
        <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
        <div class="field"><label for="cronScheduleMode">Cuándo</label><select class="input" id="cronScheduleMode" name="schedule_mode"><option value="delay">Después de una espera</option><option value="now">Ahora</option><option value="custom">Fecha y hora</option></select></div>
        <div class="field"><label for="cronDelay">Espera</label><select class="input" id="cronDelay" name="schedule_delay_minutes"><?php foreach ([5 => '5 minutos', 10 => '10 minutos', 20 => '20 minutos', 30 => '30 minutos', 0 => 'Ahora'] as $value => $label): ?><option value="<?= (int) $value ?>" <?= (($syncSettings ?? null) && $syncSettings->overdueRescheduleDefaultMinutes() === (int) $value) ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="cronScheduleAt">Fecha y hora local</label><input class="input" id="cronScheduleAt" type="datetime-local" name="schedule_at"></div>
        <button class="btn primary" type="submit">Reprogramar bloques vencidos</button>
      </form>
    <?php endif; ?>
  </div>
</section>

<details class="technical-details">
  <summary><span>Opciones avanzadas y Diagnóstico horario</span><span aria-hidden="true">⌄</span></summary>
  <div class="technical-details-body">
    <div class="table-scroll"><table class="data-table"><caption>Comparación de hora PHP, MySQL y ERP</caption><tbody><tr><th>Hora PHP</th><td><?= View::e((string) ($timeDiagnostic['php_now'] ?? '—')) ?></td></tr><tr><th>Zona PHP</th><td><?= View::e((string) ($timeDiagnostic['php_timezone'] ?? '—')) ?></td></tr><tr><th>Hora UTC</th><td><?= View::e((string) ($timeDiagnostic['php_utc'] ?? '—')) ?></td></tr><tr><th>Hora local ERP</th><td><?= View::e((string) ($timeDiagnostic['php_local'] ?? '—')) ?></td></tr><tr><th>MySQL UTC</th><td><?= View::e((string) ($timeDiagnostic['mysql_utc'] ?? '—')) ?></td></tr><tr><th>Diferencia MySQL/PHP</th><td><?= isset($timeDiagnostic['mysql_php_utc_diff_seconds']) && $timeDiagnostic['mysql_php_utc_diff_seconds'] !== null ? (int) $timeDiagnostic['mysql_php_utc_diff_seconds'] . ' s' : '—' ?></td></tr></tbody></table></div>
    <div class="table-scroll mt-2"><table class="data-table"><caption>Charset y collation de la conexión MySQL actual</caption><tbody><tr><th>Cliente</th><td><?= View::e((string) ($databaseSession['character_set_client'] ?? '—')) ?></td></tr><tr><th>Conexión</th><td><?= View::e((string) ($databaseSession['character_set_connection'] ?? '—')) ?></td></tr><tr><th>Resultados</th><td><?= View::e((string) ($databaseSession['character_set_results'] ?? '—')) ?></td></tr><tr><th>Collation</th><td><code><?= View::e((string) ($databaseSession['collation_connection'] ?? '—')) ?></code></td></tr><tr><th>Esperada</th><td><code><?= View::e((string) ($databaseSession['expected_collation'] ?? 'utf8mb4_unicode_ci')) ?></code></td></tr></tbody></table></div>
    <div class="table-scroll mt-2"><table class="data-table"><caption>PHP utilizado por web y cron</caption><thead><tr><th>Proceso</th><th>PHP</th><th>SAPI</th><th>Binario</th></tr></thead><tbody><tr><td>Web</td><td><?= View::e((string) ($phpRuntime['version'] ?? PHP_VERSION)) ?></td><td><?= View::e((string) ($phpRuntime['sapi'] ?? PHP_SAPI)) ?></td><td><code><?= View::e((string) ($phpRuntime['binary'] ?? PHP_BINARY)) ?></code></td></tr><tr><td>Último cron</td><td><?= View::e((string) ($cronRuntime['php_version'] ?? 'sin dato')) ?></td><td><?= View::e((string) ($cronRuntime['sapi'] ?? 'sin dato')) ?></td><td><code><?= View::e((string) ($cronRuntime['binary'] ?? 'sin dato')) ?></code></td></tr></tbody></table></div>
    <div class="panel-body">
      <p><strong>Único lanzador automático · solicite cada minuto</strong></p><code><?= View::e($cronCommand) ?></code>
      <p class="muted">La frecuencia real se calcula con las señales recibidas; Hostinger puede entregarlas más tarde.</p>
      <p class="mt-2"><strong>Preflight web</strong></p><p class="muted">“Comprobar instalación” sólo lee integridad, Queue V4, heartbeat y OAuth. No ejecuta Cron ni crea trabajo.</p>
      <p class="muted mt-2">No configure tareas separadas de notificaciones, campañas ni probes como recurrentes. El único comando recurrente es Queue V4 con <code>--runtime=45 --max-jobs=3</code>.</p>
    </div>
    <?php if ($latest): ?><div class="table-scroll mt-2"><table class="data-table"><caption>Última ejecución automática</caption><tbody><tr><th>Origen</th><td>CLI automático</td></tr><tr><th>Release</th><td><?= View::e((string) ($latest['release_version'] ?? 'sin identificar')) ?> · <code><?= View::e((string) ($latest['release_build_id'] ?? 'build anterior')) ?></code></td></tr><tr><th>Estado</th><td><?= View::e($resultLabel((string) ($latest['result_state'] ?? $latest['status']))) ?></td></tr><tr><th>Inicio</th><td><?= View::e(DateTimePresenter::formatQueue($latest['started_at'])) ?></td></tr><tr><th>Heartbeat</th><td><?= !empty($latest['heartbeat_at']) ? View::e(DateTimePresenter::formatQueue($latest['heartbeat_at'])) : '—' ?></td></tr><tr><th>Fin</th><td><?= !empty($latest['finished_at']) ? View::e(DateTimePresenter::formatQueue($latest['finished_at'])) : '—' ?></td></tr><tr><th>Duración</th><td><?= (int) $latest['duration_ms'] ?> ms</td></tr><tr><th>Código de salida</th><td><?= isset($latest['exit_code']) ? (int) $latest['exit_code'] : '—' ?></td></tr><tr><th>Identificador</th><td><code><?= View::e((string) ($latest['run_token'] ?? '—')) ?></code></td></tr><tr><th>Mensaje</th><td><?= View::e((string) ($latest['message'] ?: '—')) ?></td></tr></tbody></table></div><?php endif; ?>
    <?php if ($latestManual): ?><div class="table-scroll mt-2"><table class="data-table"><caption>Última prueba manual</caption><tbody><tr><th>Origen</th><td>Prueba web; no verifica la programación</td></tr><tr><th>Fecha</th><td><?= View::e(DateTimePresenter::formatQueue($latestManual['finished_at'] ?: $latestManual['started_at'])) ?></td></tr><tr><th>Estado</th><td><?= View::e($resultLabel((string) ($latestManual['result_state'] ?? $latestManual['status']))) ?></td></tr><tr><th>Identificador</th><td><code><?= View::e((string) ($latestManual['run_token'] ?? '—')) ?></code></td></tr></tbody></table></div><?php endif; ?>
  </div>
</details>
