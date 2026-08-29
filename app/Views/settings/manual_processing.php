<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$scopeLabels = [
  'recommended' => ['Recomendado ahora', 'Trabajo elegible y seguro para adelantar desde esta pestaña.'],
  'sales' => ['Ventas', 'Órdenes, notificaciones y datos relacionados.'],
  'finance' => ['Finanzas', 'Cálculos y conciliaciones pendientes.'],
  'audits' => ['Auditorías', 'Comprobaciones y reparaciones de ventas.'],
  'products' => ['Productos', 'Actualización de publicaciones.'],
  'descriptions' => ['Descripciones', 'Carga delicada: una consulta por descripción.'],
  'modules' => ['Módulos', 'Snapshots de capacidades aisladas.'],
  'all' => ['Todo lo que puede procesarse ahora', 'Sólo recursos comprobados, disponibles y seguros para esta pestaña.'],
];
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
$previewRows = is_array($preview ?? null) ? array_values((array) ($preview['rows'] ?? [])) : [];
$previewLimit = is_array($preview ?? null)
  ? max(1, min($configuredLimit, max(1, count($previewRows))))
  : $configuredLimit;
?>
<div class="page-head manual-page-head">
  <div>
    <p class="eyebrow">ADMINISTRACIÓN</p>
    <h1>Procesamiento manual</h1>
    <p>Elija qué procesar, confirme cuántos trabajos exactos y espere el resultado. Al terminar, la petición se detiene.</p>
    <p><span class="badge neutral">MANUAL_EXACT</span> Sin campaña, sin drenador continuo, sin trabajo en segundo plano.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base . '/settings/cron') ?>">Ver automatización</a>
  </div>
</div>

<ol class="manual-stepper" aria-label="Etapas del procesamiento">
  <li class="<?= $preview === null ? 'active' : 'complete' ?>"><span>1</span> Qué procesar</li>
  <li class="<?= $preview === null ? 'active' : 'complete' ?>"><span>2</span> Cuántos</li>
  <li class="<?= $preview !== null ? 'active' : '' ?>"><span>3</span> Preview exacto</li>
  <li><span>4</span> Procesar</li>
  <li><span>5</span> Resultado</li>
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
<?php else: ?>
<section class="panel manual-engine-card is-ready">
  <div class="panel-body manual-engine-summary">
    <div>
      <span class="status-dot"></span><strong>Listo para procesamiento manual exacto</strong>
      <p>Una confirmación procesa únicamente las filas del preview, hasta el límite elegido, bajo una sola autoridad global.</p>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="panel manual-config">
  <div class="panel-head">
    <div>
      <p class="eyebrow">1 · Qué procesar</p>
      <h2>Elegir trabajo</h2>
      <p>El preview sólo incluirá trabajos elegibles para ejecución manual exacta.</p>
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

      <div class="manual-scope-grid">
        <?php foreach ($scopeLabels as $key => $meta): ?>
          <label class="manual-scope-card">
            <input type="radio" name="scope" value="<?= View::e($key) ?>" <?= $scope === $key ? 'checked' : '' ?>>
            <strong><?= View::e($meta[0]) ?></strong>
            <span><?= View::e($meta[1]) ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="manual-config-grid">
        <label>
          Cuenta Mercado Libre
          <input type="number" min="0" name="account_id" value="<?= (int) ($accountId ?? 0) ?>" placeholder="0 = todas las autorizadas">
          <small>Siempre se revalida empresa y cuenta antes de encolar.</small>
        </label>
        <label>
          ¿Cuántos procesar?
          <input type="number" min="1" max="60" name="block_size" value="<?= $configuredLimit ?>">
          <small>Procesa como máximo N filas exactas del preview. No continúa después.</small>
        </label>
      </div>

      <div class="manual-actions">
        <button class="btn primary" type="submit" <?= empty($campaignReady) || !empty($emergencyStop) ? 'disabled' : '' ?>>Previsualizar trabajo exacto</button>
      </div>
    </form>
  </div>
</section>

<?php if ($preview !== null): ?>
<section class="panel manual-preview" id="resultado-calculo">
  <div class="panel-head">
    <div>
      <p class="eyebrow">3 · Preview exacto</p>
      <h2>Qué se va a procesar</h2>
      <p>Esta selección queda ligada al botón. Si procesa, sólo puede tomar estas filas y hasta <?= $previewLimit ?> trabajo(s).</p>
    </div>
  </div>
  <div class="panel-body">
    <div class="metric-grid manual-metric-grid">
      <article><span>Elegibles ahora</span><strong><?= (int) ($preview['eligible_jobs'] ?? count($previewRows)) ?></strong></article>
      <article><span>Límite elegido</span><strong><?= $previewLimit ?></strong></article>
      <article><span>Alcance</span><strong><?= View::e((string) ($preview['configuration']['scope'] ?? $scope)) ?></strong></article>
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
          <thead><tr><th>#</th><th>Tipo</th><th>Cuenta</th><th>Origen</th><th>Estado</th></tr></thead>
          <tbody>
            <?php foreach (array_slice($previewRows, 0, $previewLimit) as $index => $row): ?>
              <tr>
                <td><?= $index + 1 ?></td>
                <td><?= View::e((string) ($row['label'] ?? $row['queue_key'] ?? 'trabajo')) ?></td>
                <td><?= (int) ($row['meli_account_id'] ?? 0) ?></td>
                <td><?= View::e((string) ($row['source_alias'] ?? $row['source_id'] ?? 'exacto')) ?></td>
                <td><span class="badge neutral">SELECTED_EXACT_ONLY</span></td>
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
        <p><strong>Confirmación:</strong> procesar máximo <?= $previewLimit ?> trabajo(s) exactos del preview. Sin campaña, sin sesión, sin continuación oculta.</p>
        <button class="btn primary" type="submit">Procesar selección exacta</button>
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

<section class="panel manual-engine-card">
  <div class="panel-body">
    <p><span class="badge neutral">ACTIVE_DRAINERS_MAX=1</span> Manual y automático comparten la autoridad global de ejecución.</p>
    <p><span class="badge neutral">BACKGROUND_CONTINUATION=0</span> Al responder la petición no queda un drainer manual vivo.</p>
  </div>
</section>
