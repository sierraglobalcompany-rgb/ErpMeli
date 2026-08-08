<?php
use App\Core\Env;
use App\Core\View;
use App\Services\AppSettingsService;
use App\Services\DateTimePresenter;
use App\Services\ContextHelpPresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$automationTab = 'queue';
require __DIR__ . '/_automation_nav.php';
$rows = $queue['rows'] ?? [];
$currentPage = max(1, (int) ($queue['page'] ?? 1));
$perPage = max(1, (int) ($queue['per_page'] ?? 50));
$totalRows = max(0, (int) ($queue['total'] ?? 0));
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$pageQuery = $filters;
$settings = new AppSettingsService();
$attentionHoverDelay = min(10000, max(500, $settings->int('automation.attention_tooltip_hover_delay_ms', 2000)));
$attentionFocusDelay = min(2000, max(100, $settings->int('automation.attention_tooltip_focus_delay_ms', 300)));
?>
<div class="page-head">
  <div><span class="eyebrow">Automatización</span><h1>Cola completa <?= ContextHelpPresenter::button('automation.queue') ?></h1><p>Todo lo pendiente, en ejecución, aplazado o con error, ordenado por prioridad comercial y antigüedad.</p></div>
  <div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/settings/cron/next">Ver próxima ejecución</a></div>
</div>

<nav class="automation-view-tabs" aria-label="Grupos de la cola">
  <?php foreach ([
    'running' => 'En ejecución',
    'attention' => 'Necesitan intervención',
    'waiting' => 'Esperando',
    'upcoming' => 'Próximos',
    'completed' => 'Completados recientes',
    'all' => 'Todos',
  ] as $groupKey => $groupLabel): ?>
    <a class="<?= ($filters['group'] ?? 'attention') === $groupKey ? 'active' : '' ?>"
       href="<?= View::e($base . '/settings/cron/queue?' . http_build_query(['group' => $groupKey])) ?>">
      <?= View::e($groupLabel) ?>
    </a>
  <?php endforeach; ?>
</nav>

