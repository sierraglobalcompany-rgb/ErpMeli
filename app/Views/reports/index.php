<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$money = static fn($v) => '$ ' . number_format((float) $v, 0, ',', '.');
$activeReportTab = 'monthly';
?>
<div class="page-head">
  <div>
    <h1>Reportes</h1>
    <p>Elige entre cierre mensual, análisis por fechas o rentabilidad por producto.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/reports/date">Reporte por fechas</a>
    <a class="btn" href="<?= View::e($base) ?>/reports/profitability">Rentabilidad</a>
  </div>
</div>
<?php require __DIR__ . '/_tabs.php'; ?>
<div class="report-help"><strong>Mensual:</strong> úsalo para preparar cierres por mes entre empresas. Si necesitas un rango específico, abre la pestaña <strong>Por fechas</strong>.</div>
<section class="panel filter-bar">
  <form method="post" action="<?= View::e($base) ?>/reports">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <div class="form-grid">
      <div class="field"><label>Empresa emisora</label><select class="input" name="issuer_company_id" required><?php foreach ($companies as $c): ?><option value="<?= (int) $c['id'] ?>"><?= View::e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Empresa cliente</label><select class="input" name="customer_company_id" required><?php foreach ($companies as $c): ?><option value="<?= (int) $c['id'] ?>"><?= View::e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Cuenta Mercado Libre</label><select class="input" name="meli_account_id" required><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= View::e($a['account_name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Mes</label><input class="input" type="month" name="month" value="<?= date('Y-m') ?>" required></div>
      <div class="field"><label>&nbsp;</label><button class="btn primary">Generar borrador mensual</button></div>
    </div>
  </form>
</section>
<section class="panel table-panel">
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Mes</th><th>Emisora</th><th>Cliente</th><th>Cuenta</th><th>Venta bruta</th><th>Base manual</th><th>Estado</th></tr></thead>
      <tbody>
      <?php if (!$reports): ?><tr><td colspan="7"><div class="empty">Aún no hay cierres mensuales.</div></td></tr><?php endif; ?>
      <?php foreach ($reports as $r): ?>
        <tr>
          <td><a class="link" href="<?= View::e($base) ?>/reports/show?id=<?= (int) $r['id'] ?>"><?= View::e(substr($r['report_month'], 0, 7)) ?></a></td>
          <td><?= View::e($r['issuer_name']) ?></td>
          <td><?= View::e($r['customer_name']) ?></td>
          <td><?= View::e($r['account_name']) ?></td>
          <td><?= $money($r['gross_sales']) ?></td>
          <td><?= $money($r['manual_base']) ?></td>
          <td><span class="badge <?= in_array($r['status'], ['aprobado', 'facturado'], true) ? 'green' : 'amber' ?>"><?= View::e($r['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
