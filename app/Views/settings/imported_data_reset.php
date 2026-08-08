<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$request = is_array($request ?? null) ? $request : null;
$safety = is_array($safety ?? null) ? $safety : [];
$accounts = is_array($accounts ?? null) ? $accounts : [];
$steps = is_array($recent_steps ?? null) ? $recent_steps : [];
$freshBackup = is_array($fresh_backup ?? null) ? $fresh_backup : null;
$activeCampaigns = is_array($active_campaigns ?? null) ? $active_campaigns : [];
$status = (string) ($request['status'] ?? '');
$plan = is_array($request['plan'] ?? null) ? $request['plan'] : [];
$scope = is_array($request['scope'] ?? null) ? $request['scope'] : [];
$counters = is_array($request['counters'] ?? null) ? $request['counters'] : [];
$phase = (string) ($request['phase'] ?? '');
$isClosed = $status === 'failed' && $phase === 'closed';
$resetActive = in_array($status, ['authorized','running','pausing','paused'], true)
    || ($status === 'failed' && !$isClosed);
$statusLabels = [
    'analyzed' => 'Análisis listo',
    'authorized' => 'Esperando ejecución CLI',
    'running' => 'Retirando datos importados',
    'pausing' => 'Terminando el lote actual',
    'paused' => 'Pausado',
    'verifying' => 'Verificando lo conservado',
    'completed' => 'Restablecimiento verificado',
    'failed' => 'Detenido para revisión',
];
$statusLabel = $isClosed
    ? 'Solicitud cerrada'
    : ($statusLabels[$status] ?? 'Estado no reconocido');
$candidateTotal = (int) ($plan['candidate_total'] ?? 0);
$reviewed = (int) ($counters['reviewed'] ?? 0);
$progress = $candidateTotal > 0 ? min(99, (int) floor($reviewed * 100 / $candidateTotal)) : 0;
if ($status === 'completed') {
    $progress = 100;
}
?>
<div class="page-head imported-reset-head">
  <div>
    <span class="eyebrow">Configuración · operación destructiva local</span>
    <h1>Restablecer datos importados de Mercado Libre</h1>
    <p>Retira copias locales que pueden volver a descargarse. No modifica nada en Mercado Libre.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/settings/backups">Copias y recuperación</a>
    <?php if (!$resetActive): ?>
      <a class="btn" href="<?= View::e($base) ?>/settings/database-maintenance">Saneamiento técnico</a>
    <?php endif; ?>
  </div>
</div>

<section class="maintenance-choice-guide is-reset-choice" aria-labelledby="reset-choice-title">
  <div>
    <span class="eyebrow">EMPEZAR DE NUEVO</span>
    <h2 id="reset-choice-title">Esto sí retira copias importadas</h2>
    <p>Úselo cuando necesite volver a descargar información de Mercado Libre. Conserva empresas, cuentas, tokens cifrados, configuración, vínculos y evidencia protegida, pero reinicia la posición de sincronización.</p>
  </div>
  <?php if (!$resetActive): ?>
    <a class="btn" href="<?= View::e($base) ?>/settings/database-maintenance">Solo limpiar ruido técnico</a>
  <?php endif; ?>
</section>

<?php if ($activeCampaigns !== []): ?>
<section class="panel reset-active-campaigns" role="alert" aria-labelledby="reset-campaign-title">
  <div>
    <span class="eyebrow">DECISIÓN PENDIENTE · NO ES RUIDO</span>
    <h2 id="reset-campaign-title">Conserve o cierre cada campaña antes del restablecimiento</h2>
    <p>Una campaña activa contiene decisiones y checkpoints. El ERP no la eliminará ni devolverá sus pendientes automáticamente.</p>
  </div>
  <ul class="reset-account-list">
    <?php foreach ($activeCampaigns as $campaign): ?>
      <li>
        <strong>Campaña #<?= (int) ($campaign['id'] ?? 0) ?></strong>
        <span>
          <?= number_format((int) ($campaign['total_items'] ?? 0), 0, ',', '.') ?> trabajos ·
          <?= number_format((int) ($campaign['total_units'] ?? 0), 0, ',', '.') ?> unidades
        </span>
        <a class="btn" href="<?= View::e($base . '/settings/manual-processing/session?id=' . (int) ($campaign['id'] ?? 0)) ?>">
          Decidir: conservar o devolver pendientes
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
  <p><strong>Conservar:</strong> no autorice este restablecimiento. <strong>Devolver o abandonar:</strong> abra la campaña, finalícela y devuelva sus pendientes de forma explícita.</p>
