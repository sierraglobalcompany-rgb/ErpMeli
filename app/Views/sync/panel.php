<?php

use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;
use App\Services\UiLabelPresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$panel = (string) ($panel ?? 'summary');
$accountId = (int) ($accountId ?? 0);
$year = (int) ($year ?? date('Y'));
$statusLabel = static fn(string $s): string => UiLabelPresenter::status($s);
$nowLocal = (new DateTimeImmutable('now', new DateTimeZone(DateTimePresenter::timezone())))->format('Y-m-d H:i');
$next = ($globalStatus['upcoming'] ?? [])[0] ?? null;

if ($panel === 'summary'):
?>
<section class="panel sync-monitor">
  <header class="panel-head">
    <div><h2>Resumen operativo</h2><p>Estado ligero de sincronización. No consulta Mercado Libre mientras carga esta pantalla.</p></div>
    <span class="badge neutral">Automático</span>
  </header>
  <div class="panel-body">
    <section class="operation-explainer" aria-label="Estado de sincronización">
      <div><span>Qué está pasando</span><strong><?= View::e((string) ($cron['label'] ?? 'Comprobando el lanzador único')) ?></strong></div>
      <div><span>Autoridad</span><strong>Procesamiento automático certificado; la acción manual sólo ejecuta un paso de navegador</strong></div>
      <div><span>Hora local ERP</span><strong><?= View::e($nowLocal) ?></strong></div>
    </section>
    <?php if ($accountId <= 0): ?>
      <div class="alert warning mt-2">Conecte o seleccione una cuenta Mercado Libre para ver progreso por cuenta.</div>
    <?php else: ?>
      <div class="progress-wrap mt-2"><div class="progress-bar"><span style="width:<?= (float)($globalStatus['percent'] ?? 0) ?>%"></span></div><strong><?= number_format((float)($globalStatus['percent'] ?? 0), 1, ',', '.') ?>%</strong></div>
      <div class="mini-grid mt-2">
        <div class="mini-card"><span>Bloques completos</span><strong><?= (int)($globalStatus['chunks_complete'] ?? 0) ?> / <?= (int)($globalStatus['chunks_total'] ?? 0) ?></strong></div>
        <div class="mini-card"><span>Órdenes procesadas</span><strong><?= (int)($globalStatus['processed'] ?? 0) ?> / <?= (int)($globalStatus['estimated'] ?? 0) ?></strong></div>
        <div class="mini-card"><span>En ejecución</span><strong><?= (int)($globalStatus['chunks_running'] ?? 0) ?></strong></div>
        <div class="mini-card"><span>Atrasados/listos</span><strong><?= (int)($globalStatus['chunks_overdue'] ?? 0) ?></strong></div>
        <div class="mini-card"><span>Con error</span><strong><?= (int)($globalStatus['chunks_error'] ?? 0) ?></strong></div>
      </div>
      <?php if (!empty($globalStatus['latest_error'])): $err = $globalStatus['latest_error']; ?>
        <div class="alert danger mt-2" role="alert"><strong>Qué pasó:</strong> <?= View::e(UiLabelPresenter::safeOperationMessage((string) ($err['last_error'] ?? ''), isset($err['diagnostic_id']) ? (string) $err['diagnostic_id'] : null)) ?></div>
      <?php endif; ?>
    <?php endif; ?>
    <div class="page-actions mt-2">
      <a class="btn" href="<?= View::e($base) ?>/settings/cron">Ver automatización</a>
    </div>
  </div>
</section>
<?php elseif ($panel === 'queue'): ?>
<section class="panel sync-monitor">
  <header class="panel-head"><div><h2>Cola de sincronización</h2><p>Agenda operativa y próximos bloques elegibles.</p></div><a class="link" href="<?= View::e($base) ?>/sync/schedule?account_id=<?= $accountId ?>">Ver agenda completa</a></header>
  <div class="panel-body">
    <div class="quick-sync-status">
      <span><strong>Zona horaria:</strong> <?= View::e(DateTimePresenter::timezone()) ?></span>
      <span><strong>Cron:</strong> <?= View::e((string) ($cron['label'] ?? 'Sin señales')) ?></span>
      <span><strong>Próxima ejecución:</strong> <?= View::e($next ? DateTimePresenter::formatQueue($next['next_run_at']) : 'Sin bloques') ?></span>
    </div>
    <h3 class="mt-2">Bloques atrasados/listos</h3>
    <div class="table-scroll"><table class="data-table"><tbody>
      <?php foreach ((array) ($overdue ?? []) as $item): ?><tr><td><?= View::e((string) $item['account_name']) ?></td><td><?= View::e(substr((string) $item['date_from'], 0, 10) . ' a ' . substr((string) $item['date_to'], 0, 10)) ?></td><td><?= View::e(DateTimePresenter::formatQueue($item['next_run_at'])) ?></td><td><span class="badge amber"><?= View::e($statusLabel((string) $item['status'])) ?></span></td></tr><?php endforeach; ?>
      <?php if (empty($overdue)): ?><tr><td><div class="empty">No hay bloques vencidos para esta cuenta.</div></td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
