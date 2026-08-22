<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$migrationDiagnostic = $diagnostic['migration_diagnostic'] ?? [];
$latestMigrationFailure = $migrationDiagnostic['latest_failure'] ?? null;
$phpRuntime = $diagnostic['php_runtime'] ?? [];
$moduleProblems = array_values(array_filter($diagnostic['modules'] ?? [], static fn(array $module): bool => ($module['status'] ?? '') !== 'ok'));
$pendingMigrations = (int) ($migrationDiagnostic['pending_count'] ?? 0);
$phpProblem = ($phpRuntime['status'] ?? 'ok') !== 'ok' || !empty($phpRuntime['missing_required_extensions']);
$problemCount = count($moduleProblems) + ($pendingMigrations > 0 ? 1 : 0) + ($phpProblem ? 1 : 0);
$warningCount = count($diagnostic['recent_errors'] ?? []);
$scopeAudit = $diagnostic['business_scope'] ?? ['status' => 'failed', 'label' => 'No se pudo comprobar'];
$incidentMaterializer = $diagnostic['incident_materializer'] ?? ['available' => false, 'current' => false, 'lag' => 0];
$statusClass = $problemCount > 0 ? 'is-danger' : ($warningCount > 0 ? 'is-warning' : 'is-success');
$statusTitle = $problemCount > 0 ? 'Hay problemas que requieren acción' : ($warningCount > 0 ? 'Sistema operativo con advertencias' : 'Todo funciona correctamente');
$statusMessage = $problemCount > 0 ? "Se detectaron {$problemCount} áreas que debe revisar." : ($warningCount > 0 ? 'El sistema funciona, pero conserva eventos recientes para revisión.' : 'No se detectaron problemas en instalación, módulos o PHP.');
?>
<?php if (empty($embedded)): ?>
<div class="page-head"><div><span class="eyebrow">Soporte</span><h1>Diagnóstico del sistema</h1><p>Primero se muestran los problemas accionables; los datos técnicos quedan disponibles al final.</p></div><div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/settings/diagnostics/migrations/export">Exportar diagnóstico</a><a class="btn" href="<?= View::e($base) ?>/settings/update">Actualizaciones</a></div></div>
<?php endif; ?>

<section class="human-status-hero <?= View::e($statusClass) ?>"><div class="human-status-mark" aria-hidden="true"><?= $problemCount > 0 ? '!' : '✓' ?></div><div><span class="eyebrow">Resultado</span><h2><?= View::e($statusTitle) ?></h2><p><?= View::e($statusMessage) ?></p></div></section>

<section class="settings-overview">
  <article class="status-card <?= $pendingMigrations > 0 ? 'is-warning' : 'is-success' ?>"><span class="status-card-label">Actualización</span><strong><?= $pendingMigrations > 0 ? "{$pendingMigrations} pendientes" : 'Base al día' ?></strong><p><?= $pendingMigrations > 0 ? 'Complete la actualización para alinear archivos y base de datos.' : 'No hay migraciones pendientes.' ?></p><?php if ($pendingMigrations > 0): ?><a href="<?= View::e($base) ?>/settings/update">Completar actualización</a><?php endif; ?></article>
  <article class="status-card <?= $phpProblem ? 'is-warning' : 'is-success' ?>"><span class="status-card-label">PHP</span><strong><?= View::e((string) ($phpRuntime['version'] ?? PHP_VERSION)) ?></strong><p><?= View::e((string) ($phpRuntime['message'] ?? 'Versión compatible con el ERP.')) ?></p></article>
  <article class="status-card <?= $moduleProblems !== [] ? 'is-warning' : 'is-success' ?>"><span class="status-card-label">Módulos</span><strong><?= $moduleProblems === [] ? 'Todos disponibles' : count($moduleProblems) . ' con problema' ?></strong><p>Se verificaron <?= count($diagnostic['modules'] ?? []) ?> módulos principales.</p></article>
  <article class="status-card <?= ($scopeAudit['status'] ?? '') === 'ok' ? 'is-success' : 'is-warning' ?>"><span class="status-card-label">Empresa y cuenta</span><strong><?= View::e((string) ($scopeAudit['label'] ?? 'No se pudo comprobar')) ?></strong><p><?= View::e((string) ($scopeAudit['message'] ?? 'Abra el diagnóstico para revisar el alcance.')) ?></p></article>
  <article class="status-card <?= !empty($incidentMaterializer['current']) ? 'is-success' : 'is-warning' ?>"><span class="status-card-label">Catálogo de incidentes</span><strong><?= !empty($incidentMaterializer['current']) ? 'Al día' : (!empty($incidentMaterializer['available']) ? number_format((int) ($incidentMaterializer['lag'] ?? 0), 0, ',', '.') . ' eventos pendientes' : 'NO CERTIFICADO') ?></strong><p><?= !empty($incidentMaterializer['current']) ? 'La vista agrupada coincide con la telemetría.' : 'La telemetría directa permanece disponible; revise Salud API antes de confiar en grupos históricos.' ?></p></article>
</section>

