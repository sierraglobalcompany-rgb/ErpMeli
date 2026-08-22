<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
$base = rtrim(Env::get('APP_URL', ''), '/');
$scopeLabels = [
  'recommended' => ['Recomendado ahora', 'Ventas, tareas urgentes y reparaciones seguras.'],
  'sales' => ['Ventas', 'Órdenes, notificaciones y datos relacionados.'],
  'finance' => ['Finanzas', 'Cálculos y conciliaciones pendientes.'],
  'audits' => ['Auditorías', 'Comprobaciones y reparaciones de ventas.'],
  'products' => ['Productos', 'Actualización de publicaciones.'],
  'descriptions' => ['Descripciones', 'Carga delicada: una consulta por descripción.'],
  'modules' => ['Módulos', 'Snapshots de capacidades aisladas.'],
  'all' => ['Todo lo que puede procesarse ahora', 'Solo recursos comprobados, disponibles y seguros para esta pestaña.'],
];
$duration = static function (int $seconds): string {
  if ($seconds < 60) return $seconds . ' s';
  if ($seconds < 3600) return (int) ceil($seconds / 60) . ' min';
  $hours = $seconds / 3600;
  return number_format($hours, $hours < 10 ? 1 : 0, ',', '.') . ' h';
};
$exclusionPresenter = new \App\Services\ManualCampaignExclusionPresenter();
$rhythmPreview = is_array($rhythmPreview ?? null) ? $rhythmPreview : [];
$exclusionGroups = [];
if (is_array($preview ?? null)) {
  foreach ((array) ($preview['excluded_summary'] ?? []) as $excludedGroup) {
    $exclusionGroups[] = $exclusionPresenter->group(
      (array) $excludedGroup,
      (string) ($preview['preview_token'] ?? '')
    );
  }
}
?>
<div class="page-head manual-page-head">
  <div><p class="eyebrow">ADMINISTRACIÓN</p><h1>Procesar ahora</h1>
    <p>Elija trabajos concretos para adelantarlos. Las ventas nuevas conservan prioridad y cada resultado queda guardado.</p>
    <p><span class="badge neutral">MANUAL_EXACT</span> Un paso idempotente por confirmación; no inicia ni continúa Cron en segundo plano.</p></div>
  <div class="page-actions"><a class="btn" href="<?= View::e($base . '/settings/cron') ?>">Ver automatización</a></div>
</div>

<ol class="manual-stepper" aria-label="Etapas del procesamiento">
  <li class="<?= $preview === null ? 'active' : 'complete' ?>"><span>1</span> Elegir</li>
  <li class="<?= $preview !== null ? 'active' : '' ?>"><span>2</span> Configurar</li>
  <li><span>3</span> Confirmar</li><li><span>4</span> Procesar</li><li><span>5</span> Resultado</li>
</ol>

<?php if (!empty($emergencyStop)): ?>
<section class="panel manual-engine-card is-warning"><div class="panel-body manual-engine-summary">
  <div><strong>Procesamiento en mantenimiento</strong>
    <p>No se iniciaran consultas nuevas. Los resultados anteriores permanecen guardados.</p></div>
  <?php if ($activeSession): ?><a class="btn" href="<?= View::e($base . '/settings/manual-processing/session?id=' . (int) $activeSession['id']) ?>">Ver campaña guardada</a><?php endif; ?>
</div></section>
<?php elseif (!$campaignReady): ?>
<section class="panel manual-engine-card is-warning"><div class="panel-body">
  <strong>Actualización pendiente</strong>
  <p>Los archivos están instalados, pero falta preparar la base de datos para el procesamiento interactivo.</p>
  <a class="btn primary" href="<?= View::e($base . '/settings/update') ?>">Completar actualización</a>
