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
    <article><span>Scheduler</span><strong data-qv4-scheduler>Inactivo</strong><p>Esta pantalla no crea tareas Hostinger.</p></article>
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
