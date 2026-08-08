<?php

use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$status = is_array($overview['status'] ?? null) ? $overview['status'] : [];
$queries = is_array($overview['queries'] ?? null) ? $overview['queries'] : [];
$accountSummary = is_array($overview['accounts'] ?? null) ? $overview['accounts'] : ['rows' => []];
$incidents = is_array($overview['incidents'] ?? null) ? $overview['incidents'] : [];
$protection = is_array($overview['protection'] ?? null) ? $overview['protection'] : [];
$erpProcessing = is_array($overview['erp_processing'] ?? null) ? $overview['erp_processing'] : [];
$automationEvidence = is_array($overview['automation_evidence'] ?? null) ? $overview['automation_evidence'] : [];
$rows = is_array($accountSummary['rows'] ?? null) ? $accountSummary['rows'] : [];
$manualPause = is_array($protection['manual_pause'] ?? null) ? $protection['manual_pause'] : [];
$emergencyStop = is_array($protection['emergency_stop'] ?? null) ? $protection['emergency_stop'] : [];
$systemSafety = is_array($overview['system_safety'] ?? null) ? $overview['system_safety'] : [];
$apiStopped = (string) ($systemSafety['api'] ?? 'unknown') === 'stopped';
$activeIssues = array_values(array_filter($rows, static fn(array $row): bool => in_array((string) ($row['state'] ?? ''), ['attention', 'paused', 'disconnected'], true)));
$priorityIncidents = array_values(array_filter(
    array_merge((array) ($incidents['active'] ?? []), (array) ($incidents['recovered'] ?? [])),
    static fn(array $incident): bool => !empty($incident['rate_limit_signal'])
        || !empty($incident['signal_requires_protection'])
        || in_array((string) ($incident['severity'] ?? ''), ['critical', 'high'], true)
));
$rateLimitPriorityCount = count(array_filter($priorityIncidents, static fn(array $incident): bool => !empty($incident['rate_limit_signal']) || (int) ($incident['http_status'] ?? 0) === 429));
$apiHealthSection = 'overview';
$apiHealthHours = (int) ($overview['hours'] ?? 24);
$apiHealthCheckedAt = $overview['checked_at'] ?? null;
if (empty($apiHealthPartial)) {
    require __DIR__ . '/_api_health_header.php';
    require __DIR__ . '/_api_health_nav.php';
}
?>
<p class="sr-only">Salud de la integración. Estado por cuenta, incidentes, protección y Detalles técnicos.</p>

<section class="api-health-triad" aria-label="Estado de Mercado Libre, protección y automatización"
         data-api-health-live
         data-overview-url="<?= View::e($base) ?>/settings/api-health/overview.json?hours=<?= (int) $apiHealthHours ?><?= !empty($overview['account_id']) ? '&amp;account_id=' . (int) $overview['account_id'] : '' ?>">
  <article data-health-card="marketplace">
    <span>Mercado Libre</span>
    <strong data-health-label><?= View::e((string) ($status['label'] ?? 'No comprobado')) ?></strong>
    <p data-health-message><?= View::e((string) ($status['summary'] ?? 'Sin evidencia disponible.')) ?></p>
  </article>
  <article data-health-card="protection">
    <span>Protección del ERP</span>
    <strong data-health-label><?= $apiStopped ? 'Bloqueada por mantenimiento' : ((int) ($protection['erp_wait_count'] ?? 0) > 0 || (int) ($protection['pause_count'] ?? 0) > 0 ? 'Limitando el ritmo' : 'Sin pausas activas') ?></strong>
    <p data-health-message><?= (int) ($protection['erp_wait_count'] ?? 0) ?> esperas preventivas · <?= (int) ($protection['pause_count'] ?? 0) ?> pausas activas.</p>
  </article>
  <article data-health-card="automation">
    <span>Automatización</span>
    <strong data-health-label><?= View::e((string) ($automationEvidence['label'] ?? 'No comprobada')) ?></strong>
    <p data-health-message><?= View::e((string) ($automationEvidence['message'] ?? 'Cron se evalúa por separado.')) ?></p>
  </article>
</section>
<p class="api-live-freshness" data-api-health-freshness aria-live="polite">Estado comprobado en el servidor.</p>

