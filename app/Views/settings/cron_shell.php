<?php
use App\Core\Env;
use App\Core\Csrf;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$csrfToken = Csrf::token();
$overview = is_array($overview ?? null) ? $overview : [];
$lastRun = is_array($overview['last_run'] ?? null) ? $overview['last_run'] : null;
$now = is_array($overview['now'] ?? null) ? $overview['now'] : null;
$next = is_array($overview['next'] ?? null) ? $overview['next'] : [];
$workload = is_array($overview['workload'] ?? null) ? $overview['workload'] : [];
$history = is_array($overview['history'] ?? null) ? $overview['history'] : [];
$state = (string) ($overview['state'] ?? 'unknown');
$tone = match ($state) {
    'ok', 'running' => 'success',
    'pending_verification', 'stale' => 'warning',
    'error', 'interrupted' => 'danger',
    default => 'neutral',
};
?>
<div class="page-head cron-page-head">
  <div>
    <span class="eyebrow">AUTOMATIZACIÓN</span>
    <h1>Centro de Automatización</h1>
    <p>Cada ciclo separa funciones reclamadas, transportes HTTP y recursos finalizados.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/settings/diagnostics">Diagnóstico</a>
    <a class="btn" href="<?= View::e($base) ?>/stop/">Freno de mano</a>
  </div>
</div>

<nav class="cron-view-tabs" aria-label="Vistas de Cron">
  <a class="is-active" aria-current="page" href="#resumen">Resumen</a>
  <a href="<?= View::e($base) ?>/settings/cron/rhythm">Ritmo</a>
  <a href="#colas">Colas</a>
  <a href="#historial">Historial</a>
  <a href="<?= View::e($base) ?>/settings/cron/parked">Parqueados</a>
</nav>

