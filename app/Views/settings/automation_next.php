<?php
use App\Core\Env;
use App\Core\View;
use App\Services\AppSettingsService;
use App\Services\ContextHelpPresenter;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$automationTab = 'next';
require __DIR__ . '/_automation_nav.php';
$eligibleExamples = $preview['eligible_examples'] ?? ($preview['selected'] ?? []);
$waiting = $preview['waiting'] ?? [];
$runtimeSelected = $preview['runtime_selected'] ?? [];
$campaignNotice = is_array($preview['campaign_notice'] ?? null) ? $preview['campaign_notice'] : null;
$settings = new AppSettingsService();
$attentionHoverDelay = min(10000, max(500, $settings->int('automation.attention_tooltip_hover_delay_ms', 2000)));
$attentionFocusDelay = min(2000, max(100, $settings->int('automation.attention_tooltip_focus_delay_ms', 300)));
?>
<div class="page-head">
  <div><span class="eyebrow">Automatización</span><h1>Próxima ejecución <?= ContextHelpPresenter::button('automation.preview') ?></h1><p>Simulación de lo que elegirá el siguiente ciclo; no reclama ni modifica trabajos.</p></div>
  <div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/settings/cron/queue">Ver toda la cola</a></div>
</div>

<section class="panel">
  <header class="panel-head"><div><h2>Selección real del próximo ciclo</h2><p>Usa el mismo orden de carriles que el lanzador CLI.</p></div></header>
  <div class="automation-sequence">
    <?php foreach ($runtimeSelected as $index => $task): ?>
      <article class="automation-sequence-item">
        <span class="sequence-number"><?= $index + 1 ?></span>
        <div>
          <strong><?= View::e((string) $task['label']) ?></strong>
          <p><?= View::e((string) $task['reason']) ?></p>
          <small><?= !empty($task['known']) ? (int) $task['work_count'] . ' trabajos detectados' : 'La cantidad se comprobará al ejecutar' ?></small>
        </div>
        <span class="badge <?= (string) $task['lane'] === 'directed' ? 'blue' : 'neutral' ?>">
          <?= View::e((string) $task['lane'] === 'directed' ? 'Turno garantizado' : (!empty($task['is_api']) ? 'Consulta API' : 'Trabajo local')) ?>
        </span>
      </article>
    <?php endforeach; ?>
    <?php if ($runtimeSelected === []): ?><div class="empty-state compact"><h3>No hay una tarea seleccionable</h3><p>Las colas están vacías, programadas o no pudieron comprobarse.</p></div><?php endif; ?>
  </div>
  <?php if ($campaignNotice): ?>
    <div class="operation-note mt-2">
      <strong><?= View::e((string) $campaignNotice['label']) ?></strong>
      <span><?= View::e((string) $campaignNotice['message']) ?><?= !empty($campaignNotice['next_at']) ? ' Próxima oportunidad: ' . View::e(DateTimePresenter::formatQueue($campaignNotice['next_at'], 'd/m H:i:s')) . '.' : '' ?></span>
    </div>
  <?php endif; ?>
</section>

<section class="panel mt-2">
  <header class="panel-head"><div><h2><?= $eligibleExamples ? 'Ejemplos de recursos listos' : (!empty($preview['all_checked']) ? 'Nada listo por ahora' : 'Falta comprobar parte de la automatización') ?></h2><p><?= $eligibleExamples ? 'Son recursos elegibles dentro de las funciones seleccionadas arriba. El lanzador confirma el recurso exacto al adquirirlo; esta lista no es una segunda selección.' : View::e((string) ($preview['reason'] ?? '')) ?></p></div></header>
  <div class="automation-sequence">
    <?php foreach ($eligibleExamples as $index => $item): ?>
      <article class="automation-sequence-item">
        <span class="sequence-number"><?= $index + 1 ?></span>
        <div>
          <strong><?= View::e($item['human_label']) ?></strong>
          <p><?= View::e($item['content_summary']) ?></p>
          <small><?= View::e($item['attention']['short_reason']) ?></small>
          <small><?= View::e($item['account_name'] ?: 'Todas las cuentas') ?> · <?= !empty($item['is_api_task']) ? 'Consulta Mercado Libre' : 'Proceso local' ?></small>
        </div>
        <div class="sequence-estimate">
          <strong><?= View::e($item['estimate']['label'] ?? 'Por estimar') ?></strong>
          <small><?= isset($item['estimated_api_calls']) ? (int) $item['estimated_api_calls'] . ' consultas estimadas' : 'Consultas por estimar' ?></small>
          <a class="work-secondary-link" href="<?= View::e((string) $item['attention']['detail_url']) ?>">Ver trabajo</a>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if (!$eligibleExamples): ?><div class="empty-state compact"><h3><?= !empty($preview['all_checked']) ? 'No hay trabajo listo' : 'La comprobación está incompleta' ?></h3><p><?= !empty($preview['all_checked']) ? 'Puede haber trabajos futuros, pausados o esperando presupuesto en la cola completa.' : 'Abra Diagnóstico para identificar las colas que no pudieron leerse.' ?></p></div><?php endif; ?>
  </div>
</section>

<?php if ($waiting): ?>
<section class="panel mt-2">
  <header class="panel-head"><div><h2>Deberán esperar</h2><p>Motivos de presupuesto, horario, error o límite del ciclo.</p></div></header>
  <div class="human-list">
    <?php foreach ($waiting as $item): ?>
      <?php $attention = $item['attention']; ?>
      <article class="human-list-item automation-waiting-item">
        <div>
          <strong><?= View::e($item['human_label']) ?></strong>
          <p><?= View::e($item['content_summary']) ?></p>
          <p class="work-inline-reason"><?= View::e($attention['short_reason']) ?></p>
          <small>
            <?= View::e($item['account_name'] ?: 'Todas las cuentas') ?>
            · Creado <?= !empty($item['created_at_source']) ? View::e(DateTimePresenter::formatQueue($item['created_at_source'], 'd/m/Y H:i')) : 'sin fecha disponible' ?>
            · <?= !empty($item['next_eligible_at']) ? 'próxima oportunidad ' . View::e(DateTimePresenter::formatQueue($item['next_eligible_at'], 'd/m/Y H:i')) : 'disponible ahora' ?>
          </small>
        </div>
        <div class="work-attention-actions">
          <a
            class="badge work-attention-link <?= View::e($attention['tone']) ?>"
            href="<?= View::e((string) $attention['detail_url']) ?>"
            data-work-attention
            data-attention-title="<?= View::e($attention['label']) ?>"
            data-attention-text="<?= View::e($attention['tooltip']) ?>"
            data-hover-delay="<?= $attentionHoverDelay ?>"
            data-focus-delay="<?= $attentionFocusDelay ?>"
          ><?= View::e($attention['icon'] . ' ' . ($attention['decision_reason'] === 'action_required' ? 'Ver qué ocurrió' : $attention['label'])) ?></a>
          <small><?= View::e($item['estimate']['label'] ?? 'Por estimar') ?> · <?= $item['estimated_api_calls'] !== null ? (int) $item['estimated_api_calls'] . ' consultas' : 'consultas por estimar' ?></small>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
