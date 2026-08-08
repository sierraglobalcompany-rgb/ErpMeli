<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\AppSettingsService;

$base = rtrim(Env::get('APP_URL', ''), '/');
$phpRuntime = $diagnostic['php_runtime'] ?? [];
$migrationDiagnostic = $diagnostic['migration_diagnostic'] ?? [];
$latestMigrationFailure = $migrationDiagnostic['latest_failure'] ?? null;
$migrationRecovery = $migrationDiagnostic['recovery'] ?? null;
$settings = new AppSettingsService();
$engineReady = (bool) ($engine['engine_ready'] ?? false);
$activeRun = $run ?? ($engine['active_run'] ?? null);
$pendingMigrations = count(array_filter($diagnostic['migrations'], static fn(array $migration): bool => !$migration['applied']));
$remoteCount = count($engine['remote_releases'] ?? []);
$hasMigrationFailure = is_array($latestMigrationFailure);
$updateState = $hasMigrationFailure ? 'blocked' : ($pendingMigrations > 0 ? 'incomplete' : 'current');
$updateStateLabel = [
    'current' => 'Sistema al día',
    'incomplete' => 'Actualización por completar',
    'blocked' => 'Actualización necesita revisión',
][$updateState];
$updateStateMessage = [
    'current' => 'Los archivos y la base de datos están alineados. No hay migraciones pendientes.',
    'incomplete' => "Los archivos ya están cargados. Falta aplicar {$pendingMigrations} " . ($pendingMigrations === 1 ? 'migración segura' : 'migraciones seguras') . '.',
    'blocked' => !empty($migrationRecovery['authorized'])
        ? 'Se confirmó el fallo conocido de MariaDB y la recuperación certificada está lista.'
        : 'El último intento se detuvo de forma segura. Revise el diagnóstico antes de volver a intentarlo.',
][$updateState];
$formatBytes = static function (int $bytes): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B','KB','MB','GB','TB'];
    $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return number_format($bytes / (1024 ** $power), $power > 1 ? 1 : 0, ',', '.') . ' ' . $units[$power];
};
$stateLabels = [
    'discovered'=>'Detectada','verified'=>'Verificada','diagnosing'=>'Diagnosticando',
    'path_resolved'=>'Ruta compatible','awaiting_backup_choice'=>'Esperando respaldo',
    'backing_up'=>'Respaldando','backup_skipped'=>'Sin respaldo','staging'=>'Preparando release',
    'bridging'=>'Aplicando puente','migrating'=>'Migrando','activating'=>'Activando',
    'health_check'=>'Comprobando','completed'=>'Completa','paused'=>'Pausada','failed'=>'Fallida',
    'rollback_pending'=>'Rollback requerido','rolled_back'=>'Revertida','manual_intervention'=>'Intervención manual',
];
?>
<link rel="stylesheet" href="<?= View::e(View::asset($base, 'update.css')) ?>&amp;v=2.11.2">
<div class="page-head">
  <div><h1>Actualizaciones del sistema</h1><p>Complete una actualización o consulte las herramientas técnicas cuando las necesite.</p></div>
  <div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/settings/diagnostics">Diagnóstico</a><a class="btn" href="<?= View::e($base) ?>/settings">Configuración</a></div>
</div>

<section class="operation-explainer" aria-label="Siguiente paso de la actualización">
  <div><span>Qué está pasando</span><strong><?= View::e($updateStateLabel) ?></strong></div>
  <div><span>Qué hará el ERP</span><strong><?= $pendingMigrations > 0 ? 'Aplicará una migración segura por paso.' : 'Conservará la versión instalada.' ?></strong></div>
  <div><span>Qué puede hacer ahora</span><strong><?= $hasMigrationFailure ? 'Revise la causa antes de reintentar.' : ($pendingMigrations > 0 ? 'Complete la actualización.' : 'No necesita hacer nada.') ?></strong></div>
</section>

