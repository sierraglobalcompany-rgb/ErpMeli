<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$analysis = is_array($analysis ?? null) ? $analysis : [];
$session = is_array($session ?? null) ? $session : null;
$recentSteps = is_array($recent_steps ?? null) ? $recent_steps : [];
$safety = is_array($safety ?? null) ? $safety : [];
$readyBackup = is_array($analysis['verified_backup'] ?? null)
    && !empty($analysis['verified_backup']['file_available'])
    && !empty($analysis['verified_backup']['fresh']);
$protection = is_array($analysis['protection'] ?? null)
    ? $analysis['protection']
    : (is_array($session['protection'] ?? null) ? $session['protection'] : []);
$protectionReady = !empty($protection['ready']);
$physicalRecovery = is_array($physical_recovery ?? null) ? $physical_recovery : null;
$formatBytes = static function (?int $bytes): string {
    if ($bytes === null) {
        return 'No se pudo comprobar';
    }
    $value = max(0, $bytes);
    foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
        if ($value < 1024 || $unit === 'TB') {
            return number_format($value, $unit === 'B' ? 0 : 1, ',', '.') . ' ' . $unit;
        }
        $value /= 1024;
    }
    return '0 B';
};
$formatCount = static function (mixed $value): string {
    return $value === null ? 'Por analizar' : number_format((int) $value, 0, ',', '.');
};
$statusLabels = [
    'analyzed' => 'Análisis listo',
    'running' => 'Autorizado para tarea local',
    'pausing' => 'Terminando el lote actual',
    'finishing' => 'Preparando verificación final',
    'verifying' => 'Verificando integridad',
    'paused' => 'Pausado',
    'completed' => 'Saneamiento verificado',
    'finished' => 'Sesión finalizada',
    'failed' => 'Necesita revisión',
];
$phaseLabels = [
    'analysis' => 'Análisis',
    'retention' => 'Archivo y retención',
    'legacy_notifications' => 'Normalización histórica',
    'legacy_messages' => 'Reducción de texto técnico repetido',
    'payloads' => 'Payloads privados',
    'orphans' => 'Archivos huérfanos',
    'cold_archives' => 'Retención de archivos fríos',
    'verify' => 'Verificación de integridad',
    'completed' => 'Completado',
];
$datasetLabels = [
    'notification_success' => 'Notificaciones resueltas',
    'notification_incidents' => 'Incidentes técnicos',
    'api_request_logs' => 'Historial API',
    'cron_health_checks' => 'Historial de automatización',
    'financial_job_items' => 'Detalle de trabajos financieros',
    'meli_orders' => 'Órdenes',
    'meli_shipments' => 'Envíos',
    'meli_payments' => 'Pagos',
    'meli_packs' => 'Packs',
    'meli_order_items' => 'Productos vendidos',
];
$sessionId = (int) ($session['id'] ?? 0);
$status = (string) ($session['status'] ?? '');
$visualStatus = $status === 'running' && (string) ($session['phase'] ?? '') === 'verify'
    ? 'verifying'
    : $status;
$counters = is_array($session['counters'] ?? null) ? $session['counters'] : [];
$backupId = (int) ($analysis['verified_backup']['id'] ?? 0);
$returnTo = '/settings/database-maintenance'
    . ($sessionId > 0 ? '?id=' . $sessionId : '')
    . '#resultado';
$backupCenterUrl = $base . '/settings/backups?return_to=' . rawurlencode($returnTo);
?>
<div class="page-head database-maintenance-head">
  <div>
    <span class="eyebrow">Configuración · mantenimiento local</span>
    <h1>Saneamiento de base de datos</h1>
    <p><span class="badge neutral">LOCAL_ONLY</span> Avanza sólo en esta pestaña; no depende de Cron ni se ejecuta en segundo plano.</p>
    <p>Archive y retire ruido técnico sin tocar ventas, pagos, cierres ni evidencia fiscal.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($backupCenterUrl) ?>">Ver copias</a>
    <a class="btn" href="<?= View::e($base) ?>/mantenimiento.php">Acceso directo</a>
  </div>