</section>
<?php endif; ?>

<section class="maintenance-safety-strip" aria-label="Protecciones">
  <span class="<?= ($safety['api'] ?? '') === 'stopped' ? 'is-safe' : 'is-warning' ?>">
    Mercado Libre: <?= View::e(($safety['api'] ?? '') === 'stopped' ? 'detenido' : 'se detendrá al autorizar') ?>
  </span>
  <span class="<?= ($safety['automation'] ?? '') === 'stopped' ? 'is-safe' : 'is-warning' ?>">
    Automatización: <?= View::e(($safety['automation'] ?? '') === 'stopped' ? 'detenida' : 'se detendrá al autorizar') ?>
  </span>
  <span class="<?= $freshBackup !== null ? 'is-safe' : 'is-warning' ?>">
    Copia posterior al análisis: <?= View::e($freshBackup !== null ? 'verificada' : 'pendiente') ?>
  </span>
</section>

<section class="reset-impact-grid" aria-label="Alcance del restablecimiento">
  <article class="reset-impact-card is-preserve">
    <span class="eyebrow">SE CONSERVA</span>
    <h2>Su ERP y sus decisiones</h2>
    <ul>
      <?php foreach ($preserved_labels ?? [] as $label): ?>
        <li><?= View::e((string) $label) ?></li>
      <?php endforeach; ?>
    </ul>
  </article>
  <article class="reset-impact-card is-delete">
    <span class="eyebrow">SE RETIRA</span>
    <h2>Copias importadas recuperables</h2>
    <ul>
      <?php foreach ($deleted_labels ?? [] as $label): ?>
        <li><?= View::e((string) $label) ?></li>
      <?php endforeach; ?>
    </ul>
  </article>
  <article class="reset-impact-card is-reset">
    <span class="eyebrow">SE REINICIA</span>
    <h2>La posición de sincronización</h2>
    <ul>
      <?php foreach ($reset_labels ?? [] as $label): ?>
        <li><?= View::e((string) $label) ?></li>
      <?php endforeach; ?>
    </ul>
  </article>
</section>

<?php if ($request === null): ?>
<section class="panel reset-analysis">
  <div>
    <span class="eyebrow">PASO 1</span>
    <h2>Analizar todas las cuentas autorizadas</h2>
    <p>El análisis cuenta datos y detecta evidencia que debe permanecer. Todavía no elimina filas ni detiene procesos.</p>
    <details>
      <summary>Ver las <?= count($accounts) ?> cuentas incluidas</summary>
      <ul class="reset-account-list">
        <?php foreach ($accounts as $account): ?>
          <li><strong><?= View::e((string) $account['account_name']) ?></strong><span><?= View::e((string) $account['company_name']) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </details>
  </div>
  <form method="post" action="<?= View::e($base) ?>/settings/imported-data-reset/analyze">
    <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
    <button class="btn primary" type="submit"<?= $accounts === [] ? ' disabled' : '' ?>>Analizar sin borrar</button>
  </form>
