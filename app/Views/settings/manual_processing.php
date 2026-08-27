<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
$base = rtrim(Env::get('APP_URL', ''), '/');
$scopeLabels = [
  'recommended' => ['Recomendado ahora', 'Ventas, tareas urgentes y reparaciones seguras.'],
  'sales' => ['Ventas', 'Órdenes, notificaciones y datos relacionados.'],
  'finance' => ['Finanzas', 'Cálculos y conciliaciones pendientes.'],
  'audits' => ['Auditorías', 'Comprobaciones y reparaciones de ventas.'],
  'products' => ['Productos', 'Actualización de publicaciones.'],
  'descriptions' => ['Descripciones', 'Carga delicada: una consulta por descripción.'],
  'modules' => ['Módulos', 'Snapshots de capacidades aisladas.'],
  'all' => ['Todo lo que puede procesarse ahora', 'Solo recursos comprobados, disponibles y seguros para esta pestaña.'],
];
$duration = static function (int $seconds): string {
  if ($seconds < 60) return $seconds . ' s';
  if ($seconds < 3600) return (int) ceil($seconds / 60) . ' min';
  $hours = $seconds / 3600;
  return number_format($hours, $hours < 10 ? 1 : 0, ',', '.') . ' h';
};
$exclusionPresenter = new \App\Services\ManualCampaignExclusionPresenter();
$preview = is_array($preview ?? null) ? $preview : null;
$rhythmPreview = is_array($rhythmPreview ?? null) ? $rhythmPreview : [];
$manualQueueSnapshot = is_array($manualQueueSnapshot ?? null) ? $manualQueueSnapshot : [];
$counterValue = static function (string $key) use ($manualQueueSnapshot, $preview): string {
  if (isset($preview[$key . '_jobs'])) return (string) (int) $preview[$key . '_jobs'];
  if ($key === 'ready' && isset($preview['eligible_jobs'])) return (string) (int) $preview['eligible_jobs'];
  return array_key_exists($key, $manualQueueSnapshot) && $manualQueueSnapshot[$key] !== null
    ? (string) (int) $manualQueueSnapshot[$key]
    : '—';
};
$hasPreparedPreview = $preview !== null && !empty($preview['can_start_campaign']);
$previewConfiguration = is_array($preview['configuration'] ?? null) ? $preview['configuration'] : [];
$executionMode = in_array((string) ($_GET['execution_mode'] ?? $previewConfiguration['execution_mode'] ?? 'ONE_STEP'), ['ONE_STEP', 'CONTINUE_AUTOMATICALLY'], true)
  ? (string) ($_GET['execution_mode'] ?? $previewConfiguration['execution_mode'] ?? 'ONE_STEP')
  : 'ONE_STEP';
$maxItems = max(1, min(10000, (int) ($_GET['max_items'] ?? $previewConfiguration['max_items'] ?? $blockSize ?? 1)));
$maxDurationSeconds = max(0, min(604800, (int) ($_GET['max_duration_seconds'] ?? (($previewConfiguration['max_duration_minutes'] ?? $maxDurationMinutes ?? 0) * 60))));
$remoteCallsPerBurst = max(1, min(60, (int) ($_GET['remote_calls_per_burst'] ?? $previewConfiguration['remote_calls_per_burst'] ?? 1)));
$minimumDelayBetweenRemoteCalls = max(15, min(3600, (int) ($_GET['minimum_delay_between_remote_calls'] ?? $previewConfiguration['minimum_delay_between_remote_calls'] ?? max(15, (int) ceil(($intervalSeconds ?? 15))))));
$cooldownAfterBurst = max(0, min(3600, (int) ($_GET['cooldown_after_burst'] ?? $previewConfiguration['cooldown_after_burst'] ?? max(0, (int) ceil(($blockPauseSeconds ?? 30))))));
$rateLimitAction = in_array((string) ($_GET['429_action'] ?? $previewConfiguration['429_action'] ?? 'pause_and_ask'), ['pause_and_ask', 'auto_degrade'], true)
  ? (string) ($_GET['429_action'] ?? $previewConfiguration['429_action'] ?? 'pause_and_ask')
  : 'pause_and_ask';
$fiveXxAction = in_array((string) ($_GET['bounded_5xx_preferences'] ?? $previewConfiguration['bounded_5xx_preferences'] ?? 'pause_and_ask'), ['pause_and_ask', 'cooldown'], true)
  ? (string) ($_GET['bounded_5xx_preferences'] ?? $previewConfiguration['bounded_5xx_preferences'] ?? 'pause_and_ask')
  : 'pause_and_ask';
