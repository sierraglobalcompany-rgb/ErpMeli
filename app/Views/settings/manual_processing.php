<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\QueueV4Clean\QueueV4CleanWorker;

$base = rtrim(Env::get('APP_URL', ''), '/');
$scopeLabels = [
  'available_queue' => ['Cola disponible', 'Procesa directamente los trabajos que el sistema tiene listos ahora.', (int) ($availableQueueCount ?? 0) . ' listos ahora'],
  'recommended' => ['Recomendado', 'Lo más seguro para avanzar ahora.'],
  'sales' => ['Ventas', 'Órdenes y notificaciones.'],
  'finance' => ['Finanzas', 'Conciliación y cálculo.'],
  'audits' => ['Auditorías', 'Revisión operativa.'],
  'products' => ['Productos', 'Publicaciones.'],
  'descriptions' => ['Descripciones', 'Contenido de publicaciones.'],
  'modules' => ['Módulos', 'Capacidades aisladas.'],
  'all' => ['Todo lo elegible', 'Todo lo elegible.'],
];
$scopeHelp = [
  'available_queue' => 'Procesa sólo la cola que está lista ahora, con la misma elegibilidad que la automatización natural.',
  'recommended' => 'Muestra primero trabajo elegible, seguro y de bajo ruido para adelantar desde esta pestaña.',
  'sales' => 'Filtra trabajos manuales relacionados con órdenes, notificaciones y ventas.',
  'finance' => 'Filtra trabajos financieros que ya estén autorizados por su fuente de dominio.',
  'audits' => 'Filtra tareas de revisión operativa que pueden resolverse de forma acotada.',
  'products' => 'Filtra sincronización de publicaciones y datos de producto.',
  'descriptions' => 'Filtra descripciones; mantiene controles conservadores por ser una carga delicada.',
  'modules' => 'Filtra capacidades aisladas sin iniciar procesos en segundo plano.',
  'all' => 'Muestra todos los trabajos exactos elegibles dentro del contexto autorizado actual.',
];
$availableQueueMax = max(1, (int) QueueV4CleanWorker::HARD_MAX_CALLS);
$availableQueueOptions = array_values(array_filter(
  [1, 5, 10, 15],
  static fn (int $size): bool => $size <= $availableQueueMax
));
if (!in_array($availableQueueMax, $availableQueueOptions, true)) {
  $availableQueueOptions[] = $availableQueueMax;
}
sort($availableQueueOptions);
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
$configuredLimit = max(1, min(60, (int) ($blockSize ?? 1)));
if ((string) ($scope ?? '') === 'available_queue') {
  $configuredLimit = min($configuredLimit, $availableQueueMax);
}
$previewRows = is_array($preview ?? null) ? array_values((array) ($preview['rows'] ?? [])) : [];
$isAvailableQueuePreview = is_array($preview ?? null)
  && (string) (($preview['configuration']['scope'] ?? $scope) ?: '') === 'available_queue';
$previewLimit = is_array($preview ?? null)
  ? max(1, min($configuredLimit, max(1, count($previewRows))))
  : $configuredLimit;
if ($isAvailableQueuePreview) {
  $previewLimit = min($previewLimit, $availableQueueMax);
}
$manualAccountLabel = trim((string) ($manualAccountLabel ?? '')) ?: 'Todas las cuentas autorizadas';
$manualResult = is_array($manualResult ?? null) ? $manualResult : null;
$manualAvailableQueueResult = is_array($manualAvailableQueueResult ?? null) ? $manualAvailableQueueResult : null;
$hasAnyManualResult = $manualResult !== null || $manualAvailableQueueResult !== null;
?>
<div class="page-head manual-page-head">
  <div>
    <p class="eyebrow">ADMINISTRACIÓN</p>
    <h1>Procesamiento manual</h1>
    <p><span class="status-dot <?= empty($campaignReady) || !empty($emergencyStop) ? 'is-paused' : '' ?>"></span><span class="badge <?= empty($campaignReady) || !empty($emergencyStop) ? 'amber' : 'green' ?>"><?= empty($campaignReady) || !empty($emergencyStop) ? 'Ocupado' : 'Disponible' ?></span> Procesa una selección acotada y termina al responder la petición.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base . '/settings/cron') ?>">Ver automatización</a>
  </div>