<section class="api-command-section" aria-labelledby="api-priority-title">
  <header>
    <div>
      <span class="eyebrow">Prioridad</span>
      <h2 id="api-priority-title">Errores que importan ahora</h2>
      <p>Rate limit, permisos, OAuth y señales de bloqueo se muestran aunque ya se hayan recuperado.</p>
    </div>
    <a class="btn" href="<?= View::e($base) ?>/settings/api-health/incidents?severity=high&amp;origin=remote">Ver señales altas</a>
  </header>
  <div class="api-essential-metrics">
    <a href="<?= View::e($base) ?>/settings/api-health/incidents?severity=critical"><span>Críticos</span><strong><?= count(array_filter($priorityIncidents, static fn(array $incident): bool => (string) ($incident['severity'] ?? '') === 'critical')) ?></strong><p>OAuth, bloqueo o autorización</p></a>
    <a href="<?= View::e($base) ?>/settings/api-health/incidents?http_status=429&amp;origin=remote"><span>Rate limit 429</span><strong><?= (int) $rateLimitPriorityCount ?></strong><p>Requiere respetar espera y limitar ritmo</p></a>
    <a href="<?= View::e($base) ?>/settings/api-health/incidents?severity=high"><span>Permisos / API</span><strong><?= count(array_filter($priorityIncidents, static fn(array $incident): bool => (string) ($incident['severity'] ?? '') === 'high' && empty($incident['rate_limit_signal']))) ?></strong><p>403, permisos o respuesta remota</p></a>
  </div>
  <?php if ($priorityIncidents === []): ?>
    <p class="api-inline-empty is-success">No hay señales críticas, 429 ni errores altos en el periodo.</p>
  <?php else: ?>
    <div class="api-attention-list">
      <?php foreach (array_slice($priorityIncidents, 0, 4) as $incident): ?>
        <a href="<?= View::e($base) ?>/settings/api-health/incidents/show?key=<?= View::e((string) $incident['incident_key']) ?>">
          <strong><?= View::e((string) ($incident['signal_label'] ?? $incident['title'] ?? 'Incidente')) ?></strong>
          <span><?= View::e((string) ($incident['impact'] ?? 'Revise el incidente.')) ?> · Activo ahora: <?= !empty($incident['active_now']) ? 'Sí' : 'No' ?></span>
          <b>Revisar</b>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="api-command-status is-<?= View::e((string) ($status['tone'] ?? 'unknown')) ?>" aria-live="polite">
  <div class="api-command-status-mark" aria-hidden="true"><?= View::e((string) ($status['icon'] ?? '?')) ?></div>
  <div class="api-command-status-copy">
    <span class="eyebrow">Estado actual</span>
    <h2><?= View::e((string) ($status['label'] ?? 'No se pudo comprobar')) ?></h2>
    <p><?= View::e((string) ($status['summary'] ?? 'No hay información disponible.')) ?></p>
    <small>Comprobado: <?= View::e(DateTimePresenter::formatQueue($overview['checked_at'] ?? null, 'd/m/Y H:i:s')) ?></small>
  </div>
  <div class="api-command-status-actions">
    <?php if ($apiStopped): ?>
      <a class="btn primary" href="<?= View::e($base) ?>/stop/">Abrir freno de mano</a>
    <?php else: ?>
      <?php if (($incidents['remote_active_count'] ?? 0) > 0): ?><a class="btn primary" href="<?= View::e($base) ?>/settings/api-health/incidents?status=active&amp;origin=remote">Revisar incidente</a><?php endif; ?>
      <?php if (($protection['pause_count'] ?? 0) > 0): ?><a class="btn primary" href="<?= View::e($base) ?>/settings/api-health/protection">Ver protección</a><?php endif; ?>
      <?php if (($status['status'] ?? '') === 'unknown'): ?><a class="btn primary" href="<?= View::e($base) ?>/settings/diagnostics">Ejecutar diagnóstico</a><?php endif; ?>
      <button class="btn" type="button" data-dialog-open="apiPauseDialog">Pausar consultas</button>
    <?php endif; ?>
  </div>
</section>

