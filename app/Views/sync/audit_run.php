<?php

use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$status = (string) ($job['status'] ?? 'pending');
$running = in_array($status, ['pending', 'running', 'waiting_budget'], true);
$tone = match ($status) {
    'complete' => 'green',
    'error' => 'red',
    'waiting_budget' => 'amber',
    default => 'blue',
};
$statusLabel = match ($status) {
    'complete' => 'Comprobación terminada',
    'error' => 'Necesita revisión',
    'waiting_budget' => 'Esperando una hora segura',
    'running' => 'Comprobando el mes',
    default => 'Esperando el próximo ciclo',
};
$monthName = DateTimePresenter::monthName((int) $job['period_month']);
?>
<div class="page-head">
  <div>
    <a class="muted" href="<?= View::e($base) ?>/sync/audit?account_id=<?= (int) $job['meli_account_id'] ?>&year=<?= (int) $job['period_year'] ?>&month=<?= (int) $job['period_month'] ?>">← Volver al periodo</a>
    <div class="eyebrow">Paso 1 de 4 · Comprobar</div>
    <h1><?= View::e($monthName) ?> de <?= (int) $job['period_year'] ?></h1>
    <p>El ERP está comparando las órdenes del mes sin modificar Mercado Libre.</p>
  </div>
</div>

<nav class="audit-stepper" aria-label="Progreso de la auditoría">
  <span class="active"><b>1</b> Comprobar</span><span><b>2</b> Revisar</span><span><b>3</b> Reparar</span><span><b>4</b> Verificar</span>
</nav>

<section class="status-hero <?= View::e($tone) ?>" aria-live="polite">
  <div class="status-hero-icon" aria-hidden="true"><?= $status === 'complete' ? '✓' : ($status === 'error' ? '!' : '↻') ?></div>
  <div>
    <div class="eyebrow">Estado</div>
    <h2><?= View::e($statusLabel) ?></h2>
    <p>
      <?php if ($status === 'waiting_budget'): ?>
        El trabajo conserva su progreso y continuará cuando exista presupuesto seguro.
      <?php elseif ($status === 'error'): ?>
        El trabajo se detuvo de forma segura. Revise el diagnóstico antes de reintentarlo.
      <?php elseif ($status === 'complete'): ?>
        Ya puede revisar las diferencias confirmadas del periodo.
      <?php else: ?>
        El lanzador único continuará automáticamente. Puede cerrar esta página.
      <?php endif; ?>
    </p>
  </div>
  <?php if ($status === 'complete'): ?>
    <a class="btn primary" href="<?= View::e($base) ?>/sync/audit?account_id=<?= (int) $job['meli_account_id'] ?>&year=<?= (int) $job['period_year'] ?>&month=<?= (int) $job['period_month'] ?>">Revisar resultado</a>
  <?php endif; ?>
</section>

<section class="panel mt-2">
  <header class="panel-head"><h2>Avance</h2><strong><?= (int) $job['progress_percent'] ?> %</strong></header>
  <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $job['progress_percent'] ?>"><span style="width:<?= (int) $job['progress_percent'] ?>%"></span></div>
  <div class="metrics compact mt-2">
    <article class="metric-card compact"><div><div class="metric-label">Órdenes revisadas</div><div class="metric-value"><?= number_format((int) $job['next_offset'], 0, ',', '.') ?></div></div></article>
    <article class="metric-card compact"><div><div class="metric-label">Total informado</div><div class="metric-value"><?= (int) $job['remote_reported_total'] > 0 ? number_format((int) $job['remote_reported_total'], 0, ',', '.') : 'Por conocer' ?></div></div></article>
    <article class="metric-card compact"><div><div class="metric-label">Páginas estimadas</div><div class="metric-value"><?= $job['estimated_pages'] !== null ? (int) $job['estimated_pages'] : '—' ?></div></div></article>
    <article class="metric-card compact"><div><div class="metric-label">Próxima oportunidad</div><div class="metric-value small"><?= View::e(DateTimePresenter::formatQueue($job['next_run_at'] ?? null)) ?></div></div></article>
  </div>
  <?php if (!empty($job['safe_error_message'])): ?>
    <div class="alert warning mt-2"><strong>Qué ocurrió:</strong> <?= View::e((string) $job['safe_error_message']) ?><?php if (!empty($job['diagnostic_id'])): ?> · Diagnóstico <code><?= View::e((string) $job['diagnostic_id']) ?></code><?php endif; ?></div>
  <?php endif; ?>
</section>

<?php if ($running): ?><p class="muted mt-2">Esta página no mantiene abierto el proceso. La automatización trabaja por ciclos y guarda cada avance.</p><?php endif; ?>
