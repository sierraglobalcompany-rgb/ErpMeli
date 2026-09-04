<?php

use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\AppSettingsService;
use App\Services\AssetVersionService;
use App\Services\AutomationHumanLanguageService;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$apiHealthAsset = View::asset($base, 'api-health.js') . '&v=' . rawurlencode(AssetVersionService::fingerprint('assets/api-health.js'));
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
$operatorWindows = is_array($overview['operator_windows'] ?? null) ? $overview['operator_windows'] : [];
$windowRows = is_array($operatorWindows['windows'] ?? null) ? $operatorWindows['windows'] : [];
$nowWindow = is_array($windowRows['60m'] ?? null) ? $windowRows['60m'] : [];
$dayWindow = is_array($windowRows['24h'] ?? null) ? $windowRows['24h'] : [];
$monthWindow = is_array($windowRows['30d'] ?? null) ? $windowRows['30d'] : [];
$apiStopped = (string) ($systemSafety['api'] ?? 'unknown') === 'stopped';
$activeIssues = array_values(array_filter($rows, static fn(array $row): bool => in_array((string) ($row['state'] ?? ''), ['attention', 'paused', 'disconnected'], true)));
$priorityIncidents = array_values(array_filter(
    (array) ($incidents['active'] ?? []),
    static fn(array $incident): bool => !empty($incident['rate_limit_signal'])
        || !empty($incident['signal_requires_protection'])
        || in_array((string) ($incident['severity'] ?? ''), ['critical', 'high'], true)
));
$rateLimitPriorityCount = count(array_filter($priorityIncidents, static fn(array $incident): bool => ($incident['transport_class'] ?? '') === 'REMOTE_HTTP_429'));
$alertSettings = new AppSettingsService();
$alertsEmailEnabled = $alertSettings->bool('alerts.email.enabled', false);
$alertsEmailTo = (string) $alertSettings->get('alerts.email.to', '');
$alertsEmailCooldown = max(5, min(1440, $alertSettings->int('alerts.email.cooldown_minutes', 60)));
$alertsEmailNotify429 = $alertSettings->bool('alerts.email.notify_429', true);
$alertsEmailNotifyAuth = $alertSettings->bool('alerts.email.notify_auth', true);
$apiHealthSection = 'overview';
$apiHealthHours = (int) ($overview['hours'] ?? 24);
$apiHealthCheckedAt = $overview['checked_at'] ?? null;
$operatorCheckedAtLabel = $apiHealthCheckedAt ? DateTimePresenter::formatQueue((string) $apiHealthCheckedAt, 'd/m H:i') : '';
$incidentLink = static function (array $overrides = []) use ($base, $apiHealthHours, $overview): string {
    $query = array_merge([
        'hours' => $apiHealthHours,
        'account_id' => (int) ($overview['account_id'] ?? 0),
    ], $overrides);
    $query = array_filter($query, static fn($value): bool => $value !== '' && $value !== null && $value !== 0 && $value !== '0');
    return $base . '/settings/api-health/incidents?' . http_build_query($query);
};
if (empty($apiHealthPartial)) {
    require __DIR__ . '/_api_health_header.php';
    require __DIR__ . '/_api_health_nav.php';
}
?>
<p class="sr-only">Salud de la integración. Estado por cuenta, incidentes, protección y Detalles técnicos.</p>

<section class="api-command-section" aria-labelledby="api-now-title">
  <header>
    <div>
      <span class="eyebrow">Salud Mercado Libre</span>
      <h2 id="api-now-title">Ahora</h2>
      <p><?= View::e(AutomationHumanLanguageService::verifiedDataLabel($operatorCheckedAtLabel)) ?></p>
    </div>
    <a class="btn" href="<?= View::e($incidentLink(['hours' => 24, 'http_status' => 429, 'origin' => 'remote'])) ?>">Ver 429 remotos</a>
  </header>
  <div class="api-essential-metrics">
    <a href="<?= View::e($incidentLink(['hours' => 1, 'http_status' => 429, 'origin' => 'remote'])) ?>"><span>429 remoto · 60m</span><strong><?= (int) ($nowWindow['remote_429'] ?? 0) ?></strong><p>24h: <?= (int) ($dayWindow['remote_429'] ?? 0) ?> · 30d histórico: <?= (int) ($monthWindow['remote_429'] ?? 0) ?></p></a>
    <a href="<?= View::e($incidentLink(['hours' => 1, 'origin' => 'remote'])) ?>"><span>5xx remoto · 60m</span><strong><?= (int) ($nowWindow['remote_5xx'] ?? 0) ?></strong><p>Fallo real de Mercado Libre. 24h: <?= (int) ($dayWindow['remote_5xx'] ?? 0) ?></p></a>
    <a href="<?= View::e($incidentLink(['hours' => 1, 'origin' => 'protection'])) ?>"><span>Protecciones locales · 60m</span><strong><?= (int) ($nowWindow['local_protections'] ?? 0) ?></strong><p>Sin HTTP remoto. 24h: <?= (int) ($dayWindow['local_protections'] ?? 0) ?> · 30d histórico: <?= (int) ($monthWindow['local_protections'] ?? 0) ?></p></a>
    <a href="<?= View::e($incidentLink(['hours' => 1, 'severity' => 'critical'])) ?>"><span>OAuth / permisos · 60m</span><strong><?= (int) ($nowWindow['oauth_or_permission'] ?? 0) ?></strong><p>401/403 remotos con acción de cuenta.</p></a>
  </div>
