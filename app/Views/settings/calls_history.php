<?php
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;
$base=rtrim(Env::get('APP_URL',''),'/');
$automationTab='history'; require __DIR__.'/_automation_nav.php';
?>
<div class="page-head"><div><p class="eyebrow">AUTOMATIZACIÓN V4</p><h1>Historial actual</h1><p>Las filas son registros de progreso. La capacidad se controla en llamadas HTTP físicas.</p></div></div>
<p><a class="btn" href="<?= View::e($base) ?>/settings/cron/history?archive=legacy">Abrir archivo histórico V2/V3</a></p>
<section class="panel"><div class="panel-body">
<p class="alert info">Evidencia parcial: este historial correlaciona sólo llamadas del worker V4. No suma OAuth, auditoría ni reparación como si fueran el mismo registro. El total físico del ciclo es UNKNOWN cuando no puede reconstruirse.</p>
<div class="table-scroll"><table class="data-table"><thead><tr><th>Inicio</th><th>Origen</th><th>Estado</th><th>HTTP worker</th><th>Enviadas sin respuesta conocida</th><th>Total ciclo</th><th>Recursos</th></tr></thead><tbody>
<?php foreach ($history['runs'] as $run): ?>
<tr><td><?= View::e(DateTimePresenter::formatQueue($run['started_at'])) ?></td><td><?= View::e($run['launcher']) ?></td><td><?= View::e($run['status']) ?></td><td><?= (int)$run['known_worker_http_calls'] ?></td><td><?= (int)$run['uncertain_worker_http_calls'] ?></td><td>UNKNOWN</td><td><?= (int)$run['jobs_completed'] ?> completados · <?= (int)$run['jobs_deferred'] ?> aplazados</td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($history['runs']===[]): ?><p>No hay registros actuales V4. Esto no prueba que el Cron esté apagado.</p><?php endif; ?>
<nav class="pagination" aria-label="Historial V4">
<?php if ($page>1): ?><a class="btn" href="?page=<?= $page-1 ?>">Anterior</a><?php endif; ?>
<span>Página <?= $page ?></span>
<?php if ($page*$perPage<$history['total']): ?><a class="btn" href="?page=<?= $page+1 ?>">Siguiente</a><?php endif; ?>
</nav></div></section>