$exclusionGroups = [];
if ($preview !== null) {
  foreach ((array) ($preview['excluded_summary'] ?? []) as $excludedGroup) {
    $exclusionGroups[] = $exclusionPresenter->group(
      (array) $excludedGroup,
      (string) ($preview['preview_token'] ?? '')
    );
  }
}
?>
<div class="page-head manual-page-head">
  <div><p class="eyebrow">ADMINISTRACIÓN</p><h1>Procesamiento manual</h1>
    <p>Una entrada humana, un estado y una acción primaria: procesar el siguiente trabajo seguro sin iniciar Cron en segundo plano.</p>
    <p>Un paso por confirmación: la página muestra qué puede avanzar, qué espera y por qué.</p></div>
  <div class="page-actions"><a class="btn" href="<?= View::e($base . '/settings/cron') ?>">Ver automatización</a></div>
</div>

<nav class="manual-journey" aria-label="Flujo del operador">
  <span>Estado</span>
  <span>Qué procesar</span>
  <span>Configuración</span>
  <span>Acción</span>
  <span>Progreso</span>
</nav>

<?php if (!empty($emergencyStop)): ?>
<section class="panel manual-engine-card is-warning"><div class="panel-body manual-engine-summary">
  <div><strong>Procesamiento en mantenimiento</strong>
    <p>No se iniciaran consultas nuevas. Los resultados anteriores permanecen guardados.</p></div>
  <?php if ($activeSession): ?><a class="btn" href="<?= View::e($base . '/settings/manual-processing/session?id=' . (int) $activeSession['id']) ?>">Ver sesión guardada</a><?php endif; ?>
</div></section>
<?php elseif (!$campaignReady): ?>
<section class="panel manual-engine-card is-warning"><div class="panel-body">
  <strong>Actualización pendiente</strong>
  <p>Los archivos están instalados, pero falta preparar la base de datos para el procesamiento interactivo.</p>
  <a class="btn primary" href="<?= View::e($base . '/settings/update') ?>">Completar actualización</a>
</div></section>
<?php else: ?>
<section class="panel manual-engine-card is-ready"><div class="panel-body manual-engine-summary">
  <div><span class="status-dot"></span><strong>Procesamiento manual listo</strong>
    <p>Cada confirmacion ejecuta un solo trabajo exacto mientras esta pestana permanece abierta.</p></div>
  <?php if ($activeSession): ?><a class="btn primary" href="<?= View::e($base . '/settings/manual-processing/session?id=' . (int) $activeSession['id']) ?>">Ver sesión activa</a><?php endif; ?>
</div></section>
<?php endif; ?>

