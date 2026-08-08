<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$riskRows = is_array($risks) ? array_values(array_filter($risks, 'is_array')) : [];
$coverageSummary = is_array($coverage['summary'] ?? null) ? $coverage['summary'] : [];
$usedCalls = is_array($usage['calls'] ?? null) ? $usage['calls'] : [];
$statusCounts = [];
foreach ($endpoints as $endpoint) {
    $status = (string) ($endpoint['status'] ?? 'unknown');
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
}
?>
<div class="page-head">
  <div>
    <h1>Documentación API Mercado Libre</h1>
    <p>Contratos materializados para comparar documentación, endpoints usados por el ERP y riesgos operativos.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/settings/api-health">Salud API</a>
    <?php if (!empty($canRegenerate)): ?><form method="post" action="<?= View::e($base) ?>/settings/api-docs/regenerate" data-confirm="¿Regenerar contratos desde la documentación local?">
      <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
      <button class="btn primary" type="submit">Regenerar contratos</button>
    </form><?php endif; ?>
  </div>
</div>

<?php if (!$contractsAvailable): ?>
  <div class="alert warning">No hay contratos compactos disponibles. Esta sección queda degradada sin afectar la integración.</div>
<?php endif; ?>
<?php if (empty($sourceAvailable)): ?><div class="alert info">Modo lectura. Las fuentes documentales pesadas no están instaladas; los contratos compactos continúan disponibles.</div><?php endif; ?>
<?php foreach (($warnings ?? []) as $warning): ?><div class="alert warning"><?= View::e((string) $warning) ?></div><?php endforeach; ?>
<?php if (!empty($generatedAt)): ?><p class="muted">Contratos generados: <?= View::e((string) $generatedAt) ?>.</p><?php endif; ?>

<div class="metric-grid">
  <div class="metric-card"><span>Endpoints materializados</span><strong><?= count($endpoints) ?></strong></div>
  <div class="metric-card"><span>Llamadas ERP detectadas</span><strong><?= count($usedCalls) ?></strong></div>
  <div class="metric-card"><span>Riesgos detectados</span><strong><?= count($riskRows) ?></strong></div>
  <div class="metric-card"><span>Cobertura</span><strong><?= View::e((string) ($coverageSummary['coverage_percent'] ?? '—')) ?><?= isset($coverageSummary['coverage_percent']) ? '%' : '' ?></strong></div>
</div>

<section class="panel">
  <h2>Estados de endpoints</h2>
  <div class="chips">
    <?php foreach ($statusCounts as $status => $count): ?>
      <span class="chip"><?= View::e($status) ?>: <?= (int) $count ?></span>
    <?php endforeach; ?>
  </div>
</section>

<section class="panel table-panel">
  <div class="section-head"><h2>Endpoints principales</h2><p>Los desactivados o en investigación no deben ejecutarse desde servicios.</p></div>
  <div class="table-scroll">
    <table class="data-table">
      <caption>Endpoints principales documentados para la integración</caption>
      <thead><tr><th>Método</th><th>Endpoint</th><th>Estado</th><th>Módulo</th><th>Riesgo</th><th>Detalle</th></tr></thead>
      <tbody>
      <?php if (!$endpoints): ?><tr><td colspan="6"><div class="empty">No hay endpoints materializados.</div></td></tr><?php endif; ?>
      <?php foreach (array_slice($endpoints, 0, 80) as $endpoint): ?>
        <?php $path = (string) ($endpoint['normalized_path'] ?? $endpoint['documented_path'] ?? ''); ?>
        <tr>
          <td><?= View::e((string) ($endpoint['method'] ?? 'GET')) ?></td>
          <td><code><?= View::e($path) ?></code></td>
          <td><span class="badge <?= in_array(($endpoint['status'] ?? ''), ['disabled','dangerous','investigating'], true) ? 'amber' : 'green' ?>"><?= View::e((string) ($endpoint['status'] ?? 'unknown')) ?></span></td>
          <td><?= View::e(is_array($endpoint['erp_modules'] ?? null) ? implode(', ', $endpoint['erp_modules']) : (string) ($endpoint['module'] ?? '—')) ?></td>
          <td><?= View::e((string) ($endpoint['risk_level'] ?? '—')) ?></td>
          <td><a class="link" href="<?= View::e($base) ?>/settings/api-docs/endpoint?path=<?= urlencode($path) ?>">Ver</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel table-panel">
  <div class="section-head"><h2>Riesgos relevantes</h2><p>Resumen seguro; no contiene tokens ni datos comerciales.</p></div>
  <div class="table-scroll">
    <table class="data-table">
      <caption>Riesgos documentados y recomendaciones operativas</caption>
      <thead><tr><th>Severidad</th><th>Código</th><th>Descripción</th><th>Recomendación</th></tr></thead>
      <tbody>
      <?php if (!$riskRows): ?><tr><td colspan="4"><div class="empty">No hay riesgos materializados.</div></td></tr><?php endif; ?>
      <?php foreach (array_slice($riskRows, 0, 50) as $risk): ?>
        <tr>
          <td><span class="badge <?= ($risk['severity'] ?? '') === 'critical' ? 'red' : 'amber' ?>"><?= View::e((string) ($risk['severity'] ?? 'medio')) ?></span></td>
          <td><?= View::e((string) ($risk['code'] ?? '—')) ?></td>
          <td><?= View::e((string) ($risk['message'] ?? $risk['description'] ?? '—')) ?></td>
          <td><?= View::e((string) ($risk['recommendation'] ?? 'Revisar antes de ampliar uso API.')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
