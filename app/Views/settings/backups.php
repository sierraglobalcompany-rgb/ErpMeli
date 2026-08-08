<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\UiLabelPresenter;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$statusLabels = [
    'prepared' => 'Preparada', 'queued' => 'Lista para crear en esta pestaña', 'creating' => 'Creando',
    'verifying' => 'Verificando', 'ready_pending_release' => 'Liberando protección',
    'ready' => 'Lista y verificada', 'failed' => 'Fallida',
    'cancel_requested' => 'Cancelación solicitada',
    'deleting' => 'Eliminando de forma segura',
];
$formatBytes = static function (?int $bytes): string {
    if ($bytes === null) return 'Por calcular';
    $units = ['B','KB','MB','GB','TB'];
    $value = max(0, $bytes);
    $unit = 0;
    while ($value >= 1024 && $unit < count($units)-1) { $value /= 1024; $unit++; }
    return number_format($value, $unit === 0 ? 0 : 1, ',', '.') . ' ' . $units[$unit];
};
$formatDuration = static function (?int $seconds): string {
    if ($seconds === null) {
        return 'Calculando';
    }
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remaining = $seconds % 60;
    if ($hours > 0) {
        return sprintf('%d h %02d min', $hours, $minutes);
    }
    if ($minutes > 0) {
        return sprintf('%d min %02d s', $minutes, $remaining);
    }
    return sprintf('%d s', $remaining);
};
$formatEta = static function (?int $min, ?int $max) use ($formatDuration): string {
    if ($min === null || $max === null) {
        return 'Calculando';
    }
    return $formatDuration($min) . ' – ' . $formatDuration($max);
};
$requestedReturn = trim((string) ($_GET['return_to'] ?? ''));
$safeReturn = preg_match(
    '~^/settings/database-maintenance(?:\?id=\d+)?(?:\#[A-Za-z0-9_-]+)?$~',
    $requestedReturn
) === 1 ? $requestedReturn : '';
$returnSessionId = preg_match('/[?&]id=(\d+)/', $safeReturn, $returnMatch) === 1
    ? (int) $returnMatch[1]
    : 0;
$contextReadyArchive = null;
foreach ($archives as $archiveCandidate) {
    if (
        (string) ($archiveCandidate['status'] ?? '') === 'ready'
        && (string) ($archiveCandidate['context_type'] ?? '') === 'database_sanitation'
        && (int) ($archiveCandidate['context_id'] ?? 0) === $returnSessionId
    ) {
        $contextReadyArchive = $archiveCandidate;
        break;
    }
}
$activeJob = is_array($active['job'] ?? null) ? $active['job'] : [];
$progress = is_array($active['progress'] ?? null)
    ? $active['progress']
    : (is_array($progress ?? null) ? $progress : []);
$progressPercent = max(0.0, min(100.0, (float) ($progress['percent'] ?? 0)));
$progressActivity = is_array($progress['activity'] ?? null) ? $progress['activity'] : [];
?>
<div
  data-backup-monitor
  data-status-url="<?= View::e($base) ?>/settings/backups/status.json<?= !empty($active['id']) ? '?backup=' . (int) $active['id'] : '' ?>"
  data-step-url="<?= View::e($base) ?>/settings/backups/interactive/step"
  data-csrf="<?= View::e(Csrf::token()) ?>"
  data-active-id="<?= (int) ($active['id'] ?? 0) ?>"
  data-active-status="<?= View::e((string) ($active['status'] ?? '')) ?>"
>
<div class="page-head">
  <div>
    <span class="eyebrow">Configuración · protección local</span>
    <h1>Copias y recuperación</h1>
    <p>Cree copias completas cifradas sin consultar ni modificar Mercado Libre.</p>
  </div>
  <div class="page-actions"><a class="btn primary" href="<?= View::e($base) ?>/settings/backups/restore">Restaurar una copia</a><a class="btn" href="<?= View::e($base) ?>/settings">Volver a Configuración</a></div>
</div>

<section class="operation-explainer" aria-label="Estado de la copia">
  <div><span>Qué está pasando</span><strong><?= !empty($active['id']) ? View::e((string) ($progress['status_label'] ?? 'Copia en curso')) : ($latest ? 'Hay una copia verificada.' : 'Aún no hay una copia verificada.') ?></strong></div>
  <div><span>Qué hará el ERP</span><strong><?= !empty($active['id']) ? 'Esta pestaña continuará el siguiente micro-lote.' : 'Esperará a que usted inicie una copia.' ?></strong></div>
  <div><span>Qué puede hacer ahora</span><strong><?= !empty($active['id']) ? 'Mantenga esta pestaña abierta o vuelva después para continuar.' : 'Cree una copia antes de un cambio importante.' ?></strong></div>
