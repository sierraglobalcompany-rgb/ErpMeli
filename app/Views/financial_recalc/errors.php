<?php

use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
?>
<div class="page-head">
  <div>
    <a class="link" href="<?= View::e($base) ?>/financial-recalc">← Volver a procesamiento financiero</a>
    <h1>Errores de recálculo financiero</h1>
    <p>Detalle seguro de órdenes que no pudieron recalcularse.</p>
  </div>
</div>

<section class="panel table-panel">
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Recálculo</th><th>Cuenta</th><th>Orden</th><th>Error</th><th>Procesado</th><th>Acciones</th></tr></thead>
    <tbody>
    <?php if (!$errors): ?><tr><td colspan="6"><div class="empty">No hay errores financieros registrados.</div></td></tr><?php endif; ?>
    <?php foreach ($errors as $error): ?><tr>
      <td><a class="link" href="<?= View::e($base) ?>/financial-recalc/show?id=<?= (int) $error['order_financial_recalc_job_id'] ?>">#<?= (int) $error['order_financial_recalc_job_id'] ?></a></td>
      <td><?= View::e($error['account_name'] ?: '—') ?></td>
      <td><?= View::e($error['external_order_id']) ?></td>
      <td><?= View::e($error['error_message'] ?: 'Error no especificado') ?></td>
      <td><?= View::e(DateTimePresenter::formatQueue($error['processed_at'] ?? null)) ?></td>
      <td><a class="btn small" href="<?= View::e($base) ?>/orders/show?id=<?= (int) $error['meli_order_id'] ?>">Ver orden</a></td>
    </tr><?php endforeach; ?>
    </tbody>
  </table></div>
</section>