<section class="metrics update-metrics">
<?php
$metricRows = [
    ['Versión instalada', $diagnostic['installed_version']],
    ['Versión preparada', $diagnostic['file_version']],
    ['Migraciones pendientes', $pendingMigrations],
    ['Actualización remota', $remoteCount > 0 ? "{$remoteCount} disponible" : 'Sin novedades'],
];
foreach ($metricRows as [$label, $value]):
?>
  <article class="metric-card"><div><div class="metric-label"><?= View::e((string) $label) ?></div><div class="metric-value"><?= View::e((string) $value) ?></div></div></article>
<?php endforeach; ?>
</section>

<section class="panel update-guided-state is-<?= View::e($updateState) ?>">
  <div class="update-guided-icon" aria-hidden="true"><?= $updateState === 'current' ? '✓' : ($updateState === 'blocked' ? '!' : '↻') ?></div>
  <div class="update-guided-copy">
    <span class="eyebrow">Estado de la actualización</span>
    <h2><?= View::e($updateStateLabel) ?></h2>
    <p><?= View::e($updateStateMessage) ?></p>
    <?php if ($pendingMigrations > 0): ?>
      <form method="post" action="<?= View::e($base) ?>/settings/update/migrate" data-confirm="¿Completar ahora la actualización de la base de datos?">
        <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
        <label>Confirme su contraseña<input class="input compact" type="password" name="admin_password" autocomplete="current-password" required></label>
        <button class="btn primary" type="submit">Completar actualización</button>
      </form>
    <?php endif; ?>
  </div>
</section>

<?php if ($latestMigrationFailure): ?>
<div class="alert <?= !empty($migrationRecovery['authorized']) ? 'warning' : 'danger' ?>">
  <strong><?= !empty($migrationRecovery['authorized']) ? 'Recuperación certificada disponible.' : 'El último intento no terminó.' ?></strong>
  <?= View::e((string)($migrationDiagnostic['recommendation'] ?? 'Revise el diagnóstico antes de continuar.')) ?>
  <details class="inline-technical-detail">
    <summary>Ver detalles técnicos</summary>
    <p>Migración <?= View::e((string)($latestMigrationFailure['migration_key'] ?? 'sin seleccionar')) ?> ·
    etapa <?= View::e((string)($latestMigrationFailure['stage'] ?? 'desconocida')) ?> ·
    diagnóstico <code><?= View::e((string)($latestMigrationFailure['diagnostic_id'] ?? 'sin registro')) ?></code>.</p>
    <a class="link" href="<?= View::e($base) ?>/settings/diagnostics/migrations">Abrir trazabilidad completa</a>
  </details>
</div>
<?php endif; ?>