</section>

<section class="settings-overview" aria-label="Estado de protección">
  <article class="status-card <?= $latest ? 'is-success' : 'is-warning' ?>">
    <span class="status-card-label">Última copia verificada</span>
    <strong><?= $latest ? View::e((string) $latest['verified_at']) : 'Todavía no existe' ?></strong>
    <p><?= $latest ? $formatBytes((int) $latest['size_bytes']) . ' · ' . (int) $latest['table_count'] . ' tablas' : 'Cree la primera copia antes de continuar la auditoría.' ?></p>
  </article>
  <article class="status-card">
    <span class="status-card-label">Espacio disponible</span>
    <strong><?= $formatBytes(is_int($free_bytes) ? $free_bytes : null) ?></strong>
    <p>El ERP exigirá al menos 2,5 veces el tamaño estimado.</p>
  </article>
  <article class="status-card <?= $key_ready && $key_exported ? 'is-success' : 'is-warning' ?>">
    <span class="status-card-label">Clave de recuperación</span>
    <strong><?= $key_exported ? 'Paquete externo generado' : ($key_ready ? 'Falta guardar copia externa' : 'Requiere preparación') ?></strong>
    <p><?= $key_exported ? 'Existe evidencia de una exportación; confirme que puede localizarla.' : 'Descargue y conserve externamente el paquete protegido por su contraseña.' ?></p>
  </article>
  <article class="status-card">
    <span class="status-card-label">Copias conservadas</span>
    <strong><?= count($archives) ?></strong>
    <p>Las copias manuales no vencen automáticamente.</p>
  </article>
</section>

<?php if ($contextReadyArchive && $safeReturn !== ''): ?>
<section class="panel notice success">
  <div>
    <strong>Copia verificada</strong>
    <p>La copia quedó fijada a esta sesión. Ya puede continuar sin usar otra copia global.</p>
  </div>
  <a class="btn primary" href="<?= View::e($base . $safeReturn) ?>">Volver al saneamiento</a>
</section>
<?php endif; ?>

<?php if ($active): ?>
<section class="backup-workbench" aria-live="polite">
  <header class="backup-workbench-head">
    <div>
      <span class="eyebrow">Copia local por navegador</span>
      <h2>Copia en progreso</h2>
      <p data-backup-runtime><?= View::e((string) ($progress['message'] ?? 'Esta pestaña está creando la copia por lotes locales.')) ?></p>
    </div>
    <span class="backup-state-pill" data-backup-state><?= View::e((string) ($progress['status_label'] ?? ($statusLabels[(string)$active['status']] ?? (string)$active['status']))) ?></span>
  </header>

  <div class="backup-progress-hero">
    <div class="backup-progress-copy">
      <strong data-backup-percent><?= number_format($progressPercent, 1, ',', '.') ?> %</strong>
      <span data-backup-progress><?= (int) ($progress['tables_completed'] ?? 0) ?> de <?= (int) ($progress['tables_total'] ?? 0) ?> tablas</span>
    </div>
    <div
      class="backup-progress-bar"
      role="progressbar"
      aria-label="Progreso de la copia"
      aria-valuemin="0"
      aria-valuemax="100"
      aria-valuenow="<?= View::e((string) $progressPercent) ?>"
      data-backup-progressbar
    ><span data-backup-progressfill style="transform: scaleX(<?= View::e((string) ($progressPercent / 100)) ?>)"></span></div>
  </div>

  <div class="backup-progress-stats" aria-label="Indicadores de avance">
    <span><small>Tablas</small><strong data-backup-tables><?= (int) ($progress['tables_completed'] ?? 0) ?> / <?= (int) ($progress['tables_total'] ?? 0) ?></strong></span>
    <span><small>Filas</small><strong data-backup-rows><?= number_format((int) ($progress['rows_processed'] ?? 0), 0, ',', '.') ?></strong></span>
    <span><small>Fragmentos</small><strong data-backup-chunks><?= number_format((int) ($progress['chunks_completed'] ?? 0), 0, ',', '.') ?></strong></span>
    <span><small>Tiempo</small><strong data-backup-elapsed><?= View::e($formatDuration(is_int($progress['elapsed_seconds'] ?? null) ? $progress['elapsed_seconds'] : null)) ?></strong></span>
    <span><small>Restante</small><strong data-backup-eta><?= View::e($formatEta(
        is_int($progress['eta_seconds_min'] ?? null) ? $progress['eta_seconds_min'] : null,
        is_int($progress['eta_seconds_max'] ?? null) ? $progress['eta_seconds_max'] : null
    )) ?></strong></span>
  </div>

  <div class="backup-now-next">
    <article>
      <small>Ahora</small>
      <strong data-backup-now><?= View::e((string) ($progress['now'] ?? 'Preparando primera tabla')) ?></strong>
      <p data-backup-current-table><?= !empty($progress['current_table']) ? 'Conjunto actual: ' . View::e((string) $progress['current_table']) : 'Se abrirá el primer checkpoint aprobado.' ?></p>
    </article>
    <article>
      <small>Siguiente</small>
      <strong data-backup-next><?= View::e((string) ($progress['next'] ?? 'Continuar con el siguiente micro-lote local.')) ?></strong>
      <p data-backup-heartbeat><?= !empty($progress['last_advance_at']) ? 'Último avance: ' . View::e((string) $progress['last_advance_at']) : 'Esta pestaña todavía no confirmó un lote.' ?></p>
    </article>
  </div>

  <details class="backup-activity" open>
    <summary>Bitácora reciente de lotes</summary>
    <ul data-backup-activity>
      <?php foreach ($progressActivity as $event): ?>
      <li>
        <time><?= View::e((string) ($event['time'] ?? '')) ?></time>
        <strong><?= View::e((string) ($event['label'] ?? 'Avance')) ?></strong>
        <span><?= View::e((string) ($event['detail'] ?? '')) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
  </details>

  <details class="backup-secondary-actions">
    <summary>Acciones secundarias</summary>
    <p>Use recuperar, cancelar o limpiar solo si el avance se detiene o si desea descartar esta copia. Las acciones están en la fila del historial para exigir confirmación.</p>
  </details>