<section class="api-command-section" aria-labelledby="api-automation-title">
  <header>
    <div>
      <span class="eyebrow">Automatización del ERP</span>
      <h2 id="api-automation-title"><?= View::e((string) ($automationEvidence['label'] ?? 'Automatización no comprobada')) ?></h2>
      <p><?= View::e((string) ($automationEvidence['message'] ?? 'Este bloque describe Cron. La conexión con Mercado Libre se evalúa por separado.')) ?></p>
    </div>
    <a class="btn" href="<?= View::e($base) ?>/settings/cron">Abrir Cron</a>
  </header>
  <div class="alert <?= View::e((string) (($automationEvidence['tone'] ?? 'neutral') === 'danger' ? 'danger' : (($automationEvidence['tone'] ?? 'neutral') === 'warning' ? 'warning' : 'info'))) ?>">
    <strong>Son dos comprobaciones distintas.</strong>
    Salud API indica si Mercado Libre responde; este bloque indica si Cron está avanzando el trabajo del ERP.
  </div>
  <?php if (!empty($automationEvidence['available'])): ?>
    <div class="api-essential-metrics">
      <article><span>Seleccionados</span><strong><?= (int) ($automationEvidence['selected'] ?? 0) ?></strong><p><?= (int) ($automationEvidence['started'] ?? 0) ?> comenzaron</p></article>
      <article><span>Terminados</span><strong><?= (int) ($automationEvidence['completed'] ?? 0) ?></strong><p><?= (int) ($automationEvidence['deferred'] ?? 0) ?> aplazados</p></article>
      <article><span>Transporte completado</span><strong><?= (int) ($automationEvidence['remote_calls'] ?? 0) ?></strong><p><?= (int) ($automationEvidence['remote_calls_15m'] ?? 0) ?> consultas reales en 15 min; <?= (int) ($automationEvidence['blocked_remote_calls'] ?? 0) ?> bloqueadas antes de salir</p></article>
      <article><span>Finalizado</span><strong><?= View::e(DateTimePresenter::formatQueue($automationEvidence['finished_at'] ?? null, 'H:i:s')) ?></strong><p>Hora Bogotá</p></article>
    </div>
  <?php else: ?>
    <p class="api-inline-empty"><?= View::e((string) ($automationEvidence['message'] ?? 'Todavía no hay un ciclo automático finalizado.')) ?></p>
  <?php endif; ?>
</section>

<?php if (($erpProcessing['status'] ?? '') === 'attention' && !$apiStopped): ?>
  <section class="alert warning">
    <strong>Mercado Libre está disponible.</strong>
    <?= View::e((string) ($erpProcessing['label'] ?? 'Un proceso interno necesita revisión.')) ?>
    <a href="<?= View::e($base) ?>/settings/api-health/incidents?status=active&amp;origin=local">Ver procesos del ERP</a>
  </section>
<?php endif; ?>

<?php if (!empty($emergencyStop['active'])): ?>
<section class="api-active-protection" aria-label="Bloqueo de emergencia activo">
  <article>
    <span class="api-protection-icon" aria-hidden="true">Ⅱ</span>
    <div>
      <strong>Consultas a Mercado Libre bloqueadas por mantenimiento</strong>
      <p>No se iniciarán consultas, renovaciones OAuth ni campañas remotas. Los datos y trabajos permanecen guardados.</p>
    </div>
  </article>
</section>
<?php endif; ?>

<?php if (!empty($manualPause['pauses'])): ?>
<section class="api-active-protection" aria-label="Pausas activas">
  <?php foreach ($manualPause['pauses'] as $pause): ?>
    <article>
      <span class="api-protection-icon" aria-hidden="true">Ⅱ</span>
      <div>
        <strong>Consultas pausadas para <?= View::e(($pause['scope'] ?? '') === 'app' ? 'todas las cuentas' : (string) ($pause['account_name'] ?? 'una cuenta')) ?></strong>
        <p><?= View::e((string) ($pause['reason'] ?? 'Pausa preventiva')) ?> · <?= empty($pause['paused_until']) ? 'Sin reactivación automática' : 'Reactivación prevista: ' . View::e(DateTimePresenter::formatQueue($pause['paused_until'], 'd/m H:i')) ?></p>
      </div>
      <form method="post" action="<?= View::e($base) ?>/settings/api-health/resume" data-confirm="¿Reanudar las consultas de este alcance?">
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
        <input type="hidden" name="pause_id" value="<?= (int) ($pause['id'] ?? 0) ?>">
        <input type="hidden" name="reason" value="Reanudación confirmada por administración">
        <button class="btn primary" type="submit">Reanudar</button>
      </form>
    </article>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if (empty($overview['classified'])): ?>
  <div class="alert warning">Complete la migración 085 para separar fallos internos de respuestas de Mercado Libre.</div>
