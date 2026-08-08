<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$jobId = (int) $job['id'];
$canOperate = in_array(Auth::role(), ['admin', 'operador'], true);
$terminal = in_array((string) $job['status'], ['completed', 'partial', 'cancelled'], true);
?>
<div class="page-head catalog-admin-head">
  <div>
    <a class="muted" href="<?= View::e($base) ?>/catalogs/<?= (int) $job['catalog_id'] ?>/description-jobs">← Volver al historial</a>
    <h1>Descripciones · Trabajo #<?= $jobId ?></h1>
    <p><?= View::e((string) $job['catalog_name']) ?> · Proceso por lotes seguro y reanudable.</p>
  </div>
  <div class="page-actions">
    <a class="btn primary" href="<?= View::e($base) ?>/settings/manual-processing?scope=descriptions&amp;origin=descriptions">Procesar ahora</a>
    <a class="btn" href="<?= View::e($base) ?>/catalogs/<?= (int) $job['catalog_id'] ?>">Administrar catálogo</a>
  </div>
</div>

<section
  class="panel panel-body catalog-description-monitor"
  data-catalog-description-monitor
  data-job-id="<?= $jobId ?>"
  data-status-url="<?= View::e($base) ?>/catalogs/description-jobs/<?= $jobId ?>/status.json"
>
  <div class="section-head">
    <div>
      <h2>Progreso del trabajo</h2>
      <p class="muted" data-description-message><?= View::e((string) ($job['last_error_message'] ?: 'Listo para continuar.')) ?></p>
    </div>
    <span class="badge" data-description-status><?= View::e((string) $job['status_label']) ?></span>
  </div>
  <div class="catalog-description-progress" aria-label="Progreso">
    <span data-description-progress style="width:<?= max(0, min(100, (float) $job['percent'])) ?>%"></span>
  </div>
  <div class="metrics catalog-description-metrics">
    <article class="metric-card compact"><div><div class="metric-label">Procesadas</div><div class="metric-value"><span data-description-processed><?= (int) $job['processed_items'] ?></span> / <span data-description-total><?= (int) $job['total_items'] ?></span></div></div></article>
    <article class="metric-card compact"><div><div class="metric-label">Confirmadas</div><div class="metric-value" data-description-confirmed><?= (int) $job['confirmed_items'] ?></div></div></article>
    <article class="metric-card compact"><div><div class="metric-label">Sin descripción</div><div class="metric-value" data-description-unavailable><?= (int) $job['unavailable_items'] ?></div></div></article>
    <article class="metric-card compact"><div><div class="metric-label">Pendientes</div><div class="metric-value" data-description-remaining><?= (int) $job['remaining_items'] ?></div></div></article>
    <article class="metric-card compact"><div><div class="metric-label">Errores</div><div class="metric-value" data-description-errors><?= (int) $job['error_items'] ?></div></div></article>
    <article class="metric-card compact"><div><div class="metric-label">Cuenta actual</div><div class="metric-value small-value" data-description-account><?= View::e((string) ($job['current_account_name'] ?: '—')) ?></div></div></article>
  </div>
  <div class="catalog-description-meta">
    <span>Próxima etapa: <strong data-description-next><?= View::e(DateTimePresenter::formatQueue($job['next_run_at'] ?? null)) ?></strong></span>
    <span>Motivo: <strong data-description-reason><?= View::e((string) $job['stop_reason_label']) ?></strong></span>
    <span>Motor: <strong>lanzador CLI del ERP</strong></span>
  </div>
  <?php if ($canOperate): ?>
    <div class="catalog-description-actions">
      <?php if ((string) $job['status'] === 'paused'): ?>
        <form method="post" action="<?= View::e($base) ?>/catalogs/description-jobs/<?= $jobId ?>/resume"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><button class="btn primary">Reanudar trabajo</button></form>
      <?php elseif (!$terminal): ?>
        <form method="post" action="<?= View::e($base) ?>/catalogs/description-jobs/<?= $jobId ?>/pause"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><button class="btn">Pausar trabajo</button></form>
      <?php endif; ?>
      <?php if ((int) $job['error_items'] > 0): ?><form method="post" action="<?= View::e($base) ?>/catalogs/description-jobs/<?= $jobId ?>/retry"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><button class="btn">Reintentar errores</button></form><?php endif; ?>
      <?php if ((string) $job['status'] === 'cancelled'): ?>
        <form method="post" action="<?= View::e($base) ?>/catalogs/description-jobs/<?= $jobId ?>/reopen"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><button class="btn">Reabrir desde checkpoint</button></form>
      <?php elseif (!$terminal): ?>
        <form method="post" action="<?= View::e($base) ?>/catalogs/description-jobs/<?= $jobId ?>/cancel" data-confirm="Cancelará el trabajo, pero conservará historial y checkpoint."><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><button class="btn danger">Cancelar trabajo</button></form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<section class="panel table-panel mt-2">
  <header class="panel-head">
    <h2>Productos del trabajo</h2>
    <div class="page-actions">
      <a class="btn small" href="<?= View::e($base) ?>/catalogs/description-jobs/<?= $jobId ?>">Todos</a>
      <a class="btn small" href="<?= View::e($base) ?>/catalogs/description-jobs/<?= $jobId ?>?status=pending">Pendientes</a>
      <a class="btn small" href="<?= View::e($base) ?>/catalogs/description-jobs/<?= $jobId ?>?status=error">Errores</a>
      <a class="btn small" href="<?= View::e($base) ?>/catalogs/description-jobs/<?= $jobId ?>?status=confirmed">Confirmados</a>
    </div>
  </header>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Publicación</th><th>Cuenta</th><th>Estado</th><th>Resultado ML</th><th>Intentos</th><th>Procesado</th><th>Error seguro</th></tr></thead>
      <tbody>
      <?php if (!$items): ?><tr><td colspan="7"><div class="empty">No hay productos para este filtro.</div></td></tr><?php endif; ?>
      <?php foreach ($items as $item): ?>
        <tr>
          <td><?= View::e((string) $item['external_item_id']) ?></td>
          <td><?= View::e((string) ($item['account_name'] ?: '—')) ?></td>
          <td><span class="badge"><?= View::e((string) $item['status']) ?></span></td>
          <td><?= View::e((string) ($item['source_status'] ?: '—')) ?></td>
          <td><?= (int) $item['attempts'] ?></td>
          <td><?= View::e(DateTimePresenter::formatQueue($item['processed_at'] ?? null)) ?></td>
          <td><?= View::e((string) ($item['safe_error_message'] ?: '—')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