</div>

<ol class="manual-stepper" aria-label="Etapas del procesamiento">
  <li class="<?= $preview === null ? 'active' : 'complete' ?>"><span>1</span> Qué procesar</li>
  <li class="<?= $preview === null ? 'active' : 'complete' ?>"><span>2</span> Cantidad</li>
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
      <h2>Cola disponible procesada</h2>
      <p>La petición terminó. No quedó continuación manual en segundo plano.</p>
    </div>
  </div>
  <div class="panel-body">
    <div class="metric-grid manual-metric-grid">
      <article><span>Llamadas API solicitadas</span><strong><?= (int) ($manualAvailableQueueResult['requested_api_calls'] ?? $manualAvailableQueueResult['requested_count'] ?? 0) ?></strong></article>
      <article><span>Llamadas API usadas</span><strong><?= (int) ($manualAvailableQueueResult['api_calls_used'] ?? 0) ?></strong></article>
      <article><span>Trabajos procesados</span><strong><?= (int) ($manualAvailableQueueResult['processed_count'] ?? 0) ?></strong></article>
      <article><span>Completados</span><strong><?= (int) ($manualAvailableQueueResult['completed_count'] ?? 0) ?></strong></article>
      <article><span>Esperando</span><strong><?= (int) ($manualAvailableQueueResult['waiting_count'] ?? 0) ?></strong></article>
      <article><span>Revisión / errores</span><strong><?= (int) ($manualAvailableQueueResult['review_error_count'] ?? 0) ?></strong></article>
      <article><span>No procesados</span><strong><?= (int) ($manualAvailableQueueResult['not_processed_count'] ?? max(0, (int) ($manualAvailableQueueResult['requested_count'] ?? 0) - (int) ($manualAvailableQueueResult['processed_count'] ?? 0))) ?></strong></article>
    </div>
    <a class="btn primary" href="<?= View::e($base . '/settings/manual-processing?scope=available_queue') ?>">Procesar más</a>
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
      <article><span>Procesados</span><strong><?= (int) ($manualResult['processed'] ?? 0) ?></strong></article>
      <article><span>Completados</span><strong><?= (int) ($manualResult['completed'] ?? 0) ?></strong></article>
      <article><span>Esperando</span><strong><?= (int) ($manualResult['waiting'] ?? 0) ?></strong></article>
      <article><span>Revisión / errores</span><strong><?= (int) ($manualResult['review'] ?? 0) ?></strong></article>
    </div>
    <a class="btn primary" href="<?= View::e($base . '/settings/manual-processing') ?>">Procesar más</a>
  </div>
</section>
<?php endif; ?>

