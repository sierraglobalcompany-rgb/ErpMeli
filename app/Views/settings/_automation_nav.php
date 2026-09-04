<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$automationTab = $automationTab ?? 'summary';
$tabs = [
    'summary' => ['/settings/cron', 'Resumen'],
    'rhythm' => ['/settings/cron/rhythm', 'Calibración'],
    'manual' => ['/settings/manual-processing', 'Procesar ahora'],
    'health' => ['/settings/api-health', 'Salud y alertas'],
];
?>
<nav class="cron-view-tabs automation-tabs" aria-label="Automatización y seguridad API">
  <?php foreach ($tabs as $key => [$path, $label]): ?>
    <a href="<?= View::e($base . $path) ?>" <?= $automationTab === $key ? 'aria-current="page" class="is-active active"' : '' ?>><?= View::e($label) ?></a>
  <?php endforeach; ?>
</nav>
