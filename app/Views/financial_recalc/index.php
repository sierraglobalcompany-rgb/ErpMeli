<?php

use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\UiLabelPresenter;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$progressive = !empty($progressive);
if ($progressive) {
    $sectionUrl = $base . '/financial-recalc/section.json' . ($accountId > 0 ? '?account_id=' . (int) $accountId : '');
    ?>
    <section class="async-section" data-async-section="financial-recalc" data-url="<?= View::e($sectionUrl) ?>" aria-live="polite" aria-busy="true">
      <div data-async-skeleton class="panel async-skeleton is-visible">
        <div class="page-head"><div><h1>Procesamiento financiero</h1><p>El módulo ya abrió. Estamos agrupando recursos, avance y errores sin bloquear la navegación.</p></div></div>
        <div class="skeleton-table"><span></span><span></span><span></span><span></span></div>
      </div>
      <div data-async-status class="async-section-status" hidden></div>
      <div data-async-content></div>
    </section>
    <noscript><a class="btn primary" href="<?= View::e($base) ?>/financial-recalc?account_id=<?= (int) $accountId ?>&amp;full=1">Cargar estado completo</a></noscript>
    <?php
    return;
}
$statusLabel = static fn(string $s): string => UiLabelPresenter::status($s);
$statusClass = static fn(string $s): string => ['pending'=>'amber','running'=>'blue','complete'=>'green','error'=>'red','cancelled'=>'muted'][$s] ?? '';
$percent = (float) ($summary['percent'] ?? 0);
?>
<div class="page-head">
  <div>
    <a class="link" href="<?= View::e($base) ?>/sync?account_id=<?= (int) $accountId ?>">← Volver a sincronizaciones</a>
    <h1>Procesamiento financiero</h1>
    <p>Recálculos de conciliación financiera. Usa datos locales y completa billing automáticamente solo cuando faltan datos.</p>
  </div>
  <div class="page-actions">
    <a class="btn primary" href="<?= View::e($base) ?>/settings/manual-processing?scope=finance&amp;origin=financial<?= $accountId > 0 ? '&amp;account_id=' . (int) $accountId : '' ?>">Procesar ahora</a>
    <a class="btn" href="<?= View::e($base) ?>/financial-recalc/errors?account_id=<?= (int) $accountId ?>">Ver errores</a>
  </div>
</div>

<section class="panel sync-monitor" data-financial-recalc-monitor data-financial-status-url="<?= View::e($base) ?>/financial-recalc/status.json?account_id=<?= (int) $accountId ?>">
  <header class="panel-head"><div><h2>Estado del recálculo</h2><p><strong>Qué pasa:</strong> los números agrupan recursos financieros, aunque internamente existan varios pasos técnicos pequeños.</p></div></header>
  <div class="panel-body">
    <div class="progress-wrap"><div class="progress-bar"><span data-financial-progress-bar style="width:<?= $percent ?>%"></span></div><strong data-financial-progress-text><?= number_format($percent, 1, ',', '.') ?>%</strong></div>
    <div class="mini-grid mt-2">
      <div class="mini-card"><span>Grupos pendientes</span><strong data-financial-pending><?= (int) ($summary['pending_jobs'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Ejecutando</span><strong data-financial-running><?= (int) ($summary['running_jobs'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Órdenes procesadas</span><strong data-financial-processed><?= (int) ($summary['complete_items'] ?? 0) ?> / <?= (int) ($summary['total_items'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Pendientes</span><strong data-financial-active><?= (int) ($summary['active_items'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Con error</span><strong data-financial-errors><?= (int) ($summary['failed_items'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Billing requerido</span><strong><?= (int) ($summary['billing_needed_items'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Billing importado</span><strong><?= (int) ($summary['billing_imported_items'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Conciliadas</span><strong><?= (int) ($summary['matched_items'] ?? 0) ?></strong></div>
      <div class="mini-card"><span>Parciales</span><strong><?= (int) ($summary['partial_items'] ?? 0) ?></strong></div>
    </div>
    <div class="operation-next-step mt-2">
      <div><strong>Qué hará la automatización</strong><p>Atenderá sólo recursos financieros elegibles. Una espera preventiva no es un error ni garantiza una hora de ejecución.</p></div>
      <div class="page-actions">
      <a class="btn primary" href="<?= View::e($base) ?>/settings/manual-processing?scope=finance&amp;origin=financial<?= $accountId > 0 ? '&amp;account_id=' . (int) $accountId : '' ?>">Procesar finanzas ahora</a>
      <a class="btn" href="<?= View::e($base) ?>/financial-recalc/errors?account_id=<?= (int) $accountId ?>">Revisar recursos con error</a>
      <span class="muted" data-financial-process-status></span>
      </div>
    </div>
  </div>
</section>

<section class="panel table-panel mt-2">
  <header class="panel-head"><h2>Recálculos financieros recientes</h2></header>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>#</th><th>Estado</th><th>Fase</th><th>Cuenta</th><th>Rango</th><th>Modo</th><th>Origen</th><th>Avance</th><th>DB</th><th>Creado</th><th>Acciones</th></tr></thead>
    <tbody>
    <?php if (!$jobs): ?><tr><td colspan="11"><div class="empty">No hay recálculos financieros todavía.</div></td></tr><?php endif; ?>
    <?php foreach ($jobs as $job): ?><tr>
      <td>#<?= (int) $job['id'] ?></td>
      <td><span class="badge <?= View::e($statusClass((string) $job['status'])) ?>"><?= View::e($statusLabel((string) $job['status'])) ?></span></td>
      <td><?= View::e(UiLabelPresenter::financialPhase($job['current_phase'] ?? 'local_recalc')) ?></td>
      <td><?= View::e($job['account_name'] ?: 'Todas / local') ?></td>
      <td><?= View::e(substr((string) $job['date_from'], 0, 10) ?: '—') ?> → <?= View::e(substr((string) $job['date_to'], 0, 10) ?: '—') ?></td>
      <td><?= View::e((string) $job['mode']) ?></td>
      <td><?= View::e((string) $job['source_type']) ?></td>
      <td><?= (int) $job['processed_items'] ?> / <?= (int) $job['total_items'] ?> <?php if ((int) $job['error_items'] > 0): ?><span class="badge red"><?= (int) $job['error_items'] ?> errores</span><?php endif; ?></td>
      <td><?php if ((int) ($job['db_reconnect_count'] ?? 0) > 0): ?><span class="badge amber"><?= (int) $job['db_reconnect_count'] ?> recon.</span><?php else: ?>—<?php endif; ?></td>
      <td><?= View::e(DateTimePresenter::formatQueue($job['created_at'] ?? null)) ?></td>
      <td><a class="btn small" href="<?= View::e($base) ?>/financial-recalc/show?id=<?= (int) $job['id'] ?>">Ver detalle</a></td>
    </tr><?php endforeach; ?>
    </tbody>
  </table></div>
</section>