<?php endif; ?>

<section class="api-essential-metrics" aria-label="Indicadores principales">
  <article>
    <span>Consultas correctas</span>
    <span class="sr-only">Enviadas a Mercado Libre</span>
    <strong><?= number_format((int) ($queries['successful'] ?? 0), 0, ',', '.') ?> <small>/ <?= number_format((int) ($queries['sent'] ?? 0), 0, ',', '.') ?></small></strong>
    <p><?= $queries['success_rate'] !== null ? View::e(number_format((float) $queries['success_rate'], 1, ',', '.')) . ' % de éxito' : 'Sin consultas en el periodo' ?></p>
  </article>
  <article>
    <span>Cuentas disponibles</span>
    <strong><?= (int) ($accountSummary['available'] ?? 0) ?> <small>/ <?= (int) ($accountSummary['total'] ?? 0) ?></small></strong>
    <p><?= (int) ($accountSummary['disconnected'] ?? 0) ?> desconectadas</p>
  </article>
  <a href="<?= View::e($base) ?>/settings/api-health/incidents?status=active">
    <span>Incidentes activos</span>
    <strong><?= (int) ($incidents['remote_active_count'] ?? 0) ?></strong>
    <p><?= (int) ($incidents['remote_active_count'] ?? 0) > 0 ? 'Respuestas de Mercado Libre por revisar' : 'Sin alertas de Mercado Libre' ?></p>
  </a>
  <a href="<?= View::e($base) ?>/settings/api-health/protection">
    <span>Protección</span>
    <span class="sr-only">Presupuesto preventivo</span>
    <strong><?= (int) ($protection['pause_count'] ?? 0) > 0 ? (int) $protection['pause_count'] : 'Sin pausas' ?></strong>
    <p><?= $apiStopped
        ? 'No comprobada durante el mantenimiento'
        : (!empty($protection['next_safe_at'])
            ? 'Disponible: ' . View::e(DateTimePresenter::formatQueue($protection['next_safe_at'], 'd/m H:i'))
            : 'Consultas habilitadas') ?></p>
  </a>
</section>