</div>

<section class="operation-explainer" aria-label="Estado del saneamiento">
  <div><span>Qué está pasando</span><strong><?= $sessionId > 0 ? View::e($statusLabels[$visualStatus] ?? 'Sesión en revisión') : 'Todavía no hay una sesión activa.' ?></strong></div>
  <div><span>Qué hará el ERP</span><strong><?= $sessionId > 0 ? 'Procesará únicamente ruido técnico en micro-lotes verificables.' : 'Primero analizará sin eliminar información.' ?></strong></div>
  <div><span>Qué puede hacer ahora</span><strong><?= $sessionId > 0 ? 'Continúe con la única acción disponible para este estado.' : 'Use Analizar sin borrar.' ?></strong></div>
</section>

<section class="maintenance-choice-guide" aria-labelledby="maintenance-choice-title">
  <div>
    <span class="eyebrow">¿CUÁL HERRAMIENTA NECESITA?</span>
    <h2 id="maintenance-choice-title">Saneamiento conserva sus ventas importadas</h2>
    <p>Use esta pantalla para retirar historial técnico antiguo y contenido redundante. Para borrar copias importadas y descargarlas nuevamente, use <strong>Restablecer datos importados</strong>.</p>
  </div>
  <a class="btn" href="<?= View::e($base) ?>/settings/imported-data-reset">Comparar con “Empezar de nuevo”</a>
</section>

<section class="maintenance-safety-strip" aria-label="Protecciones activas">
  <span class="<?= ($safety['api'] ?? '') === 'stopped' ? 'is-safe' : 'is-danger' ?>">
    Mercado Libre: <?= View::e(($safety['api'] ?? '') === 'stopped' ? 'bloqueado' : 'detención requerida') ?>
  </span>
  <span class="<?= ($safety['automation'] ?? '') === 'stopped' ? 'is-safe' : 'is-danger' ?>">
    Automatización: <?= View::e(($safety['automation'] ?? '') === 'stopped' ? 'detenida' : 'detención requerida') ?>
  </span>
  <span class="<?= $protectionReady ? 'is-safe' : 'is-warning' ?>">
    Protección: <?= View::e($protectionReady ? (string) ($protection['label'] ?? 'lista') : 'por elegir') ?>
  </span>
</section>

<section class="maintenance-overview" aria-label="Estado de la base">
  <article>
    <span>Tamaño activo</span>
    <strong><?= View::e($formatBytes((int) (($analysis['totals']['data_bytes'] ?? 0) + ($analysis['totals']['index_bytes'] ?? 0)))) ?></strong>
    <small>Datos e índices asignados</small>
  </article>
  <article>
    <span>Fragmentación</span>
    <strong><?= View::e($formatBytes((int) ($analysis['totals']['free_bytes'] ?? 0))) ?></strong>
    <small>Se recupera en una fase separada</small>
  </article>
  <article>
    <span>Filas listas ahora</span>
    <strong><?= View::e($formatCount($analysis['eligible_total'] ?? null)) ?></strong>
    <small><?php if (($analysis['open_period_deferred'] ?? null) === null): ?>El mes abierto se medirá al analizar<?php else: ?><?= View::e($formatCount($analysis['open_period_deferred'])) ?> filas del mes abierto se conservan hasta su cierre<?php endif; ?></small>
  </article>
  <article>
    <span>Payloads completos</span>
    <strong><?= View::e($formatCount($analysis['payloads']['rows'] ?? null)) ?></strong>
    <small><?php if (($analysis['payloads']['bytes'] ?? null) === null): ?>Se calculará al analizar<?php else: ?><?= View::e($formatBytes((int) $analysis['payloads']['bytes'])) ?> externalizables<?php endif; ?></small>
  </article>
</section>

<section class="panel maintenance-analysis">
  <div>
    <span class="eyebrow">PASO 1</span>
    <h2>Analizar sin borrar</h2>
    <p>Vuelve a medir la base, congela un plan y calcula la huella comercial protegida.</p>
  </div>
  <form method="post" action="<?= View::e($base) ?>/settings/database-maintenance/analyze">
    <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
    <button class="btn primary" type="submit">Analizar sin borrar</button>
  </form>