</section>
<?php else: ?>
<section class="panel">
  <header class="panel-head"><div><h2>Crear una copia completa</h2><p>Incluye base, configuración recuperable, manifiesto, conteos y hashes. Esta pestaña procesará micro-lotes locales mientras permanezca abierta.</p></div></header>
  <form method="post" action="<?= View::e($base) ?>/settings/backups/interactive/start">
    <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
    <?php if ($safeReturn !== ''): ?><input type="hidden" name="return_to" value="<?= View::e($safeReturn) ?>"><?php endif; ?>
    <button class="btn primary">Crear copia en esta pestaña</button>
    <p class="form-help">No se consultará Mercado Libre. Si cierra la pestaña, podrá continuar después.</p>
  </form>
</section>
<?php endif; ?>

<section class="panel">
  <header class="panel-head"><div><h2>Clave externa de recuperación</h2><p>La contraseña elegida cifra el paquete y nunca se guarda en el ERP.</p></div></header>
  <form method="post" action="<?= View::e($base) ?>/settings/backups/recovery-key" class="form-grid">
    <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
    <label>Contraseña administrativa<input class="input" type="password" name="password" required autocomplete="current-password"></label>
    <label>Contraseña del paquete<input class="input" type="password" name="package_password" required minlength="12" autocomplete="new-password"></label>
    <label>Repita la contraseña<input class="input" type="password" name="package_password_confirmation" required minlength="12" autocomplete="new-password"></label>
    <div><button class="btn">Descargar paquete de recuperación</button></div>
  </form>
  <details>
    <summary class="btn">Incorporar un paquete existente</summary>
    <form method="post" action="<?= View::e($base) ?>/settings/backups/recovery-key/import" enctype="multipart/form-data" class="form-grid">
      <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
      <label>Paquete .erpkeys<input class="input" type="file" name="recovery_package" accept=".erpkeys,application/octet-stream" required></label>
      <label>Contraseña del paquete<input class="input" type="password" name="package_password" required autocomplete="off"></label>
      <label>Contraseña administrativa<input class="input" type="password" name="password" required autocomplete="current-password"></label>
      <div><button class="btn">Comprobar e incorporar claves</button></div>
    </form>
  </details>
</section>
</div>