<section class="api-command-section">
  <header>
    <div><span class="eyebrow">Operación</span><h2>Cuentas Mercado Libre</h2><p>Disponibilidad y resultado reciente de cada conexión.</p></div>
    <a class="btn" href="<?= View::e($base) ?>/settings/api-health/accounts">Ver todas</a>
  </header>
  <div class="api-account-list">
    <?php if ($rows === []): ?><p class="api-inline-empty">No hay cuentas Mercado Libre conectadas.</p><?php endif; ?>
    <?php foreach ($rows as $row): ?>
      <article class="api-account-row">
        <div class="api-account-identity">
          <strong><?= View::e((string) $row['name']) ?></strong>
          <span class="api-state-badge is-<?= View::e((string) $row['state']) ?>"><?= View::e((string) $row['state_label']) ?></span>
        </div>
        <div class="api-account-result"><strong><?= (int) $row['successful'] ?>/<?= (int) $row['sent'] ?></strong><span>consultas correctas</span></div>
        <div class="api-account-result"><strong><?= $row['last_activity_at'] ? View::e(DateTimePresenter::formatQueue($row['last_activity_at'], 'd/m H:i')) : 'Sin actividad' ?></strong><span><?= View::e((string) ($row['evidence_label'] ?? 'última actividad')) ?></span></div>
        <div class="api-account-result"><strong><?= (int) $row['active_incidents'] ?></strong><span>incidentes activos</span></div>
        <details class="api-row-menu">
          <summary aria-label="Acciones para <?= View::e((string) $row['name']) ?>">⋯</summary>
          <div>
            <a href="<?= View::e($base) ?>/settings/api-health/incidents?account_id=<?= (int) $row['id'] ?>">Ver incidentes</a>
            <a href="<?= View::e($base) ?>/logs?type=api&amp;account_id=<?= (int) $row['id'] ?>">Ver operaciones</a>
            <?php if (!$apiStopped && !$row['paused'] && $row['connected']): ?><button type="button" data-dialog-open="apiPauseDialog" data-account-id="<?= (int) $row['id'] ?>">Pausar cuenta</button><?php endif; ?>
          </div>
        </details>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<section class="api-command-section">
  <header><div><span class="eyebrow">Prioridad</span><h2>Necesitan atención</h2></div></header>
  <?php if ($activeIssues === [] && empty($incidents['active'])): ?>
    <p class="api-inline-empty is-success">No hay asuntos que requieran atención.</p>
  <?php else: ?>
    <div class="api-attention-list">
      <?php foreach (array_slice($incidents['active'] ?? [], 0, 3) as $incident): ?>
        <a href="<?= View::e($base) ?>/settings/api-health/incidents/show?key=<?= View::e((string) $incident['incident_key']) ?>"><strong><?= View::e((string) $incident['title']) ?></strong><span><?= View::e((string) $incident['impact']) ?></span><b>Revisar</b></a>
      <?php endforeach; ?>
      <?php foreach (array_slice($activeIssues, 0, 3) as $issue): ?>
        <a href="<?= View::e($base) ?>/settings/api-health/accounts?account_id=<?= (int) $issue['id'] ?>"><strong><?= View::e((string) $issue['name']) ?> · <?= View::e((string) $issue['state_label']) ?></strong><span><?= (int) $issue['active_incidents'] ?> incidentes activos. <?= $issue['next_safe_at'] ? 'Hora segura: ' . View::e(DateTimePresenter::formatQueue($issue['next_safe_at'])) : 'Revise la conexión y los incidentes.' ?></span><b>Ver cuenta</b></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="api-command-section">
  <header><div><span class="eyebrow">Seguimiento</span><h2>Actividad reciente</h2></div><a class="btn" href="<?= View::e($base) ?>/logs?type=api">Ver historial completo</a></header>
  <div class="api-activity-timeline">
    <?php if (empty($overview['recent_activity'])): ?><p class="api-inline-empty">No hay actividad reciente en el periodo seleccionado.</p><?php endif; ?>
    <?php foreach (($overview['recent_activity'] ?? []) as $activity): ?>
      <article><time><?= View::e(DateTimePresenter::formatQueue($activity['created_at'] ?? null, 'H:i')) ?></time><span class="api-activity-dot" aria-hidden="true"></span><div><strong><?= View::e((string) ($activity['operation_label'] ?? 'Consulta completada')) ?></strong><span><?= View::e((string) ($activity['account_name'] ?? 'Aplicación')) ?></span></div></article>
    <?php endforeach; ?>
  </div>
</section>

<?php if (!empty($incidents['recovered'])): ?>
<details class="api-recovered-history">
  <summary>Incidentes recuperados <span class="sr-only">Historial recuperado</span> · <?= (int) ($incidents['recovered_count'] ?? 0) ?> <?= (int) ($incidents['recovered_count'] ?? 0) === 1 ? 'incidente' : 'incidentes' ?></summary>
  <div>
    <?php foreach ($incidents['recovered'] as $incident): ?>
      <a href="<?= View::e($base) ?>/settings/api-health/incidents/show?key=<?= View::e((string) $incident['incident_key']) ?>"><strong><?= View::e((string) $incident['title']) ?></strong><span><?= number_format((int) $incident['repetitions'], 0, ',', '.') ?> repeticiones · última vez <?= View::e(DateTimePresenter::formatQueue($incident['last_seen_at'])) ?> · señal: <?= View::e((string) ($incident['signal_label'] ?? 'recuperada')) ?> · activo ahora: no</span></a>
    <?php endforeach; ?>
  </div>
</details>
<?php endif; ?>

<?php if (!$apiStopped): $pauseAccounts = $rows; require __DIR__ . '/_api_health_pause_dialog.php'; endif; ?>
<?php if (empty($apiHealthPartial)): ?><script src="<?= View::e($base) ?>/assets/api-health.js?v=2.34.1" defer></script><?php endif; ?>