</section>

<?php if ($session !== null): ?>
<section
  class="panel maintenance-console"
  id="resultado"
  data-database-maintenance
  data-session-id="<?= $sessionId ?>"
  data-session-status="<?= View::e($status) ?>"
  data-session-phase="<?= View::e((string) ($session['phase'] ?? '')) ?>"
  data-physical-status="<?= View::e((string) ($physicalRecovery['status'] ?? '')) ?>"
  data-tab-token-seed="<?= View::e((string) $tabTokenSeed) ?>"
  data-status-url="<?= View::e($base) ?>/settings/database-maintenance/status.json?id=<?= $sessionId ?>"
  data-step-url="<?= View::e($base) ?>/settings/database-maintenance/step"
  data-csrf="<?= View::e(Csrf::token()) ?>"
>
  <header class="maintenance-console-head">
    <div>
      <span class="maintenance-state" data-maintenance-state><?= View::e($statusLabels[$visualStatus] ?? 'Estado no reconocido') ?></span>
      <h2>Sesión #<?= $sessionId ?></h2>
      <p data-maintenance-message><?= View::e((string) ($session['safe_message'] ?? 'Revise el plan antes de continuar.')) ?></p>
    </div>
    <div class="maintenance-next">
      <span>Siguiente paso</span>
      <strong data-maintenance-next><?= View::e((string) ($session['next_action'] ?? 'Por calcular')) ?></strong>
    </div>
  </header>

  <div class="maintenance-progress" role="progressbar" aria-label="Progreso del saneamiento" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) round((float) ($session['progress_percent'] ?? 0)) ?>" data-maintenance-progress>
    <span style="width:<?= max(0, min(100, (float) ($session['progress_percent'] ?? 0))) ?>%" data-maintenance-progress-bar></span>
  </div>

  <div class="maintenance-phase-row">
    <div><span>Fase</span><strong data-maintenance-phase><?= View::e($phaseLabels[(string) ($session['phase'] ?? '')] ?? 'Por calcular') ?></strong></div>
    <div><span>Conjunto</span><strong data-maintenance-dataset><?= View::e($datasetLabels[(string) ($session['dataset_key'] ?? '')] ?? '—') ?></strong></div>
    <div><span>Último lote</span><strong data-maintenance-last><?= View::e(!empty($session['last_step_at']) ? (string) $session['last_step_at'] : 'Todavía no ejecutado') ?></strong></div>
  </div>

  <div class="maintenance-counters" aria-label="Resultados aprobados">
    <span title="Una fila puede comprobarse al archivar y nuevamente al retirarla.">Comprobaciones <strong data-counter="reviewed"><?= number_format((int) ($counters['reviewed'] ?? 0), 0, ',', '.') ?></strong></span>
    <span>Archivadas <strong data-counter="archived"><?= number_format((int) ($counters['archived'] ?? 0), 0, ',', '.') ?></strong></span>
    <span>Resumidas <strong data-counter="summarized"><?= number_format((int) ($counters['summarized'] ?? 0), 0, ',', '.') ?></strong></span>
    <span>Eliminadas <strong data-counter="deleted"><?= number_format((int) ($counters['deleted'] ?? 0), 0, ',', '.') ?></strong></span>
    <span>Payloads <strong data-counter="payloads"><?= number_format((int) ($counters['payloads'] ?? 0), 0, ',', '.') ?></strong></span>
    <span>Errores <strong data-counter="errors"><?= number_format((int) ($counters['errors'] ?? 0), 0, ',', '.') ?></strong></span>
  </div>

  <?php if ($status === 'analyzed'): ?>
    <?php if (!$protectionReady): ?>
      <div class="maintenance-protection-grid">
        <div class="maintenance-blocker">
          <strong>Elija cómo proteger esta sesión</strong>
          <p>Puede sanear ruido técnico con una copia ERP, con una copia externa que ya tenga guardada, o sin respaldo interno bajo su responsabilidad. No se tocarán ventas, pagos, cuentas ni enlaces de Mercado Libre.</p>
        </div>
        <?php if ($readyBackup): ?>
        <form method="post" action="<?= View::e($base) ?>/settings/database-maintenance/protect" class="maintenance-protection-card">
          <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
          <input type="hidden" name="session_id" value="<?= $sessionId ?>">
          <input type="hidden" name="protection_mode" value="erp_backup">
          <input type="hidden" name="backup_id" value="<?= $backupId ?>">
          <h3>Usar copia ERP verificada</h3>
          <p>Copia #<?= $backupId ?> lista y vinculada al análisis.</p>
          <label for="maintenance-protect-backup-password">Contraseña administrativa</label>
          <input class="input" id="maintenance-protect-backup-password" type="password" name="password" required autocomplete="current-password">
          <button class="btn primary" type="submit">Usar esta copia y continuar</button>
        </form>
        <?php else: ?>
        <article class="maintenance-protection-card">
          <h3>Crear copia local</h3>
          <p>La copia se crea desde una pestaña del navegador, por micro-lotes. No depende de tareas automáticas ni consulta Mercado Libre.</p>
          <a class="btn" href="<?= View::e($backupCenterUrl) ?>">Crear copia en esta pestaña</a>
        </article>
        <?php endif; ?>
        <form method="post" action="<?= View::e($base) ?>/settings/database-maintenance/protect" class="maintenance-protection-card">
          <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
          <input type="hidden" name="session_id" value="<?= $sessionId ?>">
          <input type="hidden" name="protection_mode" value="external_backup">
          <h3>Usar respaldo externo</h3>
          <p>Use esta opción si ya descargó una copia por phpMyAdmin o un archivo .erpbackup y lo conserva fuera del ERP.</p>
          <label for="maintenance-protect-external-password">Contraseña administrativa</label>
          <input class="input" id="maintenance-protect-external-password" type="password" name="password" required autocomplete="current-password">
          <button class="btn primary" type="submit">Usar respaldo externo y continuar</button>
        </form>
        <form method="post" action="<?= View::e($base) ?>/settings/database-maintenance/protect" class="maintenance-protection-card is-danger">
          <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
          <input type="hidden" name="session_id" value="<?= $sessionId ?>">
          <input type="hidden" name="protection_mode" value="waived">
          <h3>Continuar sin respaldo interno</h3>
          <p>Solo habilita saneamiento lógico de ruido técnico. La recuperación física quedará bloqueada.</p>
          <label for="maintenance-protect-waive-password">Contraseña administrativa</label>
          <input class="input" id="maintenance-protect-waive-password" type="password" name="password" required autocomplete="current-password">
          <button class="btn danger" type="submit">Continuar sin respaldo</button>
        </form>
      </div>
    <?php else: ?>
      <p class="maintenance-backup-note">Protección elegida: <?= View::e((string) ($protection['label'] ?? 'lista')) ?>. Primero se ejecutará un lote canario.</p>
      <form method="post" action="<?= View::e($base) ?>/settings/database-maintenance/start" data-maintenance-start>
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
        <input type="hidden" name="session_id" value="<?= $sessionId ?>">
        <?php if ($backupId > 0): ?><input type="hidden" name="backup_id" value="<?= $backupId ?>"><?php endif; ?>
        <input type="hidden" name="control_token" value="<?= View::e((string) $tabTokenSeed) ?>" data-control-token>
        <label for="maintenance-start-password">Contraseña administrativa</label>
        <input class="input" id="maintenance-start-password" type="password" name="password" required autocomplete="current-password">
        <button class="btn primary" type="submit"><?= (int) ($analysis['actionable_total'] ?? 0) > 0 ? 'Iniciar lote canario' : 'Verificar integridad nuevamente' ?></button>
      </form>
    <?php endif; ?>
  <?php elseif ($status === 'paused'): ?>
    <form method="post" action="<?= View::e($base) ?>/settings/database-maintenance/start" data-maintenance-start>
      <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
      <input type="hidden" name="session_id" value="<?= $sessionId ?>">
      <?php if ($backupId > 0): ?><input type="hidden" name="backup_id" value="<?= $backupId ?>"><?php endif; ?>
      <input type="hidden" name="control_token" value="<?= View::e((string) $tabTokenSeed) ?>" data-control-token>
      <label for="maintenance-resume-password">Contraseña administrativa</label>
      <input class="input" id="maintenance-resume-password" type="password" name="password" required autocomplete="current-password">
      <button class="btn primary" type="submit">Continuar desde el checkpoint</button>
    </form>
  <?php endif; ?>

  <?php if (in_array($status, ['running', 'pausing', 'finishing'], true)): ?>
    <div class="maintenance-live" role="status" aria-live="polite" data-maintenance-live>
      <span><?= View::e(
          $status === 'pausing'
                ? 'Pausa solicitada. Esta pestaña cerrará primero el lote actual.'
              : ($status === 'finishing' || $visualStatus === 'verifying'
                  ? 'La integridad se comprobará antes de liberar la base.'
                  : 'Autorizado. Mantenga esta pestaña abierta para ejecutar lotes locales seguros.')
      ) ?></span>
    </div>
    <?php if ($status === 'running'): ?>
    <div class="maintenance-actions">
      <form method="post" action="<?= View::e($base) ?>/settings/database-maintenance/pause">
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
        <input type="hidden" name="session_id" value="<?= $sessionId ?>">
        <button class="btn" type="submit">Pausar después del lote</button>
      </form>
      <form method="post" action="<?= View::e($base) ?>/settings/database-maintenance/finish">
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
        <input type="hidden" name="session_id" value="<?= $sessionId ?>">
        <button class="btn" type="submit">Finalizar sesión</button>
      </form>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="maintenance-log">
    <div class="maintenance-log-head"><h3>Bitácora reciente</h3><span>Solo resultados guardados</span></div>
    <ol data-maintenance-log>
      <?php if ($recentSteps === []): ?>
        <li><span>—</span><strong>Todavía no se ejecutaron lotes.</strong><small>Esperando</small></li>
      <?php else: foreach ($recentSteps as $step): ?>
        <li>
          <span><?= View::e((string) ($step['completed_at'] ?? $step['started_at'] ?? '')) ?></span>
          <strong><?= View::e((string) ($step['safe_message'] ?? 'Paso registrado.')) ?></strong>
          <small><?= number_format((int) ($step['duration_ms'] ?? 0), 0, ',', '.') ?> ms</small>
        </li>
      <?php endforeach; endif; ?>
    </ol>
  </div>
