<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$automationTab = $automationTab ?? 'summary';
$tabs = [
    'summary' => ['/settings/cron', 'Centro'],
    'next' => ['/settings/cron/next', 'Próxima ejecución'],
    'queue' => ['/settings/cron/queue', 'Pendientes'],
    'history' => ['/settings/cron/history', 'Evidencia'],
    'manual' => ['/settings/manual-processing', 'Procesar ahora'],
];
?>
<nav class="section-tabs automation-tabs" aria-label="Procesamiento del ERP">
  <?php foreach ($tabs as $key => [$path, $label]): ?>
    <a href="<?= View::e($base . $path) ?>" <?= $automationTab === $key ? 'aria-current="page" class="active"' : '' ?>><?= View::e($label) ?></a>
  <?php endforeach; ?>
</nav>
