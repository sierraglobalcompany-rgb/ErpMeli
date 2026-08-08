<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$activeBillingTab = $activeBillingTab ?? 'monthly';
$tabs = [
    ['monthly', '/billing', 'Mensual'],
    ['date', '/billing/date', 'Por fechas'],
];
?>
<nav class="report-tabs" aria-label="Tipos de facturación">
  <?php foreach ($tabs as [$key, $href, $label]): ?>
    <a class="report-tab <?= $activeBillingTab === $key ? 'active' : '' ?>" href="<?= View::e($base . $href) ?>"><?= View::e($label) ?></a>
  <?php endforeach; ?>
</nav>
