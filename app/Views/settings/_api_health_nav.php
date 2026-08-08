<?php

use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$apiHealthSection = (string) ($apiHealthSection ?? 'overview');
$apiHealthTabs = [
    'overview' => ['/settings/api-health', 'Resumen'],
    'accounts' => ['/settings/api-health/accounts', 'Cuentas'],
    'incidents' => ['/settings/api-health/incidents', 'Incidentes'],
    'protection' => ['/settings/api-health/protection', 'Protección'],
    'technical' => ['/settings/api-health/technical', 'Detalles técnicos'],
];
?>
<nav class="api-health-tabs" aria-label="Secciones de Salud Mercado Libre">
  <?php foreach ($apiHealthTabs as $key => [$href, $label]): ?>
    <a href="<?= View::e($base . $href) ?>" <?= $apiHealthSection === $key ? 'aria-current="page"' : '' ?>><?= View::e($label) ?></a>
  <?php endforeach; ?>
</nav>
