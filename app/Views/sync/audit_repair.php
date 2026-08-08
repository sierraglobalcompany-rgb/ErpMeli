<?php

use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$status = (string) $job['status'];
$tone = match ($status) {
    'complete' => 'green',
    'partial', 'waiting_budget', 'retry', 'paused' => 'amber',
    'error' => 'red',
    default => 'blue',
};
$label = match ($status) {
    'complete' => 'Reparación terminada',
    'partial' => 'Terminó con diferencias',
    'waiting_budget' => 'Esperando una hora segura',
    'retry' => 'Se reintentará automáticamente',
    'paused' => 'Reparación pausada',
    'error' => 'Necesita intervención',
    'running' => 'Incorporando órdenes',
    default => 'Esperando el próximo ciclo',
};
$counts = $job['item_counts'] ?? [];
$pending = (int) ($counts['pending'] ?? 0) + (int) ($counts['retry'] ?? 0) + (int) ($counts['waiting_budget'] ?? 0);
?>
<div class="page-head">
  <div>
    <a class="muted" href="<?= View::e($base) ?>/sync/audit?account_id=<?= (int) $job['meli_account_id'] ?>&year=<?= (int) $job['period_year'] ?>&month=<?= (int) $job['period_month'] ?>">← Volver al periodo</a>
    <div class="eyebrow">Paso <?= in_array($status, ['complete', 'partial'], true) ? '4' : '3' ?> de 4</div>
    <h1>Reparación de ventas #<?= (int) $job['id'] ?></h1>
    <p><?= View::e((string) $job['account_name']) ?> · <?= View::e((string) $job['company_name']) ?></p>
  </div>
</div>

<nav class="audit-stepper" aria-label="Progreso de la auditoría">
  <span class="done"><b>✓</b> Comprobar</span><span class="done"><b>✓</b> Revisar</span><span class="<?= in_array($status, ['complete', 'partial'], true) ? 'done' : 'active' ?>"><b><?= in_array($status, ['complete', 'partial'], true) ? '✓' : '3' ?></b> Reparar</span><span class="<?= in_array($status, ['complete', 'partial'], true) ? 'active' : '' ?>"><b>4</b> Verificar</span>
</nav>

<section class="status-hero <?= View::e($tone) ?>" aria-live="polite">
  <div class="status-hero-icon" aria-hidden="true"><?= $status === 'complete' ? '✓' : ($status === 'error' ? '!' : '↻') ?></div>
  <div>
    <div class="eyebrow">Estado</div><h2><?= View::e($label) ?></h2>
    <p>
      <?php if ($status === 'complete'): ?>Todas las órdenes disponibles fueron incorporadas. Se programó una comprobación final.
      <?php elseif ($status === 'partial'): ?>Algunas órdenes no estaban disponibles o requieren revisión. La comprobación final aclarará el resultado.
      <?php elseif ($status === 'waiting_budget'): ?>No se perdió progreso. Continuará cuando exista presupuesto seguro.
      <?php elseif ($status === 'paused'): ?>No se realizarán nuevas consultas hasta que la reanude.
      <?php else: ?>La automatización trabaja por lotes y conserva cada avance.<?php endif; ?>
    </p>
  </div>
</section>

<section class="panel mt-2">
  <header class="panel-head"><h2>Progreso</h2><strong><?= (int) $job['progress_percent'] ?> %</strong></header>
  <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $job['progress_percent'] ?>"><span style="width:<?= (int) $job['progress_percent'] ?>%"></span></div>
  <div class="metrics compact mt-2">
    <article class="metric-card compact"><div><div class="metric-label">Incorporadas o existentes</div><div class="metric-value"><?= (int) $job['success_items'] ?></div></div></article>
    <article class="metric-card compact"><div><div class="metric-label">Pendientes</div><div class="metric-value"><?= $pending ?></div></div></article>
    <article class="metric-card compact"><div><div class="metric-label">No disponibles</div><div class="metric-value"><?= (int) $job['unavailable_items'] ?></div></div></article>
    <article class="metric-card compact"><div><div class="metric-label">Con error</div><div class="metric-value"><?= (int) $job['error_items'] ?></div></div></article>
  </div>
  <dl class="definition-list mt-2">
    <div><dt>Próxima oportunidad</dt><dd><?= View::e(DateTimePresenter::formatQueue($job['next_run_at'] ?? null)) ?></dd></div>
    <div><dt>Comprobación final</dt><dd><?= !empty($job['verification_audit_job_id']) ? 'Programada como trabajo #' . (int) $job['verification_audit_job_id'] : 'Se programará al terminar' ?></dd></div>
    <div><dt>Consultas remotas</dt><dd>Solo lectura; no modifica Mercado Libre.</dd></div>
  </dl>
  <?php if (!empty($job['safe_error_message'])): ?><div class="alert warning mt-2"><?= View::e((string) $job['safe_error_message']) ?><?php if (!empty($job['diagnostic_id'])): ?> · <code><?= View::e((string) $job['diagnostic_id']) ?></code><?php endif; ?></div><?php endif; ?>
  <div class="page-actions mt-2">
    <?php if (in_array($status, ['pending', 'retry', 'waiting_budget', 'error'], true)): ?>
      <form method="post" action="<?= View::e($base) ?>/sync/audit/repair/pause"><input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>"><button class="btn" type="submit">Pausar reparación</button></form>
    <?php elseif (in_array($status, ['paused', 'error', 'partial'], true)): ?>
      <form method="post" action="<?= View::e($base) ?>/sync/audit/repair/resume"><input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>"><button class="btn primary" type="submit">Continuar pendientes</button></form>
    <?php endif; ?>
    <a class="btn" href="<?= View::e($base) ?>/settings/cron/queue?type=sales_repair">Ver en automatización</a>
  </div>
</section>
