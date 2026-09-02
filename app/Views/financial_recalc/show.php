<?php

use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\UiLabelPresenter;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$statusLabel = static fn(string $s): string => ['pending'=>'Pendiente','running'=>'Ejecutando','complete'=>'Completo','error'=>'Error','cancelled'=>'Cancelado'][$s] ?? ucfirst($s);
$statusClass = static fn(string $s): string => ['pending'=>'amber','running'=>'blue','complete'=>'green','error'=>'red','cancelled'=>'muted'][$s] ?? '';
$jobDiagnosticId = isset($job['diagnostic_id']) ? (string) $job['diagnostic_id'] : (isset($job['last_error_diagnostic_id']) ? (string) $job['last_error_diagnostic_id'] : null);
?>
<div class="page-head">
  <div>
    <a class="link" href="<?= View::e($base) ?>/financial-recalc">← Volver a procesamiento financiero</a>
    <h1>Recálculo financiero #<?= (int) $job['id'] ?></h1>
    <p>Recálculo inteligente: local → detectar faltantes → billing → neto conciliado.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/financial-recalc/errors?job_id=<?= (int) $job['id'] ?>">Ver errores</a>
  </div>
</div>

<section class="metrics">
  <article class="metric-card"><div><div class="metric-label">Estado</div><div class="metric-value"><span class="badge <?= View::e($statusClass((string) $job['status'])) ?>"><?= View::e($statusLabel((string) $job['status'])) ?></span></div></div></article>
  <article class="metric-card"><div><div class="metric-label">Fase actual</div><div class="metric-value"><?= View::e(UiLabelPresenter::financialPhase($job['current_phase'] ?? 'local_recalc')) ?></div></div></article>
  <article class="metric-card"><div><div class="metric-label">Cuenta</div><div class="metric-value"><?= View::e($job['account_name'] ?: 'Todas / local') ?></div></div></article>
  <article class="metric-card"><div><div class="metric-label">Procesadas</div><div class="metric-value"><?= (int) $job['processed_items'] ?> / <?= (int) $job['total_items'] ?></div></div></article>
  <article class="metric-card"><div><div class="metric-label">Billing importado</div><div class="metric-value"><?= (int) ($job['billing_imported_items'] ?? 0) ?></div></div></article>
  <article class="metric-card"><div><div class="metric-label">Parciales</div><div class="metric-value"><?= (int) ($job['partial_items'] ?? 0) ?></div></div></article>
  <article class="metric-card"><div><div class="metric-label">Reconexiones DB</div><div class="metric-value"><?= (int) ($job['db_reconnect_count'] ?? 0) ?></div></div></article>
  <article class="metric-card"><div><div class="metric-label">Errores</div><div class="metric-value"><?= (int) $job['error_items'] ?></div></div></article>
</section>

<?php if (!empty($job['safe_message'])): ?><section class="alert info"><?= View::e($job['safe_message']) ?></section><?php endif; ?>
<?php if (!empty($job['last_db_error_message'] ?? null)): ?><section class="alert warning"><strong>Conexión local:</strong> <?= View::e(UiLabelPresenter::safeOperationMessage((string) $job['last_db_error_message'], $jobDiagnosticId)) ?></section><?php endif; ?>
<?php if (!empty($job['last_pause_reason'] ?? null)): ?><section class="alert warning"><strong>Espera:</strong> <?= View::e(UiLabelPresenter::safeOperationMessage((string) $job['last_pause_reason'], $jobDiagnosticId)) ?></section><?php endif; ?>
<?php if (!empty($job['last_error_message'] ?? null)): ?><section class="alert danger"><strong>Qué pasó:</strong> <?= View::e(UiLabelPresenter::safeOperationMessage((string) $job['last_error_message'], $jobDiagnosticId)) ?></section><?php endif; ?>

<section class="panel">
  <h2>Acciones</h2>
  <div class="page-actions">
    <a class="btn primary" href="<?= View::e($base) ?>/settings/manual-processing?scope=finance&amp;origin=financial&amp;account_id=<?= (int) ($job['meli_account_id'] ?? 0) ?>">Procesar finanzas ahora</a>
    <form method="post" action="<?= View::e($base) ?>/financial-recalc/retry-failed" data-confirm="Reintentará errores de este recálculo.">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
      <button class="btn">Reintentar errores</button>
    </form>
    <?php if (in_array((string) $job['status'], ['pending','running','error'], true)): ?>
    <form method="post" action="<?= View::e($base) ?>/financial-recalc/cancel" data-confirm="Cancelará este recálculo financiero sin borrar el historial.">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
      <button class="btn danger">Cancelar recálculo</button>
    </form>
    <?php endif; ?>
  </div>
</section>

<section class="panel table-panel mt-2">
  <header class="panel-head">
    <h2>Órdenes del recálculo</h2>
    <div class="page-actions">
      <a class="btn small" href="<?= View::e($base) ?>/financial-recalc/show?id=<?= (int) $job['id'] ?>">Todas</a>
      <a class="btn small" href="<?= View::e($base) ?>/financial-recalc/show?id=<?= (int) $job['id'] ?>&status=pending">Pendientes</a>
      <a class="btn small" href="<?= View::e($base) ?>/financial-recalc/show?id=<?= (int) $job['id'] ?>&status=error">Errores</a>
      <a class="btn small" href="<?= View::e($base) ?>/financial-recalc/show?id=<?= (int) $job['id'] ?>&status=complete">Completas</a>
    </div>
  </header>
  <div class="table-scroll"><table class="data-table" data-responsive="cards">
    <thead><tr><th>Orden</th><th>Estado</th><th>Fase</th><th>Faltantes</th><th>Billing</th><th>Financiero</th><th>Procesado</th><th>Diagnóstico/error</th><th>Acciones</th></tr></thead>
    <tbody>
    <?php if (!$items): ?><tr><td colspan="9"><div class="empty">No hay órdenes para este filtro.</div></td></tr><?php endif; ?>
    <?php foreach ($items as $item): $itemError = trim((string) ($item['error_message'] ?: ($item['diagnostic_message'] ?? ''))); ?><tr>
      <td><?= View::e($item['external_order_id'] ?: $item['order_external_id']) ?></td>
      <td><span class="badge <?= $item['status']==='complete'?'green':($item['status']==='error'?'red':'amber') ?>"><?= View::e(UiLabelPresenter::status($item['status'] ?? null)) ?></span></td>
      <td><?= View::e(UiLabelPresenter::financialPhase($item['phase'] ?? 'local_recalc')) ?></td>
      <td><?= View::e((string) ($item['missing_flags'] ?? '—')) ?></td>
      <td><?= View::e((string) ($item['billing_status'] ?? 'pending')) ?></td>
      <td><?= View::e((string) ($item['financial_status'] ?? 'pending')) ?></td>
      <td><?= View::e(DateTimePresenter::formatQueue($item['processed_at'] ?? null)) ?></td>
      <td><?= $itemError !== ''
          ? View::e(UiLabelPresenter::safeOperationMessage($itemError, isset($item['diagnostic_id']) ? (string) $item['diagnostic_id'] : null))
          : '—' ?></td>
      <td><a class="btn small" href="<?= View::e($base) ?>/orders/show?id=<?= (int) $item['meli_order_id'] ?>">Ver orden</a></td>
    </tr><?php endforeach; ?>
    </tbody>
  </table></div>
</section>