<?php if ($activeRun): ?>
<section class="panel update-run-panel" data-update-run data-current-state="<?= View::e((string) $activeRun['state']) ?>" data-status-url="<?= View::e($base) ?>/settings/update/status.json?run_id=<?= (int) $activeRun['id'] ?>">
  <header class="panel-head">
    <div><h2>Actualización #<?= (int) $activeRun['id'] ?> · <?= View::e((string) $activeRun['version_from']) ?> → <?= View::e((string) $activeRun['version_to']) ?></h2>
    <p><?= View::e($stateLabels[$activeRun['state']] ?? (string) $activeRun['state']) ?> · modo <?= View::e((string) $activeRun['mode']) ?></p></div>
    <span class="badge <?= in_array($activeRun['state'], ['failed','manual_intervention','rollback_pending'], true) ? 'red' : ($activeRun['state'] === 'completed' ? 'green' : 'amber') ?>" data-update-state><?= View::e($stateLabels[$activeRun['state']] ?? (string) $activeRun['state']) ?></span>
  </header>
  <div class="update-progress"><span style="width:<?= max(0, min(100, (float) $activeRun['progress_percent'])) ?>%" data-update-progress></span></div>
  <div class="update-run-summary">
    <div><small>Etapa</small><strong data-update-step><?= View::e((string) ($activeRun['current_step'] ?? '—')) ?></strong></div>
    <div><small>Progreso</small><strong data-update-percent><?= View::e(number_format((float) $activeRun['progress_percent'], 1, ',', '.')) ?> %</strong></div>
    <div><small>Clasificación esquema</small><strong><?= View::e((string) ($activeRun['schema_classification'] ?? 'pendiente')) ?></strong></div>
    <div><small>Siguiente acción</small><strong><?= View::e((string) ($activeRun['next_action'] ?? '—')) ?></strong></div>
  </div>
  <?php if (!empty($activeRun['safe_error_message'])): ?><div class="alert danger"><strong><?= View::e((string) $activeRun['safe_error_code']) ?>:</strong> <?= View::e((string) $activeRun['safe_error_message']) ?></div><?php endif; ?>
  <div class="update-step-list">
  <?php foreach ($activeRun['steps'] ?? [] as $step): ?>
    <div class="update-step <?= View::e((string) $step['status']) ?>"><span></span><div><strong><?= View::e(str_replace('_', ' ', (string) $step['step_key'])) ?></strong><small><?= View::e((string) ($step['safe_message'] ?? $step['status'])) ?></small></div></div>
  <?php endforeach; ?>
  </div>
  <div class="page-actions update-actions">
    <?php if (!in_array($activeRun['state'], ['completed','rolled_back','manual_intervention'], true)): ?>
      <?php if (in_array($activeRun['state'], ['paused','failed'], true)): ?>
      <form method="post" action="<?= View::e($base) ?>/settings/update/run/resume"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="run_id" value="<?= (int) $activeRun['id'] ?>"><button class="btn primary">Reanudar</button></form>
      <?php else: ?>
      <form method="post" action="<?= View::e($base) ?>/settings/update/run/process"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="run_id" value="<?= (int) $activeRun['id'] ?>"><button class="btn primary"><?= $activeRun['state'] === 'failed' ? 'Revisar estado' : 'Ejecutar siguiente etapa' ?></button></form>
      <form method="post" action="<?= View::e($base) ?>/settings/update/run/pause"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="run_id" value="<?= (int) $activeRun['id'] ?>"><button class="btn">Pausar</button></form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (in_array($activeRun['state'], ['completed','rollback_pending','failed'], true) && !empty($activeRun['previous_release_id'])): ?>
    <form method="post" action="<?= View::e($base) ?>/settings/update/run/rollback" data-confirm="¿Activar la release anterior? Si la base cambió de forma incompatible también necesitará restaurar el backup."><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="run_id" value="<?= (int) $activeRun['id'] ?>"><input class="input compact" type="password" name="admin_password" autocomplete="current-password" aria-label="Contraseña administrativa" placeholder="Confirme su contraseña" required><button class="btn danger">Rollback de código</button></form>
    <?php endif; ?>
  </div>
  <?php if (!empty($activeRun['events'])): ?><details class="update-events"><summary>Ver registro seguro</summary><table class="data-table"><thead><tr><th>Fecha</th><th>Nivel</th><th>Evento</th><th>Mensaje</th></tr></thead><tbody><?php foreach ($activeRun['events'] as $event): ?><tr><td><?= View::e((string) $event['created_at']) ?></td><td><?= View::e((string) $event['level']) ?></td><td><?= View::e((string) $event['event_code']) ?></td><td><?= View::e((string) $event['message']) ?></td></tr><?php endforeach; ?></tbody></table></details><?php endif; ?>
</section>
<?php endif; ?>

<details class="panel update-advanced">
<summary>
  <span><strong>Opciones avanzadas</strong><small>Paquetes, carpetas completas, servidor remoto, respaldos y recuperación.</small></span>
  <span aria-hidden="true">⌄</span>
</summary>
<div class="update-advanced-body">
<?php if ($engineReady): ?>
<div class="update-columns">
<section class="panel">
  <header class="panel-head"><div><h2>Paquete manual .erpupd</h2><p>El paquete se verifica antes de crear una ejecución.</p></div></header>
  <form method="post" enctype="multipart/form-data" action="<?= View::e($base) ?>/settings/update/package" class="stack-form">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <input class="input" type="file" name="package" accept=".erpupd" required>
    <label>Confirme su contraseña<input class="input" type="password" name="admin_password" autocomplete="current-password" required></label>
    <button class="btn primary">Cargar paquete</button>
  </form>
