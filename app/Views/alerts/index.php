<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\ContextHelpPresenter;
use App\Services\DateTimePresenter;
$base = rtrim((string) Env::get('APP_URL', ''), '/');
$critical = count(array_filter($alerts, static fn(array $alert): bool => $alert['severity'] === 'critical'));
?>
<div class="page-head">
  <div><h1>Alertas operativas</h1><p>Asuntos que pueden requerir una decisión. Los eventos normales permanecen en Actividad.</p></div>
  <div class="page-actions"><form method="post" action="<?= View::e($base) ?>/alerts/refresh"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><button class="btn primary" type="submit">Actualizar alertas</button><?= ContextHelpPresenter::button('alerts.refresh') ?></form></div>
</div>

<section class="status-hero <?= $critical > 0 ? 'red' : ($pageData['total'] > 0 ? 'amber' : 'green') ?>">
  <div class="status-hero-icon" aria-hidden="true"><?= $critical > 0 ? '!' : ($pageData['total'] > 0 ? '•' : '✓') ?></div>
  <div><div class="eyebrow">Conclusión</div><h2><?= $critical > 0 ? $critical . ' asuntos críticos visibles' : ($pageData['total'] > 0 ? 'Hay asuntos para revisar' : 'No hay alertas abiertas') ?></h2><p><?= $critical > 0 ? 'Abra primero las alertas críticas y confirme su impacto antes de actuar.' : 'Las alertas se agrupan por importancia y fecha más reciente.' ?></p></div>
</section>

<form method="post" action="<?= View::e($base) ?>/alerts/resolve-batch">
  <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
  <section class="panel table-panel">
    <header class="panel-head"><div><h2>Necesitan atención</h2><p class="muted"><?= (int)$pageData['total'] ?> abiertas · página <?= (int)$pageData['page'] ?> de <?= (int)$pageData['pages'] ?></p></div></header>
    <div class="bulk-action-bar"><span>Seleccione alertas que ya revisó.</span><button class="btn" type="submit">Marcar seleccionadas como revisadas</button></div>
    <div class="table-scroll"><table class="data-table responsive-table">
      <caption>Alertas operativas abiertas</caption>
      <thead><tr><th><span class="sr-only">Seleccionar</span></th><th>Riesgo</th><th>Asunto</th><th>Cuenta</th><th>Qué ocurrió</th><th>Última vez</th></tr></thead><tbody>
      <?php if(!$alerts):?><tr><td colspan="6"><div class="empty-state compact"><h3>Sin asuntos pendientes</h3><p>No hay alertas que requieran una decisión.</p></div></td></tr><?php endif;?>
      <?php foreach($alerts as $alert): $tone=$alert['severity']==='critical'?'red':($alert['severity']==='warning'?'amber':'blue'); ?>
        <tr>
          <td data-label="Seleccionar"><input type="checkbox" name="ids[]" value="<?= (int)$alert['id'] ?>" aria-label="Seleccionar <?= View::e($alert['title']) ?>"></td>
          <td data-label="Riesgo"><span class="badge <?= $tone ?>"><?= View::e($alert['severity']==='critical'?'Crítico':($alert['severity']==='warning'?'Atención':'Informativo')) ?></span></td>
          <td data-label="Asunto"><strong><?= View::e($alert['title']) ?></strong><small><?= View::e(str_replace('_',' ',(string)$alert['alert_type'])) ?></small></td>
          <td data-label="Cuenta"><?= View::e($alert['account_name'] ?: ($alert['company_name'] ?: 'General')) ?></td>
          <td data-label="Qué ocurrió"><?= View::e($alert['message']) ?></td>
          <td data-label="Última vez"><?= View::e(DateTimePresenter::formatQueue($alert['last_seen_at'])) ?></td>
        </tr>
      <?php endforeach;?>
      </tbody>
    </table></div>
  </section>
</form>
