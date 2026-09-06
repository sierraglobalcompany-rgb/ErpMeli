<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\CapacityPolicyService;

$base = rtrim(Env::get('APP_URL', ''), '/');
$scopeLabels = [
  'available_queue' => ['Pendientes disponibles ahora', 'Atiende directamente lo que el sistema tiene listo ahora.', (int) ($availableQueueCount ?? 0) . ' listos ahora'],
  'recommended' => ['Recomendado', 'Lo más seguro para avanzar ahora.'],
  'sales' => ['Ventas', 'Órdenes y notificaciones.'],
  'finance' => ['Finanzas', 'Conciliación y cálculo.'],
  'audits' => ['Auditorías', 'Revisión operativa.'],
  'products' => ['Productos', 'Publicaciones.'],
  'descriptions' => ['Descripciones', 'Contenido de publicaciones.'],
  'all' => ['Todo lo elegible', 'Todo lo elegible.'],
];
$scopeHelp = [
  'available_queue' => 'Atiende sólo pendientes listos ahora, con la misma elegibilidad que la automatización natural.',
  'recommended' => 'Muestra primero elementos elegibles, seguros y de bajo ruido para adelantar desde esta pestaña.',
  'sales' => 'Filtra pendientes manuales relacionados con órdenes, notificaciones y ventas.',
  'finance' => 'Filtra pendientes financieros que ya estén autorizados por su fuente de dominio.',
  'audits' => 'Filtra tareas de revisión operativa que pueden resolverse de forma acotada.',
  'products' => 'Filtra sincronización de publicaciones y datos de producto.',
  'descriptions' => 'Filtra descripciones; mantiene controles conservadores por ser una carga delicada.',
  'all' => 'Muestra todos los pendientes exactos elegibles dentro del contexto autorizado actual.',
];
$capacity = $capacity ?? (new CapacityPolicyService())->snapshot('manual');
$duration = static function (int $seconds): string {
  if ($seconds < 60) return $seconds . ' s';
  if ($seconds < 3600) return (int) ceil($seconds / 60) . ' min';
  $hours = $seconds / 3600;
  return number_format($hours, $hours < 10 ? 1 : 0, ',', '.') . ' h';
};
$exclusionPresenter = new \App\Services\ManualCampaignExclusionPresenter();
$exclusionGroups = [];
if (is_array($preview ?? null)) {
  foreach ((array) ($preview['excluded_summary'] ?? []) as $excludedGroup) {
    $exclusionGroups[] = $exclusionPresenter->group(
      (array) $excludedGroup,
      (string) ($preview['preview_token'] ?? '')
    );
  }
}
$configuredLimit = (int) $capacity['current'];
$previewRows = is_array($preview ?? null) ? array_values((array) ($preview['rows'] ?? [])) : [];
$isAvailableQueuePreview = is_array($preview ?? null)
  && (string) (($preview['configuration']['scope'] ?? $scope) ?: '') === 'available_queue';
$previewLimit = is_array($preview ?? null)
  ? (int) ($preview['configuration']['physical_api_call_budget'] ?? $configuredLimit)
  : $configuredLimit;
$previewStale = is_array($preview ?? null) && ($previewLimit > $configuredLimit
  || (string) ($preview['configuration']['capacity_revision'] ?? '') !== (string) $capacity['revision']);
$manualAccountLabel = trim((string) ($manualAccountLabel ?? '')) ?: 'Todas las cuentas autorizadas';
$manualResult = is_array($manualResult ?? null) ? $manualResult : null;
$manualAvailableQueueResult = is_array($manualAvailableQueueResult ?? null) ? $manualAvailableQueueResult : null;
$hasAnyManualResult = $manualResult !== null || $manualAvailableQueueResult !== null;
?>
<div class="page-head manual-page-head">
  <div>
    <p class="eyebrow">ADMINISTRACIÓN</p>
    <h1>Procesar ahora</h1>
    <p><span class="status-dot <?= empty($campaignReady) || !empty($emergencyStop) ? 'is-paused' : '' ?>"></span><span class="badge <?= empty($campaignReady) || !empty($emergencyStop) ? 'amber' : 'green' ?>"><?= empty($campaignReady) || !empty($emergencyStop) ? 'Ocupado' : 'Disponible' ?></span> Procesa una selección acotada y termina al responder la petición.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base . '/settings/cron') ?>">Ver automatización</a>
  </div>
