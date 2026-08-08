<?php
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$isEvents = $kind === 'events';
$rows = (array) ($listing['rows'] ?? []);
$statusLabels = [
    'pending' => 'Por hacer',
    'running' => 'En curso',
    'waiting' => 'Esperando',
    'retry' => 'Se reintentará',
    'completed' => 'Completado',
    'skipped' => 'Omitido',
    'failed' => 'Necesita atención',
    'returned' => 'Devuelto a Automatización',
];
$severityLabels = [
    'success' => 'Correcto',
    'warning' => 'Esperando',
    'error' => 'Necesita atención',
    'info' => 'Intento iniciado',
    'neutral' => 'Información',
];
?>
<div class="page-head compact-head">
  <div>
    <p class="eyebrow">PROCESAR AHORA</p>
    <h1><?= $isEvents ? 'Bitácora de la campaña' : 'Trabajos de la campaña' ?></h1>
    <p><?= $isEvents ? 'Cambios confirmados, del más reciente al más antiguo.' : 'Contenido congelado y resultado real de cada trabajo.' ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base . '/settings/manual-processing/session?id=' . (int) $campaign['id']) ?>">Volver al monitor</a>
    <a class="btn" href="<?= View::e($base . '/settings/manual-processing/session/' . ($isEvents ? 'items' : 'events') . '?id=' . (int) $campaign['id']) ?>">
      <?= $isEvents ? 'Ver trabajos' : 'Ver bitácora' ?>
    </a>
  </div>
</div>

<section class="panel">
  <div class="panel-head compact-panel-head">
    <div>
      <h2><?= number_format((int) ($listing['total'] ?? 0), 0, ',', '.') ?> <?= $isEvents ? 'eventos' : 'trabajos' ?></h2>
      <p>Campaña #<?= (int) $campaign['id'] ?> · <?= View::e((string) ($campaign['account_label'] ?? 'Todas las cuentas')) ?></p>
    </div>
  </div>
  <div class="table-scroll">
    <table class="human-table">
      <caption><?= $isEvents ? 'Bitácora de actividad de la campaña' : 'Trabajos incluidos en la campaña' ?></caption>
      <thead>
        <?php if ($isEvents): ?>
        <tr><th>Fecha</th><th>Actividad</th><th>Cuenta</th><th>Resultado</th></tr>
        <?php else: ?>
        <tr><th>Trabajo</th><th>Cuenta</th><th>Progreso</th><th>Estado</th><th>Disponible desde</th><th>Motivo</th><th>Resultado</th></tr>
        <?php endif; ?>
      </thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <?php if ($isEvents): ?>
        <tr>
          <td><time datetime="<?= View::e((string) $row['created_at']) ?>"><?= View::e((string) ($row['display_time'] ?? DateTimePresenter::formatQueue($row['created_at'] ?? null))) ?></time></td>
          <td><strong><?= View::e((string) ($row['human_label'] ?: 'Campaña')) ?></strong><small><?= View::e((string) $row['safe_message']) ?></small></td>
          <td><?= View::e((string) ($row['account_name'] ?: 'General')) ?></td>
          <td><span class="status-badge is-<?= View::e((string) $row['severity']) ?>"><?= View::e($severityLabels[(string) $row['severity']] ?? 'Información') ?></span></td>
        </tr>
        <?php else: ?>
        <?php
          $approved = (int) $row['completed_units'];
          $failedUnits = (int) $row['failed_units'];
          $skippedUnits = (int) $row['skipped_units'];
        ?>
        <tr>
          <td><strong><?= View::e((string) $row['human_label']) ?></strong><small><?= View::e((string) $row['content_summary']) ?></small></td>
          <td><?= View::e((string) ($row['account_name'] ?: 'General')) ?></td>
          <td>
            <?= $approved ?> de <?= (int) $row['total_units'] ?> aprobadas
            <?php if ($failedUnits > 0): ?><small><?= $failedUnits ?> requieren revisión; no cuentan como completadas.</small><?php endif; ?>
            <?php if ($skippedUnits > 0): ?><small><?= $skippedUnits ?> omitidas; se conservan separadas del progreso.</small><?php endif; ?>
          </td>
          <td><span class="status-badge is-<?= View::e((string) $row['status']) ?>"><?= View::e((string) ($row['status_label'] ?? ($statusLabels[(string) $row['status']] ?? (string) $row['status']))) ?></span></td>
          <td><?= View::e((string) ($row['available_from_label'] ?? 'Ahora')) ?></td>
          <td><?= View::e((string) ($row['wait_reason_label'] ?? 'Pendiente')) ?></td>
          <td>
            <?= View::e((string) ($row['result_summary'] ?: $row['source_resolution'] ?: 'Pendiente')) ?>
            <?php if (!empty($row['last_attempt_label']) && $row['last_attempt_label'] !== 'Sin intentar'): ?>
              <small>Último intento: <?= View::e((string) $row['last_attempt_label']) ?></small>
            <?php endif; ?>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="<?= $isEvents ? 4 : 7 ?>">No hay información para este filtro.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
