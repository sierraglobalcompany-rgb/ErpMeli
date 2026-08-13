<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$importStatus = is_array($importStatus ?? null) ? $importStatus : null;
$label = static fn(string $state): string => match ($state) {
    'not_started' => 'Sin preparar',
    'queued' => 'Preparado',
    'running' => 'En ejecución',
    'needs_repair' => 'Faltantes',
    'repairing' => 'Descargando',
    'date_repair_needed' => 'Fechas',
    'date_repairing' => 'Corrigiendo',
    'review_needed' => 'Revisar',
    'verified' => 'Verificado',
    'partial_history' => 'Parcial',
    'blocked' => 'Pausado',
    'future' => 'Futuro',
    default => ucfirst(str_replace('_', ' ', $state)),
};
?>
<?php if (!$importStatus): ?>
  <div class="alert info">Seleccione una cuenta para consultar su progreso anual.</div>
<?php else: ?>
  <div class="sales-import-status" aria-label="Estado de importación">
    <div class="sales-import-progress">
      <span>Progreso</span>
      <strong><?= (int) $importStatus['progress']['percent'] ?>%</strong>
      <div class="sales-import-bar"><i style="width: <?= (int) $importStatus['progress']['percent'] ?>%"></i></div>
    </div>
    <div><span>Verificados</span><strong><?= (int) $importStatus['progress']['verified'] ?>/<?= (int) $importStatus['progress']['total'] ?></strong></div>
    <div><span>En proceso</span><strong><?= (int) $importStatus['progress']['working'] ?></strong></div>
    <div><span>Atención</span><strong><?= (int) $importStatus['progress']['attention'] ?></strong></div>
  </div>
  <div class="sales-import-months" aria-label="Meses del año">
    <?php foreach ($importStatus['months'] as $monthState): ?>
      <span class="sales-import-month tone-<?= View::e((string) $monthState['tone']) ?>" title="<?= View::e((string) $monthState['summary']) ?>">
        <strong><?= View::e((string) $monthState['name']) ?></strong>
        <small><?= View::e($label((string) $monthState['state'])) ?></small>
      </span>
    <?php endforeach; ?>
  </div>
  <?php if (!empty($importStatus['stages'])): ?>
    <div class="sales-import-status" aria-label="Progreso por etapas">
      <?php foreach ($importStatus['stages'] as $stage): ?>
        <div>
          <span><?= View::e((string) $stage['label']) ?></span>
          <strong><?= number_format((int) $stage['ready'], 0, ',', '.') ?>/<?= number_format((int) $stage['total'], 0, ',', '.') ?></strong>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($importStatus['advanced_url'])): ?>
    <p><a class="btn small" href="<?= View::e($base . (string) $importStatus['advanced_url']) ?>">Ver evidencia avanzada</a></p>
  <?php endif; ?>
<?php endif; ?>
