<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
$base = rtrim(Env::get('APP_URL', ''), '/');
?>
<div class="page-head">
  <div><a class="muted" href="<?= View::e($base) ?>/sync">← Volver a sincronizaciones</a><h1>Diagnóstico de volumen</h1><p>Estima ventas por día usando muestras de `/orders/search` sin importar todas las órdenes.</p></div>
</div>
<section class="panel filter-bar">
  <form class="inline-form" method="post" action="<?= View::e($base) ?>/sync/diagnostics/run">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <div class="field"><label>Cuenta</label><select class="input" name="account_id"><?php foreach($accounts as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $accountId===(int)$a['id']?'selected':'' ?>><?= View::e($a['account_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Año</label><input class="input" type="number" name="year" value="<?= (int)$year ?>"></div>
    <div class="field"><label>Mes</label><input class="input" type="number" min="1" max="12" name="month" value="<?= (int)$month ?>"></div>
    <button class="btn primary">Calcular diagnóstico</button>
  </form>
</section>
<?php if ($diagnostic): ?>
<section class="metrics">
  <article class="metric-card"><div><div class="metric-label">Promedio diario</div><div class="metric-value"><?= View::e((string)$diagnostic['average_daily_orders']) ?></div></div></article>
  <article class="metric-card"><div><div class="metric-label">Estimado mensual</div><div class="metric-value"><?= (int)$diagnostic['estimated_month_orders'] ?></div></div></article>
  <article class="metric-card"><div><div class="metric-label">Recomendación</div><div class="metric-value"><?= View::e($diagnostic['recommended_mode']==='weekly'?'Semanal':$diagnostic['recommended_parts'].' partes') ?></div></div></article>
</section>
<section class="panel table-panel">
  <header class="panel-head"><h2>Muestras tomadas</h2></header>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Fecha</th><th>Día semana</th><th>Órdenes encontradas</th></tr></thead><tbody>
    <?php foreach (($diagnostic['sample'] ?? []) as $sample): ?><tr><td><?= View::e($sample['date']) ?></td><td><?= (int)$sample['weekday'] ?></td><td><?= (int)$sample['orders'] ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<div class="page-actions mt-2"><a class="btn primary" href="<?= View::e($base) ?>/sync/account?id=<?= (int)$accountId ?>&year=<?= (int)$year ?>&month=<?= (int)$month ?>">Usar recomendación en planificación</a></div>
<?php else: ?>
<div class="alert warning">Aún no hay diagnóstico para esta cuenta y mes.</div>
<?php endif; ?>
