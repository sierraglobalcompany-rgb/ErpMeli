<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
$base = rtrim(Env::get('APP_URL', ''), '/');
$accountId = (int) $account['id']; $companyId = (int) $account['company_id']; $active = 'issues';
$groups = $run ? [
 ['key'=>'missing_remote','label'=>'Faltan en el ERP','count'=>(int)$run['missing_total'],'help'=>'Mercado Libre incluyó estas ventas, pero todavía no existe una copia local.'],
 ['key'=>'shifted_date','label'=>'Fecha diferente','count'=>(int)$run['shifted_total'],'help'=>'La venta existe, pero quedó asociada a otro día.'],
 ['key'=>'missing_normalized_date','label'=>'Sin fecha normalizada','count'=>(int)$run['missing_normalized_total'],'help'=>'La venta existe, pero falta calcular su fecha de control.'],
 ['key'=>'other_account','label'=>'Otra cuenta','count'=>(int)$run['other_account_total'],'help'=>'El ID existe en otra cuenta; se mantiene separado para evitar cruces.'],
 ['key'=>'extra_local','label'=>'Solo existe localmente','count'=>(int)$run['extra_total'],'help'=>'El ERP conserva la venta, pero la búsqueda remota no la incluyó.'],
] : [];
?>
<header class="sales-control-header compact"><div><a class="muted" href="<?= View::e($base) ?>/sales-control/month?account_id=<?= $accountId ?>&year=<?= (int)$year ?>&month=<?= (int)$month ?>">← Volver al mes</a><div class="eyebrow">Evidencia avanzada</div><h1>Diferencias de <?= View::e($monthRow['name']) ?></h1><p>Primero se muestra la causa y la solución; la tabla individual queda como detalle.</p></div></header>
<?php if (!$run): ?>
<section class="empty-state"><h2>Primero debe verificar el mes</h2><p>Sin una verificación exacta no es seguro descargar ni corregir ventas.</p><a class="btn primary" href="<?= View::e($base) ?>/sales-control/month?account_id=<?= $accountId ?>&year=<?= (int)$year ?>&month=<?= (int)$month ?>">Crear verificación</a></section>
<?php else: ?>
<section class="issue-groups">
<?php foreach ($groups as $group): if ($group['count'] <= 0) continue; ?>
 <article><div><h2><?= View::e($group['label']) ?> <span><?= (int)$group['count'] ?></span></h2><p><?= View::e($group['help']) ?></p></div>
 <a class="btn small" href="<?= View::e($base) ?>/sales-control/issues?account_id=<?= $accountId ?>&year=<?= (int)$year ?>&month=<?= (int)$month ?>&classification=<?= View::e($group['key']) ?>">Ver registros</a></article>
<?php endforeach; ?>
<?php if (array_sum(array_column($groups,'count')) === 0): ?><p class="success-note">No se encontraron diferencias de ventas.</p><?php endif; ?>
</section>
<?php if ((int)$run['missing_total'] > 0): ?>
<section class="sales-conclusion"><div><h2>Descargar ventas faltantes</h2><p>Se usarán únicamente los IDs confirmados por esta comprobación. La acción no modifica Mercado Libre.</p></div>
<form method="post" action="<?= View::e($base) ?>/sales-control/repair-missing"><input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="run_id" value="<?= (int)$run['id'] ?>"><input type="hidden" name="company_id" value="<?= $companyId ?>"><input type="hidden" name="account_id" value="<?= $accountId ?>"><input type="hidden" name="year" value="<?= (int)$year ?>"><input type="hidden" name="month" value="<?= (int)$month ?>"><button class="btn primary" type="submit">Preparar descarga segura</button></form></section>
<?php endif; ?>
<?php if (!empty($run['differences'])): ?><div class="table-scroll"><table><caption>Registros de la diferencia seleccionada</caption><thead><tr><th>Venta</th><th>Clasificación</th><th>Fecha remota</th><th>Explicación</th></tr></thead><tbody>
<?php foreach ($run['differences'] as $row): ?><tr><td><?= View::e($row['external_order_id']) ?></td><td><?= View::e($row['classification']) ?></td><td><?= View::e($row['remote_date_created'] ?: 'No disponible') ?></td><td><?= View::e($row['safe_explanation']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php endif; ?>