<?php if ($problemCount > 0): ?>
<section class="panel">
  <header class="panel-head"><div><h2>Qué debe resolver</h2><p>Cada punto incluye el impacto y la acción recomendada.</p></div></header>
  <div class="human-list">
    <?php if ($pendingMigrations > 0): ?><article class="human-list-item"><div><strong>Actualización incompleta</strong><p>Afecta la disponibilidad de funciones nuevas. Es seguro continuar si el diagnóstico de migración lo permite.</p></div><a class="btn primary" href="<?= View::e($base) ?>/settings/update">Completar</a></article><?php endif; ?>
    <?php if ($phpProblem): ?><article class="human-list-item"><div><strong>Entorno PHP requiere revisión</strong><p><?= View::e((string) ($phpRuntime['message'] ?? 'Revise versión y extensiones requeridas.')) ?></p></div><a class="btn" href="#detalles-tecnicos">Ver detalle</a></article><?php endif; ?>
    <?php foreach ($moduleProblems as $module): ?><article class="human-list-item"><div><strong><?= View::e((string) $module['module']) ?></strong><p><?= View::e((string) $module['message']) ?></p></div></article><?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($warningCount > 0): ?>
<section class="panel mt-2"><header class="panel-head"><div><h2>Eventos recientes</h2><p>Los fallos iguales se agrupan para que una repetición no parezca un problema nuevo.</p></div></header><div class="human-list"><?php foreach (array_slice($diagnostic['recent_errors'], 0, 10) as $error): ?><article class="human-list-item"><div><strong><?= View::e((string) $error['message']) ?></strong><p>Último evento: <?= View::e((string) ($error['last_seen_at'] ?? $error['created_at'])) ?> · <?= View::e((string) $error['level']) ?><?php if ((int) ($error['occurrences'] ?? 1) > 1): ?> · <?= (int) $error['occurrences'] ?> repeticiones<?php endif; ?></p></div><?php if (!empty($error['context'])): ?><details><summary>Contexto seguro</summary><pre><?= View::e((string) json_encode($error['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?></article><?php endforeach; ?></div></section>
<?php endif; ?>

<details class="technical-details" id="detalles-tecnicos">
  <summary><span>Detalles técnicos del sistema</span><span aria-hidden="true">⌄</span></summary>
  <div class="technical-details-body">
    <div class="table-scroll"><table class="data-table"><caption>Instalación y seguridad</caption><tbody><?php foreach (['file_version' => 'Versión de archivos', 'installed_version' => 'Versión instalada', 'config_env' => 'Configuración privada', 'app_key' => 'Llave de aplicación', 'install_lock' => 'Bloqueo de instalación', 'ml_write_enabled' => 'Escrituras Mercado Libre'] as $key => $label): ?><tr><th><?= View::e($label) ?></th><td><?= View::e((string) ($diagnostic[$key] ?? '—')) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <div class="table-scroll mt-2"><table class="data-table"><caption>Compatibilidad PHP 8.3–8.5 y servidor web</caption><tbody><tr><th>Rango soportado</th><td><?= View::e((string) ($phpRuntime['supported_range'] ?? '>=8.3 <8.6')) ?></td></tr><tr><th>Versión</th><td><?= View::e((string) ($phpRuntime['version'] ?? PHP_VERSION)) ?></td></tr><tr><th>SAPI</th><td><?= View::e((string) ($phpRuntime['sapi'] ?? PHP_SAPI)) ?></td></tr><tr><th>Binario</th><td><code><?= View::e((string) ($phpRuntime['binary'] ?? PHP_BINARY)) ?></code></td></tr><tr><th>php.ini</th><td><code><?= View::e((string) ($phpRuntime['loaded_ini'] ?? 'ninguno')) ?></code></td></tr><tr><th>Extensiones faltantes</th><td><?= View::e(implode(', ', $phpRuntime['missing_required_extensions'] ?? []) ?: 'ninguna') ?></td></tr><tr><th>OPcache</th><td><?= View::e((string) ($phpRuntime['opcache'] ?? 'no disponible')) ?></td></tr></tbody></table></div>
    <div class="table-scroll mt-2"><table class="data-table"><caption>Módulos principales</caption><thead><tr><th>Módulo</th><th>Ruta</th><th>Estado</th><th>Detalle</th></tr></thead><tbody><?php foreach (($diagnostic['modules'] ?? []) as $module): ?><tr><td><?= View::e((string) $module['module']) ?></td><td><code><?= View::e((string) $module['path']) ?></code></td><td><?= View::e((string) $module['status']) ?></td><td><?= View::e((string) $module['message']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <div class="table-scroll mt-2"><table class="data-table"><caption>Carpetas de almacenamiento</caption><tbody><?php foreach (($diagnostic['storage'] ?? []) as $directory => $status): ?><tr><th><?= View::e((string) $directory) ?></th><td><?= View::e((string) $status) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <p class="mt-2"><a class="btn" href="<?= View::e($base) ?>/settings/diagnostics/migrations">Abrir diagnóstico detallado de migraciones</a></p>
  </div>
</details>