</div>

<?php $automationTab = 'manual'; require __DIR__ . '/_automation_nav.php'; ?>

<section class="panel manual-capacity">
  <div class="panel-head"><div><h2>Capacidad manual guardada</h2><p>Independiente de la automatización. Guardar no procesa pendientes.</p></div></div>
  <div class="panel-body">
    <form method="post" action="<?= View::e($base) ?>/settings/manual-processing/call-budget" class="manual-config-form">
      <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
      <input type="hidden" name="capacity_revision" value="<?= View::e($capacity['revision']) ?>">
      <div class="manual-config-grid">
        <label class="field">Techo permitido de llamadas API<input class="input" type="number" name="manual_api_calls_ceiling" min="1" max="100" value="<?= (int) $capacity['ceiling'] ?>" required><small>Inicial: 55. Límite técnico: 100. Cambiar sólo el techo conserva el presupuesto actual.</small></label>
        <label class="field">Máximo de llamadas API por paso<input class="input" type="number" name="manual_api_calls_per_step" min="1" max="100" value="<?= $configuredLimit ?>" required><small>Debe ser menor o igual al techo elegido. Los aumentos requieren salud comprobada.</small></label>
      </div>
      <div class="manual-actions"><button class="btn" type="submit">Revisar y guardar capacidad</button></div>
    </form>
  </div>
</section>

<ol class="manual-stepper" aria-label="Etapas del procesamiento">
  <li class="<?= $preview === null ? 'active' : 'complete' ?>"><span>1</span> Qué procesar</li>
  <li class="<?= $preview === null ? 'active' : 'complete' ?>"><span>2</span> Llamadas</li>
  <li class="<?= $preview !== null ? 'active' : '' ?>"><span>3</span> Previsualizar</li>
  <li><span>4</span> Procesar</li>
  <li class="<?= $hasAnyManualResult ? 'active' : '' ?>"><span>5</span> Resultado</li>
</ol>

<?php if (!empty($emergencyStop)): ?>
<section class="panel manual-engine-card is-warning">
  <div class="panel-body manual-engine-summary">
    <div>
      <strong>Procesamiento en mantenimiento</strong>
      <p>No se iniciarán consultas nuevas. Los resultados anteriores permanecen guardados.</p>
    </div>
  </div>
</section>
<?php elseif (!$campaignReady): ?>
<section class="panel manual-engine-card is-warning">
  <div class="panel-body">
    <strong>Actualización pendiente</strong>
    <p>Falta preparar la base de datos para habilitar el procesamiento manual exacto.</p>
    <a class="btn primary" href="<?= View::e($base . '/settings/update') ?>">Completar actualización</a>
  </div>
</section>
<?php endif; ?>

<?php if ($manualAvailableQueueResult !== null): ?>
<section class="panel manual-result" id="resultado-proceso">
  <div class="panel-head">
    <div>
      <p class="eyebrow">5 · Resultado</p>
      <h2>Pendientes disponibles atendidos</h2>
      <p>La petición terminó. No quedó continuación manual en segundo plano.</p>
    </div>
  </div>
  <div class="panel-body">
    <div class="metric-grid manual-metric-grid">
      <article><span>Elementos atendidos</span><strong><?= (int) ($manualAvailableQueueResult['processed_count'] ?? 0) ?></strong></article>
      <article><span>Elementos completados</span><strong><?= (int) ($manualAvailableQueueResult['completed_count'] ?? 0) ?></strong></article>
      <article><span>Elementos esperando</span><strong><?= (int) ($manualAvailableQueueResult['waiting_count'] ?? 0) ?></strong></article>
      <article><span>Elementos para revisión</span><strong><?= (int) ($manualAvailableQueueResult['review_error_count'] ?? 0) ?></strong></article>
      <article><span>No procesados</span><strong><?= (int) ($manualAvailableQueueResult['not_processed_count'] ?? max(0, (int) ($manualAvailableQueueResult['requested_count'] ?? 0) - (int) ($manualAvailableQueueResult['processed_count'] ?? 0))) ?></strong></article>
    </div>
    <?php $callResult = $manualAvailableQueueResult; require __DIR__ . '/_calls_result.php'; ?>
    <a class="btn primary" href="<?= View::e($base . '/settings/manual-processing?scope=available_queue') ?>">Volver a calcular</a>
  </div>
