<?php

use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$apiHealthSection = 'technical';
$apiHealthHours = 24;
$apiHealthCheckedAt = gmdate('Y-m-d H:i:s');
$budget = $technical['budget'] ?? [];
$rows = $technical['rows'] ?? [];
$filters = $technical['filters'] ?? [];
$queryForPage = static function (int $page) use ($filters, $technical): string {
    return http_build_query([
        'account_id' => (int) ($filters['account_id'] ?? 0),
        'operation' => (string) ($filters['operation'] ?? ''),
        'state' => (string) ($filters['state'] ?? ''),
        'per_page' => (int) ($technical['per_page'] ?? 50),
        'page' => $page,
    ]);
};
require __DIR__ . '/_api_health_header.php';
require __DIR__ . '/_api_health_nav.php';
?>

<section class="api-command-section">
  <header><div><span class="eyebrow">Administración avanzada</span><h2>Detalles técnicos</h2><p>Ventanas, operaciones, límites y códigos HTTP. Esta información no determina por sí sola el riesgo.</p></div></header>
  <div class="api-technical-kpis">
    <div><span>Presupuesto general</span><strong><?= (int) ($budget['global_limit'] ?? 300) ?> / 15 min</strong></div>
    <div><span>Por cuenta</span><strong><?= (int) ($budget['account_limit'] ?? 90) ?> / 15 min</strong></div>
    <div><span>Por operación</span><strong><?= (int) ($budget['endpoint_limit'] ?? 50) ?> / 15 min</strong></div>
    <div><span>Máximo por pantalla</span><strong><?= (int) ($budget['web_limit'] ?? 10) ?></strong></div>
  </div>
  <form class="api-compact-filters" method="get" action="<?= View::e($base) ?>/settings/api-health/technical">
    <div class="field"><label for="technical-account">Cuenta</label><select class="input" id="technical-account" name="account_id"><option value="0">Todas</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) ($filters['account_id'] ?? 0) === (int) $account['id'] ? 'selected' : '' ?>><?= View::e((string) $account['account_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="technical-operation">Operación o trabajo</label><input class="input" id="technical-operation" name="operation" value="<?= View::e((string) ($filters['operation'] ?? '')) ?>" placeholder="Órdenes, productos…"></div>
    <div class="field"><label for="technical-state">Disponibilidad</label><select class="input" id="technical-state" name="state"><option value="">Todas</option><option value="available" <?= ($filters['state'] ?? '') === 'available' ? 'selected' : '' ?>>Disponible</option><option value="cooldown" <?= ($filters['state'] ?? '') === 'cooldown' ? 'selected' : '' ?>>En pausa</option></select></div>
    <div class="field"><label for="technical-per-page">Resultados</label><select class="input" id="technical-per-page" name="per_page"><?php foreach ([25,50,100] as $size): ?><option value="<?= $size ?>" <?= (int) ($technical['per_page'] ?? 50) === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></div>
    <button class="btn primary" type="submit">Aplicar filtros</button>
  </form>
  <div class="api-technical-table-wrap">
    <table class="data-table api-technical-table">
      <caption>Ventanas de capacidad API registradas</caption>
      <thead><tr><th>Protección</th><th>Cuenta</th><th>Operación</th><th>Trabajo</th><th>Uso</th><th>400</th><th>401</th><th>403</th><th>429</th><th>Fallas ML</th><th>Disponible</th></tr></thead>
      <tbody>
      <?php if ($rows === []): ?><tr><td colspan="11"><div class="empty-state"><strong>Sin ventanas para estos filtros</strong><span>Las ventanas aparecerán después de las primeras consultas.</span></div></td></tr><?php endif; ?>
      <?php foreach ($rows as $window): ?>
        <tr>
          <td><?= View::e((string) ($window['scope'] ?? '')) ?></td>
          <td><?= View::e((string) ($window['account_name'] ?? 'Global')) ?></td>
          <td><code><?= View::e((string) ($window['endpoint_path'] ?? '—')) ?></code></td>
          <td><?= View::e((string) ($window['job_type'] ?? '—')) ?></td>
          <td><?= (int) ($window['request_count'] ?? 0) ?> / <?= (int) ($window['request_limit'] ?? 0) ?></td>
          <td><?= (int) ($window['error_400_count'] ?? 0) ?></td><td><?= (int) ($window['error_401_count'] ?? 0) ?></td><td><?= (int) ($window['error_403_count'] ?? 0) ?></td><td><?= (int) ($window['error_429_count'] ?? 0) ?></td><td><?= (int) ($window['error_5xx_count'] ?? 0) ?></td>
          <td><?= !empty($window['cooldown_until']) ? View::e(DateTimePresenter::formatQueue($window['cooldown_until'], 'd/m H:i')) : 'Ahora' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <nav class="pagination" aria-label="Paginación de detalles técnicos">
    <?php if ((int) $technical['page'] > 1): ?><a class="btn" href="?<?= View::e($queryForPage((int) $technical['page'] - 1)) ?>">Anterior</a><?php endif; ?>
    <span>Página <?= (int) $technical['page'] ?> de <?= (int) $technical['pages'] ?> · <?= (int) $technical['total'] ?> registros</span>
    <?php if ((int) $technical['page'] < (int) $technical['pages']): ?><a class="btn" href="?<?= View::e($queryForPage((int) $technical['page'] + 1)) ?>">Siguiente</a><?php endif; ?>
  </nav>
</section>
