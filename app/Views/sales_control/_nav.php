<?php
use App\Core\Env;
use App\Core\View;
$base = rtrim(Env::get('APP_URL', ''), '/');
$salesNavQuery = '?account_id=' . (int) $accountId . '&year=' . (int) $year;
?>
<nav class="sales-control-nav" aria-label="Secciones de evidencia avanzada">
  <a class="<?= $active === 'summary' ? 'active' : '' ?>" href="<?= View::e($base) ?>/sales-control<?= View::e($salesNavQuery) ?>">Resumen avanzado</a>
  <a class="<?= $active === 'issues' ? 'active' : '' ?>" href="<?= View::e($base) ?>/sales-control/issues<?= View::e($salesNavQuery . '&month=' . (int) ($month ?? date('n'))) ?>">Diferencias</a>
  <a class="<?= $active === 'fiscal' ? 'active' : '' ?>" href="<?= View::e($base) ?>/sales-control/fiscal<?= View::e($salesNavQuery . '&month=' . (int) ($month ?? date('n'))) ?>">Datos fiscales</a>
  <a class="<?= $active === 'closes' ? 'active' : '' ?>" href="<?= View::e($base) ?>/sales-control/closes<?= View::e($salesNavQuery) ?>">Cierres</a>
</nav>
