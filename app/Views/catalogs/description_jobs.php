<?php

use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$labels = [
    'queued' => 'En cola',
    'running' => 'Procesando',
    'waiting' => 'Esperando',
    'paused' => 'Pausado',
    'completed' => 'Completo',
    'partial' => 'Completo con errores',
    'error' => 'Error',
    'cancelled' => 'Cancelado',
];
?>
<div class="page-head catalog-admin-head">
  <div>
    <a class="muted" href="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>">← Volver al catálogo</a>
    <h1>Actualización de descripciones</h1>
    <p><?= View::e($catalog['name']) ?> · Historial persistente de trabajos.</p>
  </div>
  <div class="page-actions">
    <a class="btn primary" href="<?= View::e($base) ?>/settings/manual-processing?scope=descriptions&amp;origin=descriptions">Procesar descripciones ahora</a>
  </div>
</div>

<section class="panel table-panel">
  <header class="panel-head"><h2>Trabajos</h2></header>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Trabajo</th><th>Modo</th><th>Estado</th><th>Progreso</th><th>Confirmadas</th><th>Sin descripción</th><th>Errores</th><th>Próxima etapa</th><th></th></tr></thead>
      <tbody>
      <?php if (!$jobs): ?><tr><td colspan="9"><div class="empty">Todavía no hay trabajos de descripciones.</div></td></tr><?php endif; ?>
      <?php foreach ($jobs as $job): ?>
        <tr>
          <td>#<?= (int) $job['id'] ?></td>
          <td><?= View::e((string) $job['mode']) ?></td>
          <td><span class="badge"><?= View::e($labels[(string) $job['status']] ?? (string) $job['status']) ?></span></td>
          <td><?= (int) $job['processed_items'] ?> / <?= (int) $job['total_items'] ?></td>
          <td><?= (int) $job['confirmed_items'] ?></td>
          <td><?= (int) $job['unavailable_items'] ?></td>
          <td><?= (int) $job['error_items'] ?></td>
          <td><?= View::e(DateTimePresenter::formatQueue($job['next_run_at'] ?? null)) ?></td>
          <td><a class="btn small" href="<?= View::e($base) ?>/catalogs/description-jobs/<?= (int) $job['id'] ?>">Ver progreso</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
