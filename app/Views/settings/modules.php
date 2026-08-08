<?php
use App\Core\Csrf;
use App\Core\View;
use App\Services\UiLabelPresenter;
$base = rtrim(\App\Core\Env::get('APP_URL', ''), '/');
$stateLabels = [
  'discovered' => 'Detectado',
  'installed' => 'Instalado',
  'disabled' => 'Deshabilitado',
  'enabled' => 'Habilitado',
  'migration_required' => 'Requiere instalación',
  'degraded' => 'Requiere revisión',
  'incompatible' => 'No compatible',
  'failed' => 'Con error',
];
?>
<header class="page-header">
  <div><p class="eyebrow">ADMINISTRACIÓN</p><h1>Módulos Mercado Libre</h1><p>Nuevas capacidades aisladas. Puede detener un módulo sin afectar el ERP principal.</p></div>
</header>
<section class="operation-explainer" aria-label="Cómo administrar módulos">
  <div><span>Qué está pasando</span><strong>El ERP y cada módulo se comprueban por separado.</strong></div>
  <div><span>Qué hará el ERP</span><strong>Un módulo detenido no afectará las funciones principales.</strong></div>
  <div><span>Qué puede hacer ahora</span><strong>Revise solo las tarjetas marcadas como “Requiere revisión”.</strong></div>
</section>
<section class="card module-queue-health"
         aria-labelledby="module-queue-title"
         data-module-queue-url="<?= View::e($base) ?>/settings/modules/jobs/status.json">
  <div class="card-header">
    <div>
      <h2 id="module-queue-title">Integridad de trabajos modulares</h2>
      <p data-module-queue-recommendation><?= View::e((string) ($queueHealth['recommendation'] ?? 'Comprobando trabajos después de mostrar los módulos…')) ?></p>
    </div>
    <?php $queueNeedsAttention = (int)($queueHealth['duplicate_groups'] ?? 0) > 0 || (int)($queueHealth['expired_leases'] ?? 0) > 0 || (int)($queueHealth['orphan_events'] ?? 0) > 0; ?>
    <span class="badge <?= !empty($queueHealth['available']) && !$queueNeedsAttention ? 'success' : 'warning' ?>" data-module-queue-badge>
      <?= !empty($queueHealth['available']) ? ($queueNeedsAttention ? 'Necesita atención' : 'Comprobado') : 'Comprobando' ?>
    </span>
  </div>
  <div class="module-queue-health__metrics" aria-label="Resumen de la cola modular">
    <span><strong data-module-queue-value="active_jobs">—</strong> trabajos activos</span>
    <span><strong data-module-queue-value="duplicate_groups">—</strong> grupos duplicados</span>
    <span><strong data-module-queue-value="expired_leases">—</strong> reservas vencidas</span>
    <span><strong data-module-queue-value="orphan_events">—</strong> eventos sin trabajo</span>
  </div>
    <form method="post" action="<?= View::e($base) ?>/settings/modules/events/reconcile"
          data-module-reconcile-form hidden>
      <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
      <button class="btn btn-secondary" type="submit">Reconciliar eventos locales</button>
    </form>
</section>
<div class="module-admin-grid">
<?php foreach ($modules as $module): ?>
  <article class="card module-admin-card">
    <div class="card-header">
      <div><h2><?= View::e($module['label']) ?></h2><p><?= View::e($module['id']) ?> · v<?= View::e($module['version']) ?></p></div>
      <span class="badge <?= $module['enabled'] ? 'success' : ((string)$module['status'] === 'migration_required' ? 'warning' : 'neutral') ?>"><?= $module['enabled'] ? 'Habilitado' : View::e($stateLabels[(string)$module['status']] ?? UiLabelPresenter::status((string)$module['status'])) ?></span>
    </div>
    <?php if (empty($module['runtime_ready'])): ?><div class="alert warning">Esperando completar la actualización del sistema. Este módulo no cargará rutas, trabajos ni eventos.</div>
    <?php elseif ((int)$module['pending_migrations'] > 0): ?><div class="alert warning">Falta completar la instalación de este módulo. Sus pantallas, trabajos y eventos permanecerán detenidos de forma segura.</div><?php endif; ?>
    <?php if (!$module['rollout_allowed']): ?><div class="alert info"><strong>Próximamente.</strong> La actualización central puede estar completa; este proveedor permanece aislado hasta que su propia instalación esté disponible.</div><?php endif; ?>
    <div class="module-admin-card__facts">
      <span><strong><?= (int)$module['pending_migrations'] ?></strong> migraciones pendientes</span>
      <span><strong><?= $module['compatible'] ? 'Sí' : 'No' ?></strong> compatible</span>
    </div>
    <?php if ($module['last_error_message']): ?><div class="alert warning"><strong>Qué pasó:</strong> <?= View::e(UiLabelPresenter::safeOperationMessage((string) $module['last_error_message'], isset($module['diagnostic_id']) ? (string) $module['diagnostic_id'] : null)) ?></div><?php endif; ?>
    <div class="button-row">
      <?php if (empty($module['runtime_ready'])): ?>
      <button class="btn btn-secondary" type="button" disabled>Actualización central pendiente</button>
      <?php elseif (!$module['rollout_allowed']): ?>
      <button class="btn btn-secondary" type="button" disabled>No disponible todavía</button>
      <?php elseif ((int)$module['pending_migrations'] > 0): ?>
      <form method="post" action="<?= View::e($base) ?>/settings/modules/migrate"><input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="module_id" value="<?= View::e($module['id']) ?>"><button class="btn btn-primary" type="submit">Instalar migraciones</button></form>
      <?php elseif ($module['enabled']): ?>
      <form method="post" action="<?= View::e($base) ?>/settings/modules/disable"><input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="module_id" value="<?= View::e($module['id']) ?>"><button class="btn btn-secondary" type="submit">Deshabilitar</button></form>
      <?php else: ?>
      <form method="post" action="<?= View::e($base) ?>/settings/modules/enable"><input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="module_id" value="<?= View::e($module['id']) ?>"><button class="btn btn-primary" type="submit">Habilitar</button></form>
      <?php endif; ?>
      <form method="post" action="<?= View::e($base) ?>/settings/modules/diagnose"><input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="module_id" value="<?= View::e($module['id']) ?>"><button class="btn btn-tertiary" type="submit">Diagnosticar</button></form>
    </div>
  </article>
<?php endforeach; ?>
</div>
<style>.module-queue-health{margin-bottom:1rem}.module-queue-health__metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem;margin:1rem 0}.module-queue-health__metrics span{padding:.8rem;border:1px solid #dbe4ef;border-radius:.65rem;color:#64748b}.module-queue-health__metrics strong{display:block;color:#0f2748;font-size:1.25rem}.module-admin-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,330px),1fr));gap:1rem}.module-admin-card{min-width:0}.module-admin-card__facts{display:flex;gap:1rem;padding:1rem 0;color:#64748b}.module-admin-card__facts strong{color:#0f2748}.module-admin-card .button-row{display:flex;flex-wrap:wrap;gap:.5rem}@media(max-width:720px){.module-queue-health__metrics{grid-template-columns:1fr 1fr}}</style>