</div></section>
<?php else: ?>
<section class="panel manual-engine-card is-ready"><div class="panel-body manual-engine-summary">
  <div><span class="status-dot"></span><strong>Procesamiento manual listo</strong>
    <p>Cada confirmacion ejecuta un solo trabajo exacto mientras esta pestana permanece abierta.</p></div>
  <?php if ($activeSession): ?><a class="btn primary" href="<?= View::e($base . '/settings/manual-processing/session?id=' . (int) $activeSession['id']) ?>">Ver campaña activa</a><?php endif; ?>
</div></section>
<?php endif; ?>

<?php if ($campaignReady && empty($emergencyStop) && !$activeSession): ?>
<section class="panel manual-panel">
  <div class="panel-head"><div><p class="eyebrow"><?= $preview === null ? 'PASO 1' : 'PASO 2' ?></p><h2><?= $preview === null ? '¿Qué desea procesar?' : 'Configure el ritmo' ?></h2></div></div>
  <div class="panel-body">
  <form method="post" action="<?= View::e($base . '/settings/manual-processing/preview') ?>" class="manual-campaign-form" data-manual-preview-form>
    <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
    <input type="hidden" name="origin" value="<?= View::e((string) $origin) ?>">
    <?php foreach ((array) $originContext as $contextKey => $contextValue): ?>
      <?php if ((string) $contextValue !== '' && (int) $contextValue !== 0): ?>
        <input type="hidden" name="<?= View::e((string) $contextKey) ?>" value="<?= View::e((string) $contextValue) ?>">
      <?php endif; ?>
    <?php endforeach; ?>
    <fieldset><legend>Selección</legend><div class="manual-choice-grid">
      <?php foreach ($scopeLabels as $key => [$label, $description]): ?>
      <label class="manual-choice-card"><input type="radio" name="scope" value="<?= View::e($key) ?>" <?= $scope === $key ? 'checked' : '' ?>>
        <span><strong><?= View::e($label) ?></strong><small><?= View::e($description) ?></small></span></label>
      <?php endforeach; ?>
    </div></fieldset>
    <fieldset class="manual-rhythm-grid"><legend>Limites usados para calcular disponibilidad</legend>
      <label><span>Resultados antes de guardar checkpoint</span><input type="number" name="block_size" min="1" max="60" value="<?= (int) $blockSize ?>"><small>No equivale a salidas HTTP.</small></label>
      <label><span>Después de cada salida HTTP</span><span class="input-with-unit"><input type="number" name="interval_seconds" min="0" max="300" step="0.1" value="<?= View::e((string) $intervalSeconds) ?>"><em>segundos</em></span></label>
      <label><span>Pausa después del checkpoint</span><span class="input-with-unit"><input type="number" name="block_pause_seconds" min="0" max="3600" step="1" value="<?= View::e((string) $blockPauseSeconds) ?>"><em>segundos</em></span></label>
      <label><span>Puntos de avance máximos</span><input type="number" name="max_blocks" min="0" max="10000" value="<?= (int) $maxBlocks ?>" aria-describedby="max-blocks-help"><small id="max-blocks-help">0 significa hasta terminar.</small></label>
      <label><span>Duración máxima</span><span class="input-with-unit"><input type="number" name="max_duration_minutes" min="0" max="10080" value="<?= (int) $maxDurationMinutes ?>"><em>minutos</em></span><small>0 significa sin límite.</small></label>
      <div class="manual-readonly-setting"><span>Ejecucion</span><strong>Un solo trabajo por confirmacion</strong></div>
    </fieldset>
    <div class="alert info"><strong>Comparte el techo HTTP de Cron.</strong><p>La campaña guarda avance cada <?= (int) $blockSize ?> resultado(s), pero sus salidas HTTP respetan el techo global. Permitido ahora: <?= View::e(number_format((float) ($rhythmPreview['effective_rpm'] ?? 0), 1, ',', '.')) ?> HTTP/min, limitado por <?= View::e((string) ($rhythmPreview['limiting_scope'] ?? 'protección disponible')) ?>. Esta campaña puede ir más lento, nunca más rápido.</p></div>
    <div class="manual-quick-delays" aria-label="Intervalos rápidos">
      <?php foreach ([0,1,2,5,10,20,30,60,300] as $delay): ?><button type="button" class="chip" data-manual-delay="<?= $delay ?>"><?= $delay < 60 ? $delay . ' s' : ($delay / 60) . ' min' ?></button><?php endforeach; ?>
    </div>
    <button class="btn primary" type="submit" data-manual-preview-submit>
      <span data-ready-label>Calcular trabajos disponibles</span>
      <span data-busy-label hidden>Comprobando trabajos…</span>
    </button>
  </form>
  </div>