</section>

<section class="api-command-section" aria-labelledby="api-24h-title">
  <header>
    <div>
      <span class="eyebrow">Últimas 24 horas</span>
      <h2 id="api-24h-title">Contexto reciente</h2>
      <p>No reemplaza la alarma actual de 60m.</p>
    </div>
  </header>
  <div class="api-essential-metrics">
    <a href="<?= View::e($incidentLink(['hours' => 24, 'http_status' => 429, 'origin' => 'remote'])) ?>"><span>429 remoto</span><strong><?= (int) ($dayWindow['remote_429'] ?? 0) ?></strong><p>30d histórico: <?= (int) ($monthWindow['remote_429'] ?? 0) ?></p></a>
    <a href="<?= View::e($incidentLink(['hours' => 24, 'origin' => 'protection'])) ?>"><span>Protecciones locales</span><strong><?= (int) ($dayWindow['local_protections'] ?? 0) ?></strong><p>No llegaron a Mercado Libre.</p></a>
    <a href="<?= View::e($incidentLink(['hours' => 24, 'origin' => 'remote'])) ?>"><span>5xx remoto</span><strong><?= (int) ($dayWindow['remote_5xx'] ?? 0) ?></strong><p>Fallo real del remoto si aparece.</p></a>
  </div>
</section>

<section class="api-command-section" aria-labelledby="api-critical-email-title">
  <header>
    <div>
      <span class="eyebrow">Salud y alertas</span>
      <h2 id="api-critical-email-title">Alertas críticas por email</h2>
      <p>Configura avisos de 429 remoto, autorización/permisos y fallos críticos sin duplicar controles en Configuración.</p>
    </div>
  </header>
  <form method="post" action="<?= View::e($base) ?>/settings/api-health/email-settings" class="settings-section-form api-email-settings-form">
    <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
    <div class="settings-fields-grid">
      <div class="setting-field is-toggle">
        <div class="setting-field-copy">
          <label for="alerts-email-enabled">Activar alertas críticas</label>
          <p id="alerts-email-enabled-help">Notifica 429 remoto, autorización o fallos críticos con deduplicación y sin secretos.</p>
        </div>
        <label class="switch" aria-label="Activar alertas críticas">
          <input id="alerts-email-enabled" type="checkbox" name="alerts_email_enabled" value="1" <?= $alertsEmailEnabled ? 'checked' : '' ?> aria-describedby="alerts-email-enabled-help">
          <span aria-hidden="true"></span>
        </label>
      </div>
      <div class="setting-field">
        <label for="alerts-email-to">Correo de destino</label>
        <p id="alerts-email-to-help">Destino operativo para alertas API críticas.</p>
        <div class="setting-control">
          <input class="input" id="alerts-email-to" type="email" name="alerts_email_to" value="<?= View::e($alertsEmailTo) ?>" aria-describedby="alerts-email-to-help">
        </div>
      </div>
      <div class="setting-field">
        <label for="alerts-email-cooldown">Cooldown</label>
        <p id="alerts-email-cooldown-help">Evita repetir correos por el mismo incidente dentro de la ventana.</p>
        <div class="setting-control">
          <input class="input" id="alerts-email-cooldown" type="number" name="alerts_email_cooldown_minutes" min="5" max="1440" value="<?= $alertsEmailCooldown ?>" aria-describedby="alerts-email-cooldown-help">
          <span class="setting-unit">min</span>
        </div>
      </div>
      <div class="setting-field is-toggle">
        <div class="setting-field-copy">
          <label for="alerts-email-429">Notificar 429</label>
          <p id="alerts-email-429-help">Avisa cuando Mercado Libre confirma rate limit remoto.</p>
        </div>
        <label class="switch" aria-label="Notificar 429">
          <input id="alerts-email-429" type="checkbox" name="alerts_email_notify_429" value="1" <?= $alertsEmailNotify429 ? 'checked' : '' ?> aria-describedby="alerts-email-429-help">
          <span aria-hidden="true"></span>
        </label>
      </div>
      <div class="setting-field is-toggle">
        <div class="setting-field-copy">
          <label for="alerts-email-auth">Notificar 401/403</label>
          <p id="alerts-email-auth-help">Avisa errores remotos de autorización o permisos.</p>
        </div>
        <label class="switch" aria-label="Notificar 401/403">
          <input id="alerts-email-auth" type="checkbox" name="alerts_email_notify_auth" value="1" <?= $alertsEmailNotifyAuth ? 'checked' : '' ?> aria-describedby="alerts-email-auth-help">
          <span aria-hidden="true"></span>
        </label>
      </div>
    </div>
    <div class="page-actions mt-2">
      <button class="btn primary" type="submit">Guardar alertas</button>
      <button class="btn" type="submit" formaction="<?= View::e($base) ?>/settings/api-health/email-test">Enviar email de prueba</button>
    </div>
  </form>
