<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\AutomationCallBudgetService;

$base = rtrim(Env::get('APP_URL', ''), '/');
$csrfToken = Csrf::token();
$callBudget = (new AutomationCallBudgetService())->resolve();
$maxCalls = (int) $callBudget['max_calls'];
?>
<div class="page-head cron-page-head">
  <div>
    <span class="eyebrow">AUTOMATIZACIÓN</span>
    <h1>Automatización y seguridad API</h1>
    <p>Estado actual, último ciclo, llamadas API físicas y protección 429 sin mezclar histórico con alarmas.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/settings/api-health">Salud API</a>
    <a class="btn" href="<?= View::e($base) ?>/settings/cron/review">Review</a>
    <a class="btn" href="<?= View::e($base) ?>/settings/manual-processing">Procesamiento manual</a>
  </div>
</div>

<?php $automationTab = 'summary'; require __DIR__ . '/_automation_nav.php'; ?>

<div class="cron-control" data-queue-v4-clean
     data-status-url="<?= View::e($base) ?>/settings/cron/queue-v4.json">
  <section class="cron-status-line is-neutral" aria-live="polite" data-qv4-status-line>
    <span class="cron-status-dot" aria-hidden="true"></span>
    <div>
      <strong><span data-qv4-state>Comprobando</span></strong>
      <span data-qv4-message>Validando automatización actual.</span>
    </div>
  </section>

  <section class="cron-primary-grid" aria-label="Resumen de automatización">
    <article><span>Estado</span><strong data-qv4-engine>—</strong><p>Activa / Atención / Detenida.</p></article>
    <article><span>Último ciclo</span><strong data-qv4-heartbeat>—</strong><p>Debe actualizarse cerca de cada minuto.</p></article>
    <article><span>Cron físico</span><strong data-qv4-physical>Verificando…</strong><p>Cada minuto.</p></article>
    <article><span>Máximo de llamadas API por ciclo</span><strong data-qv4-max-calls><?= $maxCalls ?></strong><p>Comando canónico: <code>jobs/queue_v4_clean.php --runtime=45</code>. El ERP lee la configuración guardada.</p></article>
  </section>

  <section class="cron-task-section cron-api-risks" data-cron-api-risks
           data-risks-url="<?= View::e($base) ?>/settings/cron/api-risks.json"
           data-app-base="<?= View::e($base) ?>"
           aria-labelledby="cron-api-risks-title" aria-busy="true">
    <div class="section-heading">
      <div>
        <h2 id="cron-api-risks-title">Salud ahora</h2>
        <p data-cron-api-risks-source>Cargando telemetría certificada de la última hora…</p>
      </div>
      <a class="btn" data-cron-api-risks-detail href="<?= View::e($base) ?>/settings/api-health">Salud API</a>
    </div>
    <section class="cron-primary-grid" aria-label="Salud actual">
      <article><span>429 remoto 60m</span><strong data-cron-api-risk="remote_http_429">—</strong><p data-cron-api-risk-context="remote_http_429">Mercado Libre respondió 429.</p></article>
      <article><span>5xx remoto 60m</span><strong data-cron-api-risk="http_5xx">—</strong><p data-cron-api-risk-context="http_5xx">Fallo real de Mercado Libre.</p></article>
      <article><span>Protecciones locales 60m</span><strong data-cron-api-risk="local_rate_limited_pretransport">—</strong><p data-cron-api-risk-context="local_rate_limited_pretransport">No llegaron a Mercado Libre; no son 429.</p></article>
      <article><span>Cuentas disponibles</span><strong data-qv4-oauth>—</strong><p>OAuth / permisos actuales.</p></article>
    </section>
    <details class="technical-details">
      <summary><span>Histórico / diagnóstico API</span><span aria-hidden="true">⌄</span></summary>
      <div class="technical-details-body">
        <p class="cron-api-risks-meta" data-cron-api-risks-meta aria-live="polite"></p>
        <p class="cron-api-risks-links">
          <a data-cron-api-risks-remote href="<?= View::e($base) ?>/settings/api-health/incidents?hours=1&amp;http_status=429&amp;origin=remote">Ver 429 remotos</a>
          <a data-cron-api-risks-local href="<?= View::e($base) ?>/settings/api-health/incidents?hours=1&amp;origin=protection">Ver protecciones locales</a>
          <a data-cron-api-risks-technical href="<?= View::e($base) ?>/settings/api-health/technical">Ver diagnóstico técnico</a>
        </p>
        <div class="cron-api-risks-lists">
          <article>
            <h3>Histórico 30d normalizado</h3>
            <ol data-cron-api-risks-top><li>Cargando evidencia…</li></ol>
          </article>
          <article>
            <h3>Eventos recientes</h3>
            <ol data-cron-api-risks-recent><li>Cargando evidencia…</li></ol>
          </article>
        </div>
      </div>
    </details>
  </section>

  <section class="cron-task-section" aria-labelledby="queue-work-title">
    <div class="section-heading"><div>
      <h2 id="queue-work-title">Atención efectiva por llamadas API</h2>
      <p>Lectura reciente del ciclo natural; mide llamadas API físicas y no ejecuta cron manual.</p>
    </div></div>
    <section class="cron-primary-grid" aria-label="Atención efectiva">
      <article><span>Elementos completados</span><strong data-qv4-work="completed">—</strong></article>
      <article><span>Elementos aplazados</span><strong data-qv4-work="deferred">—</strong></article>
      <article><span>Efectividad</span><strong data-qv4-work="ratio">—</strong></article>
    </section>
  </section>

  <section class="cron-task-section" aria-labelledby="queue-now-title">
    <div class="section-heading"><div>
      <h2 id="queue-now-title">Pendientes ahora</h2>
      <p>Sólo “Listos” entra al turno FIFO; lo demás espera su autoridad o revisión.</p>
    </div></div>
    <section class="cron-primary-grid" aria-label="Contadores de pendientes automáticos">
      <article><span>Listos</span><strong data-qv4-count="ready">0</strong></article>
      <article><span>Esperando</span><strong data-qv4-count="waiting">0</strong></article>
      <article><span>Revisión</span><strong data-qv4-count="review">0</strong></article>
      <article><span>En atención</span><strong data-qv4-count="running">0</strong></article>
      <span hidden data-qv4-count="dead">0</span>
    </section>
  </section>

  <section class="cron-task-section" aria-labelledby="queue-v4-review-title">
    <div class="section-heading"><div>
      <h2 id="queue-v4-review-title">Revisión</h2>
      <p>Resumen accionable; el detalle vive en la superficie Review.</p>
    </div></div>
    <section class="cron-primary-grid" aria-label="Resumen Review">
      <article><span>Total</span><strong data-qv4-review="total">246</strong></article>
      <article><span>Requieren cuenta</span><strong data-qv4-review="account">159</strong></article>
      <article><span>Decisión manual</span><strong>3</strong></article>
      <article><span>Abrir</span><strong><a href="<?= View::e($base) ?>/settings/cron/review">Review</a></strong></article>
    </section>
  </section>

  <details class="technical-details cron-advanced">
    <summary><span>Diagnóstico avanzado</span><span aria-hidden="true">⌄</span></summary>
    <div class="technical-details-body">
      <section class="cron-truth-grid" aria-label="Detalles internos">
        <article><span>Readiness GET</span><strong data-qv4-readiness>0/3</strong><p>GET /users/me por cuenta.</p></article>
        <article><span>Programador</span><strong data-qv4-scheduler>Inactivo</strong><p>Estado configurado, no acción manual.</p></article>
        <article><span>Comando</span><strong>queue_v4_clean.php --runtime=45</strong><p>Entrada canónica. <code>--max-calls</code> queda sólo como reductor técnico; <code>--max-jobs</code> fue retirado.</p></article>
        <article><span>Legado consultado</span><strong data-qv4-legacy>No</strong><p>Debe permanecer fuera de autoridad.</p></article>
      </section>
      <section class="cron-truth-grid" aria-label="OAuth técnico" data-qv4-oauth-operations>
        <article><span>OAuth técnico</span><strong>Cargando…</strong><p>Sin mostrar tokens.</p></article>
      </section>
      <div class="table-scroll mt-2">
        <table class="data-table human-table">
          <caption>Clasificación técnica Review</caption>
          <thead><tr><th>Clase</th><th>Filas</th><th>Edad</th><th>Acción segura</th></tr></thead>
          <tbody data-qv4-review-actions>
            <tr><td colspan="4"><div class="empty">Cargando clasificación…</div></td></tr>
          </tbody>
        </table>
      </div>
      <span hidden data-qv4-review="recoverable">0</span>
      <span hidden data-qv4-review="functional">0</span>
      <span hidden data-qv4-review="ambiguous">0</span>
      <span hidden data-qv4-review="oldest">—</span>
      <span hidden data-qv4-review="unknown_reason">0</span>
      <span hidden data-qv4-review="no_path">0</span>
      <span hidden data-qv4-review="dominant">—</span>
      <span hidden data-qv4-review="human">0</span>
      <span hidden data-cron-api-risk="remote_uncertain">0</span>
      <span hidden data-cron-api-risk-context="remote_uncertain"></span>
      <?php include __DIR__ . '/_queue_v4_diagnostic_bundle.php'; ?>
    </div>
  </details>

  <details class="technical-details cron-admin-actions">
    <summary><span>Acciones administrativas</span><span aria-hidden="true">⌄</span></summary>
    <div class="technical-details-body">
      <p>Prepare sin llamadas y compruebe cada cuenta por separado: máximo una llamada por clic. La confirmación administrativa dura diez minutos; activar o detener conserva su confirmación independiente.</p>
      <label>Contraseña administrativa
        <input type="password" autocomplete="current-password" data-qv4-password>
      </label>
      <div class="page-actions mt-2">
        <form method="post" action="<?= View::e($base) ?>/settings/cron/queue-v4/readiness" data-qv4-action="readiness">
          <input type="hidden" name="_token" value="<?= View::e($csrfToken) ?>">
          <input type="hidden" name="admin_password" value="">
          <input type="hidden" name="action" value="prepare">
          <button class="btn primary" type="submit" disabled>Preparar comprobación · sin llamadas</button>
        </form>
        <form method="post" action="<?= View::e($base) ?>/settings/cron/queue-v4/readiness" data-qv4-action="cancel">
          <input type="hidden" name="_token" value="<?= View::e($csrfToken) ?>">
          <input type="hidden" name="action" value="cancel">
          <button class="btn" type="submit" disabled>Cancelar comprobación</button>
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
      <p class="muted mt-2" data-qv4-progress aria-live="polite">Sin comprobación preparada.</p>
      <p class="muted" data-qv4-selected-accounts></p>
      <p class="muted mt-2" data-qv4-feedback aria-live="polite">Cargando estado sin mutaciones…</p>
    </div>
  </details>
</div>