</section>

<?php if ($preview !== null): ?>
<section class="panel manual-review-card" id="resultado-calculo" tabindex="-1" aria-live="polite">
  <div class="panel-head"><div><p class="eyebrow">PASO 3</p><h2>Revise antes de comenzar</h2>
    <p>El cálculo no consulta Mercado Libre y utiliza un modo seguro con recursos exactos. Vigente hasta <?= View::e((string) ($preview['expires_at'] ?? '')) ?> UTC.</p></div></div>
  <div class="panel-body">
    <?php if (empty($preview['can_start_campaign'])): ?>
      <?php if ($scope === 'audits'): ?>
        <div class="empty-state"><strong>Primero debe crear una comprobación.</strong><p>Seleccione una cuenta y un periodo en Control de ventas. Allí podrá comprobar, revisar diferencias y preparar una reparación segura.</p><a class="btn primary" href="<?= View::e($base) ?>/sales-control">Ir a Control de ventas</a></div>
      <?php else: ?>
        <div class="empty-state"><strong>No hay trabajos remotos exactos listos ahora.</strong><p>El cálculo terminó correctamente. Abra uno de los grupos para ver cada trabajo, entender por qué no entró y llegar a su solución.</p></div>
      <?php endif; ?>
      <?php if ($exclusionGroups !== []): ?>
      <div class="manual-preview-breakdown" aria-label="Resultado completo del cálculo">
        <?php foreach ($exclusionGroups as $group): ?>
          <a class="manual-preview-group is-<?= View::e((string) $group['tone']) ?>" href="<?= View::e($base . $group['url']) ?>">
            <strong><?= (int) $group['count'] ?></strong>
            <span><?= View::e((string) $group['label']) ?></span>
            <small><?= View::e((string) $group['explanation']) ?></small>
            <b><?= View::e((string) $group['action']) ?> →</b>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="manual-safety-note"><strong>Procesar rápido no significa saltar protecciones.</strong><p>Solo entran consultas exactas que el ERP puede reservar y reanudar sin duplicarlas. Los errores, horarios futuros y trabajos por etapas se abren desde su grupo para resolverlos correctamente.</p></div>
      <?php endif; ?>
    <?php else: ?>
      <p class="manual-conclusion">Hay <strong><?= (int) $preview['eligible_jobs'] ?> trabajos</strong> disponibles. Esta confirmacion procesara solamente el primero y ejecutara como maximo una salida HTTP.</p>
      <div class="metric-grid manual-metric-grid">
        <article><span>Trabajos</span><strong><?= (int) $preview['eligible_jobs'] ?></strong></article>
        <article><span>HTTP máximos estimados</span><strong><?= (int) $preview['estimated_calls'] ?></strong></article>
        <article><span>Puntos de avance estimados</span><strong><?= max(1, (int) ($preview['total_blocks'] ?? 1)) ?></strong></article>
        <article><span>Duración aproximada</span><strong><?= View::e($duration((int) $preview['estimated_duration_seconds'])) ?></strong></article>
      </div>
      <div class="alert info"><strong>El reloj controla llamadas reales.</strong><p>Una publicación, una página de órdenes o una descripción consumirá como máximo una salida remota antes de respetar la espera configurada.</p></div>
      <div class="table-scroll"><table class="data-table compact"><caption>Operaciones incluidas en la campaña</caption><thead><tr><th>Operación</th><th>Trabajos</th><th>HTTP estimados</th><th>Puntos de avance</th><th>Ritmo efectivo</th></tr></thead><tbody>
      <?php foreach ($preview['operations'] as $operation): ?><tr><td><?= View::e((string) $operation['label']) ?></td><td><?= (int) $operation['items'] ?></td><td><?= (int) $operation['calls'] ?></td><td><?= (int) ($operation['blocks'] ?? 1) ?></td><td><?= View::e(number_format(((int) $operation['effective_interval_ms']) / 1000, 1, ',', '.')) ?> s<?= !empty($operation['adjusted']) ? ' · protegido' : '' ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php if ($exclusionGroups !== []): ?>
      <div class="manual-preview-breakdown" aria-label="Trabajos que no entrarán en la campaña">
        <?php foreach ($exclusionGroups as $group): ?>
          <a class="manual-preview-group is-<?= View::e((string) $group['tone']) ?>" href="<?= View::e($base . $group['url']) ?>">
            <strong><?= (int) $group['count'] ?></strong>
            <span><?= View::e((string) $group['label']) ?></span>
            <small><?= View::e((string) $group['explanation']) ?></small>
            <b><?= View::e((string) $group['action']) ?> →</b>
          </a>
        <?php endforeach; ?>
      </div>
      <details class="technical-details"><summary>Vista rápida de las primeras exclusiones</summary>
        <ul><?php foreach (array_slice((array) $preview['excluded_jobs'], 0, 25) as $excluded): ?><li><strong><?= View::e((string) ($excluded['label'] ?? 'Trabajo')) ?>:</strong> <?= View::e((string) ($excluded['reason'] ?? 'No disponible.')) ?></li><?php endforeach; ?></ul>
      </details>
      <?php endif; ?>
      <?php if (!empty($preview['selection_limited'])): ?><div class="alert info"><strong>La campaña tiene un límite.</strong><p>Los trabajos restantes seguirán disponibles para otra campaña o para la automatización.</p></div><?php endif; ?>
      <?php if (!empty($preview['contains_descriptions'])): ?><div class="alert warning"><strong>Incluye descripciones.</strong><p>Aunque solicite un intervalo menor, se aplicarán al menos 20 segundos y una descripción por bloque.</p></div><?php endif; ?>
      <form method="post" action="<?= View::e($base . '/settings/manual-processing/start') ?>" class="manual-confirm-form">
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="scope" value="<?= View::e($scope) ?>">
        <input type="hidden" name="preview_token" value="<?= View::e((string) ($preview['preview_token'] ?? '')) ?>">
        <input type="hidden" name="origin" value="<?= View::e((string) $origin) ?>">
        <?php foreach ((array) $originContext as $contextKey => $contextValue): ?>
          <?php if ((string) $contextValue !== '' && (int) $contextValue !== 0): ?>
            <input type="hidden" name="<?= View::e((string) $contextKey) ?>" value="<?= View::e((string) $contextValue) ?>">
          <?php endif; ?>
        <?php endforeach; ?>
        <input type="hidden" name="preset" value="custom"><input type="hidden" name="block_size" value="<?= (int) $blockSize ?>">
        <input type="hidden" name="interval_seconds" value="<?= View::e((string) $intervalSeconds) ?>"><input type="hidden" name="block_pause_seconds" value="<?= View::e((string) $blockPauseSeconds) ?>">
        <input type="hidden" name="max_blocks" value="<?= (int) $maxBlocks ?>"><input type="hidden" name="max_duration_minutes" value="<?= (int) $maxDurationMinutes ?>">
        <?php if (!empty($preview['contains_descriptions'])): ?><label class="manual-confirm-delicate"><input type="checkbox" name="confirm_delicate" value="1" required><span>Entiendo que las descripciones avanzan con un límite especial.</span></label><?php endif; ?>
        <p class="manual-browser-note"><strong>Mantenga abierta esta pagina.</strong> La peticion termina despues de un unico resultado y no deja una campana en segundo plano.</p>
        <button class="btn primary" type="submit">Procesar un trabajo</button>
      </form>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
<?php endif; ?>