</section>

<section class="api-command-section" aria-labelledby="api-protections-title">
  <header>
    <div>
      <span class="eyebrow">Protecciones</span>
      <h2 id="api-protections-title">Ritmo y espera</h2>
      <p>Las protecciones locales evitan salir a Mercado Libre cuando no es seguro consultar.</p>
    </div>
    <a class="btn" href="<?= View::e($base) ?>/settings/api-health/protection">Ver protección</a>
  </header>
  <div class="api-essential-metrics">
    <article><span>Billing</span><strong><?= !empty($protection['next_safe_at']) ? 'Esperando' : 'Disponible' ?></strong><p>Intervalo y cooldown vigentes.</p></article>
    <article><span>Ritmo global</span><strong><?= (int) ($protection['pause_count'] ?? 0) > 0 ? 'Pausado' : 'Activo' ?></strong><p>Permisos antes del transporte.</p></article>
    <article><span>Retry-After</span><strong>Vigente</strong><p>Se muestra sólo como probado por incidente cuando hay telemetría.</p></article>
  </div>
</section>

<section class="api-command-section" aria-labelledby="api-priority-title">
  <header>
    <div>
      <span class="eyebrow">Prioridad</span>
      <h2 id="api-priority-title">Errores que importan ahora</h2>
      <p>Sólo incidentes activos o accionables ahora. Lo recuperado queda en histórico reciente.</p>
    </div>
    <a class="btn" href="<?= View::e($incidentLink(['severity' => 'high', 'origin' => 'remote'])) ?>">Ver señales altas</a>
  </header>
  <div class="api-essential-metrics">
    <a href="<?= View::e($incidentLink(['severity' => 'critical'])) ?>"><span>Críticos</span><strong><?= count(array_filter($priorityIncidents, static fn(array $incident): bool => (string) ($incident['severity'] ?? '') === 'critical')) ?></strong><p>OAuth, bloqueo o autorización</p></a>
    <a href="<?= View::e($incidentLink(['http_status' => 429, 'origin' => 'remote'])) ?>"><span>Rate limit 429</span><strong><?= (int) $rateLimitPriorityCount ?></strong><p>Requiere respetar espera y limitar ritmo</p></a>
    <a href="<?= View::e($incidentLink(['severity' => 'high'])) ?>"><span>Permisos / API</span><strong><?= count(array_filter($priorityIncidents, static fn(array $incident): bool => (string) ($incident['severity'] ?? '') === 'high' && empty($incident['rate_limit_signal']))) ?></strong><p>403, permisos o respuesta remota</p></a>
  </div>
  <?php if ($priorityIncidents === []): ?>
    <p class="api-inline-empty is-success">No hay señales críticas, 429 ni errores altos activos ahora.</p>
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

<section class="api-command-section" aria-labelledby="api-automation-title">
  <header>
    <div>
      <span class="eyebrow">Automatización del ERP</span>
      <h2 id="api-automation-title">Automatización</h2>
      <p><span class="status-dot"></span><?= View::e((string) ($automationEvidence['label'] ?? 'Activa')) ?>.</p>
    </div>
    <a class="btn" href="<?= View::e($base) ?>/settings/cron">Ver automatización</a>
  </header>
</section>

<?php if (($erpProcessing['status'] ?? '') === 'attention' && !$apiStopped): ?>
  <section class="alert warning">
    <strong>Mercado Libre está disponible.</strong>
    <?= View::e((string) ($erpProcessing['label'] ?? 'Un proceso interno necesita revisión.')) ?>
    <a href="<?= View::e($incidentLink(['status' => 'active', 'origin' => 'local'])) ?>">Ver procesos del ERP</a>
  </section>
<?php endif; ?>

<?php if (!empty($emergencyStop['active'])): ?>
<section class="api-active-protection" aria-label="Bloqueo de emergencia activo">
  <article>
    <span class="api-protection-icon" aria-hidden="true">Ⅱ</span>
    <div>
      <strong>Consultas a Mercado Libre bloqueadas por mantenimiento</strong>
      <p>No se iniciarán consultas, renovaciones OAuth ni campañas remotas. Los datos y pendientes permanecen guardados.</p>
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
  <a href="<?= View::e($incidentLink(['status' => 'active'])) ?>">
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
            <a href="<?= View::e($incidentLink(['account_id' => (int) $row['id']])) ?>">Ver incidentes</a>
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
<?php if (empty($apiHealthPartial)): ?><script src="<?= View::e($apiHealthAsset) ?>" defer></script><?php endif; ?>
