<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$automationTab = 'queue';
require __DIR__ . '/_automation_nav.php';
$attention = (array) $work['attention'];
$estimate = (array) $work['estimate'];
$policy = (array) ($resolution['policy'] ?? []);
$actions = (array) ($resolution['actions'] ?? []);
$progressTotal = max(0, (int) ($work['progress_total'] ?? 0));
$progressCurrent = max(0, (int) ($work['progress_current'] ?? 0));
$automatic = !empty($policy['automatic']);
?>
<div class="page-head work-resolution-head">
  <div>
    <span class="eyebrow">Automatización · Trabajo exacto</span>
    <h1><?= View::e((string) $work['human_label']) ?></h1>
    <p><?= View::e((string) $work['content_summary']) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/settings/cron/queue">Volver a la cola</a>
  </div>
</div>

<section class="work-resolution-hero tone-<?= View::e((string) ($policy['tone'] ?? 'gray')) ?>" aria-labelledby="work-status-title">
  <div class="work-resolution-symbol" aria-hidden="true"><?= View::e((string) $attention['icon']) ?></div>
  <div>
    <span class="eyebrow">Estado actual</span>
    <h2 id="work-status-title"><?= View::e((string) ($policy['label'] ?? $attention['label'])) ?></h2>
    <p><?= View::e((string) ($policy['whatHappened'] ?? $attention['short_reason'])) ?></p>
  </div>
  <span class="status-pill <?= $automatic ? 'tone-success' : 'tone-warning' ?>">
    <?= $automatic ? 'Continúa automáticamente' : 'Decisión disponible' ?>
  </span>
</section>

<div class="work-resolution-flow" aria-label="Explicación y resolución del trabajo">
  <section class="work-resolution-step">
    <span class="work-resolution-number" aria-hidden="true">1</span>
    <div>
      <h2>Qué pasó</h2>
      <span class="sr-only">Qué ocurrió</span>
      <p><?= View::e((string) ($policy['whatHappened'] ?? $attention['cause'])) ?></p>
      <dl class="work-resolution-facts">
        <div><dt>¿Llegó a Mercado Libre?</dt><dd><?= View::e((string) $attention['reached_remote_label']) ?></dd></div>
        <div><dt>Cuenta</dt><dd><?= View::e((string) ($work['account_name'] ?: 'General')) ?></dd></div>
        <div><dt>Próxima oportunidad</dt><dd><?= !empty($work['next_eligible_at']) ? View::e(DateTimePresenter::formatQueue((string) $work['next_eligible_at'])) : 'Ahora' ?></dd></div>
      </dl>
    </div>
  </section>

  <section class="work-resolution-step">
    <span class="work-resolution-number" aria-hidden="true">2</span>
    <div>
      <h2>Qué hará el ERP</h2>
      <span class="sr-only">Qué debe hacer</span>
      <p><?= View::e((string) ($policy['next'] ?? $attention['recommended_action'])) ?></p>
      <p class="muted"><?= View::e((string) ($policy['impact'] ?? $attention['impact'])) ?></p>
    </div>
  </section>

  <section class="work-resolution-step <?= $actions === [] ? 'is-quiet' : '' ?>">
    <span class="work-resolution-number" aria-hidden="true">3</span>
    <div>
      <h2>Qué puede hacer ahora</h2>
      <?php if (empty($resolution['ready'])): ?>
        <p><?= View::e((string) ($resolution['unavailable_message'] ?? 'Complete la actualización para habilitar las acciones.')) ?></p>
        <a class="btn primary" href="<?= View::e($base) ?>/actualizar.php">Abrir actualizador</a>
      <?php elseif ($actions === []): ?>
        <p><?= View::e((string) (($resolution['unavailable_message'] ?? null)
            ?: ($automatic
                ? 'No necesita intervenir. Puede volver a la cola; Cron continuará con este y con los demás recursos.'
                : 'Este estado no admite una modificación segura desde la web.'))) ?></p>
        <a class="btn" href="<?= View::e((string) $attention['context_url']) ?>"><?= View::e((string) $attention['context_label']) ?></a>
      <?php else: ?>
        <div class="work-resolution-actions">
          <?php foreach ($actions as $index => $action):
              $isPrimary = !empty($action['primary']);
              $isDestructive = in_array((string) $action['key'], ['skip_campaign', 'close_unavailable'], true);
              $actionNonce = bin2hex(random_bytes(16));
              $idempotencyKey = hash('sha256', implode('|', [
                  (string) $work['queue_key'], (string) $work['source_id'], (string) $action['key'],
                  (string) ($action['campaign_id'] ?? 0), (string) ($action['campaign_item_id'] ?? 0),
                  (string) $resolution['expected_status'], (string) $resolution['expected_generation'], $actionNonce,
              ]));
          ?>
          <form method="post" action="<?= View::e($base) ?>/settings/cron/work/remediate" class="work-resolution-action">
            <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
            <input type="hidden" name="queue_key" value="<?= View::e((string) $work['queue_key']) ?>">
            <input type="hidden" name="source_id" value="<?= View::e((string) $work['source_id']) ?>">
            <input type="hidden" name="action" value="<?= View::e((string) $action['key']) ?>">
            <input type="hidden" name="expected_status" value="<?= View::e((string) $resolution['expected_status']) ?>">
            <input type="hidden" name="expected_generation" value="<?= (int) $resolution['expected_generation'] ?>">
            <input type="hidden" name="campaign_id" value="<?= (int) ($action['campaign_id'] ?? 0) ?>">
            <input type="hidden" name="campaign_item_id" value="<?= (int) ($action['campaign_item_id'] ?? 0) ?>">
            <input type="hidden" name="action_nonce" value="<?= View::e($actionNonce) ?>">
            <input type="hidden" name="idempotency_key" value="<?= View::e($idempotencyKey) ?>">
            <button
              class="btn <?= $isPrimary && $index === 0 ? 'primary' : ($isDestructive ? 'danger-outline' : '') ?>"
              type="submit"
              <?= $isDestructive ? 'data-confirm-exact-action="¿Desea ' . View::e(mb_strtolower((string) $action['label'])) . '? La orden y los datos comerciales se conservarán."' : '' ?>
            ><?= View::e((string) $action['label']) ?></button>
            <small><?= View::e((string) $action['description']) ?></small>
          </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>

