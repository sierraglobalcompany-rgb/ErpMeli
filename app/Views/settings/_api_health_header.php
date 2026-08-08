<?php

use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$apiHealthHours = (int) ($apiHealthHours ?? 24);
$apiHealthCheckedAt = $apiHealthCheckedAt ?? null;
?>
<div class="page-head api-health-page-head">
  <div>
    <span class="eyebrow">Mercado Libre</span>
    <h1>Salud de Mercado Libre</h1>
    <p>Estado de la conexión, cuentas y protecciones.</p>
    <?php if ($apiHealthCheckedAt): ?><small>Última comprobación: <?= View::e(DateTimePresenter::formatQueue($apiHealthCheckedAt, 'd/m/Y H:i:s')) ?></small><?php endif; ?>
  </div>
  <div class="api-health-head-tools">
    <nav class="api-period-switch" aria-label="Periodo de análisis">
      <?php foreach ([24 => '24 h', 168 => '7 días', 720 => '30 días'] as $hours => $label): ?>
        <a href="?hours=<?= $hours ?>" <?= $apiHealthHours === $hours ? 'aria-current="page"' : '' ?>><?= View::e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <details class="api-more-menu">
      <summary class="btn">Más acciones</summary>
      <div class="api-more-menu-popover">
        <a href="<?= View::e($base) ?>/settings/api-health/export?hours=<?= $apiHealthHours ?>">Exportar informe</a>
        <a href="<?= View::e($base) ?>/logs?type=api">Historial técnico</a>
        <a href="<?= View::e($base) ?>/settings/api-docs">Documentación API</a>
      </div>
    </details>
  </div>
</div>
