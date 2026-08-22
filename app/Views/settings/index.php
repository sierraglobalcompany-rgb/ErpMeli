<?php
use App\Core\Env;
use App\Core\View;
use App\Repositories\SettingsDefinitionRepository;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$sections = (new SettingsDefinitionRepository())->sections();
$sectionMeta = [
    'general' => ['General', 'Zona horaria y comportamiento general.'],
    'synchronization' => ['Sincronización', 'Colas, pausas y automatización de órdenes.'],
    'mercadolibre' => ['Mercado Libre', 'Protección API y presupuesto preventivo.'],
    'communications' => ['Atención y avisos', 'Preguntas, reclamos y notificaciones.'],
    'financial' => ['Conciliación financiera', 'Recálculos, billing y lotes.'],
    'catalogs' => ['Catálogos', 'Paginación, estadísticas y descripciones.'],
    'system' => ['Sistema', 'Cron, diagnóstico y actualizaciones.'],
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
    <a href="<?= View::e($base) ?>/settings/cron">Ver cron</a>
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

<section class="panel settings-tools">
  <header class="panel-head">
    <div><h2>Herramientas del sistema</h2><p>Use estas opciones para supervisión, soporte y mantenimiento.</p></div>
  </header>
  <div class="settings-tool-grid">
    <a href="<?= View::e($base) ?>/settings/manual-processing"><strong>Procesar ahora · MANUAL_EXACT</strong><span><?php
      echo View::e(!empty($manualEngine['ready'])
        ? 'Prepare una campaña dirigida y supervise su avance.'
        : (($manualEngine['state'] ?? '') === 'maintenance'
            ? 'En mantenimiento: las campañas conservan su progreso.'
            : 'Complete la actualización para habilitar las campañas.'));
    ?></span></a>
    <a href="<?= View::e($base) ?>/settings/api-health"><strong>Salud API Mercado Libre</strong><span>Estado, pausas y capacidad por cuenta.</span></a>
    <a href="<?= View::e($base) ?>/settings/cron"><strong>Automatización y cron</strong><span>Última ejecución, próxima tarea y recuperación.</span></a>
    <a href="<?= View::e($base) ?>/settings/diagnostics"><strong>Diagnóstico</strong><span>Problemas detectados y acciones recomendadas.</span></a>
    <a href="<?= View::e($base) ?>/settings/update"><strong>Actualizaciones</strong><span>Versión, migraciones y recuperación segura.</span></a>
    <a href="<?= View::e($base) ?>/settings/backups"><strong>Copias y recuperación · LOCAL_ONLY</strong><span>Copias completas cifradas, verificación y restauración segura.</span></a>
    <a href="<?= View::e($base) ?>/settings/database-maintenance"><strong>Saneamiento de base de datos · LOCAL_ONLY</strong><span>Analice, archive y retire ruido técnico con copia verificada.</span></a>
    <a href="<?= View::e($base) ?>/settings/imported-data-reset"><strong>Restablecer datos importados · LOCAL_ONLY</strong><span>Retire copias de Mercado Libre sin borrar cuentas, tokens, configuración ni evidencia.</span></a>
    <a href="<?= View::e($base) ?>/settings/emergency-control"><strong>Freno de mano</strong><span>Prepare o rote el acceso independiente de emergencia.</span></a>
    <a href="<?= View::e($base) ?>/settings/api-docs"><strong>Documentación API</strong><span>Contratos y cobertura técnica.</span></a>
    <a href="<?= View::e($base) ?>/settings/api-logs"><strong>Historial API</strong><span>Errores agrupados y evidencia sanitizada.</span></a>
  </div>
</section>