<?php $smartDrainSessionToken = is_array(($smartDrain['session'] ?? null)) ? (string) ($smartDrain['session']['session_token'] ?? '') : ''; ?>
<section class="panel manual-engine-card" id="k10-manual-drain" data-k10-drain data-status-url="<?= View::e($base . '/settings/manual-processing/drain/status.json') ?>" data-csrf="<?= View::e(Csrf::token()) ?>" data-session-token="<?= View::e($smartDrainSessionToken) ?>">
  <div class="panel-head">
    <div>
      <p class="eyebrow">Acción primaria</p>
      <h2>Procesamiento manual seguro: Procesar un trabajo</h2>
      <p>Use esta tarjeta para avanzar sólo un recurso elegible desde el navegador. Las opciones avanzadas quedan debajo y no compiten como segundo flujo principal.</p>
    </div>
  </div>
  <div class="panel-body">
    <?php if (empty($smartDrainReady)): ?>
      <div class="alert info"><strong>Instalación pendiente.</strong><p>Esta opción aparecerá después de aplicar la actualización requerida. No crea otra cola ni otro motor.</p></div>
    <?php else: ?>
      <?php $drainSession = is_array(($smartDrain['session'] ?? null)) ? $smartDrain['session'] : null; ?>
      <?php $drainProfile = is_array(($drainSession['profile'] ?? null)) ? $drainSession['profile'] : []; ?>
      <?php $drainStateUpper = strtoupper((string) ($smartDrain['state'] ?? ($drainSession['state'] ?? 'OFF'))); ?>
      <?php $hasDrainSession = $drainSession !== null && !in_array($drainStateUpper, ['OFF', 'EXPIRED', 'ABANDONED', 'RELEASING'], true); ?>
      <?php $pausedDrainSession = $hasDrainSession && $drainStateUpper === 'PAUSED'; ?>
      <p>Ejecuta un solo trabajo seguro por paso. Primero elija y calcule el grupo; después queda una sola acción primaria para avanzar.</p>
      <div class="metric-grid manual-metric-grid">
        <article><span>Listos ahora</span><strong><?= View::e($counterValue('ready')) ?></strong></article>
        <article><span>Esperando</span><strong><?= View::e($counterValue('waiting')) ?></strong></article>
        <article><span>Revisión</span><strong><?= View::e($counterValue('review')) ?></strong></article>
        <article><span>Requiere atención</span><strong><?= View::e($counterValue('attention')) ?></strong></article>
        <article><span>Procesados en esta sesión</span><strong data-k10-processed><?= (int) ($drainSession['jobs_processed'] ?? 0) ?></strong></article>
        <article><span>HTTP de esta sesión</span><strong data-k10-remote><?= (int) ($drainSession['remote_calls_observed'] ?? 0) ?></strong></article>
        <article><span>Próxima oportunidad segura</span><strong data-k10-next-safe><?= View::e((string) (($drainSession['last_result']['effective_next_safe_at'] ?? '') ?: '—')) ?></strong></article>
        <article><span>Última señal de vida</span><strong data-k10-heartbeat><?= View::e((string) ($drainSession['heartbeat_at'] ?? '—')) ?></strong></article>
      </div>
      <div class="alert warning" data-k10-429-panel>
        <strong>429 y errores peligrosos</strong>
        <p data-k10-last-result><?= View::e((string) (($drainSession['last_result']['classification'] ?? '') ?: 'Sin resultado todavía.')) ?></p>
        <p>Ante un HTTP 429 real se pausa y se respeta la espera segura. La opción conservadora nunca acorta Retry-After ni reintenta a ciegas.</p>
      </div>
      <p class="manual-browser-note" data-k10-feedback aria-live="polite">Listo. Esta pestaña ejecuta pasos seriales; si la cierra, la sesión expira y el procesamiento automático puede retomar.</p>
      <?php if (!$hasPreparedPreview && !$hasDrainSession): ?>
        <div class="manual-safety-note"><strong>Paso actual: elegir qué procesar.</strong><p>Use el cálculo de abajo para confirmar que hay trabajo seguro. Después aparecerá la acción primaria de ejecución.</p></div>
      <?php endif; ?>
      <form method="post" action="<?= View::e($base . '/settings/manual-processing/drain/start') ?>" class="manual-confirm-form" data-k10-action="start" <?= (!$hasDrainSession && $hasPreparedPreview) ? '' : 'hidden' ?>>
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
        <input type="hidden" name="preview_token" value="<?= View::e((string) ($preview['preview_token'] ?? '')) ?>" data-k10-profile-field="preview_token">
        <input type="hidden" name="selected_scope" value="<?= View::e($scope) ?>" data-k10-profile-field="selected_scope">
        <input type="hidden" name="selected_account_id" value="<?= (int) $accountId ?>" data-k10-profile-field="selected_account_id">
        <input type="hidden" name="execution_mode" value="<?= View::e($executionMode) ?>" data-k10-profile-field="execution_mode">
        <input type="hidden" name="max_items" value="<?= (int) $maxItems ?>" data-k10-profile-field="max_items">
        <input type="hidden" name="max_duration_seconds" value="<?= (int) $maxDurationSeconds ?>" data-k10-profile-field="max_duration_seconds">
        <input type="hidden" name="remote_calls_per_burst" value="<?= (int) ($drainProfile['remote_calls_per_burst'] ?? $remoteCallsPerBurst) ?>" data-k10-profile-field="remote_calls_per_burst">
        <input type="hidden" name="minimum_delay_between_remote_calls" value="<?= (int) ($drainProfile['minimum_delay_between_remote_calls'] ?? $minimumDelayBetweenRemoteCalls) ?>" data-k10-profile-field="minimum_delay_between_remote_calls">
        <input type="hidden" name="cooldown_after_burst" value="<?= (int) ($drainProfile['cooldown_after_burst'] ?? $cooldownAfterBurst) ?>" data-k10-profile-field="cooldown_after_burst">
        <input type="hidden" name="429_action" value="<?= View::e((string) ($drainProfile['429_action'] ?? $rateLimitAction)) ?>" data-k10-profile-field="429_action">
        <input type="hidden" name="bounded_5xx_preferences" value="<?= View::e((string) ($drainProfile['bounded_5xx_preferences'] ?? $fiveXxAction)) ?>" data-k10-profile-field="bounded_5xx_preferences">
        <button class="btn primary" type="submit" data-k10-start>Preparar siguiente paso</button>
      </form>
      <form method="post" action="<?= View::e($base . '/settings/manual-processing/drain/step') ?>" class="manual-confirm-form" data-k10-action="step" <?= $hasDrainSession && !$pausedDrainSession ? '' : 'hidden' ?>>
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
        <input type="hidden" name="session_token" value="<?= View::e($smartDrainSessionToken) ?>" data-k10-token-field>
        <input type="hidden" name="drain_generation" value="<?= (int) ($drainSession['drain_generation'] ?? 0) ?>" data-k10-generation-field>
        <button class="btn primary" type="submit" data-k10-step>Procesar siguiente trabajo</button>
      </form>
      <details class="manual-secondary-actions" <?= $hasDrainSession ? '' : 'hidden' ?>>
        <summary>Opciones de sesión</summary>
        <div class="manual-secondary-actions-body">
      <form method="post" action="<?= View::e($base . '/settings/manual-processing/drain/profile') ?>" class="manual-confirm-form manual-profile-form" data-k10-action="profile" <?= $hasDrainSession ? '' : 'hidden' ?>>
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
        <input type="hidden" name="session_token" value="<?= View::e($smartDrainSessionToken) ?>" data-k10-token-field>
        <input type="hidden" name="drain_generation" value="<?= (int) ($drainSession['drain_generation'] ?? 0) ?>" data-k10-generation-field>
        <label>Máximo de consultas por tanda <input type="number" min="1" max="60" name="remote_calls_per_burst" value="<?= (int) ($drainProfile['remote_calls_per_burst'] ?? 1) ?>" data-k10-profile-field="remote_calls_per_burst"></label>
        <label>Espera mínima entre consultas (s) <input type="number" min="15" max="3600" name="minimum_delay_between_remote_calls" value="<?= (int) ($drainProfile['minimum_delay_between_remote_calls'] ?? 15) ?>" data-k10-profile-field="minimum_delay_between_remote_calls"></label>
        <label>Descanso después de cada tanda (s) <input type="number" min="0" max="3600" name="cooldown_after_burst" value="<?= (int) ($drainProfile['cooldown_after_burst'] ?? 30) ?>" data-k10-profile-field="cooldown_after_burst"></label>
        <label>HTTP 429
          <select name="429_action" data-k10-profile-field="429_action">
            <option value="pause_and_ask" <?= (($drainProfile['429_action'] ?? 'pause_and_ask') === 'pause_and_ask') ? 'selected' : '' ?>>Pausar y revisar</option>
            <option value="auto_degrade" <?= (($drainProfile['429_action'] ?? '') === 'auto_degrade') ? 'selected' : '' ?>>Bajar ritmo manual automáticamente</option>
          </select>
        </label>
        <label>HTTP 5xx
          <select name="bounded_5xx_preferences" data-k10-profile-field="bounded_5xx_preferences">
            <option value="pause_and_ask" <?= (($drainProfile['bounded_5xx_preferences'] ?? 'pause_and_ask') === 'pause_and_ask') ? 'selected' : '' ?>>Pausar y revisar</option>
            <option value="cooldown" <?= (($drainProfile['bounded_5xx_preferences'] ?? '') === 'cooldown') ? 'selected' : '' ?>>Enfriar sin reintento ciego</option>
          </select>
        </label>
        <button class="btn" type="submit">Guardar perfil para el siguiente paso</button>
      </form>
      <form method="post" action="<?= View::e($base . '/settings/manual-processing/drain/heartbeat') ?>" data-k10-action="heartbeat" hidden>
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
        <input type="hidden" name="session_token" value="<?= View::e($smartDrainSessionToken) ?>" data-k10-token-field>
        <input type="hidden" name="drain_generation" value="<?= (int) ($drainSession['drain_generation'] ?? 0) ?>" data-k10-generation-field>
      </form>
      <form method="post" action="<?= View::e($base . '/settings/manual-processing/drain/pause') ?>" class="manual-confirm-form" data-k10-action="pause" <?= $hasDrainSession && !$pausedDrainSession ? '' : 'hidden' ?>>
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
        <input type="hidden" name="session_token" value="<?= View::e($smartDrainSessionToken) ?>" data-k10-token-field>
        <input type="hidden" name="drain_generation" value="<?= (int) ($drainSession['drain_generation'] ?? 0) ?>" data-k10-generation-field>
        <button class="btn" type="submit">Pausar</button>
      </form>
      <form method="post" action="<?= View::e($base . '/settings/manual-processing/drain/resume') ?>" class="manual-confirm-form" data-k10-action="resume" <?= $pausedDrainSession ? '' : 'hidden' ?>>
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
        <input type="hidden" name="session_token" value="<?= View::e($smartDrainSessionToken) ?>" data-k10-token-field>
        <input type="hidden" name="drain_generation" value="<?= (int) ($drainSession['drain_generation'] ?? 0) ?>" data-k10-generation-field>
        <button class="btn primary" type="submit">Reanudar</button>
      </form>
      <form method="post" action="<?= View::e($base . '/settings/manual-processing/drain/stop') ?>" class="manual-confirm-form" data-k10-action="stop" <?= $hasDrainSession ? '' : 'hidden' ?>>
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
        <input type="hidden" name="session_token" value="<?= View::e($smartDrainSessionToken) ?>" data-k10-token-field>
        <input type="hidden" name="drain_generation" value="<?= (int) ($drainSession['drain_generation'] ?? 0) ?>" data-k10-generation-field>
        <button class="btn danger" type="submit">Finalizar / devolver al automático</button>
      </form>
        </div>
      </details>
      <details class="cron-technical">
        <summary>Detalle de seguridad</summary>
        <div class="details-content">
          <p>Si la automatización está procesando en ese instante, esta acción espera y no reclama trabajos ni consulta Mercado Libre.</p>
        </div>
      </details>
    <?php endif; ?>
  </div>
