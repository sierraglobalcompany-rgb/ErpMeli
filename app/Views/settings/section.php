<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$basicFields = array_values(array_filter($section['fields'], static fn(array $field): bool => !($field['advanced'] ?? false)));
$advancedFields = array_values(array_filter($section['fields'], static fn(array $field): bool => (bool) ($field['advanced'] ?? false)));
$fieldId = static fn(string $key): string => 'setting-' . str_replace(['.', '_'], '-', $key);
$renderField = static function (array $field) use ($values, $fieldId): void {
    $key = $field['key'];
    $id = $fieldId($key);
    $value = (string) ($values[$key] ?? '');
    $recommended = is_bool($field['recommended']) ? ($field['recommended'] ? 'Activado' : 'Desactivado') : (string) $field['recommended'];
?>
  <div class="setting-field <?= $field['type'] === 'boolean' ? 'is-toggle' : '' ?>">
    <?php if ($field['type'] === 'boolean'): ?>
      <div class="setting-field-copy">
        <label for="<?= View::e($id) ?>"><?= View::e($field['label']) ?></label>
        <p id="<?= View::e($id) ?>-help"><?= View::e($field['help']) ?></p>
        <small>Recomendado: <?= View::e($recommended) ?></small>
      </div>
      <input type="hidden" name="settings[<?= View::e($key) ?>]" value="0">
      <label class="switch" aria-label="<?= View::e($field['label']) ?>">
        <input id="<?= View::e($id) ?>" type="checkbox" name="settings[<?= View::e($key) ?>]" value="1" <?= in_array($value, ['1', 'true', 'on'], true) ? 'checked' : '' ?> aria-describedby="<?= View::e($id) ?>-help">
        <span aria-hidden="true"></span>
      </label>
    <?php else: ?>
      <label for="<?= View::e($id) ?>"><?= View::e($field['label']) ?></label>
      <p id="<?= View::e($id) ?>-help"><?= View::e($field['help']) ?></p>
      <div class="setting-control">
        <?php if ($field['type'] === 'number'): ?>
          <input class="input" id="<?= View::e($id) ?>" type="number" name="settings[<?= View::e($key) ?>]" value="<?= View::e($value) ?>" min="<?= (int) $field['min'] ?>" max="<?= (int) $field['max'] ?>" aria-describedby="<?= View::e($id) ?>-help">
        <?php elseif ($field['type'] === 'select'): ?>
          <select class="input" id="<?= View::e($id) ?>" name="settings[<?= View::e($key) ?>]" aria-describedby="<?= View::e($id) ?>-help">
            <?php foreach ($field['options'] as $optionValue => $optionLabel): ?>
              <option value="<?= View::e((string) $optionValue) ?>" <?= $value === (string) $optionValue ? 'selected' : '' ?>><?= View::e($optionLabel) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input class="input" id="<?= View::e($id) ?>" type="<?= View::e((string) ($field['inputType'] ?? 'text')) ?>" name="settings[<?= View::e($key) ?>]" value="<?= View::e($value) ?>" aria-describedby="<?= View::e($id) ?>-help">
        <?php endif; ?>
        <?php if (!empty($field['unit'])): ?><span class="setting-unit"><?= View::e($field['unit']) ?></span><?php endif; ?>
      </div>
      <small>Recomendado: <?= View::e($recommended) ?></small>
    <?php endif; ?>
  </div>
<?php
};
?>
<nav class="breadcrumb" aria-label="Ruta de navegación"><a href="<?= View::e($base) ?>/settings">Configuración</a><span aria-hidden="true">/</span><span><?= View::e($section['title']) ?></span></nav>
<div class="page-head">
  <div><span class="eyebrow">Configuración por sección</span><h1><?= View::e($section['title']) ?></h1><p><?= View::e($section['description']) ?></p></div>
</div>
<?php if ($sectionKey === 'mercadolibre'):
    $apiPauseBase = $base;
    require __DIR__ . '/_api_emergency_controls.php';
endif; ?>

<form method="post" action="<?= View::e($base) ?>/settings/<?= View::e($sectionKey) ?>" class="settings-section-form" data-dirty-form>
  <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
  <section class="panel settings-fields">
    <header class="panel-head"><div><h2>Opciones principales</h2><p>Los cambios de esta pantalla no afectan otras secciones.</p></div></header>
    <div class="settings-fields-grid">
      <?php foreach ($basicFields as $field) $renderField($field); ?>
    </div>
  </section>
  <?php if ($advancedFields !== []): ?>
    <details class="panel settings-advanced">
      <summary><span><strong>Opciones avanzadas</strong><small>Úselas solo si conoce el impacto operativo.</small></span><span aria-hidden="true">⌄</span></summary>
      <div class="settings-fields-grid">
        <?php foreach ($advancedFields as $field) $renderField($field); ?>
      </div>
    </details>
  <?php endif; ?>
  <div class="settings-savebar" aria-live="polite">
    <span data-dirty-message>Sin cambios pendientes.</span>
    <div>
      <button class="btn" type="submit" name="action" value="restore_recommended" data-confirm="¿Restaurar los valores recomendados de esta sección?">Restaurar recomendados</button>
      <button class="btn primary" type="submit" name="action" value="save">Guardar cambios</button>
    </div>
  </div>
</form>