</section>
<section class="panel">
  <header class="panel-head"><div><h2>Carpeta completa</h2><p>Suba una carpeta a <code>storage/update-inbox/</code>. Se detectará sin reemplazar la instalación activa.</p></div></header>
  <a class="btn" href="<?= View::e($base) ?>/settings/update">Volver a examinar carpeta de entrada</a>
</section>
</div>

<section class="panel table-panel">
  <header class="panel-head"><div><h2>Fuentes manuales disponibles</h2><p>El despliegue atómico es recomendado; la sobrescritura clásica se conserva para compatibilidad.</p></div></header>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Fuente</th><th>Tipo</th><th>Versión</th><th>Tamaño</th><th>Preparar</th></tr></thead><tbody>
  <?php if (empty($engine['local_sources'])): ?><tr><td colspan="5" class="empty">No hay paquetes ni carpetas en la bandeja de actualización.</td></tr><?php endif; ?>
  <?php foreach ($engine['local_sources'] ?? [] as $source): ?>
    <tr><td><strong><?= View::e((string) $source['name']) ?></strong><?php if (!empty($source['error'])): ?><small class="text-danger"><?= View::e((string) $source['error']) ?></small><?php endif; ?></td>
    <td><?= View::e((string) $source['type']) ?></td><td><?= View::e((string) ($source['version'] ?? 'se verificará')) ?></td><td><?= View::e($formatBytes((int) $source['size'])) ?></td>
    <td><?php if (($source['type'] ?? '') !== 'invalid_folder'): ?>
      <form method="post" action="<?= View::e($base) ?>/settings/update/run" class="update-source-form" data-backup-choice>
        <input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="source_key" value="<?= View::e((string) $source['key']) ?>"><input type="hidden" name="source_type" value="local">
        <input class="input compact" type="password" name="admin_password" autocomplete="current-password" aria-label="Contraseña administrativa" placeholder="Confirme su contraseña" required>
        <input type="hidden" name="mode" value="atomic">
        <span class="badge green">Instalación atómica obligatoria</span>
        <label class="check-row"><input type="checkbox" name="backup_requested" value="1" <?= $settings->bool('update.backup_default', true) ? 'checked' : '' ?> data-backup-checkbox> Crear backup verificado</label>
        <button class="btn primary small">Preparar</button>
      </form>
    <?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>

<section class="panel table-panel">
  <header class="panel-head"><div><h2>Versiones remotas</h2><p>Canal <?= View::e((string) $settings->get('update.channel', 'stable')) ?> · servidor privado HTTPS.</p></div><a class="btn" href="<?= View::e($base) ?>/settings/update?refresh_remote=1">Actualizar catálogo remoto</a></header>
  <?php if (!empty($engine['remote_error'])): ?><div class="alert warning"><?= View::e((string) $engine['remote_error']) ?></div><?php endif; ?>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Versión</th><th>Canal</th><th>Publicada</th><th>Estado</th><th>Preparar</th></tr></thead><tbody>
  <?php if (empty($engine['remote_releases'])): ?><tr><td colspan="5" class="empty">No hay versiones remotas disponibles o el servidor no está configurado.</td></tr><?php endif; ?>
  <?php foreach ($engine['remote_releases'] ?? [] as $release): $withdrawn=(bool)($release['withdrawn'] ?? false); ?>
    <tr><td><strong><?= View::e((string) ($release['version'] ?? '—')) ?></strong></td><td><?= View::e((string) ($release['channel'] ?? 'stable')) ?></td><td><?= View::e((string) ($release['published_at'] ?? '—')) ?></td>
    <td><span class="badge <?= $withdrawn ? 'red' : 'green' ?>"><?= $withdrawn ? 'Retirada' : 'Disponible' ?></span></td><td>
      <?php if (!$withdrawn && !empty($release['package_url'])): ?><form method="post" action="<?= View::e($base) ?>/settings/update/run" class="update-source-form" data-backup-choice>
        <input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="source_type" value="remote"><input type="hidden" name="source_key" value="<?= View::e(hash('sha256', (string) $release['package_url'])) ?>">
        <input class="input compact" type="password" name="admin_password" autocomplete="current-password" aria-label="Contraseña administrativa" placeholder="Confirme su contraseña" required>
        <input type="hidden" name="mode" value="atomic"><label class="check-row"><input type="checkbox" name="backup_requested" value="1" <?= $settings->bool('update.backup_default', true) ? 'checked' : '' ?> data-backup-checkbox> Backup verificado</label>
        <button class="btn primary small">Descargar y preparar</button>
      </form><?php endif; ?>
    </td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>

