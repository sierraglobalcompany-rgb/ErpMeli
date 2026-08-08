<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$activeReportTab = $activeReportTab ?? 'profitability';
$tabs = [
    ['profitability', '/reports/profitability', 'Rentabilidad'],
    ['exports', '/exports', 'Exportaciones'],
];
?>
<nav class="report-tabs" aria-label="Tipos de reporte">
  <?php foreach ($tabs as [$key, $href, $label]): ?>
    <a class="report-tab <?= $activeReportTab === $key ? 'active' : '' ?>" href="<?= View::e($base . $href) ?>"><?= View::e($label) ?></a>
  <?php endforeach; ?>
</nav>