</section>
<?php elseif ($panel === 'finance'): ?>
<section class="panel sync-monitor">
  <header class="panel-head"><div><h2>Cola financiera</h2><p>Conciliación y recálculo financiero son independientes de la cola de ventas.</p></div><a class="link" href="<?= View::e($base) ?>/financial-recalc?account_id=<?= $accountId ?>">Ver detalle</a></header>
  <div class="panel-body">
    <?php $financialPercent = (float)($financialQueue['percent'] ?? 0); ?>
    <div class="progress-wrap"><div class="progress-bar"><span style="width:<?= $financialPercent ?>%"></span></div><strong><?= number_format($financialPercent, 1, ',', '.') ?>%</strong></div>
    <div class="mini-grid mt-2">
      <div class="mini-card"><span>Jobs pendientes</span><strong><?= (int)($financialQueue['pending_jobs'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Ejecutando</span><strong><?= (int)($financialQueue['running_jobs'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Órdenes recalculadas</span><strong><?= (int)($financialQueue['complete_items'] ?? 0) ?> / <?= (int)($financialQueue['total_items'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Con error</span><strong><?= (int)($financialQueue['failed_items'] ?? 0) ?></strong></div>
    </div>
    <div class="page-actions mt-2"><a class="btn" href="<?= View::e($base) ?>/settings/manual-processing?scope=finance">Revisar finanzas en procesamiento manual</a><a class="btn" href="<?= View::e($base) ?>/financial-recalc/errors?account_id=<?= $accountId ?>">Ver errores</a></div>
    <?php if (!empty($financialJobs)): ?>
      <h3 class="mt-2">Últimos jobs financieros</h3>
      <div class="table-scroll"><table class="data-table"><tbody>
      <?php foreach ($financialJobs as $job): ?><tr><td>#<?= (int)$job['id'] ?></td><td><?= View::e((string) ($job['account_name'] ?: 'Todas / local')) ?></td><td><?= (int)$job['processed_items'] ?> / <?= (int)$job['total_items'] ?></td><td><span class="badge <?= $job['status'] === 'complete' ? 'green' : ($job['status'] === 'error' ? 'red' : 'amber') ?>"><?= View::e($statusLabel((string)$job['status'])) ?></span></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </div>
</section>
<?php elseif ($panel === 'months'): ?>
<section class="panel table-panel">
  <header class="panel-head"><h2>Meses de <?= $year ?></h2></header>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Mes</th><th>Rango</th><th>Estado</th><th>Bloques</th><th>Órdenes procesadas</th><th>Acciones</th></tr></thead>
    <tbody>
    <?php foreach ((array) ($months ?? []) as $month): ?><tr><td><strong><?= View::e((string) $month['label']) ?></strong></td><td><?= View::e((string) $month['from'] . ' a ' . (string) $month['to']) ?></td><td><span class="badge <?= $month['status'] === 'complete' ? 'green' : ($month['status'] === 'error' ? 'red' : 'amber') ?>"><?= View::e($statusLabel((string) $month['status'])) ?></span></td><td><?= (int) $month['complete_chunks'] ?> / <?= (int) $month['total_chunks'] ?></td><td><?= (int) $month['processed_count'] ?></td><td><a class="btn small" href="<?= View::e($base) ?>/sync/account?id=<?= $accountId ?>&amp;year=<?= $year ?>&amp;month=<?= (int) $month['month'] ?>">Abrir</a></td></tr><?php endforeach; ?>
    <?php if (empty($months)): ?><tr><td colspan="6"><div class="empty">No hay calendario mensual para esta selección.</div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
<?php else: ?>
<section class="panel table-panel">
  <header class="panel-head"><h2>Ejecuciones recientes</h2></header>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Rango</th><th>Estado</th><th>Procesadas</th><th>Offset</th><th>Inicio</th><th>Error</th></tr></thead><tbody>
    <?php if (empty($runs)): ?><tr><td colspan="6"><div class="empty">Aún no hay ejecuciones de cola para esta cuenta.</div></td></tr><?php endif; ?>
    <?php foreach ((array) ($runs ?? []) as $run): ?><tr><td><?= View::e(substr((string) $run['date_from'], 0, 10) . ' a ' . substr((string) $run['date_to'], 0, 10)) ?></td><td><?= View::e($statusLabel((string) $run['status'])) ?></td><td><?= (int)$run['processed_count'] ?></td><td><?= (int)$run['cursor_offset_before'] ?> → <?= (int)$run['cursor_offset_after'] ?></td><td><?= View::e(DateTimePresenter::formatQueue($run['started_at'])) ?></td><td><?= View::e(!empty($run['error_message']) ? UiLabelPresenter::safeOperationMessage((string) $run['error_message'], isset($run['diagnostic_id']) ? (string) $run['diagnostic_id'] : null) : '—') ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif; ?>
