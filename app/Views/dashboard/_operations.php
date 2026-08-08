<?php

use App\Core\Env;
use App\Core\View;
use App\Services\UiLabelPresenter;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$syncs = $operations['syncs'] ?? [];
$auditIssues = $operations['audit_issues'] ?? [];
$pendingQuestions = (int) ($operations['pending_questions'] ?? 0);
?>
<?php if ($pendingQuestions > 0): ?><section class="alert danger">Hay <?= $pendingQuestions ?> preguntas pendientes. <a class="link" href="<?= View::e($base) ?>/questions">Revisar preguntas</a>.</section><?php endif; ?>
<?php if ($auditIssues): ?><section class="alert warning">Hay auditorías de ventas con diferencias. <a class="link" href="<?= View::e($base) ?>/sync/audit">Ver auditoría</a>.</section><?php endif; ?>
<section class="dashboard-grid dashboard-section-grid">
  <article class="panel sync-panel">
    <header class="panel-head"><h2>Sincronizaciones recientes</h2><a class="btn small" href="<?= View::e($base) ?>/sync">Ver cola</a></header>
    <div class="sync-list">
      <?php if (!$syncs): ?><div class="empty">Aún no hay sincronizaciones.</div><?php endif; ?>
      <?php foreach ($syncs as $sync): ?><div class="sync-row"><span class="sync-status" aria-hidden="true">✓</span><div><strong><?= View::e($sync['account_name'] ?? 'Cuenta') ?></strong><small><?= View::e(UiLabelPresenter::syncType($sync['sync_type'] ?? null)) ?> · <?= (int) $sync['processed_count'] ?> registros</small></div><time class="sync-time"><?= View::e(substr((string) $sync['started_at'], 11, 5)) ?></time></div><?php endforeach; ?>
    </div>
  </article>
  <article class="panel">
    <header class="panel-head"><h2>Auditorías de ventas</h2><a class="link" href="<?= View::e($base) ?>/sync/audit">Ver auditoría</a></header>
    <div class="dashboard-operation-list" aria-label="Auditorías con diferencias">
      <?php if (!$auditIssues): ?><div class="dashboard-operation-row"><span class="badge green">Sin diferencias recientes</span><span>Las últimas auditorías no reportan faltantes.</span></div><?php endif; ?>
      <?php foreach ($auditIssues as $audit): ?><div class="dashboard-operation-row"><span class="badge amber"><?= View::e(UiLabelPresenter::auditDifference((int) $audit['difference_count'])) ?></span><span><?= View::e(($audit['account_name'] ?: 'Cuenta') . ' · ' . $audit['period_year'] . '-' . str_pad((string) $audit['period_month'], 2, '0', STR_PAD_LEFT)) ?></span></div><?php endforeach; ?>
    </div>
  </article>
</section>