<section class="panel mt-2">
  <header class="panel-head"><div><h2>Contenido y progreso</h2><p>Estos valores describen únicamente este recurso.</p></div></header>
  <div class="work-facts">
    <div><span>Contenido</span><strong><?= View::e((string) $work['content_summary']) ?></strong></div>
    <div><span>Progreso aprobado</span><strong><?= $progressCurrent ?> de <?= $progressTotal ?></strong></div>
    <div><span>Tiempo estimado</span><strong><?= View::e((string) ($estimate['label'] ?? 'Todavía no se puede estimar')) ?></strong></div>
    <div><span>Salidas HTTP estimadas</span><strong><?= $work['estimated_api_calls'] !== null ? (int) $work['estimated_api_calls'] : 'Por estimar' ?></strong></div>
  </div>
  <?php if ($progressTotal > 0): ?>
    <progress class="work-detail-progress" max="<?= max(1, $progressTotal) ?>" value="<?= $progressCurrent ?>"><?= $progressCurrent ?> de <?= $progressTotal ?></progress>
  <?php endif; ?>
</section>

<details class="panel mt-2 technical-details">
  <summary>Ver detalles técnicos</summary>
  <div class="work-technical-grid">
    <div><span>Cola</span><code><?= View::e((string) $work['queue_key']) ?></code></div>
    <div><span>Estado interno</span><code><?= View::e((string) $work['source_status']) ?></code></div>
    <div><span>Trabajo</span><code><?= View::e((string) $work['source_id']) ?></code></div>
    <div><span>Diagnóstico</span><code><?= View::e((string) ($work['diagnostic_id'] ?: 'Registro anterior sin referencia')) ?></code></div>
    <?php if (!empty($work['normalized_error_code'])): ?>
      <div><span>Clasificación</span><code><?= View::e((string) $work['normalized_error_code']) ?></code></div>
    <?php endif; ?>
    <?php if (!empty($work['historical_reconciliation'])): ?>
      <div><span>Evidencia histórica</span><strong>Reconciliada localmente</strong></div>
    <?php endif; ?>
    <div><span>Riesgo de bloqueo</span><strong><?= View::e((string) $attention['blocking_risk_label']) ?></strong></div>
  </div>
  <?php if (!empty($work['safe_error_message'])): ?>
    <p><strong>Detalle seguro:</strong> <?= View::e((string) $work['safe_error_message']) ?></p>
  <?php endif; ?>
  <p class="muted">Nunca se muestran tokens, parámetros privados ni credenciales.</p>
</details>