</section>
<?php endif; ?>

<section class="panel maintenance-breakdown">
  <header class="panel-head"><div><h2>Qué puede sanearse ahora</h2><p>Las cifras excluyen el mes abierto y se comprueban nuevamente antes de eliminar.</p></div></header>
  <div class="maintenance-dataset-list">
    <div><span>Correcciones históricas locales</span><strong><?= View::e($formatCount($analysis['local_repairs'] ?? null)) ?><?= ($analysis['local_repairs'] ?? null) === null ? '' : ' pendientes' ?></strong></div>
    <?php foreach (($analysis['eligible'] ?? []) as $key => $count): ?>
      <div><span><?= View::e($datasetLabels[$key] ?? $key) ?></span><strong><?= View::e($formatCount($count)) ?><?= $count === null ? '' : ' elegibles' ?></strong></div>
    <?php endforeach; ?>
  </div>
  <details>
    <summary>Ver tablas más grandes y protección comercial</summary>
    <div class="table-wrap">
      <table class="data-table">
        <caption>Tablas con mayor espacio asignado</caption>
        <thead><tr><th>Tabla</th><th>Datos</th><th>Índices</th><th>Fragmentación</th><th>Filas estimadas</th></tr></thead>
        <tbody>
        <?php foreach (($analysis['largest_tables'] ?? []) as $table): ?>
          <tr>
            <td><code><?= View::e((string) $table['table']) ?></code></td>
            <td><?= View::e($formatBytes((int) $table['data_bytes'])) ?></td>
            <td><?= View::e($formatBytes((int) $table['index_bytes'])) ?></td>
            <td><?= View::e($formatBytes((int) $table['free_bytes'])) ?></td>
            <td><?= number_format((int) $table['estimated_rows'], 0, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p><strong>Protegidas permanentemente:</strong> <?= View::e(implode(', ', $analysis['protected_tables'] ?? [])) ?>.</p>
  </details>
</section>

<?php if ($session !== null && $status === 'completed'): ?>
<section class="panel maintenance-physical" id="espacio-fisico">
  <span class="eyebrow">PASO SEPARADO</span>
  <h2>Recuperar espacio físico</h2>
  <p>Prepare una sola tabla. Esta operación reorganiza almacenamiento de MariaDB; no borra ventas ni vuelve a ejecutar el saneamiento lógico.</p>
  <div class="maintenance-operation-compare" aria-label="Diferencia entre saneamiento y recuperación física">
    <article><strong>Saneamiento lógico</strong><span>Archiva y retira únicamente ruido técnico elegible y verificado.</span></article>
    <article><strong>Recuperación física</strong><span>Reorganiza una tabla para devolver al servidor el espacio ya liberado.</span></article>
  </div>
  <?php if ($physicalRecovery !== null): ?>
    <div class="maintenance-live<?= in_array((string) ($physicalRecovery['status'] ?? ''), ['failed', 'needs_review'], true) ? ' is-error' : '' ?>" data-physical-recovery>
      <strong><?= View::e(match ((string) ($physicalRecovery['status'] ?? '')) {
          'queued' => 'Preparada para revisión',
          'running' => 'Reconstrucción en curso',
          'completed' => 'Tabla reconstruida',
          'not_required' => 'Reconstrucción no necesaria',
          'needs_review' => 'Interrupción por revisar',
          'failed' => 'No se pudo reconstruir',
          default => 'Estado por comprobar',
      }) ?></strong>
      <span><?= View::e((string) ($physicalRecovery['safe_message'] ?? '')) ?></span>
    </div>
  <?php endif; ?>
  <?php
  $eligibleRecoveryTables = array_values(array_filter(
      $recovery_plan ?? [],
      static fn (array $table): bool => !empty($table['eligible'])
  ));
  ?>
  <?php if ($eligibleRecoveryTables === []): ?>
    <div class="empty-state">
      <strong>No hay tablas que requieran reconstrucción.</strong>
      <span>La fragmentación restante está por debajo del umbral seguro.</span>
    </div>
  <?php endif; ?>
  <?php foreach ($eligibleRecoveryTables as $table): ?>
    <details>
      <summary><span><?= View::e((string) $table['table']) ?></span><strong><?= View::e($formatBytes((int) $table['free_bytes'])) ?> recuperables</strong></summary>
      <p>Esta acción crea un encargo local exacto. No consulta Mercado Libre.</p>
      <?php if (!in_array((string) ($physicalRecovery['status'] ?? ''), ['queued', 'running'], true)): ?>
        <form method="post" action="<?= View::e($base) ?>/settings/database-maintenance/rebuild">
          <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
          <input type="hidden" name="session_id" value="<?= $sessionId ?>">
          <input type="hidden" name="table" value="<?= View::e((string) $table['table']) ?>">
          <label>
            Contraseña administrativa
            <input class="input" type="password" name="password" required autocomplete="current-password">
          </label>
          <p class="form-help">La reconstrucción física requiere una copia ERP verificada o respaldo externo confirmado. No está disponible con “continuar sin respaldo”.</p>
          <button class="btn" type="submit">Preparar recuperación física de esta tabla</button>
        </form>
      <?php endif; ?>
    </details>
  <?php endforeach; ?>
  <div class="maintenance-actions">
    <form method="post" action="<?= View::e($base) ?>/settings/database-maintenance/finish">
      <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
      <input type="hidden" name="session_id" value="<?= $sessionId ?>">
      <button class="btn primary" type="submit">Cerrar mantenimiento y volver al ERP</button>
    </form>
  </div>
</section>
<?php endif; ?>