</section>
<?php endif; ?>

<?php if ($manualResult !== null): ?>
<section class="panel manual-result" id="resultado-proceso">
  <div class="panel-head">
    <div>
      <p class="eyebrow">5 · Resultado</p>
      <h2>Resultado</h2>
      <p>La petición terminó. No quedó continuación manual en segundo plano.</p>
    </div>
  </div>
  <div class="panel-body">
    <div class="metric-grid manual-metric-grid">
      <article><span>Elementos atendidos</span><strong><?= (int) ($manualResult['processed'] ?? 0) ?></strong></article>
      <article><span>Elementos completados</span><strong><?= (int) ($manualResult['completed'] ?? 0) ?></strong></article>
      <article><span>Elementos esperando</span><strong><?= (int) ($manualResult['waiting'] ?? 0) ?></strong></article>
      <article><span>Elementos para revisión</span><strong><?= (int) ($manualResult['review'] ?? 0) ?></strong></article>
      <article><span>Sin iniciar</span><strong><?= (int) ($manualResult['not_started'] ?? 0) ?></strong></article>
    </div>
    <?php $callResult = $manualResult; require __DIR__ . '/_calls_result.php'; ?>
    <a class="btn primary" href="<?= View::e($base . '/settings/manual-processing') ?>">Volver a calcular</a>
  </div>
</section>
<?php endif; ?>

<section class="panel manual-config">
  <div class="panel-head">
    <div>
      <p class="eyebrow">1 · Qué procesar</p>
      <h2>Alcance del procesamiento</h2>
      <p>El preview sólo incluirá filas elegibles ahora, dentro del contexto global autorizado.</p>
    </div>
  </div>
  <div class="panel-body">
    <form method="post" action="<?= View::e($base . '/settings/manual-processing/preview') ?>" class="manual-config-form">
      <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
      <input type="hidden" name="origin" value="<?= View::e((string) ($origin ?? 'manual_center')) ?>">
      <input type="hidden" name="physical_api_call_budget" value="<?= $configuredLimit ?>">
      <input type="hidden" name="capacity_revision" value="<?= View::e($capacity['revision']) ?>">
      <?php if (!empty($originContext['year']) || !empty($originContext['month']) || !empty($originContext['date_from']) || !empty($originContext['date_to'])): ?>
        <div class="alert warning">El periodo de la pantalla de origen no filtra este cálculo manual. Aquí se confirma exclusivamente la cuenta, el tipo de información y las filas mostradas. Revise sus identificadores antes de procesar.</div>
      <?php endif; ?>

      <div class="manual-scope-grid manual-choice-grid">
        <?php foreach ($scopeLabels as $key => $meta): ?>
          <label class="manual-scope-card manual-choice-card <?= $scope === $key ? 'is-selected' : '' ?> <?= $key === 'available_queue' ? 'is-primary' : '' ?>">
            <input type="radio" name="scope" value="<?= View::e($key) ?>" <?= $scope === $key ? 'checked' : '' ?>>
            <span class="manual-choice-copy">
              <strong class="manual-choice-title"><?= View::e($meta[0]) ?></strong>
              <span class="manual-choice-description"><?= View::e($meta[1]) ?></span>
              <?php if (!empty($meta[2])): ?><small class="manual-choice-meta"><?= View::e((string) $meta[2]) ?></small><?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="manual-scope-help"><?= View::e($scopeHelp[$scope] ?? $scopeHelp['recommended']) ?></p>

      <div class="manual-config-grid">
        <input type="hidden" name="account_id" value="<?= (int) ($accountId ?? 0) ?>">
        <div class="manual-context-card">
          <strong>Cuenta</strong>
          <p><?= View::e($manualAccountLabel) ?></p>
          <small>La empresa y cuenta se toman del contexto global y se revalidan antes de procesar.</small>
        </div>
        <div class="manual-context-card"><strong>Máximo de llamadas API: <?= $configuredLimit ?></strong><p>El preview usa la capacidad manual guardada. La atención local puede avanzar sin gastar llamadas.</p><small>Para modificarla, guarde primero el formulario de capacidad.</small></div>
      </div>

      <div class="manual-actions">
        <button class="btn primary" type="submit" <?= empty($campaignReady) || !empty($emergencyStop) ? 'disabled' : '' ?>>PREVISUALIZAR</button>
      </div>
    </form>
    <details class="technical-details manual-preferences">
      <summary><span>Preferencias</span><span aria-hidden="true">⌄</span></summary>
      <div class="technical-details-body">
        <p><strong>Cantidad predeterminada:</strong> <?= $configuredLimit ?> llamada(s) API.</p>
        <p>La cuenta sigue siempre el contexto global; no se guarda una preferencia separada de cuenta.</p>
      </div>
    </details>
  </div>