<div class="update-columns">
<section class="panel">
  <header class="panel-head"><h2>Configuración del motor</h2></header>
  <form method="post" action="<?= View::e($base) ?>/settings/update/settings" class="stack-form">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <label>Confirme su contraseña<input class="input" type="password" name="admin_password" autocomplete="current-password" required></label>
    <label>Servidor privado HTTPS<input class="input" type="url" name="remote_url" value="<?= View::e((string) $settings->get('update.remote_url', '')) ?>" placeholder="https://updates.ejemplo.com"></label>
    <label>Canal<select class="input" name="channel"><?php foreach (['stable'=>'Estable','beta'=>'Beta','internal'=>'Interno'] as $value=>$label): ?><option value="<?= $value ?>" <?= $settings->get('update.channel','stable')===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
    <label class="check-row"><input type="checkbox" name="backup_default" value="1" <?= $settings->bool('update.backup_default',true)?'checked':'' ?>> Backup seleccionado por defecto</label>
    <label class="check-row"><input type="checkbox" name="telemetry_enabled" value="1" <?= $settings->bool('update.telemetry_enabled',false)?'checked':'' ?>> Telemetría técnica anónima</label>
    <button class="btn primary">Guardar configuración</button>
  </form>
</section>
<section class="panel">
  <header class="panel-head"><h2>Clave pública confiable</h2></header>
  <form method="post" action="<?= View::e($base) ?>/settings/update/trusted-key" class="stack-form">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <label>Confirme su contraseña<input class="input" type="password" name="admin_password" autocomplete="current-password" required></label>
    <label>ID de clave<input class="input" name="key_id" required placeholder="release-key-2026"></label>
    <label>Clave pública PEM<textarea class="input" name="public_key" rows="5" required placeholder="-----BEGIN PUBLIC KEY-----"></textarea></label>
    <button class="btn">Registrar o rotar clave</button>
  </form>
  <?php if (!empty($engine['trusted_keys'])): ?>
  <div class="table-scroll mt-2"><table class="data-table"><thead><tr><th>Clave</th><th>Canales</th><th>Estado</th><th></th></tr></thead><tbody>
  <?php foreach ($engine['trusted_keys'] as $trustedKey): ?>
    <tr>
      <td><?= View::e((string) $trustedKey['key_id']) ?></td>
      <td><?= View::e(implode(', ', (array) (json_decode((string) ($trustedKey['channels_json'] ?? '[]'), true) ?: []))) ?></td>
      <td><?= View::e((string) $trustedKey['status']) ?></td>
      <td><?php if (($trustedKey['status'] ?? '') !== 'revoked'): ?><form method="post" action="<?= View::e($base) ?>/settings/update/trusted-key/revoke" data-confirm="¿Revocar esta clave? Los paquetes nuevos firmados con ella dejarán de aceptarse."><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="key_id" value="<?= View::e((string) $trustedKey['key_id']) ?>"><input class="input compact" type="password" name="admin_password" autocomplete="current-password" aria-label="Contraseña administrativa" placeholder="Confirme su contraseña" required><button class="btn danger small">Revocar</button></form><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>
</div>