<section class="panel filter-bar">
  <form method="get" action="<?= View::e($base) ?>/settings/cron/queue" class="automation-filters">
    <input type="hidden" name="group" value="<?= View::e((string) ($filters['group'] ?? 'attention')) ?>">
    <?php if (!empty($filters['resolution'])): ?><input type="hidden" name="resolution" value="<?= View::e((string) $filters['resolution']) ?>"><?php endif; ?>
    <div class="field"><label for="queue-account">Cuenta</label><select id="queue-account" name="account_id" class="input"><option value="">Todas</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) $filters['account_id'] === (int) $account['id'] ? 'selected' : '' ?>><?= View::e($account['account_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="queue-type">Trabajo</label><select id="queue-type" name="type" class="input"><option value="">Todos</option><?php foreach ($definitions as $key => $definition): ?><option value="<?= View::e($key) ?>" <?= $filters['queue_key'] === $key ? 'selected' : '' ?>><?= View::e($definition['label']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="queue-status">Estado</label><select id="queue-status" name="status" class="input"><option value="">Todos</option><?php foreach (['pending'=>'En espera','running'=>'Ejecutándose','waiting_budget'=>'Esperando presupuesto','retry'=>'Se reintentará','error'=>'Requiere atención','scheduled'=>'Programado','paused'=>'Pausado','completed'=>'Completado'] as $key=>$label): ?><option value="<?= $key ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="queue-mode">Uso de API</label><select id="queue-mode" name="mode" class="input"><option value="">Todos</option><option value="api" <?= $filters['mode']==='api'?'selected':'' ?>>Consulta Mercado Libre</option><option value="local" <?= $filters['mode']==='local'?'selected':'' ?>>Proceso local</option></select></div>
    <div class="field"><label for="queue-page-size">Resultados</label><select id="queue-page-size" name="per_page" class="input"><?php foreach ([25,50,100] as $size): ?><option value="<?= $size ?>" <?= (int)$filters['per_page']===$size?'selected':'' ?>><?= $size ?> por página</option><?php endforeach; ?></select></div>
    <button class="btn primary" type="submit">Filtrar</button>
  </form>
</section>

<?php if (!empty($filters['resolution'])): ?>
  <?php if (($filters['resolution'] ?? '') === 'legacy_needs_diagnosis'): ?>
    <section class="alert warning">
      <strong>Registros anteriores a la trazabilidad.</strong>
      Primero abra un trabajo y use <strong>Diagnosticar localmente</strong>. El ERP revisará solo evidencia guardada; no consultará Mercado Libre ni reintentará a ciegas. Después decidirá si el recurso es reintentable, incierto o una ausencia esperada.
      <a href="<?= View::e($base) ?>/settings/cron/attention">Cambiar grupo</a>
    </section>
  <?php else: ?>
    <div class="alert info">
      <strong>Lista preparada para una decisión exacta.</strong>
      Ningún botón de esta página modifica el grupo completo. Abra un trabajo para diagnosticarlo o resolverlo individualmente.
      <a href="<?= View::e($base) ?>/settings/cron/attention">Cambiar grupo</a>
    </div>
  <?php endif; ?>
<?php endif; ?>

<section class="panel">
  <header class="panel-head"><div><h2><?= number_format((int) ($queue['total'] ?? 0), 0, ',', '.') ?> trabajos</h2><p>Mostrando <?= count($rows) ?> de <?= number_format((int) ($queue['total'] ?? 0), 0, ',', '.') ?>. El total corresponde a la cola completa; la tabla se entrega por páginas.</p></div></header>
  <?php if (!$rows): ?>
    <div class="empty-state"><h3>No hay trabajos con estos filtros</h3><p>Esto no significa necesariamente que todas las colas estén vacías; revise Diagnóstico si una cola no pudo comprobarse.</p></div>
  <?php else: ?>
  <div class="table-scroll automation-table-wrap">
    <table class="data-table responsive-table automation-table">
      <caption>Página <?= (int) ($queue['page'] ?? 1) ?> de trabajos administrados por Cron</caption>
      <thead><tr><th>Orden</th><th>Trabajo</th><th>Contenido</th><th>Cuenta</th><th>Progreso</th><th>Creado</th><th>Próxima oportunidad</th><th>Estimación</th><th>Estado</th></tr></thead>
      <tbody><?php foreach ($rows as $index => $row): $attention=$row['attention']; ?>
        <tr>
          <td data-label="Orden"><?= (($queue['page']-1)*$queue['per_page'])+$index+1 ?></td>
          <td data-label="Trabajo"><strong><?= View::e($row['human_label']) ?></strong><small><?= !empty($row['is_api_task']) ? 'Consulta Mercado Libre' : 'Proceso local' ?></small></td>
          <td data-label="Contenido"><?= View::e($row['content_summary']) ?><small class="work-inline-reason"><?= View::e($attention['short_reason']) ?></small></td>
          <td data-label="Cuenta"><?= View::e($row['account_name'] ?: 'Todas') ?></td>
          <td data-label="Progreso"><span><?= (int)$row['progress_current'] ?> / <?= (int)$row['progress_total'] ?></span><progress max="<?= max(1,(int)$row['progress_total']) ?>" value="<?= (int)$row['progress_current'] ?>"></progress></td>
          <td data-label="Creado"><?= $row['created_at_source'] ? View::e(DateTimePresenter::formatQueue($row['created_at_source'],'d/m H:i')) : '—' ?></td>
          <td data-label="Próxima oportunidad"><?= $row['next_eligible_at'] ? View::e(DateTimePresenter::formatQueue($row['next_eligible_at'],'d/m H:i')) : 'Ahora' ?></td>
          <td data-label="Estimación"><?= View::e($row['estimate']['label']) ?></td>
          <td data-label="Estado">
            <a
              class="badge work-attention-link <?= View::e($attention['tone']) ?>"
              href="<?= View::e((string) $attention['detail_url']) ?>"
              data-work-attention
              data-attention-title="<?= View::e($attention['label']) ?>"
              data-attention-text="<?= View::e($attention['tooltip']) ?>"
              data-hover-delay="<?= $attentionHoverDelay ?>"
              data-focus-delay="<?= $attentionFocusDelay ?>"
            ><?= View::e($attention['icon'].' '.$attention['label']) ?></a>
            <small><?= View::e($attention['recommended_action']) ?></small>
          </td>
        </tr>
      <?php endforeach; ?></tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Páginas de la cola">
      <?php if ($currentPage > 1): $pageQuery['page'] = $currentPage - 1; ?>
        <a class="btn" rel="prev" href="<?= View::e($base . '/settings/cron/queue?' . http_build_query($pageQuery)) ?>">Anterior</a>
      <?php endif; ?>
      <span>Página <?= $currentPage ?> de <?= $totalPages ?></span>
      <?php if ($currentPage < $totalPages): $pageQuery['page'] = $currentPage + 1; ?>
        <a class="btn" rel="next" href="<?= View::e($base . '/settings/cron/queue?' . http_build_query($pageQuery)) ?>">Siguiente</a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
</section>