</section>

<?php if ($preview !== null): ?>
<section class="panel manual-preview" id="resultado-calculo">
  <div class="panel-head">
    <div>
      <p class="eyebrow">3 · Previsualizar</p>
      <h2>Qué se va a procesar</h2>
      <p>Sólo se podrán atender las filas mostradas aquí y con la versión confirmada. Las modificadas u ocupadas se omiten; nunca se sustituyen por otras. El presupuesto limita llamadas HTTP, no la cantidad de filas.</p>
    </div>
  </div>
  <div class="panel-body">
    <?php if ($previewStale): ?><div class="alert warning">La capacidad cambió. Vuelva a previsualizar antes de procesar.</div><?php endif; ?>
    <?php if (!empty($preview['has_more'])): ?><div class="alert info">Hay más pendientes. Este cálculo muestra hasta 60 filas; las demás no quedan autorizadas. Vuelva a calcular para revisarlas.</div><?php endif; ?>
    <div class="metric-grid manual-metric-grid">
      <article><span>Elegibles ahora</span><strong><?= (int) ($preview['eligible_jobs'] ?? count($previewRows)) ?></strong></article>
      <article><span>Límite de llamadas API</span><strong><?= $previewLimit ?> llamada<?= $previewLimit === 1 ? '' : 's' ?></strong></article>
      <article><span>Alcance</span><strong><?= View::e($isAvailableQueuePreview ? 'Pendientes disponibles' : (string) ($preview['configuration']['scope'] ?? $scope)) ?></strong></article>
      <article><span>Expira en</span><strong><?= $duration(max(0, (int) ($preview['expires_in_seconds'] ?? 0))) ?></strong></article>
    </div>

    <?php if ($previewRows === []): ?>
      <div class="alert info">
        <strong>No hay pendientes exactos seguros ahora.</strong>
        <p>No se ejecutó nada. La automatización natural conserva su ritmo normal.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <?php if ($isAvailableQueuePreview): ?>
            <thead><tr><th>Orden</th><th>Tipo</th><th>Recurso</th><th>Cuenta</th><th>Estado</th></tr></thead>
          <?php else: ?>
            <thead><tr><th>#</th><th>Tipo / recurso</th><th>Cuenta</th><th>Por qué es elegible</th><th>Estado</th></tr></thead>
          <?php endif; ?>
          <tbody>
            <?php foreach ($previewRows as $index => $row): ?>
              <tr data-selection-id="<?= View::e((string) ($row['selection_id'] ?? '')) ?>">
                <?php if ($isAvailableQueuePreview): ?>
                  <td><?= $index + 1 ?></td>
                  <td><?= View::e((string) ($row['human_label'] ?? $row['label'] ?? 'Pendiente disponible')) ?></td>
                  <td><?= View::e((string) ($row['content_summary'] ?? $row['resource_label'] ?? 'Recurso disponible')) ?></td>
                  <td><?= View::e((string) ($row['account_name'] ?? $row['account_alias'] ?? ((int) ($row['meli_account_id'] ?? 0) === (int) ($accountId ?? 0) ? $manualAccountLabel : 'Cuenta autorizada'))) ?></td>
                  <td><span class="badge neutral"><?= View::e((string) ($row['status_label'] ?? $row['state_label'] ?? 'Listo ahora')) ?></span></td>
                <?php else: ?>
                  <td><?= $index + 1 ?></td>
                  <td><?= View::e((string) ($row['label'] ?? $row['queue_key'] ?? 'elemento')) ?><br><small><?= View::e((string) ($row['source_alias'] ?? $row['source_id'] ?? 'exacto')) ?></small></td>
                  <td><?= View::e((string) ($row['account_name'] ?? $row['account_alias'] ?? ((int) ($row['meli_account_id'] ?? 0) === (int) ($accountId ?? 0) ? $manualAccountLabel : 'Cuenta autorizada'))) ?></td>
                  <td><?= View::e((string) ($row['why_eligible'] ?? 'Seleccionado por previsualización y alcance autorizado.')) ?></td>
                  <td><span class="badge neutral">Seleccionado</span></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <form method="post" action="<?= View::e($base . '/settings/manual-processing/start') ?>" class="manual-confirm-form">
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
        <input type="hidden" name="preview_token" value="<?= View::e((string) ($preview['preview_token'] ?? '')) ?>">
        <input type="hidden" name="scope" value="<?= View::e((string) ($preview['configuration']['scope'] ?? $scope)) ?>">
        <input type="hidden" name="physical_api_call_budget" value="<?= $previewLimit ?>">
        <?php if ($isAvailableQueuePreview): ?>
          <p><strong>Confirmación:</strong> usar hasta <?= $previewLimit ?> llamada<?= $previewLimit === 1 ? '' : 's' ?> API en pendientes que continúen disponibles.</p>
        <?php else: ?>
          <p><strong>Confirmación:</strong> usar máximo <?= $previewLimit ?> llamada(s) API sobre elementos exactos del preview. Sin campaña, sin sesión, sin continuación oculta.</p>
        <?php endif; ?>
        <button class="btn primary" type="submit" <?= $previewStale ? 'disabled' : '' ?>><?= $isAvailableQueuePreview ? 'Procesar pendientes disponibles' : 'PROCESAR SELECCIÓN' ?></button>
      </form>
    <?php endif; ?>

    <?php if ($exclusionGroups !== []): ?>
      <details class="cron-technical">
        <summary>Pendientes excluidos del preview</summary>
        <div class="manual-exclusion-grid">
          <?php foreach ($exclusionGroups as $group): ?>
            <article>
              <strong><?= View::e((string) ($group['label'] ?? 'Excluido')) ?></strong>
              <p><?= (int) ($group['count'] ?? 0) ?> elemento(s). <?= View::e((string) ($group['description'] ?? 'No elegible para manual exacto.')) ?></p>
              <?php if (!empty($group['url'])): ?><a href="<?= View::e($group['url']) ?>">Ver detalle</a><?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<details class="technical-details manual-advanced-diagnostics">
  <summary><span>Diagnóstico avanzado</span><span aria-hidden="true">⌄</span></summary>
  <div class="technical-details-body">
    <p><span class="badge neutral">MANUAL_EXACT</span> La pestaña procesa una selección idempotente y acotada.</p>
    <p><span class="badge neutral">ACTIVE_DRAINERS_MAX=1</span> Manual y automático comparten la autoridad global de ejecución.</p>
    <p><span class="badge neutral">BACKGROUND_CONTINUATION=0</span> Al responder la petición no queda un drainer manual vivo.</p>
  </div>
</details>
