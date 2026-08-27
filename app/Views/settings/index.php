<?php
use App\Core\Env;
use App\Core\View;
use App\Repositories\SettingsDefinitionRepository;

$base = rtrim(Env::get('APP_URL', ''), '/');
$sections = (new SettingsDefinitionRepository())->sections();
$sectionMeta = [
    'general' => ['General', 'Zona horaria y comportamiento general.'],
    'synchronization' => ['Sincronización', 'Colas, pausas y automatización de órdenes.'],
    'mercadolibre' => ['Mercado Libre', 'Protección API y presupuesto preventivo.'],
    'communications' => ['Atención y avisos', 'Preguntas, reclamos y notificaciones.'],
    'financial' => ['Conciliación financiera', 'Recálculos, billing y lotes.'],
    'catalogs' => ['Catálogos', 'Paginación, estadísticas y descripciones.'],
    'system' => ['Sistema', 'Procesamiento automático, diagnóstico y actualizaciones.'],
];
$cronHealthy = (string) ($cron['state'] ?? '') === 'ok';
?>
<div class="page-head">
  <div>
    <span class="eyebrow">Administración</span>
    <h1>Centro de configuración</h1>
    <p>Encuentre una opción por tema y cambie únicamente la sección que necesita.</p>
  </div>
</div>

<section class="settings-overview" aria-label="Estado del sistema">
  <article class="status-card <?= $cronHealthy ? 'is-success' : 'is-warning' ?>">
    <span class="status-card-label">Automatización</span>
    <strong><?= View::e((string) ($cron['label'] ?? 'Sin información')) ?></strong>
    <p><?= View::e((string) ($cron['message'] ?? 'Revise el estado del cron.')) ?></p>
    <a href="<?= View::e($base) ?>/settings/cron">Ver procesamiento</a>
  </article>
  <article class="status-card <?= ($diagnostic['ml_write_enabled'] ?? 'false') === 'false' ? 'is-success' : 'is-warning' ?>">
    <span class="status-card-label">Seguridad Mercado Libre</span>
    <strong>Solo lectura <?= ($diagnostic['ml_write_enabled'] ?? 'false') === 'false' ? 'activa' : 'requiere revisión' ?></strong>
    <p>Las escrituras hacia Mercado Libre deben permanecer bloqueadas.</p>
    <a href="<?= View::e($base) ?>/settings/api-health">Ver salud API</a>
  </article>
  <article class="status-card">
    <span class="status-card-label">Versión</span>
    <strong><?= View::e((string) ($diagnostic['installed_version'] ?? '—')) ?></strong>
    <p>Archivos preparados: <?= View::e((string) ($diagnostic['file_version'] ?? '—')) ?>.</p>
    <a href="<?= View::e($base) ?>/settings/update">Ver actualizaciones</a>
  </article>
</section>

<section class="panel settings-search-panel">
  <label for="settingsSearch">Buscar una configuración</label>
  <div class="settings-search">
    <input class="input" id="settingsSearch" type="search" placeholder="Ejemplo: zona horaria, preguntas, presupuesto API…" data-settings-search>
    <span aria-hidden="true">⌕</span>
  </div>
</section>

<section class="settings-card-grid" aria-label="Secciones de configuración" data-settings-grid>
<?php foreach ($sections as $key => $section): [$title, $description] = $sectionMeta[$key]; ?>
  <article class="settings-section-card" data-settings-card data-search="<?= View::e(mb_strtolower($title . ' ' . $description . ' ' . implode(' ', array_column($section['fields'], 'label')))) ?>">
    <div class="settings-section-icon" aria-hidden="true"><?= View::e(mb_substr($title, 0, 1)) ?></div>
    <div>
      <h2><?= View::e($title) ?></h2>
      <p><?= View::e($description) ?></p>
      <span><?= count($section['fields']) ?> opciones</span>
    </div>
    <a class="btn" href="<?= View::e($base) ?>/settings/<?= View::e($key) ?>">Configurar</a>
  </article>
<?php endforeach; ?>
</section>
