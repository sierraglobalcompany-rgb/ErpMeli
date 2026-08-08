<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$automationTab = $automationTab ?? 'summary';
$tabs = [
    'summary' => ['/settings/cron', 'Resumen'],
    'next' => ['/settings/cron/next', 'Próxima ejecución'],
    'queue' => ['/settings/cron/queue', 'Cola completa'],
    'history' => ['/settings/cron/history', 'Historial'],
    'diagnostics' => ['/settings/cron/diagnostics', 'Diagnóstico'],
    'manual' => ['/settings/manual-processing', 'Procesar ahora'],
];
?>
<nav class="section-tabs automation-tabs" aria-label="Centro de Automatización">
  <?php foreach ($tabs as $key => [$path, $label]): ?>
    <a href="<?= View::e($base . $path) ?>" <?= $automationTab === $key ? 'aria-current="page" class="active"' : '' ?>><?= View::e($label) ?></a>
  <?php endforeach; ?>
</nav>