<section class="panel">
  <header class="panel-head"><div><h2>Copias conservadas</h2><p>La ruta física y las claves nunca se muestran.</p></div></header>
  <?php if (!$archives): ?>
    <div class="empty-state"><strong>No hay copias todavía.</strong><p>La primera copia aparecerá aquí después de que esta pestaña la verifique.</p></div>
  <?php else: ?>
  <div class="table-wrap backup-history-wrap"><table class="data-table backup-history-table" data-responsive="cards">
    <caption>Historial de copias cifradas</caption>
    <thead><tr><th>Fecha</th><th>Motivo</th><th>Estado</th><th>Contenido</th><th>Tamaño</th><th>Acciones</th></tr></thead>
    <tbody>
    <?php foreach ($archives as $archive): ?>
      <?php
        $archiveProgress = is_array($archive['progress'] ?? null) ? $archive['progress'] : [];
        $archiveJob = is_array($archive['job'] ?? null) ? $archive['job'] : [];
        $archiveTablesTotal = (int) ($archiveProgress['tables_total'] ?? $archiveJob['tables_total'] ?? 0);
        $archiveTablesDone = (int) ($archiveProgress['tables_completed'] ?? $archiveJob['tables_completed'] ?? 0);
        $archiveRowsDone = (int) ($archiveProgress['rows_processed'] ?? $archiveJob['rows_processed'] ?? 0);
      ?>
      <tr>
        <td data-label="Fecha"><?= View::e((string)$archive['requested_at']) ?><br><small>ERP <?= View::e((string)$archive['erp_version']) ?></small></td>
        <td data-label="Motivo"><?= $archive['purpose'] === 'manual' ? 'Manual' : 'Previa a actualización' ?></td>
        <td data-label="Estado"><strong><?= View::e($statusLabels[(string)$archive['status']] ?? UiLabelPresenter::status((string)$archive['status'])) ?></strong><?php if (!empty($archive['safe_error_message'])): ?><br><small><?= View::e(UiLabelPresenter::safeOperationMessage((string)$archive['safe_error_message'], isset($archive['diagnostic_id']) ? (string)$archive['diagnostic_id'] : null)) ?></small><?php endif; ?></td>
        <td data-label="Contenido"><?php if ($archive['table_count'] !== null): ?><?= (int)$archive['table_count'] ?> tablas · <?= number_format((int)$archive['row_count'],0,',','.') ?> filas<?php elseif ($archiveTablesTotal > 0): ?><?= $archiveTablesDone ?> / <?= $archiveTablesTotal ?> tablas · <?= number_format($archiveRowsDone,0,',','.') ?> filas<?php else: ?>Preparando primera tabla<?php endif; ?></td>
        <td data-label="Tamaño"><?= $archive['size_bytes'] !== null ? $formatBytes((int)$archive['size_bytes']) : ($archiveTablesTotal > 0 ? 'Generando' : 'Por calcular') ?></td>
        <td data-label="Acciones"><div class="backup-row-actions">
          <?php if ($archive['status'] === 'ready'): ?>
          <details><summary class="btn small">Descargar</summary>
            <form method="post" action="<?= View::e($base) ?>/settings/backups/download-grant">
              <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="backup_id" value="<?= (int)$archive['id'] ?>">
              <label>Confirme su contraseña<input class="input" type="password" name="password" required autocomplete="current-password"></label><button class="btn primary small">Autorizar descarga</button>
            </form>
          </details>
          <form method="post" action="<?= View::e($base) ?>/settings/backups/verify"><input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="backup_id" value="<?= (int)$archive['id'] ?>"><button class="btn small">Comprobar</button></form>
          <?php endif; ?>
          <?php if (in_array($archive['status'], ['prepared','queued','creating','verifying','ready_pending_release'], true)): ?>
          <details><summary class="btn small">Continuar copia</summary>
            <form method="post" action="<?= View::e($base) ?>/settings/backups/recover">
              <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="backup_id" value="<?= (int)$archive['id'] ?>">
              <label>Contraseña<input class="input" type="password" name="password" required autocomplete="current-password"></label>
              <button class="btn small">Continuar desde el último avance</button>
            </form>
          </details>
          <details><summary class="btn small danger">Cancelar</summary>
            <form method="post" action="<?= View::e($base) ?>/settings/backups/cancel">
              <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="backup_id" value="<?= (int)$archive['id'] ?>">
              <label>Contraseña<input class="input" type="password" name="password" required autocomplete="current-password"></label>
              <button class="btn danger small">Cancelar y limpiar</button>
            </form>
          </details>
          <?php elseif ($archive['status'] === 'cancel_requested'): ?>
          <a class="btn small" href="<?= View::e($base) ?>/settings/backups?backup=<?= (int) $archive['id'] ?>">Continuar limpieza</a>
          <span class="muted">Esta pestaña cerrará el micro‑lote actual y limpiará únicamente los restos de esta copia.</span>
          <?php elseif ($archive['status'] === 'deleting'): ?>
          <a class="btn small" href="<?= View::e($base) ?>/settings/backups?backup=<?= (int) $archive['id'] ?>">Ver avance</a>
          <span class="muted">La limpieza cercada está en curso. Ningún proceso vencido puede modificarla.</span>
          <?php elseif (in_array($archive['status'], ['ready','failed'], true)): ?>
          <details><summary class="btn small danger">Eliminar</summary>
            <form method="post" action="<?= View::e($base) ?>/settings/backups/delete">
              <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="backup_id" value="<?= (int)$archive['id'] ?>">
              <label>Contraseña<input class="input" type="password" name="password" required autocomplete="current-password"></label>
              <button class="btn danger small">Eliminar definitivamente</button>
            </form>
          </details>
          <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
