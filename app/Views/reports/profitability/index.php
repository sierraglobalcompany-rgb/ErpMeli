<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$progressive = !empty($progressive);
if ($progressive) {
    $query = http_build_query(array_filter([
        'company_id' => (int) ($filters['company_id'] ?? 0) ?: null,
        'account_id' => (int) ($filters['account_id'] ?? 0) ?: null,
        'from' => (string) ($filters['from'] ?? ''),
        'to' => (string) ($filters['to'] ?? ''),
        'include_returns' => !empty($filters['include_returns']) ? 1 : null,
        'page' => max(1, (int) ($_GET['page'] ?? 1)),
    ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    $sectionUrl = $base . '/reports/profitability/section.json' . ($query !== '' ? '?' . $query : '');
    ?>
    <section class="async-section" data-async-section="profitability-list" data-url="<?= View::e($sectionUrl) ?>" aria-live="polite" aria-busy="true">
      <div data-async-skeleton class="panel async-skeleton is-visible">
        <div class="page-head"><div><h1>Rentabilidad</h1><p>La pantalla ya está disponible. Estamos calculando margen y utilidad con los filtros seleccionados.</p></div></div>
        <div class="skeleton-table"><span></span><span></span><span></span><span></span></div>
      </div>
      <div data-async-status class="async-section-status" hidden></div>
      <div data-async-content></div>
    </section>
    <noscript><a class="btn primary" href="<?= View::e($base) ?>/reports/profitability?<?= View::e($query) ?>&amp;full=1">Cargar reporte completo</a></noscript>
    <?php
    return;
}
$money = static fn($v) => '$ ' . number_format((float) $v, 0, ',', '.');
$totals = ['orders' => 0, 'product' => 0.0, 'net' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'missing_cost' => 0, 'negative_margin' => 0];
foreach ($rows as $row) {
    $totals['orders'] += (int) $row['orders_count'];
    $totals['product'] += (float) $row['product_revenue'];
    $totals['net'] += (float) $row['estimated_net'];
    $totals['cost'] += (float) $row['total_cost'];
    $totals['profit'] += (float) $row['estimated_profit'];
    if ((float) $row['unit_cost'] <= 0) {
        $totals['missing_cost']++;
    }
    if ((float) $row['margin_percent'] < 0) {
        $totals['negative_margin']++;
    }
}
$overallMargin = $totals['product'] > 0 ? ($totals['profit'] / $totals['product']) * 100 : 0;
$activeReportTab = 'profitability';
?>
<div class="page-head">
  <div>
    <h1>Rentabilidad</h1>
    <p>Utilidad y margen por producto interno para detectar productos sin costo, margen negativo o vínculos pendientes.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/billing/date?company_id=<?= (int) $filters['company_id'] ?>&account_id=<?= (int) $filters['account_id'] ?>&from=<?= View::e($filters['from']) ?>&to=<?= View::e($filters['to']) ?><?= $filters['include_returns'] ? '&include_returns=1' : '' ?>">Crear facturación por fechas</a>
  </div>
</div>
<?php require dirname(__DIR__) . '/_tabs.php'; ?>
<section class="operation-explainer" aria-label="Cómo leer la rentabilidad">
  <div><span>Qué está pasando</span><strong>Este reporte calcula únicamente los 50 productos de la página.</strong></div>
  <div><span>Qué hará el ERP</span><strong>Separará productos sin costo, sin vínculo o con margen negativo.</strong></div>
  <div><span>Qué puede hacer ahora</span><strong>Complete Bodega o Vinculación antes de decidir sobre una alerta.</strong></div>
</section>
<section class="panel filter-bar"><form method="get" class="inline-form">
  <div class="field"><label>Empresa</label><select class="input" name="company_id"><option value="0">Todas</option><?php foreach ($companies as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $filters['company_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= View::e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>Cuenta</label><select class="input" name="account_id"><option value="0">Todas</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $filters['account_id'] === (int) $a['id'] ? 'selected' : '' ?>><?= View::e($a['account_name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>Desde</label><input class="input" type="date" name="from" value="<?= View::e($filters['from']) ?>"></div>
  <div class="field"><label>Hasta</label><input class="input" type="date" name="to" value="<?= View::e($filters['to']) ?>"></div>
  <label class="check-row"><input type="checkbox" name="include_returns" value="1" <?= $filters['include_returns'] ? 'checked' : '' ?>> Incluir canceladas/devueltas</label>
  <button class="btn">Calcular rentabilidad</button>
</form></section>
<section class="mini-grid">
  <div class="mini-card"><span>Utilidad en esta página</span><strong><?= $money($totals['profit']) ?></strong></div>
  <div class="mini-card"><span>Margen de esta página</span><strong><?= number_format($overallMargin, 2, ',', '.') ?>%</strong></div>
  <div class="mini-card"><span>Productos a revisar aquí</span><strong><?= number_format($totals['missing_cost'] + $totals['negative_margin'], 0, ',', '.') ?></strong></div>
</section>
<?php
$page = max(1, (int) ($page ?? 1));
$paginationQuery = static function (int $target) use ($filters): string {
    return http_build_query([
        'company_id' => (int) $filters['company_id'],
        'account_id' => (int) $filters['account_id'],
        'from' => (string) $filters['from'],
        'to' => (string) $filters['to'],
        'include_returns' => !empty($filters['include_returns']) ? 1 : 0,
        'page' => $target,
    ]);
};
?>
<nav class="pagination" aria-label="Páginas de rentabilidad">
  <span class="muted">Página <?= $page ?> · máximo 50 productos</span>
  <?php if ($page > 1): ?><a class="btn small" data-async-page href="<?= View::e($base) ?>/reports/profitability?<?= View::e($paginationQuery($page - 1)) ?>">Anterior</a><?php endif; ?>
  <?php if (!empty($hasMore)): ?><a class="btn small" data-async-page href="<?= View::e($base) ?>/reports/profitability?<?= View::e($paginationQuery($page + 1)) ?>">Siguiente</a><?php endif; ?>
</nav>
<section class="panel table-panel mt-2">
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Producto interno</th><th>Cuenta</th><th>SKU/Ítem</th><th>Ventas</th><th>Ingresos producto</th><th>Neto</th><th>Costo total</th><th>Utilidad</th><th>Margen</th><th>Alerta</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="10"><div class="empty">No hay datos de rentabilidad para este filtro.</div></td></tr><?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <?php
          $missingCost = (float) $row['unit_cost'] <= 0;
          $negativeMargin = (float) $row['margin_percent'] < 0;
          $unlinked = empty($row['internal_product_id']);
          $alert = $unlinked ? 'Sin vínculo' : ($missingCost ? 'Sin costo' : ($negativeMargin ? 'Margen negativo' : 'OK'));
          $badge = $alert === 'OK' ? 'green' : ($negativeMargin ? 'red' : 'amber');
        ?>
        <tr>
          <td><?= $unlinked ? '<span class="badge amber">Sin vínculo</span>' : View::e((string) $row['internal_product_id']) ?></td>
          <td><?= View::e($row['account_name']) ?></td>
          <td><?= View::e(($row['seller_sku'] ?: '—') . ' / ' . $row['external_item_id']) ?></td>
          <td><?= (int) $row['orders_count'] ?></td>
          <td><?= $money($row['product_revenue']) ?></td>
          <td><?= $money($row['estimated_net']) ?></td>
          <td><?= $money($row['total_cost']) ?></td>
          <td><?= $money($row['estimated_profit']) ?></td>
          <td><span class="badge <?= (float) $row['margin_percent'] < 0 ? 'red' : 'green' ?>"><?= number_format((float) $row['margin_percent'], 2, ',', '.') ?>%</span></td>
          <td><span class="badge <?= $badge ?>"><?= View::e($alert) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