</section>

<?php if (!empty($smartDrainReady) && empty($emergencyStop)): ?>
<script>
(() => {
  const panel = document.querySelector('[data-k10-drain]');
  if (!panel) return;
  const statusUrl = panel.dataset.statusUrl || '';
  let token = panel.dataset.sessionToken || '';
  const csrf = panel.dataset.csrf || '';
  const stateEl = panel.querySelector('[data-k10-state]');
  const processedEl = panel.querySelector('[data-k10-processed]');
  const remoteEl = panel.querySelector('[data-k10-remote]');
  const heartbeatEl = panel.querySelector('[data-k10-heartbeat]');
  const feedbackEl = panel.querySelector('[data-k10-feedback]');
  const burstCurrentEl = panel.querySelector('[data-k10-burst-current]');
  const burstMaxEl = panel.querySelector('[data-k10-burst-max]');
  const nextSafeEl = panel.querySelector('[data-k10-next-safe]');
  const profileRevisionEl = panel.querySelector('[data-k10-profile-revision]');
  const lastResultEl = panel.querySelector('[data-k10-last-result]');
  const tokenFields = () => panel.querySelectorAll('[data-k10-token-field]');
  const generationFields = () => panel.querySelectorAll('[data-k10-generation-field]');
  const actionForms = () => panel.querySelectorAll('[data-k10-action]');
  let inFlight = false;
  let loopEnabled = false;
  let loopTimer = 0;
  const setBusy = (busy) => {
    panel.setAttribute('aria-busy', busy ? 'true' : 'false');
    panel.querySelectorAll('button').forEach((button) => { button.disabled = busy; });
  };
  const show = (message) => {
    if (feedbackEl) feedbackEl.textContent = message;
  };
  const isAutoMode = (payload) => {
    const session = payload && payload.session ? payload.session : null;
    const profile = session && session.profile ? session.profile : {};
    const mode = String(profile.execution_mode || '').toUpperCase();
    return mode === 'CONTINUE_AUTOMATICALLY';
  };
  const update = (payload) => {
    const session = payload && payload.session ? payload.session : null;
    const state = payload && payload.state ? payload.state : (session && session.state ? session.state : 'OFF');
    if (stateEl) stateEl.textContent = state;
    if (processedEl) processedEl.textContent = String(session && session.jobs_processed ? session.jobs_processed : 0);
    if (remoteEl) remoteEl.textContent = String(session && session.remote_calls_observed ? session.remote_calls_observed : 0);
    if (heartbeatEl) heartbeatEl.textContent = session && session.heartbeat_at ? session.heartbeat_at : 'N/A';
    const last = session && session.last_result ? session.last_result : {};
    const profile = session && session.profile ? session.profile : {};
    if (burstCurrentEl) burstCurrentEl.textContent = String(session && session.burst_remote_calls_current ? session.burst_remote_calls_current : 0);
    if (burstMaxEl) burstMaxEl.textContent = String(profile.remote_calls_per_burst || 1);
    if (nextSafeEl) nextSafeEl.textContent = last.effective_next_safe_at || payload.next_safe_at || 'N/A';
    if (profileRevisionEl) profileRevisionEl.textContent = String(session && session.profile_revision ? session.profile_revision : 1);
    if (lastResultEl) lastResultEl.textContent = last.classification || payload.message || 'Sin resultado todavía.';
    if (session && session.session_token) {
      token = session.session_token;
      panel.dataset.sessionToken = token;
      tokenFields().forEach((field) => { field.value = token; });
    }
    if (session && session.drain_generation !== undefined) {
      generationFields().forEach((field) => { field.value = String(session.drain_generation); });
    }
    const hasSession = !!token && !['OFF', 'EXPIRED', 'ABANDONED', 'RELEASING'].includes(state);
    actionForms().forEach((form) => {
      const action = form.dataset.k10Action || '';
      if (action === 'start') form.hidden = hasSession;
      if (['step', 'stop', 'profile'].includes(action)) form.hidden = !hasSession;
      if (action === 'pause') form.hidden = !hasSession || state === 'PAUSED';
      if (action === 'resume') form.hidden = !hasSession || state !== 'PAUSED';
    });
    const secondary = panel.querySelector('.manual-secondary-actions');
    if (secondary) secondary.hidden = !hasSession;
    if (['PAUSED', 'RATE_LIMITED', 'OFF', 'EXPIRED', 'ABANDONED', 'RELEASING'].includes(state)) {
      loopEnabled = false;
      window.clearTimeout(loopTimer);
    }
  };
  const nextDelay = (payload) => {
    const raw = payload && (payload.next_safe_at || (payload.session && payload.session.last_result && payload.session.last_result.effective_next_safe_at));
    if (!raw) return 500;
    const at = Date.parse(String(raw).replace(' ', 'T') + 'Z');
    return Number.isFinite(at) ? Math.max(500, at - Date.now()) : 500;
  };
  const runOneStepSoon = () => {
    const form = panel.querySelector('form[data-k10-action="step"]');
    if (form) {
      loopTimer = window.setTimeout(() => post(form, true), 500);
    }
  };
  const scheduleStep = (payload) => {
    window.clearTimeout(loopTimer);
    if (!loopEnabled || !token) return;
    const state = payload && payload.state ? payload.state : '';
    const claimed = Number(payload && payload.claimed ? payload.claimed : 0);
    const hasSafeTime = !!(payload && (payload.next_safe_at || (payload.session && payload.session.last_result && payload.session.last_result.effective_next_safe_at)));
    if (state === 'ACTIVE' && claimed < 1 && !hasSafeTime) {
      loopEnabled = false;
      show('Sin trabajo elegible para este paso. La sesión queda segura; Cron puede retomar cuando corresponda.');
      return;
    }
    if (state === 'ACTIVE' || state === 'COOLING') {
      loopTimer = window.setTimeout(() => {
        const form = panel.querySelector('form[data-k10-action="step"]');
        if (form) post(form, true);
      }, nextDelay(payload));
    }
  };
  const post = async (form, fromLoop = false) => {
    if (inFlight) return;
    inFlight = true;
    setBusy(true);
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Accept': 'application/json'},
        body: new FormData(form),
      });
      const payload = await response.json();
      update(payload);
      show(payload.message || (response.ok ? 'Acción aplicada.' : 'No se pudo tomar la autoridad global.'));
      const action = form.dataset.k10Action || '';
      if (action === 'start' || action === 'resume') {
        loopEnabled = isAutoMode(payload);
        if (loopEnabled) {
          runOneStepSoon();
        } else {
          show('Sesión preparada. Pulse “Procesar siguiente trabajo” para ejecutar un solo paso.');
        }
      } else {
        if (action === 'pause' || action === 'stop') loopEnabled = false;
        if (fromLoop || loopEnabled) scheduleStep(payload);
      }
    } catch (error) {
      show('No se pudo contactar el procesamiento manual. Recargue la página antes de intentar de nuevo.');
    } finally {
      inFlight = false;
      setBusy(false);
    }
  };
  panel.querySelectorAll('form[data-k10-action]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      post(form);
    });
  });
  const refresh = async () => {
    if (!statusUrl || !token) return;
    try {
      const url = new URL(statusUrl, window.location.href);
      url.searchParams.set('session_token', token);
      const generationField = panel.querySelector('[data-k10-generation-field]');
      if (generationField) url.searchParams.set('drain_generation', generationField.value || '');
      const response = await fetch(url.toString(), {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
      if (response.ok) update(await response.json());
    } catch (error) {}
  };
  const heartbeat = async () => {
    if (!token || inFlight) return;
    const body = new FormData();
    body.set('_token', csrf);
    body.set('session_token', token);
    const generationField = panel.querySelector('[data-k10-generation-field]');
    if (generationField) body.set('drain_generation', generationField.value || '');
    try {
      const response = await fetch('<?= View::e($base . '/settings/manual-processing/drain/heartbeat') ?>', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Accept': 'application/json'},
        body,
      });
      if (response.ok) update(await response.json());
    } catch (error) {}
  };
  window.setInterval(refresh, 15000);
  window.setInterval(heartbeat, 15000);
})();
</script>
<?php endif; ?>