</section>
<?php else: ?>
<section class="panel reset-console" id="resultado">
  <header class="reset-console-head">
    <div>
      <span class="maintenance-state"><?= View::e($statusLabel) ?></span>
      <h2>Solicitud #<?= (int) $request['id'] ?></h2>
      <p><?= View::e((string) ($request['safe_message'] ?? 'Revise el análisis.')) ?></p>
    </div>
    <div class="reset-scope-summary">
      <span>Alcance explícito</span>
      <strong><?= count($scope['accounts'] ?? []) ?> cuentas</strong>
      <small>Solo empresas autorizadas para este administrador</small>
    </div>
  </header>

  <div class="maintenance-progress"
       role="progressbar"
       aria-label="Progreso del restablecimiento"
       aria-valuemin="0"
       aria-valuemax="100"
       aria-valuenow="<?= $progress ?>"
       aria-valuetext="<?= View::e($progress . ' % · ' . $reviewed . ' de ' . $candidateTotal . ' filas revisadas') ?>">
    <span style="width:<?= $progress ?>%"></span>
  </div>

  <div class="maintenance-counters" aria-label="Resumen">
    <span>Candidatas <strong><?= number_format($candidateTotal, 0, ',', '.') ?></strong></span>
    <span>Revisadas <strong><?= number_format($reviewed, 0, ',', '.') ?></strong></span>
    <span>Retiradas <strong><?= number_format((int) ($counters['deleted'] ?? 0), 0, ',', '.') ?></strong></span>
    <span>Conservadas <strong><?= number_format((int) ($counters['retained'] ?? 0), 0, ',', '.') ?></strong></span>
  </div>

  <details class="reset-account-scope">
    <summary>Cuentas incluidas en esta solicitud</summary>
    <ul class="reset-account-list">
      <?php foreach ($scope['accounts'] ?? [] as $account): ?>
        <li><strong><?= View::e((string) $account['name']) ?></strong><span><?= View::e((string) $account['company']) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </details>

  <?php if ($status === 'analyzed' && $candidateTotal === 0): ?>
    <section class="reset-empty-result" role="status">
      <span class="eyebrow">ANÁLISIS TERMINADO</span>
      <h3>No hay copias importadas que retirar</h3>
      <p>No necesita crear una copia ni autorizar un borrado. Puede volver a analizar si luego se importan datos nuevos.</p>
      <form method="post" action="<?= View::e($base) ?>/settings/imported-data-reset/analyze">
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
        <button class="btn" type="submit">Analizar de nuevo</button>
      </form>
    </section>
  <?php elseif ($status === 'analyzed'): ?>
    <section class="reset-confirmation" aria-labelledby="reset-confirmation-title">
      <div>
        <span class="eyebrow">PASO 2 · CONFIRMACIÓN</span>
        <h3 id="reset-confirmation-title">Proteja primero una copia nueva</h3>
        <?php if ($freshBackup === null): ?>
          <p>La copia debe crearse y verificarse después de este análisis para representar exactamente el estado que se va a cambiar.</p>
          <a class="btn primary" href="<?= View::e($base) ?>/settings/backups">Crear y verificar copia</a>
        <?php else: ?>
          <p>La copia #<?= (int) $freshBackup['id'] ?> está verificada. Al autorizar se detendrán Mercado Libre y toda la automatización.</p>
          <form method="post" action="<?= View::e($base) ?>/settings/imported-data-reset/authorize" class="reset-danger-form">
            <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
            <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
            <input type="hidden" name="backup_id" value="<?= (int) $freshBackup['id'] ?>">
            <fieldset>
              <legend>Confirme una operación irreversible sin restaurar la copia</legend>
              <label for="reset-authorize-password">Contraseña administrativa</label>
                <input class="input" id="reset-authorize-password" type="password" name="password" required autocomplete="current-password">
              <p id="reset-confirmation-help">Esta acción no borra empresas, cuentas, tokens, usuarios, configuración, vínculos ni evidencia inmutable.</p>
            </fieldset>
            <button class="btn danger" type="submit"<?= $activeCampaigns !== [] ? ' disabled' : '' ?>>Eliminar solo datos importados de Mercado Libre</button>
            <?php if ($activeCampaigns !== []): ?>
              <p role="status">La autorización permanece bloqueada hasta resolver las campañas mostradas arriba.</p>
            <?php endif; ?>
          </form>
        <?php endif; ?>
        <details class="reset-reanalyze">
          <summary>¿Cambió la información después del análisis?</summary>
          <p>Genere otro análisis si se sincronizaron datos, cambió el acceso a cuentas o se instaló una actualización.</p>
          <form method="post" action="<?= View::e($base) ?>/settings/imported-data-reset/analyze">
            <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
            <button class="btn" type="submit">Generar un análisis nuevo</button>
          </form>
        </details>
      </div>
    </section>
  <?php elseif ($isClosed): ?>
    <section class="reset-closed" id="progreso" role="status">
      <h3>Solicitud cerrada</h3>
      <p>Se conservó todo lo ya realizado y se liberó el modo de solo lectura. Mercado Libre y la automatización continúan detenidos hasta que los reactive por separado.</p>
      <div class="maintenance-actions">
        <a class="btn" href="<?= View::e($base) ?>/settings/emergency-control">Revisar las paradas</a>
        <form method="post" action="<?= View::e($base) ?>/settings/imported-data-reset/analyze">
          <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
          <button class="btn primary" type="submit">Crear otro análisis</button>
        </form>
      </div>
    </section>
  <?php elseif (in_array($status, ['authorized','running','pausing','paused','failed'], true)): ?>
    <section class="reset-cli-state" id="progreso" aria-live="polite">
      <div>
        <span class="eyebrow">EJECUCIÓN LOCAL SEGURA</span>
        <h3><?= View::e($status === 'failed' ? 'La operación necesita revisión' : 'El navegador no elimina datos') ?></h3>
        <p>Soporte ejecuta una tarea puntual en el servidor: procesa un lote, guarda su avance y termina. No depende de tareas automáticas adicionales.</p>
        <details>
          <summary>Comando puntual para soporte</summary>
          <code>php jobs/process_sync_queue.php</code>
        </details>
      </div>
      <?php if (in_array($status, ['authorized','running','pausing'], true)): ?>
        <form method="post" action="<?= View::e($base) ?>/settings/imported-data-reset/pause">
          <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
          <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
          <button class="btn" type="submit">Pausar después del lote</button>
        </form>
      <?php elseif ($status === 'paused'): ?>
        <form method="post" action="<?= View::e($base) ?>/settings/imported-data-reset/resume" class="reset-resume-form">
          <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
          <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
          <label for="reset-resume-password">Contraseña administrativa</label>
          <input id="reset-resume-password" name="password" type="password" autocomplete="current-password" required>
          <button class="btn primary" type="submit">Continuar desde el último lote</button>
        </form>
      <?php endif; ?>
      <?php if (in_array($status, ['paused','failed'], true) && (string) ($request['phase'] ?? '') !== 'closed'): ?>
        <details>
          <summary>Cerrar esta solicitud sin continuar</summary>
          <p>Conserva lo ya realizado, libera el modo de solo lectura y mantiene detenidas la API y la automatización.</p>
          <form method="post" action="<?= View::e($base) ?>/settings/imported-data-reset/abandon">
            <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
            <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
            <label for="reset-abandon-password">Contraseña administrativa</label>
            <input id="reset-abandon-password" name="password" type="password" autocomplete="current-password" required>
            <button class="btn" type="submit">Cerrar y mantener las paradas</button>
          </form>
        </details>
      <?php endif; ?>
    </section>
  <?php elseif ($status === 'completed'): ?>
    <section class="reset-complete" id="progreso" role="status">
      <h3>Restablecimiento verificado</h3>
      <p>La configuración y la evidencia protegida coinciden con el análisis. Las paradas continúan activas; reactívelas por separado después de revisar el resultado.</p>
      <div class="maintenance-actions">
        <a class="btn primary" href="<?= View::e($base) ?>/settings/emergency-control">Revisar freno de mano</a>
        <form method="post" action="<?= View::e($base) ?>/settings/imported-data-reset/analyze">
          <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
          <button class="btn" type="submit">Crear otro análisis</button>
        </form>
      </div>
    </section>
  <?php endif; ?>

  <section class="maintenance-log">
    <div class="maintenance-log-head"><h3>Bitácora aprobada</h3><span>Sin datos de compradores ni payloads</span></div>
    <ol>
      <?php if ($steps === []): ?>
        <li><span>—</span><strong>Todavía no se procesaron lotes.</strong><small>Esperando</small></li>
      <?php else: foreach ($steps as $step): ?>
        <li>
          <span><?= View::e((string) ($step['completed_at'] ?? '')) ?></span>
          <strong><?= View::e((string) ($step['safe_message'] ?? 'Lote registrado.')) ?></strong>
          <small><?= number_format((int) ($step['rows_deleted'] ?? 0), 0, ',', '.') ?> retiradas</small>
        </li>
      <?php endforeach; endif; ?>
    </ol>
  </section>
</section>
<?php endif; ?>