<section class="panel table-panel">
  <header class="panel-head"><h2>Historial de ejecuciones</h2></header>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>ID</th><th>Versiones</th><th>Modo</th><th>Estado</th><th>Progreso</th><th>Fecha</th></tr></thead><tbody>
  <?php foreach ($engine['runs'] ?? [] as $history): ?><tr><td><a class="link" href="<?= View::e($base) ?>/settings/update?run_id=<?= (int) $history['id'] ?>">#<?= (int) $history['id'] ?></a></td><td><?= View::e((string) $history['version_from']) ?> → <?= View::e((string) $history['version_to']) ?></td><td><?= View::e((string) $history['mode']) ?></td><td><?= View::e($stateLabels[$history['state']] ?? (string) $history['state']) ?></td><td><?= View::e(number_format((float)$history['progress_percent'],1,',','.')) ?> %</td><td><?= View::e((string)$history['created_at']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>

<section class="panel table-panel">
  <header class="panel-head"><h2>Backups verificados</h2></header>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>ID</th><th>Ejecución</th><th>Adaptador</th><th>Estado</th><th>Tamaño</th><th>Verificado</th><th>Expira</th></tr></thead><tbody>
  <?php if (empty($engine['backups'])): ?><tr><td colspan="7" class="empty">Todavía no hay backups del motor nuevo.</td></tr><?php endif; ?>
  <?php foreach ($engine['backups'] ?? [] as $backup): ?><tr><td>#<?= (int)$backup['id'] ?></td><td>#<?= (int)$backup['run_id'] ?></td><td><?= View::e((string)$backup['adapter']) ?></td><td><?= View::e((string)$backup['status']) ?></td><td><?= View::e($formatBytes((int)$backup['size_bytes'])) ?></td><td><?= View::e((string)($backup['verified_at']??'—')) ?></td><td><?= View::e((string)($backup['expires_at']??'—')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif; ?>

<section class="panel table-panel">
  <header class="panel-head"><div><h2>Compatibilidad del servidor</h2><p>El PHP web y el cron deben usar la misma rama soportada.</p></div></header>
  <div class="table-scroll"><table class="data-table"><tbody>
    <tr><th>Rango PHP</th><td><?= View::e((string)($phpRuntime['supported_range'] ?? '>=8.3 <8.6')) ?></td></tr>
    <tr><th>PHP web</th><td><?= View::e((string)($phpRuntime['version'] ?? PHP_VERSION)) ?> · <?= View::e((string)($phpRuntime['sapi'] ?? PHP_SAPI)) ?></td></tr>
    <tr><th>Adaptador</th><td><?= View::e((string)($engine['capabilities']['adapter'] ?? 'por detectar')) ?></td></tr>
    <tr><th>mysqldump</th><td><?= View::e((string)($engine['capabilities']['mysqldump'] ?? 'no disponible; se usará streaming PHP')) ?></td></tr>
    <tr><th>ZIP</th><td><?= !empty($engine['capabilities']['zip']) ? 'disponible' : 'no disponible (carpetas completas siguen funcionando)' ?></td></tr>
    <tr><th>Espacio libre</th><td><?= View::e($formatBytes((int)($engine['capabilities']['disk_free_bytes'] ?? 0))) ?></td></tr>
  </tbody></table></div>
  <div class="page-actions mt-2">
    <form method="post" action="<?= View::e($base) ?>/settings/update/clear-cache"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><button class="btn">Limpiar caché y OPcache</button></form>
    <form method="post" action="<?= View::e($base) ?>/settings/update/key" data-confirm="La llave anterior dejará de servir. La nueva también habilita el panel de rescate por 24 horas."><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input class="input compact" type="password" name="admin_password" autocomplete="current-password" aria-label="Contraseña administrativa" placeholder="Confirme su contraseña" required><button class="btn">Generar llave de rescate</button></form>
    <a class="btn" href="<?= View::e($base) ?>/actualizar.php">Abrir actualizador seguro</a>
  </div>
</section>
</div>
</details>
<script src="<?= View::e(View::asset($base, 'update.js')) ?>&amp;v=2.11.2"></script>