<?php if ($campaignReady && empty($emergencyStop) && !$activeSession): ?>
<section class="panel manual-panel manual-selection-panel" data-manual-selection>
  <div class="panel-head"><div><p class="eyebrow"><?= $preview === null ? 'Qué procesar' : 'Configuración' ?></p><h2><?= $preview === null ? 'Elegir trabajos disponibles' : 'Revisar límites antes de iniciar' ?></h2><p>El cálculo muestra recursos disponibles y motivos de espera. La acción principal sigue procesando un solo trabajo por confirmación.</p></div></div>
  <div class="panel-body">
  <form method="post" action="<?= View::e($base . '/settings/manual-processing/preview') ?>" class="manual-campaign-form" data-manual-preview-form>
    <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
    <input type="hidden" name="origin" value="<?= View::e((string) $origin) ?>">
    <?php foreach ((array) $originContext as $contextKey => $contextValue): ?>
      <?php if ((string) $contextValue !== '' && (int) $contextValue !== 0): ?>
        <input type="hidden" name="<?= View::e((string) $contextKey) ?>" value="<?= View::e((string) $contextValue) ?>">
      <?php endif; ?>
    <?php endforeach; ?>
    <fieldset><legend>Elija el grupo de trabajo</legend><div class="manual-choice-grid">
      <?php foreach ($scopeLabels as $key => [$label, $description]): ?>
      <label class="manual-choice-card"><input type="radio" name="scope" value="<?= View::e($key) ?>" <?= $scope === $key ? 'checked' : '' ?>>
        <span><strong><?= View::e($label) ?></strong><small><?= View::e($description) ?></small></span></label>
      <?php endforeach; ?>
    </div></fieldset>
    <fieldset class="manual-rhythm-grid"><legend>Configuración</legend>
      <label><span>Modo</span><select name="execution_mode" data-manual-profile-control="execution_mode">
        <option value="ONE_STEP" <?= $executionMode === 'ONE_STEP' ? 'selected' : '' ?>>Un paso por confirmación</option>
        <option value="CONTINUE_AUTOMATICALLY" <?= $executionMode === 'CONTINUE_AUTOMATICALLY' ? 'selected' : '' ?>>Continuar automáticamente en esta pestaña</option>
      </select><small>El modo automático sólo corre si usted lo elige aquí.</small></label>
      <label><span>Trabajos por revisión</span><input type="number" name="block_size" min="1" max="60" value="<?= (int) $blockSize ?>"><small>No equivale a consultas remotas.</small></label>
      <label><span>Máximo a procesar</span><input type="number" name="max_items" min="1" max="10000" value="<?= (int) $maxItems ?>"><small>El procesador se pausa al alcanzar este límite.</small></label>
      <label><span>Espera después de cada consulta</span><span class="input-with-unit"><input type="number" name="interval_seconds" min="0" max="300" step="0.1" value="<?= View::e((string) $intervalSeconds) ?>"><em>segundos</em></span></label>
      <label><span>Máximo por tanda HTTP</span><input type="number" name="remote_calls_per_burst" min="1" max="60" value="<?= (int) $remoteCallsPerBurst ?>"><small>Cuenta sólo salidas remotas físicas.</small></label>
      <label><span>Descanso entre tandas</span><span class="input-with-unit"><input type="number" name="block_pause_seconds" min="0" max="3600" step="1" value="<?= View::e((string) $blockPauseSeconds) ?>"><em>segundos</em></span></label>
      <label><span>Límite de avance</span><input type="number" name="max_blocks" min="0" max="10000" value="<?= (int) $maxBlocks ?>" aria-describedby="max-blocks-help"><small id="max-blocks-help">0 significa sólo límite por trabajos.</small></label>
      <label><span>Duración máxima</span><span class="input-with-unit"><input type="number" name="max_duration_minutes" min="0" max="10080" value="<?= (int) $maxDurationMinutes ?>"><em>minutos</em></span><small>0 significa sin límite.</small></label>
      <label><span>HTTP 429</span><select name="429_action">
        <option value="pause_and_ask" <?= $rateLimitAction === 'pause_and_ask' ? 'selected' : '' ?>>Pausar y revisar</option>
        <option value="auto_degrade" <?= $rateLimitAction === 'auto_degrade' ? 'selected' : '' ?>>Bajar ritmo manual automáticamente</option>
      </select></label>
      <label><span>HTTP 5xx</span><select name="bounded_5xx_preferences">
        <option value="pause_and_ask" <?= $fiveXxAction === 'pause_and_ask' ? 'selected' : '' ?>>Pausar y revisar</option>
        <option value="cooldown" <?= $fiveXxAction === 'cooldown' ? 'selected' : '' ?>>Enfriar sin reintento ciego</option>
      </select></label>
    </fieldset>
    <div class="alert info"><strong>Respeta el límite global de consultas.</strong><p>El cálculo manual revisa disponibilidad; cada confirmación procesa un único trabajo y respeta la protección activa. Permitido ahora: <?= View::e(number_format((float) ($rhythmPreview['effective_rpm'] ?? 0), 1, ',', '.')) ?> consulta(s)/min, limitado por <?= View::e((string) ($rhythmPreview['limiting_scope'] ?? 'protección disponible')) ?>. Puede ir más lento, nunca más rápido.</p></div>
    <div class="manual-quick-delays" aria-label="Intervalos rápidos">
      <?php foreach ([0,1,2,5,10,20,30,60,300] as $delay): ?><button type="button" class="chip" data-manual-delay="<?= $delay ?>"><?= $delay < 60 ? $delay . ' s' : ($delay / 60) . ' min' ?></button><?php endforeach; ?>
    </div>
    <button class="btn primary" type="submit" data-manual-preview-submit>
      <span data-ready-label>Calcular trabajos disponibles</span>
      <span data-busy-label hidden>Comprobando trabajos…</span>
    </button>
  </form>
  </div>