<div class="cron-control" data-cron-control
      data-operational-url="<?= View::e($base) ?>/settings/cron/operational-snapshot.json"
      data-overview-url="<?= View::e($base) ?>/settings/cron/overview.json"
      data-tasks-url="<?= View::e($base) ?>/settings/cron/tasks.json"
      data-v3-runtime-url="<?= View::e($base) ?>/settings/cron/v3-runtime-status.json"
      data-v3-setup-url="<?= View::e($base) ?>/settings/cron/v3-setup.json"
      data-v3-canary-url="<?= View::e($base) ?>/settings/cron/v3-canary.json">
  <section id="resumen" class="cron-status-line is-<?= View::e($tone) ?>" aria-live="polite">
    <span class="cron-status-dot" aria-hidden="true"></span>
    <div>
      <strong data-cron-state><?= View::e((string) ($overview['state_label'] ?? 'Comprobando Cron')) ?></strong>
      <span data-cron-signal><?= !empty($overview['last_signal_label']) ? 'Última señal: ' . View::e((string) $overview['last_signal_label']) . ' hora Bogotá' : 'Aún no hay una señal verificable.' ?></span>
    </div>
  </section>

  <section class="operation-explainer" aria-label="Cómo leer el estado de Cron">
    <div><span>Qué está pasando</span><strong data-cron-human-now><?= $now ? 'Hay un trabajo con actividad comprobada.' : 'V3 está drenando la fila FIFO ejecutable.' ?></strong></div>
    <div><span>Qué hará el ERP</span><strong data-cron-human-next>Tomará el trabajo listo más antiguo; lo parqueado queda aparte y no bloquea la fila.</strong></div>
    <div><span>Qué puede hacer ahora</span><strong><?= in_array($state, ['error', 'interrupted'], true) ? 'Abra el diagnóstico.' : 'No intervenir si la fila lista avanza; revise parqueados o ajuste rampa si quiere acelerar con seguridad.' ?></strong></div>
  </section>

  <section class="cron-truth-grid" aria-label="Resumen operativo">
    <article><span>Lanzador</span><strong data-cron-launcher><?= View::e((string) ($overview['state_label'] ?? 'Por comprobar')) ?></strong><p data-cron-launcher-detail><?= !empty($overview['last_signal_label']) ? 'Señal ' . View::e((string) $overview['last_signal_label']) : 'Sin señal reciente' ?></p></article>
    <article><span>Salidas HTTP · última hora</span><strong data-cron-remote-hour><?= isset($workload['remote_calls_last_hour']) ? number_format((int) $workload['remote_calls_last_hour'], 0, ',', '.') . ' transportes iniciados' : 'Por comprobar' ?></strong><p>Solicitudes cuyo transporte HTTP comenzó; no implica que todas terminaran bien.</p></article>
    <article><span>Trabajo terminado · última hora</span><strong data-cron-finalized-hour><?= isset($workload['finalized_last_hour']) ? number_format((int) $workload['finalized_last_hour'], 0, ',', '.') . ' recursos' : 'Por comprobar' ?></strong><p>No incluye inspecciones ni aplazamientos.</p></article>
    <article><span>Cola actual</span><strong data-cron-backlog><?= isset($workload['pending']) ? number_format((int) $workload['pending'], 0, ',', '.') . ' pendientes' : 'Por comprobar' ?></strong><p data-cron-trend><?= View::e((string) ($workload['trend_label'] ?? 'Cargando medición reciente')) ?></p></article>
  </section>

  <section class="api-command-section cron-rate-limit-panel" data-cron-rate-limit-signals hidden>
    <header>
      <div>
        <span class="eyebrow">Ritmo y protección</span>
        <h2>Rate limit 429 recientes</h2>
        <p data-cron-rate-limit-summary>Sin señales recientes.</p>
      </div>
      <a class="btn" href="<?= View::e($base) ?>/settings/api-health/incidents?http_status=429&amp;origin=remote">Ver 429</a>
    </header>
    <div class="api-attention-list" data-cron-rate-limit-list></div>
  </section>

  <section class="cron-task-section" aria-labelledby="cron-v3-title" data-cron-v3>
    <div class="section-heading">
      <div>
        <h2 id="cron-v3-title">Cron V3</h2>
        <p>Lectura independiente de los carriles local y remoto. Los contadores provienen de la cola y de intentos V3 registrados.</p>
      </div>
      <span class="status-badge is-unavailable" data-cron-v3-state>Por comprobar</span>
    </div>
    <p class="muted" data-cron-v3-message>No hay una señal V3 verificada todavía.</p>
    <div class="table-scroll">
      <table class="human-table cron-task-table" data-responsive="cards">
        <thead><tr><th>Carril</th><th>Ready FIFO</th><th>Leased</th><th>Diferidos</th><th>Parqueados</th><th>Identidad</th><th>Capacidad</th><th>Review</th><th>Dead</th><th>Antigüedad</th><th>Throughput/h</th><th>HTTP real/h</th><th>Señal</th></tr></thead>
        <tbody>
          <?php foreach (['local' => 'Local', 'remote' => 'Remoto'] as $lane => $label): ?>
            <tr data-cron-v3-lane="<?= View::e($lane) ?>">
              <td data-label="Carril"><strong><?= View::e($label) ?></strong></td>
              <?php foreach (['ready', 'leased', 'deferred', 'parked', 'waiting_identity', 'waiting_capability', 'review', 'dead'] as $metric): ?>
                <td data-label="<?= View::e(ucfirst($metric)) ?>" data-cron-v3-metric="<?= View::e($metric) ?>">0</td>
              <?php endforeach; ?>
              <td data-label="Antigüedad" data-cron-v3-metric="oldest_age_label">Sin comprobar</td>
              <td data-label="Throughput/h" data-cron-v3-metric="throughput_last_hour">0</td>
              <td data-label="HTTP real/h" data-cron-v3-metric="http_last_hour">0</td>
              <td data-label="Señal" data-cron-v3-metric="last_signal_label">Sin señal</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="muted" data-cron-v3-updated aria-live="polite">Cargando Cron V3 sin mutaciones…</p>
  </section>

  <section class="cron-task-section cron-v3-setup" aria-labelledby="cron-v3-runtime-title" data-cron-v3-runtime>
    <div class="section-heading">
      <div>
        <h2 id="cron-v3-runtime-title">Corte operativo V3</h2>
        <p>V3 queda como motor real. V2 se conserva como rollback técnico, pero no procesa colas durante el corte.</p>
      </div>
      <span class="status-badge is-unavailable" data-cron-v3-runtime-state>Comprobando</span>
    </div>
    <div class="operation-explainer">
      <div><span>Qué está pasando</span><strong data-cron-v3-runtime-now>Validando señales V3 y apagado V2.</strong></div>
      <div><span>Qué hará el ERP</span><strong data-cron-v3-runtime-next>V2 responderá skip; V3 local/remoto procesarán.</strong></div>
      <div><span>Qué hace Hostinger</span><strong data-cron-v3-runtime-hostinger>Dejar solo las dos tareas V3 activas.</strong></div>
    </div>
    <section class="cron-truth-grid" aria-label="Estado del corte V3">
      <article><span>V3 local</span><strong data-runtime-metric="local_signal">Por comprobar</strong><p>Debe recibir señal cada minuto.</p></article>
      <article><span>V3 remoto</span><strong data-runtime-metric="remote_signal">Por comprobar</strong><p>Debe recibir señal cada minuto.</p></article>
      <article><span>V2</span><strong data-runtime-metric="v2_signal">Por comprobar</strong><p>Si Hostinger lo llama, debe salir con skip.</p></article>
      <article><span>Ownership</span><strong data-runtime-metric="ownership">Por comprobar</strong><p>Transferido a V3 o bloqueado con causa.</p></article>
    </section>
    <div class="cron-command-grid" data-cron-v3-runtime-commands>
      <article><span>Eliminar o pausar V2</span><code>Se cargará desde el corte.</code></article>
      <article><span>V3 local activo</span><code>Se cargará desde el corte.</code></article>
      <article><span>V3 remoto activo</span><code>Se cargará desde el corte.</code></article>
    </div>
    <p class="muted" data-cron-v3-runtime-updated aria-live="polite">Cargando corte operativo sin mutaciones…</p>
  </section>

  <section class="cron-task-section" aria-labelledby="cron-v3-capability-title" data-cron-v3-capabilities>
    <div class="section-heading">
      <div>
        <h2 id="cron-v3-capability-title">Matriz de capacidades V3</h2>
        <p>Una fila por función: V3 activo, local seguro, esperando capacidad o no soportado. Nada queda oculto entre V2 y V3.</p>
      </div>
      <span class="status-badge is-unavailable" data-cron-v3-capability-state>Comprobando</span>
    </div>
    <div class="table-scroll">
      <table class="human-table cron-task-table" data-responsive="cards">
        <thead><tr><th>Función</th><th>Estado V3</th><th>Carril</th><th>Tipos exactos</th><th>Causa</th></tr></thead>
        <tbody data-cron-v3-capability-rows>
          <tr><td colspan="5">Cargando matriz sin modificar colas…</td></tr>
        </tbody>
      </table>
    </div>
    <p class="muted" data-cron-v3-capability-updated aria-live="polite">Cargando capacidades…</p>
  </section>

  <section class="cron-task-section cron-v3-setup" aria-labelledby="cron-v3-setup-title" data-cron-v3-setup>
    <div class="section-heading">
      <div>
        <h2 id="cron-v3-setup-title">Asistente Cron V3</h2>
        <p>El ERP prepara la configuración segura. Usted solo pega las tareas Shadow en Hostinger cuando el Doctor apruebe.</p>
      </div>
      <span class="status-badge is-unavailable" data-cron-v3-setup-state>Comprobando</span>
    </div>
    <div class="operation-explainer">
      <div><span>Qué está pasando</span><strong data-cron-v3-setup-now>Validando instalación y config.env.</strong></div>
      <div><span>Qué hará el ERP</span><strong>Preparará Shadow sin activar V3 real.</strong></div>
      <div><span>Qué hace Hostinger</span><strong>Pegar dos comandos cuando el panel lo indique.</strong></div>
    </div>
    <ol class="cron-next-list cron-v3-setup-steps" data-cron-v3-setup-steps>
      <li><strong>Instalación lista</strong><span>Por comprobar</span></li>
    </ol>
    <div class="cron-command-grid" data-cron-v3-retirement-preflight>
      <article><span>CRON_V3_ENABLED</span><strong data-cron-v3-retirement-flag="CRON_V3_ENABLED">Comprobando</strong></article>
      <article><span>CRON_V3_SHADOW_ENABLED</span><strong data-cron-v3-retirement-flag="CRON_V3_SHADOW_ENABLED">Comprobando</strong></article>
      <article><span>CRON_V4_ENABLED</span><strong data-cron-v3-retirement-flag="CRON_V4_ENABLED">Comprobando</strong></article>
      <article><span>ML_WRITE_ENABLED</span><strong data-cron-v3-retirement-flag="ML_WRITE_ENABLED">Comprobando</strong></article>
    </div>
    <p class="notice warning" data-cron-v3-retirement-state aria-live="polite">Verificando configuración efectiva y overrides de proceso…</p>
    <div class="page-actions mt-2">
      <form method="post" action="<?= View::e($base) ?>/settings/cron/v3-setup/prepare-safe-config" data-cron-v3-setup-action>
        <input type="hidden" name="_token" value="<?= View::e($csrfToken) ?>">
        <button class="btn primary" type="submit">Preparar configuración segura</button>
      </form>
      <form method="post" action="<?= View::e($base) ?>/settings/cron/v3-setup/enable-shadow" data-cron-v3-setup-action data-cron-v3-shadow-action>
        <input type="hidden" name="_token" value="<?= View::e($csrfToken) ?>">
        <button class="btn" type="submit">Activar Shadow V3</button>
      </form>
    </div>
    <section class="notice warning mt-2" data-cron-v3-retirement-action>
      <strong>Retirar autoridad V3 para preparar V4</strong>
      <p data-cron-v3-retirement-reason>Verificando versión, schema, Queue Engine y ownership activo…</p>
      <p>Esto desactivará la autoridad interna de Cron V3. No borrará trabajos históricos, ventas ni intentos. Cron V4 seguirá apagado.</p>
      <form method="post" action="<?= View::e($base) ?>/settings/cron/v3-setup/prepare-safe-config" data-cron-v3-retirement-form>
        <input type="hidden" name="_token" value="<?= View::e($csrfToken) ?>">
        <input type="hidden" name="operation" value="retire_for_v4">
        <label>Frase de confirmación
          <input type="text" name="confirmation_phrase" autocomplete="off" spellcheck="false" required
                 placeholder="RETIRAR_AUTORIDAD_V3_PARA_PREPARAR_V4">
        </label>
        <label>Contraseña administrativa
          <input type="password" name="admin_password" autocomplete="current-password" required>
        </label>
        <button class="btn danger" type="submit" disabled>Retirar autoridad V3 para preparar V4</button>
      </form>
      <pre class="code-block" data-cron-v3-retirement-receipt hidden></pre>
    </section>
    <section class="notice warning mt-2" data-v4-readiness-action>
      <strong>Preparar y certificar V4</strong>
      <p data-v4-readiness-reason>Verificando versión, schema, OAuth, Queue Engine y evidencia técnica…</p>
      <p>Cada confirmación avanza una etapa acotada. No crea Cron Hostinger ni activa Queue Engine.</p>
      <form method="post" action="<?= View::e($base) ?>/settings/cron/v3-setup/prepare-safe-config" data-v4-readiness-form>
        <input type="hidden" name="_token" value="<?= View::e($csrfToken) ?>">
        <input type="hidden" name="operation" value="v4_readiness_bootstrap">
        <label>Contraseña administrativa
          <input type="password" name="admin_password" autocomplete="current-password" required>
        </label>
        <p class="muted">La ausencia del scheduler se valida con la autoridad técnica registrada.</p>
        <button class="btn primary" type="submit" disabled>Preparar y certificar V4</button>
      </form>
      <pre class="code-block" data-v4-readiness-receipt hidden></pre>
    </section>
    <div class="cron-command-grid" data-cron-v3-commands>
      <article><span>V2 real actual</span><code>Se cargará desde el asistente.</code></article>
      <article><span>V3 local shadow</span><code>Se cargará desde el asistente.</code></article>
      <article><span>V3 remoto shadow</span><code>Se cargará desde el asistente.</code></article>
    </div>
    <p class="muted" data-cron-v3-setup-updated aria-live="polite">Cargando asistente sin mutaciones…</p>
  </section>

  <section class="cron-task-section cron-v3-setup" aria-labelledby="cron-v3-canary-title" data-cron-v3-canary>
    <div class="section-heading">
      <div>
        <h2 id="cron-v3-canary-title">Canario V3 real controlado</h2>
        <p>Activa V3 solo por familias certificadas. V2 sigue vivo y Mercado Libre continúa en solo lectura.</p>
      </div>
      <span class="status-badge is-unavailable" data-cron-v3-canary-state>Comprobando</span>
    </div>
    <div class="operation-explainer">
      <div><span>Qué está pasando</span><strong data-cron-v3-canary-now>Validando Shadow, Doctor y ownership.</strong></div>
      <div><span>Qué hará el ERP</span><strong>Primero financial_recalc local; después pack/shipment exactos.</strong></div>
      <div><span>Qué hace Hostinger</span><strong>Cambiar los dos comandos V3 solo cuando esta tarjeta lo indique.</strong></div>
    </div>
    <ol class="cron-next-list cron-v3-setup-steps" data-cron-v3-canary-steps>
      <li><strong>Shadow aprobado</strong><span>Por comprobar</span></li>
    </ol>
    <section class="cron-truth-grid" aria-label="Métricas del canario V3" data-cron-v3-canary-metrics>
      <article><span>Ciclos canario</span><strong data-canary-metric="cycles">0</strong><p>Local y remoto activos, no Shadow.</p></article>
      <article><span>HTTP reales</span><strong data-canary-metric="http_calls">0</strong><p>Solo transporte iniciado por V3 remoto.</p></article>
      <article><span>Recursos finalizados</span><strong data-canary-metric="resources_finalized">0</strong><p>No incluye inspecciones ni aplazamientos.</p></article>
      <article><span>Errores / 429 / leases</span><strong data-canary-metric="safety">0 / 0 / 0</strong><p>Cualquier alerta permite volver a V2.</p></article>
    </section>
    <div class="page-actions mt-2">
      <form method="post" action="<?= View::e($base) ?>/settings/cron/v3-canary/prepare" data-cron-v3-canary-action>
        <input type="hidden" name="_token" value="<?= View::e($csrfToken) ?>">
        <button class="btn primary" type="submit">Preparar canario V3</button>
      </form>
      <form method="post" action="<?= View::e($base) ?>/settings/cron/v3-canary/enable-local" data-cron-v3-canary-action>
        <input type="hidden" name="_token" value="<?= View::e($csrfToken) ?>">
        <button class="btn" type="submit">Habilitar canario local</button>
      </form>
      <form method="post" action="<?= View::e($base) ?>/settings/cron/v3-canary/enable-remote" data-cron-v3-canary-action>
        <input type="hidden" name="_token" value="<?= View::e($csrfToken) ?>">
        <button class="btn" type="submit">Habilitar canario remoto</button>
      </form>
      <form method="post" action="<?= View::e($base) ?>/settings/cron/v3-canary/rollback" data-cron-v3-canary-action>
        <input type="hidden" name="_token" value="<?= View::e($csrfToken) ?>">
        <button class="btn danger" type="submit">Volver a V2</button>
      </form>
    </div>
    <div class="cron-command-grid" data-cron-v3-canary-commands>
      <article><span>V2 real actual</span><code>Se cargará desde el canario.</code></article>
      <article><span>V3 local activo</span><code>Se cargará desde el canario.</code></article>
      <article><span>V3 remoto activo</span><code>Se cargará desde el canario.</code></article>
    </div>
    <p class="muted" data-cron-v3-canary-updated aria-live="polite">Cargando canario sin mutaciones…</p>
  </section>

  <section class="cron-cycle-grid" aria-label="Actividad de Cron">
    <article class="cron-cycle-block cron-now">
      <h2>Ahora</h2>
      <div data-cron-now>
        <?php if ($now): ?>
          <strong><?= View::e((string) ($now['label'] ?? 'Trabajo en curso')) ?></strong>
          <p>Inicio: <?= View::e((string) ($now['started_label'] ?? 'por comprobar')) ?> · Consulta remota: <?= !empty($now['remote_calls']) ? 'sí' : 'todavía no' ?></p>
        <?php else: ?>
          <strong>Esperando la próxima señal</strong>
          <p>No hay un trabajo con lease y heartbeat vigentes en este instante.</p>
        <?php endif; ?>
      </div>
    </article>

    <article class="cron-cycle-block">
      <h2>Último ciclo</h2>
      <div data-cron-last>
        <?php if ($lastRun): ?>
          <strong><?= (int) $lastRun['selected'] ?> funciones reclamadas · <?= (int) $lastRun['started'] ?> funciones iniciadas</strong>
          <p><?= (int) $lastRun['completed'] ?> recursos finalizados · <?= (int) $lastRun['deferred'] ?> recursos aplazados</p>
          <p><?= (int) ($lastRun['attempted_remote_calls'] ?? $lastRun['remote_calls']) ?> intentos HTTP · <?= (int) $lastRun['remote_calls'] ?> transportes iniciados · <?= (int) ($lastRun['blocked_remote_calls'] ?? 0) ?> bloqueados antes de salir</p>
          <?php if ((int) ($lastRun['not_started'] ?? 0) > 0): ?><p class="text-warning"><?= (int) $lastRun['not_started'] ?> <?= (int) $lastRun['not_started'] === 1 ? 'siguiente función no cupo' : 'funciones planeadas no cupieron' ?> en la ventana segura.</p><?php endif; ?>
        <?php else: ?>
          <strong>Sin ciclo registrado</strong><p>Cron todavía no ha cerrado una ejecución verificable.</p>
        <?php endif; ?>
      </div>
    </article>

    <article class="cron-cycle-block">
      <h2>Siguiente ciclo</h2>
      <ol class="cron-next-list" data-cron-next>
        <?php foreach ($next as $task): ?>
          <li><strong><?= View::e((string) ($task['label'] ?? 'Trabajo')) ?></strong><span><?= View::e((string) ($task['next_label'] ?? 'Siguiente ciclo')) ?></span></li>
        <?php endforeach; ?>
        <?php if ($next === []): ?><li><strong>Por comprobar</strong><span>Se actualizará sin crear trabajos.</span></li><?php endif; ?>
      </ol>
    </article>
  </section>

  <section id="colas" class="cron-task-section" aria-labelledby="cron-task-title">
    <div class="section-heading">
      <div><h2 id="cron-task-title">Trabajo administrado por Cron</h2><p><strong>Por atender</strong> es el total actual. <strong>Último lote</strong> muestra únicamente lo terminado en el turno anterior; nunca se resta ni se interpreta como total acumulado.</p></div>
      <span class="muted" data-cron-tasks-updated aria-live="polite">Cargando colas…</span>
    </div>
    <div class="table-scroll">
      <table class="human-table cron-task-table" data-responsive="cards">
        <thead><tr><th>Función</th><th>Pendientes</th><th>Finalizados/h</th><th>Salidas HTTP/h</th><th>Estado</th><th>Próxima oportunidad</th><th>ETA</th></tr></thead>
        <tbody data-cron-tasks>
          <tr><td colspan="7">Cargando el listado sin bloquear esta página…</td></tr>
        </tbody>
      </table>
    </div>
  </section>

  <p class="muted cron-overview-freshness" data-cron-overview-updated aria-live="polite">Cargando resumen…</p>

  <section id="historial" class="cron-history-section" aria-labelledby="cron-history-title">
    <div class="section-heading"><div><h2 id="cron-history-title">Historial reciente</h2><p>Las funciones, los transportes HTTP y los recursos usan contadores separados.</p></div><a class="btn" href="<?= View::e($base) ?>/settings/cron/history">Ver historial completo</a></div>
    <div class="cron-run-list" data-cron-history>
      <?php if ($history === []): ?><p class="api-inline-empty">Todavía no hay ciclos cerrados para mostrar.</p><?php endif; ?>
      <?php foreach ($history as $run): ?>
        <article>
          <div><strong><?= View::e((string) ($run['finished_label'] ?? 'Ciclo')) ?></strong><span><?= View::e((string) ($run['status'] ?? '')) ?></span></div>
          <p><?= (int) ($run['selected'] ?? 0) ?> funciones reclamadas · <?= (int) ($run['started'] ?? 0) ?> iniciadas · <?= (int) ($run['completed'] ?? 0) ?> recursos finalizados</p>
          <p><?= (int) ($run['remote_calls'] ?? 0) ?> transportes HTTP iniciados · <?= (int) ($run['deferred'] ?? 0) ?> aplazados · <?= number_format(((int) ($run['duration_ms'] ?? 0)) / 1000, 1, ',', '.') ?> s</p>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <details class="cron-technical">
    <summary>Detalle técnico y configuración</summary>
    <div class="details-content">
      <p>Única tarea permitida en Hostinger:</p>
      <code>* * * * * /usr/bin/php /home/&lt;usuario&gt;/domains/&lt;dominio&gt;/public_html/erp-meli/jobs/cron_v3_local.php --runtime=45 --max-items=50</code>
      <code>* * * * * /usr/bin/php /home/&lt;usuario&gt;/domains/&lt;dominio&gt;/public_html/erp-meli/jobs/cron_v3_remote.php --delay=10 --runtime=35 --max-http=12</code>
      <p>Con V3 operativo, V2 queda solo como rollback técnico. Si Hostinger todavía lo llama, debe responder <code>ERP_CRON_SKIP reason=v3_operational</code>.</p>
      <div class="page-actions mt-2">
        <a class="btn" href="<?= View::e($base) ?>/settings/cron/history">Historial</a>
        <a class="btn" href="<?= View::e($base) ?>/settings/cron/queue">Cola completa</a>
        <a class="btn" href="<?= View::e($base) ?>/settings/cron/diagnostics">Diagnóstico profundo</a>
      </div>
    </div>
  </details>
</div>
