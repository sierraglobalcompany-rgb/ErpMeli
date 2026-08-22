<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$csrfToken = Csrf::token();
?>
<div class="page-head cron-page-head">
  <div>
    <span class="eyebrow">AUTOMATIZACIÓN</span>
    <h1>Queue V4</h1>
    <p>Motor FIFO nuevo. El estado operativo V2, V3 y Queue Core se conserva como legado ignorado.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/settings/diagnostics">Diagnóstico</a>
    <a class="btn" href="<?= View::e($base) ?>/stop/">Freno de mano</a>
  </div>
</div>

<div class="cron-control" data-queue-v4-clean
     data-status-url="<?= View::e($base) ?>/settings/cron/queue-v4.json">
  <section class="cron-status-line is-neutral" aria-live="polite" data-qv4-status-line>
    <span class="cron-status-dot" aria-hidden="true"></span>
    <div>
      <strong data-qv4-state>No preparado</strong>
      <span data-qv4-message>Comprobando el módulo nuevo sin leer colas heredadas.</span>
    </div>
  </section>

  <section class="cron-truth-grid" aria-label="Estado Queue V4">
    <article><span>Estado</span><strong data-qv4-engine>Detenido</strong><p>Certificar no activa el motor.</p></article>
    <article><span>Cuentas OAuth</span><strong data-qv4-oauth>0/3</strong><p>Tres cuentas exactas y vigentes.</p></article>
    <article><span>Readiness GET</span><strong data-qv4-readiness>0/3</strong><p>Un GET /users/me directo por cuenta, fuera de la cola.</p></article>
    <article><span>Configuración scheduler ERP</span><strong data-qv4-scheduler>Inactivo</strong><p>Es configuración interna; no demuestra que exista Cron en hPanel.</p></article>
    <article><span>Heartbeat del Cron</span><strong data-qv4-heartbeat>Sin evidencia</strong><p data-qv4-physical>Observación física: UNKNOWN</p></article>
  </section>

  <section class="cron-task-section cron-api-risks" data-cron-api-risks
           data-risks-url="<?= View::e($base) ?>/settings/cron/api-risks.json"
           data-app-base="<?= View::e($base) ?>"
           aria-labelledby="cron-api-risks-title" aria-busy="true">
    <div class="section-heading">
      <div>
        <h2 id="cron-api-risks-title">Riesgos API · últimos 30 días</h2>
        <p data-cron-api-risks-source>Cargando telemetría directa sin modificar Cron ni Mercado Libre…</p>
      </div>
      <a class="btn" data-cron-api-risks-detail href="<?= View::e($base) ?>/settings/api-health/incidents">Abrir diagnóstico</a>
    </div>
    <section class="cron-truth-grid" aria-label="Señales API de riesgo">
      <article><span>429 remoto</span><strong data-cron-api-risk="remote_http_429">—</strong><p>Mercado Libre respondió HTTP 429.</p></article>
      <article><span>Pausa preventiva local</span><strong data-cron-api-risk="local_rate_limited_pretransport">—</strong><p>Sin HTTP remoto; no cuenta como 429.</p></article>
      <article><span>OAuth crítico</span><strong data-cron-api-risk="oauth_critical">—</strong><p>HTTP 401/403 remoto.</p></article>
      <article><span>Fallo remoto</span><strong data-cron-api-risk="http_5xx">—</strong><p>HTTP 5xx de Mercado Libre.</p></article>
      <article><span>Resultado incierto</span><strong data-cron-api-risk="remote_uncertain">—</strong><p>Requiere comprobación, no reintento ciego.</p></article>
    </section>
    <div class="cron-api-risks-meta" data-cron-api-risks-meta aria-live="polite"></div>
    <p class="cron-api-risks-links">
      <a data-cron-api-risks-remote href="<?= View::e($base) ?>/settings/api-health/incidents?hours=720&amp;http_status=429&amp;origin=remote">Ver 429 remotos</a>
      <a data-cron-api-risks-local href="<?= View::e($base) ?>/settings/api-health/incidents?hours=720&amp;origin=protection">Ver pausas preventivas</a>
      <a data-cron-api-risks-technical href="<?= View::e($base) ?>/settings/api-health/technical">Ver diagnóstico técnico</a>
    </p>
    <div class="cron-api-risks-lists">
      <article>
        <h3>10 focos principales</h3>
        <ol data-cron-api-risks-top><li>Cargando evidencia…</li></ol>
      </article>
      <article>
        <h3>10 eventos recientes</h3>
        <ol data-cron-api-risks-recent><li>Cargando evidencia…</li></ol>
      </article>
    </div>
  </section>

  <section class="cron-task-section" aria-labelledby="queue-v4-review-title">
    <div class="section-heading"><div>
      <h2 id="queue-v4-review-title">Review por causa operativa</h2>
      <p>Los aplazamientos de capacidad se separan de fallos funcionales y evidencia ambigua.</p>
    </div></div>
    <section class="cron-truth-grid" aria-label="Clasificación Review">
      <article><span>Capacidad recuperable</span><strong data-qv4-review="recoverable">0</strong></article>
      <article><span>Fallo funcional</span><strong data-qv4-review="functional">0</strong></article>
      <article><span>Ambiguo</span><strong data-qv4-review="ambiguous">0</strong></article>
      <article><span>Más antiguo</span><strong data-qv4-review="oldest">—</strong></article>
    </section>
  </section>

  <section class="cron-task-section" aria-labelledby="queue-v4-oauth-title">
    <div class="section-heading"><div>
      <h2 id="queue-v4-oauth-title">Renovación OAuth automática</h2>
      <p>Control técnico fuera del FIFO comercial. Nunca muestra ni conserva tokens en esta superficie.</p>
    </div></div>
    <div class="cron-truth-grid" data-qv4-oauth-operations aria-live="polite">
      <article><span>Autoridad</span><strong>Cargando…</strong><p>Sin realizar mutaciones.</p></article>
    </div>
  </section>

  <section class="cron-task-section" aria-labelledby="queue-v4-counts-title">
    <div class="section-heading">
      <div>
        <h2 id="queue-v4-counts-title">Cola FIFO nueva</h2>
        <p>Sólo Ready entra al FIFO. Waiting, Review y Dead están fuera de la fila ejecutable.</p>
      </div>
      <span class="status-badge is-unavailable" data-qv4-legacy>Legado no consultado</span>
    </div>
    <section class="cron-truth-grid" aria-label="Contadores Queue V4">
      <?php foreach (['ready' => 'Ready', 'running' => 'Running', 'waiting' => 'Waiting', 'review' => 'Review', 'dead' => 'Dead'] as $key => $label): ?>
        <article><span><?= View::e($label) ?></span><strong data-qv4-count="<?= View::e($key) ?>">0</strong></article>
      <?php endforeach; ?>
    </section>
  </section>

  <section class="cron-task-section" aria-labelledby="queue-v4-actions-title">
    <div class="section-heading">
      <div>
        <h2 id="queue-v4-actions-title">Control administrativo</h2>
        <p>Máximo tres acciones. Todas exigen sesión administrativa, CSRF y contraseña.</p>
      </div>
    </div>
    <label>Contraseña administrativa
      <input type="password" autocomplete="current-password" data-qv4-password>
    </label>
    <div class="page-actions mt-2">
      <form method="post" action="<?= View::e($base) ?>/settings/cron/queue-v4/readiness" data-qv4-action="readiness">
        <input type="hidden" name="_token" value="<?= View::e($csrfToken) ?>">
        <input type="hidden" name="admin_password" value="">
        <button class="btn primary" type="submit" disabled>Comprobar y certificar</button>
      </form>
      <form method="post" action="<?= View::e($base) ?>/settings/cron/queue-v4/activate" data-qv4-action="activate">
        <input type="hidden" name="_token" value="<?= View::e($csrfToken) ?>">
        <input type="hidden" name="admin_password" value="">
        <button class="btn" type="submit" disabled>Activar</button>
      </form>
      <form method="post" action="<?= View::e($base) ?>/settings/cron/queue-v4/stop" data-qv4-action="stop">
        <input type="hidden" name="_token" value="<?= View::e($csrfToken) ?>">
        <input type="hidden" name="admin_password" value="">
        <button class="btn danger" type="submit" disabled>Detener</button>
      </form>
    </div>
    <p class="muted mt-2" data-qv4-feedback aria-live="polite">Cargando estado sin mutaciones…</p>
  </section>

  <section class="notice neutral">
    <strong>Legado fuera de autoridad</strong>
    <p>V2, V3 y Queue Core no bloquean ni alimentan Queue V4.</p>
  </section>
</div>