<section class="panel manual-config">
  <div class="panel-head">
    <div>
      <p class="eyebrow">1 · Qué procesar</p>
      <h2>Alcance del trabajo</h2>
      <p>El preview sólo incluirá filas elegibles ahora, dentro del contexto global autorizado.</p>
    </div>
  </div>
  <div class="panel-body">
    <form method="post" action="<?= View::e($base . '/settings/manual-processing/preview') ?>" class="manual-config-form">
      <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
      <input type="hidden" name="origin" value="<?= View::e((string) ($origin ?? 'manual_center')) ?>">
      <input type="hidden" name="interval_seconds" value="0">
      <input type="hidden" name="block_pause_seconds" value="0">
      <input type="hidden" name="max_blocks" value="0">
      <input type="hidden" name="max_duration_minutes" value="0">
      <?php foreach ((array) ($originContext ?? []) as $key => $value): ?>
        <?php if ($value !== '' && $value !== 0): ?>
          <input type="hidden" name="<?= View::e((string) $key) ?>" value="<?= View::e((string) $value) ?>">
        <?php endif; ?>
      <?php endforeach; ?>

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
        <label>
          Máximo de llamadas API
          <select name="block_size" class="input">
            <?php foreach (($scope === 'available_queue' ? $availableQueueOptions : [5, 10, 20, 30, 40, 60]) as $size): ?>
              <option value="<?= $size ?>" <?= $configuredLimit === $size ? 'selected' : '' ?>><?= $size ?> llamada<?= $size === 1 ? '' : 's' ?></option>
            <?php endforeach; ?>
          </select>
          <small>Consume como máximo N llamadas físicas a Mercado Libre. Trabajo local puede avanzar sin gastar llamadas.</small>
        </label>
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
      <?php if ($isAvailableQueuePreview): ?>
        <p>Esta es la cola disponible en este momento. Al procesar, el sistema vuelve a validar los trabajos y tomará hasta <?= $previewLimit ?> que continúen disponibles.</p>
      <?php else: ?>
        <p>Esta selección queda ligada al botón. Si procesa, sólo puede tomar estas filas y hasta <?= $previewLimit ?> trabajo(s).</p>
      <?php endif; ?>
    </div>
  </div>
  <div class="panel-body">
    <div class="metric-grid manual-metric-grid">
      <article><span>Elegibles ahora</span><strong><?= (int) ($preview['eligible_jobs'] ?? count($previewRows)) ?></strong></article>
      <article><span>Límite elegido</span><strong><?= $previewLimit ?> llamada<?= $previewLimit === 1 ? '' : 's' ?></strong></article>
      <article><span>Alcance</span><strong><?= View::e($isAvailableQueuePreview ? 'Cola disponible' : (string) ($preview['configuration']['scope'] ?? $scope)) ?></strong></article>
      <article><span>Expira en</span><strong><?= $duration(max(0, (int) ($preview['expires_in_seconds'] ?? 0))) ?></strong></article>
    </div>

    <?php if ($previewRows === []): ?>
      <div class="alert info">
        <strong>No hay trabajo exacto seguro ahora.</strong>
        <p>No se ejecutó nada. La cola automática conserva su ritmo normal.</p>
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
            <?php foreach (array_slice($previewRows, 0, $previewLimit) as $index => $row): ?>
              <tr>
                <?php if ($isAvailableQueuePreview): ?>
                  <td><?= $index + 1 ?></td>
                  <td><?= View::e((string) ($row['human_label'] ?? $row['label'] ?? 'Trabajo disponible')) ?></td>
                  <td><?= View::e((string) ($row['content_summary'] ?? $row['resource_label'] ?? 'Recurso disponible')) ?></td>
                  <td><?= View::e((string) ($row['account_name'] ?? $row['account_alias'] ?? ((int) ($row['meli_account_id'] ?? 0) === (int) ($accountId ?? 0) ? $manualAccountLabel : 'Cuenta autorizada'))) ?></td>
                  <td><span class="badge neutral"><?= View::e((string) ($row['status_label'] ?? $row['state_label'] ?? 'Listo ahora')) ?></span></td>
                <?php else: ?>
                  <td><?= $index + 1 ?></td>
                  <td><?= View::e((string) ($row['label'] ?? $row['queue_key'] ?? 'trabajo')) ?><br><small><?= View::e((string) ($row['source_alias'] ?? $row['source_id'] ?? 'exacto')) ?></small></td>
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
        <input type="hidden" name="process_limit" value="<?= $previewLimit ?>">
        <?php if ($isAvailableQueuePreview): ?>
          <p><strong>Confirmación:</strong> usar hasta <?= $previewLimit ?> llamada<?= $previewLimit === 1 ? '' : 's' ?> API en trabajos que continúen disponibles.</p>
        <?php else: ?>
          <p><strong>Confirmación:</strong> usar máximo <?= $previewLimit ?> llamada(s) API sobre trabajos exactos del preview. Sin campaña, sin sesión, sin continuación oculta.</p>
        <?php endif; ?>
        <button class="btn primary" type="submit"><?= $isAvailableQueuePreview ? 'Procesar Cola disponible' : 'PROCESAR ' . $previewLimit ?></button>
      </form>
    <?php endif; ?>

    <?php if ($exclusionGroups !== []): ?>
      <details class="cron-technical">
        <summary>Trabajos excluidos del preview</summary>
        <div class="manual-exclusion-grid">
          <?php foreach ($exclusionGroups as $group): ?>
            <article>
              <strong><?= View::e((string) ($group['label'] ?? 'Excluido')) ?></strong>
              <p><?= (int) ($group['count'] ?? 0) ?> trabajo(s). <?= View::e((string) ($group['description'] ?? 'No elegible para manual exacto.')) ?></p>
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