</section>

<?php if ($preview !== null): ?>
<section class="panel manual-review-card" id="resultado-calculo" tabindex="-1" aria-live="polite">
  <div class="panel-head"><div><p class="eyebrow">PASO 3</p><h2>Revise antes de comenzar</h2>
    <p>El cálculo no consulta Mercado Libre y utiliza un modo seguro con recursos exactos. Vigente hasta <?= View::e((string) ($preview['expires_at'] ?? '')) ?> UTC.</p></div></div>
  <div class="panel-body">
    <?php if (empty($preview['can_start_campaign'])): ?>
      <?php if ($scope === 'audits'): ?>
        <div class="empty-state"><strong>Primero debe crear una comprobación.</strong><p>Seleccione una cuenta y un periodo en Control de ventas. Allí podrá comprobar, revisar diferencias y preparar una reparación segura.</p><a class="btn primary" href="<?= View::e($base) ?>/sales-control">Ir a Control de ventas</a></div>
      <?php else: ?>
        <div class="empty-state"><strong>No hay trabajos remotos exactos listos ahora.</strong><p>El cálculo terminó correctamente. Abra uno de los grupos para ver cada trabajo, entender por qué no entró y llegar a su solución.</p></div>
      <?php endif; ?>
      <?php if ($exclusionGroups !== []): ?>
      <div class="manual-preview-breakdown" aria-label="Resultado completo del cálculo">
        <?php foreach ($exclusionGroups as $group): ?>
          <a class="manual-preview-group is-<?= View::e((string) $group['tone']) ?>" href="<?= View::e($base . $group['url']) ?>">
            <strong><?= (int) $group['count'] ?></strong>
            <span><?= View::e((string) $group['label']) ?></span>
            <small><?= View::e((string) $group['explanation']) ?></small>
            <b><?= View::e((string) $group['action']) ?> →</b>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="manual-safety-note"><strong>Procesar rápido no significa saltar protecciones.</strong><p>Solo entran consultas exactas que el ERP puede reservar y reanudar sin duplicarlas. Los errores, horarios futuros y trabajos por etapas se abren desde su grupo para resolverlos correctamente.</p></div>
      <?php endif; ?>
    <?php else: ?>
      <p class="manual-conclusion">Hay <strong><?= (int) $preview['eligible_jobs'] ?> trabajos</strong> disponibles. Esta confirmacion procesara solamente el primero y ejecutara como maximo una salida HTTP.</p>
      <div class="metric-grid manual-metric-grid">
        <article><span>Trabajos</span><strong><?= (int) $preview['eligible_jobs'] ?></strong></article>
        <article><span>HTTP máximos estimados</span><strong><?= (int) $preview['estimated_calls'] ?></strong></article>
        <article><span>Puntos de avance estimados</span><strong><?= max(1, (int) ($preview['total_blocks'] ?? 1)) ?></strong></article>
        <article><span>Duración aproximada</span><strong><?= View::e($duration((int) $preview['estimated_duration_seconds'])) ?></strong></article>
      </div>
      <div class="alert info"><strong>El reloj controla llamadas reales.</strong><p>Una publicación, una página de órdenes o una descripción consumirá como máximo una salida remota antes de respetar la espera configurada.</p></div>
      <div class="table-scroll"><table class="data-table compact"><caption>Operaciones incluidas</caption><thead><tr><th>Operación</th><th>Trabajos</th><th>Consultas estimadas</th><th>Puntos de avance</th><th>Ritmo efectivo</th></tr></thead><tbody>
      <?php foreach ($preview['operations'] as $operation): ?><tr><td><?= View::e((string) $operation['label']) ?></td><td><?= (int) $operation['items'] ?></td><td><?= (int) $operation['calls'] ?></td><td><?= (int) ($operation['blocks'] ?? 1) ?></td><td><?= View::e(number_format(((int) $operation['effective_interval_ms']) / 1000, 1, ',', '.')) ?> s<?= !empty($operation['adjusted']) ? ' · protegido' : '' ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php if ($exclusionGroups !== []): ?>
      <div class="manual-preview-breakdown" aria-label="Trabajos que no entrarán en esta confirmación">
        <?php foreach ($exclusionGroups as $group): ?>
          <a class="manual-preview-group is-<?= View::e((string) $group['tone']) ?>" href="<?= View::e($base . $group['url']) ?>">
            <strong><?= (int) $group['count'] ?></strong>
            <span><?= View::e((string) $group['label']) ?></span>
            <small><?= View::e((string) $group['explanation']) ?></small>
            <b><?= View::e((string) $group['action']) ?> →</b>
          </a>
        <?php endforeach; ?>
      </div>
      <details class="technical-details"><summary>Vista rápida de las primeras exclusiones</summary>
        <ul><?php foreach (array_slice((array) $preview['excluded_jobs'], 0, 25) as $excluded): ?><li><strong><?= View::e((string) ($excluded['label'] ?? 'Trabajo')) ?>:</strong> <?= View::e((string) ($excluded['reason'] ?? 'No disponible.')) ?></li><?php endforeach; ?></ul>
      </details>
      <?php endif; ?>
      <?php if (!empty($preview['selection_limited'])): ?><div class="alert info"><strong>La selección tiene un límite.</strong><p>Los trabajos restantes seguirán disponibles para otra confirmación o para la automatización.</p></div><?php endif; ?>
      <?php if (!empty($preview['contains_descriptions'])): ?><div class="alert warning"><strong>Incluye descripciones.</strong><p>Aunque solicite un intervalo menor, se aplicarán al menos 20 segundos y una descripción por bloque.</p></div><?php endif; ?>
      <?php if (!empty($smartDrainReady)): ?>
        <div class="manual-safety-note"><strong>Selección preparada.</strong><p>Use la tarjeta “Acción primaria” para preparar la sesión y procesar el siguiente trabajo. No hay un segundo botón de ejecución compitiendo con el drenador.</p>
          <p><a class="btn primary" href="#k10-manual-drain">Ir a Acción primaria</a></p></div>
      <?php else: ?>
        <div class="manual-safety-note"><strong>Selección calculada, ejecución no disponible.</strong><p>La UI no muestra un segundo ejecutor. Complete la instalación del drenador seguro para procesar desde la acción primaria.</p></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
<?php endif; ?>
