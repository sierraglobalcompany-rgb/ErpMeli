<?php

use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$isApi = ($filters['type'] ?? 'api') === 'api';
$view = (string) ($filters['view'] ?? 'summary');
$query = static function (array $changes = []) use ($filters): string {
    $values = array_merge($filters, $changes);
    return http_build_query(array_filter($values, static fn (mixed $value): bool => $value !== '' && $value !== 0 && $value !== null));
};
$sortLink = static function (string $key) use ($filters, $query): string {
    $current = ($filters['sort'] ?? 'date') === $key;
    $next = $current && ($filters['direction'] ?? 'desc') === 'desc' ? 'asc' : 'desc';
    return '?' . $query(['view' => 'technical', 'sort' => $key, 'direction' => $next, 'page' => 1]);
};
$plural = static fn (int $count, string $singular, string $plural): string => $count === 1 ? $singular : $plural;
$risk = 'healthy';
$riskTitle = 'Integración disponible';
$riskText = 'No hay incidentes activos en el periodo seleccionado.';
if (empty($summary['available'])) {
    $risk = 'unknown';
    $riskTitle = 'No se pudo comprobar';
    $riskText = 'El ERP no pudo consultar el resumen. Revise el diagnóstico antes de asumir que todo está correcto.';
} elseif ($activeIncidents !== []) {
    $critical = array_filter($activeIncidents, static fn (array $incident): bool => !empty($incident['blocking_risk']));
    $risk = $critical !== [] ? 'critical' : 'attention';
    $riskTitle = $critical !== [] ? 'Existe una señal crítica vigente' : 'Hay incidentes que requieren revisión';
    $riskText = $critical !== []
        ? 'Mantenga las consultas pausadas y abra el incidente antes de reintentar.'
        : 'La integración sigue disponible, pero conviene revisar las operaciones señaladas.';
} elseif ($recoveredIncidents !== []) {
    $risk = 'recovered';
    $riskTitle = 'Integración disponible';
    $riskText = 'Hubo incidentes anteriores, pero ya no se están repitiendo.';
}
?>
<div class="page-head">
  <div>
    <span class="eyebrow">Control de la integración</span>
    <h1><?= $isApi ? 'Actividad de Mercado Libre' : 'Logs del sistema' ?></h1>
    <p><?= $isApi ? 'Qué funciona, qué requiere atención y qué ocurrió, explicado en lenguaje humano.' : 'Historial técnico de procesos internos.' ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/settings/api-health">Salud API</a>
    <?php if ($isApi): ?><a class="btn" href="<?= View::e($base) ?>/logs/api/incidents">Todos los incidentes</a><?php endif; ?>
  </div>
</div>

<?php if ($isApi): ?>
<nav class="log-view-tabs" aria-label="Vistas de actividad">
  <?php foreach ([
      'summary' => 'Resumen',
      'attention' => 'Necesitan atención',
      'activity' => 'Actividad normal',
      'technical' => 'Historial técnico',
  ] as $key => $label): ?>
    <a href="?<?= View::e($query(['view' => $key, 'page' => 1])) ?>" <?= $view === $key ? 'aria-current="page"' : '' ?>><?= View::e($label) ?></a>
  <?php endforeach; ?>
</nav>

<section class="log-status-hero is-<?= View::e($risk) ?>" aria-labelledby="log-status-title">
  <span class="log-status-mark" aria-hidden="true"><?= $risk === 'healthy' || $risk === 'recovered' ? '✓' : '!' ?></span>
  <div>
    <span class="eyebrow">Estado actual</span>
    <h2 id="log-status-title"><?= View::e($riskTitle) ?></h2>
    <p><?= View::e($riskText) ?></p>
  </div>
  <?php if ($activeIncidents !== []): ?><a class="btn" href="?<?= View::e($query(['view' => 'attention'])) ?>">Revisar ahora</a><?php endif; ?>
</section>

<form class="log-simple-filters panel" method="get" action="<?= View::e($base) ?>/logs">
  <input type="hidden" name="type" value="api">
  <input type="hidden" name="view" value="<?= View::e($view) ?>">
  <div class="field">
    <label for="log-period">Periodo</label>
    <select class="input" id="log-period" name="period">
      <?php foreach ([24 => 'Últimas 24 horas', 168 => 'Últimos 7 días', 720 => 'Últimos 30 días'] as $hours => $label): ?>
        <option value="<?= $hours ?>" <?= (int) $filters['period'] === $hours ? 'selected' : '' ?>><?= View::e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="log-account">Cuenta</label>
    <select class="input" id="log-account" name="account_id">
      <option value="0">Todas las cuentas</option>
      <?php foreach ($accounts as $account): ?>
        <option value="<?= (int) $account['id'] ?>" <?= (int) $filters['account_id'] === (int) $account['id'] ? 'selected' : '' ?>><?= View::e((string) $account['account_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn primary" type="submit">Actualizar vista</button>
</form>

<?php if ($view === 'summary'): ?>
  <section class="log-metrics" aria-label="Resumen del periodo">
    <article><span>Consultas enviadas</span><strong><?= number_format((int) ($summary['sent'] ?? 0), 0, ',', '.') ?></strong><small>Salieron del ERP</small></article>
    <article><span>Consultas correctas</span><strong><?= number_format((int) ($summary['successful'] ?? 0), 0, ',', '.') ?></strong><small>Respondieron correctamente</small></article>
    <article><span>Incidentes activos</span><strong><?= count($activeIncidents) ?></strong><small><?= count($activeIncidents) === 0 ? 'Sin asuntos vigentes' : 'Requieren revisión' ?></small></article>
    <article><span>Datos opcionales ausentes</span><strong><?= number_format((int) ($summary['expected_absence'] ?? 0), 0, ',', '.') ?></strong><small>No implican bloqueo</small></article>
  </section>

  <section class="panel">
    <header class="panel-head">
      <div><h2>Necesitan atención</h2><p>Solo incidentes que siguen activos.</p></div>
      <?php if ($activeIncidents !== []): ?><a class="btn" href="?<?= View::e($query(['view' => 'attention'])) ?>">Ver todos</a><?php endif; ?>
    </header>
    <div class="incident-list compact">
      <?php if ($activeIncidents === []): ?><div class="empty-state"><strong>No hay asuntos activos</strong><span>La actividad normal aparece en la siguiente sección.</span></div><?php endif; ?>
      <?php foreach (array_slice($activeIncidents, 0, 4) as $incident): ?>
        <a class="incident-card incident-card-link" href="<?= View::e($base) ?>/logs/api/incidents/show?key=<?= View::e((string) $incident['incident_key']) ?>">
          <div class="incident-card-main">
            <div class="incident-title-row"><strong><?= View::e((string) $incident['title']) ?></strong><span class="badge amber">Activo</span></div>
            <p><?= View::e((string) $incident['impact']) ?></p>
            <div class="incident-meta"><span><?= number_format((int) $incident['repetitions'], 0, ',', '.') ?> <?= $plural((int) $incident['repetitions'], 'repetición', 'repeticiones') ?></span><span>Riesgo vigente: <?= !empty($incident['blocking_risk']) ? 'Sí' : 'No' ?></span></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="panel mt-2">
    <header class="panel-head"><div><h2>Actividad normal reciente</h2><p>Agrupada para evitar listas repetitivas.</p></div><a class="btn" href="?<?= View::e($query(['view' => 'activity'])) ?>">Ver actividad</a></header>
    <div class="log-activity-list">
      <?php if ($activity === []): ?><div class="empty-state"><strong>Sin actividad en este periodo</strong><span>Esto no es un error si no había trabajos pendientes.</span></div><?php endif; ?>
      <?php foreach (array_slice($activity, 0, 6) as $group): ?>
        <article><div><strong><?= View::e((string) $group['operation_label']) ?></strong><span><?= View::e((string) ($group['account_name'] ?? 'Aplicación')) ?></span></div><div><strong><?= number_format((int) $group['occurrences'], 0, ',', '.') ?></strong><small><?= $plural((int) $group['occurrences'], 'consulta', 'consultas') ?> · <?= View::e(DateTimePresenter::format($group['last_seen_at'] ?? null)) ?></small></div></article>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if ($recoveredIncidents !== []): ?>
  <details class="panel mt-2 log-recovered">
    <summary>Incidentes recuperados recientes (<?= count($recoveredIncidents) ?>)</summary>
    <div class="incident-list compact">
      <?php foreach ($recoveredIncidents as $incident): ?>
        <a class="incident-card incident-card-link" href="<?= View::e($base) ?>/logs/api/incidents/show?key=<?= View::e((string) $incident['incident_key']) ?>">
          <div class="incident-card-main"><div class="incident-title-row"><strong><?= View::e((string) $incident['title']) ?></strong><span class="badge green">Recuperado</span></div><p><?= View::e((string) $incident['impact']) ?></p><div class="incident-meta"><span>Última ocurrencia: <?= View::e(DateTimePresenter::format($incident['last_seen_at'] ?? null)) ?></span><span>Riesgo vigente: No</span></div></div>
        </a>
      <?php endforeach; ?>
    </div>
  </details>
  <?php endif; ?>
<?php elseif ($view === 'activity'): ?>
  <section class="panel">
    <header class="panel-head"><div><h2>Actividad normal</h2><p>Operaciones correctas agrupadas por cuenta y tipo.</p></div></header>
    <div class="log-activity-list">
      <?php if ($activity === []): ?><div class="empty-state"><strong>Sin actividad en el periodo</strong></div><?php endif; ?>
      <?php foreach ($activity as $group): ?>
        <article><div><strong><?= View::e((string) $group['operation_label']) ?></strong><span><?= View::e((string) ($group['account_name'] ?? 'Aplicación')) ?></span></div><div><strong><?= number_format((int) $group['occurrences'], 0, ',', '.') ?></strong><small><?= $plural((int) $group['occurrences'], 'consulta', 'consultas') ?> · <?= View::e(DateTimePresenter::format($group['last_seen_at'] ?? null)) ?></small></div></article>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php if (in_array($view, ['attention', 'technical'], true)): ?>
  <?php if ($view === 'attention'): ?>
  <section class="panel">
    <header class="panel-head"><div><h2>Incidentes activos</h2><p>Agrupados por causa, no por número de repeticiones.</p></div></header>
    <div class="incident-list compact">
      <?php if ($activeIncidents === []): ?><div class="empty-state"><strong>No hay incidentes activos</strong></div><?php endif; ?>
      <?php foreach ($activeIncidents as $incident): ?>
        <a class="incident-card incident-card-link" href="<?= View::e($base) ?>/logs/api/incidents/show?key=<?= View::e((string) $incident['incident_key']) ?>"><div class="incident-card-main"><div class="incident-title-row"><strong><?= View::e((string) $incident['title']) ?></strong><span class="badge amber">Activo</span></div><p><?= View::e((string) $incident['impact']) ?></p><div class="incident-meta"><span><?= number_format((int) $incident['repetitions'], 0, ',', '.') ?> <?= $plural((int) $incident['repetitions'], 'repetición', 'repeticiones') ?></span><span>Riesgo vigente: <?= !empty($incident['blocking_risk']) ? 'Sí' : 'No' ?></span></div></div></a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <details class="panel mt-2 log-advanced" <?= $view === 'technical' ? 'open' : '' ?>>
    <summary>Opciones avanzadas</summary>
    <form class="filters log-filters" method="get" action="<?= View::e($base) ?>/logs">
      <input type="hidden" name="type" value="api"><input type="hidden" name="view" value="<?= View::e($view) ?>">
      <div class="field"><label for="log-risk">Riesgo</label><select class="input" id="log-risk" name="risk"><option value="">Todos</option><option value="none" <?= $filters['risk']==='none'?'selected':'' ?>>Ninguno</option><option value="review" <?= $filters['risk']==='review'?'selected':'' ?>>Revisar</option><option value="high" <?= $filters['risk']==='high'?'selected':'' ?>>Alto</option><option value="critical" <?= $filters['risk']==='critical'?'selected':'' ?>>Crítico</option></select></div>
      <div class="field"><label for="log-origin">Origen</label><select class="input" id="log-origin" name="origin"><option value="">Todos</option><option value="remote" <?= $filters['origin']==='remote'?'selected':'' ?>>Mercado Libre</option><option value="local" <?= $filters['origin']==='local'?'selected':'' ?>>Dentro del ERP</option></select></div>
      <div class="field"><label for="log-outcome">Resultado</label><select class="input" id="log-outcome" name="outcome"><option value="">Todos</option><option value="success" <?= $filters['outcome']==='success'?'selected':'' ?>>Correcto</option><option value="expected_absence" <?= $filters['outcome']==='expected_absence'?'selected':'' ?>>Ausencia esperada</option><option value="remote_error" <?= $filters['outcome']==='remote_error'?'selected':'' ?>>Error remoto</option><option value="local_failure" <?= $filters['outcome']==='local_failure'?'selected':'' ?>>Fallo interno</option><option value="policy_delay" <?= $filters['outcome']==='policy_delay'?'selected':'' ?>>Aplazado</option><option value="blocked_signal" <?= $filters['outcome']==='blocked_signal'?'selected':'' ?>>Señal crítica</option></select></div>
      <div class="field"><label for="log-http">HTTP</label><input class="input" id="log-http" name="http_status" inputmode="numeric" value="<?= View::e($filters['http_status']) ?>" placeholder="404"></div>
      <div class="field"><label for="log-endpoint">Operación o endpoint</label><input class="input" id="log-endpoint" name="endpoint" value="<?= View::e($filters['endpoint']) ?>" placeholder="/items/{id}/description"></div>
      <div class="field"><label for="log-from">Desde</label><input class="input" id="log-from" type="date" name="from" value="<?= View::e($filters['from']) ?>"></div>
      <div class="field"><label for="log-to">Hasta</label><input class="input" id="log-to" type="date" name="to" value="<?= View::e($filters['to']) ?>"></div>
      <div class="field"><label for="log-per-page">Resultados</label><select class="input" id="log-per-page" name="per_page"><?php foreach ([25,50,100] as $size): ?><option value="<?= $size ?>" <?= (int) $filters['per_page']===$size?'selected':'' ?>><?= $size ?> por página</option><?php endforeach; ?></select></div>
      <div class="field filter-action"><button class="btn primary" type="submit">Aplicar filtros</button></div>
    </form>
  </details>

  <section class="panel table-panel">
    <header class="panel-head"><div><h2><?= $view === 'attention' ? 'Eventos relacionados' : 'Historial técnico' ?></h2><p><?= number_format((int) $result['total'], 0, ',', '.') ?> resultados · Más recientes primero</p></div></header>
    <div class="table-scroll">
      <table class="data-table api-log-table">
        <caption>Eventos ordenados de más reciente a más antiguo</caption>
        <thead><tr><th><a href="<?= View::e($sortLink('date')) ?>">Fecha</a></th><th><a href="<?= View::e($sortLink('type')) ?>">Tipo</a></th><th><a href="<?= View::e($sortLink('account')) ?>">Cuenta</a></th><th><a href="<?= View::e($sortLink('http')) ?>">Nivel/HTTP</a></th><th><a href="<?= View::e($sortLink('operation')) ?>">Operación</a></th><th><a href="<?= View::e($sortLink('message')) ?>">Mensaje</a></th><th>Interpretación</th></tr></thead>
        <tbody>
        <?php if ($rows === []): ?><tr><td colspan="7"><div class="empty-state"><strong>No hay eventos para estos filtros</strong></div></td></tr><?php endif; ?>
        <?php foreach ($rows as $row): $meaning = $presenter->present($row); ?>
          <tr>
            <td data-label="Fecha"><?= View::e(DateTimePresenter::format($row['created_at'] ?? null)) ?></td>
            <td data-label="Tipo"><?= View::e((string) $meaning['state']) ?></td>
            <td data-label="Cuenta"><?= View::e((string) ($row['account_name'] ?? '—')) ?></td>
            <td data-label="Nivel/HTTP"><span class="badge <?= View::e((string) $meaning['class']) ?>"><?= View::e((string) ($row['http_status'] ?? 'Interno')) ?></span><small class="risk-label">Riesgo: <?= View::e((string) $meaning['risk']) ?></small></td>
            <td data-label="Operación"><strong><?= View::e(\App\Services\UiLabelPresenter::apiOperation((string) ($row['method'] ?? ''), (string) ($row['endpoint_path'] ?? ''))) ?></strong><?php if ($view === 'technical'): ?><code><?= View::e((string) ($row['method'] ?? '')) ?> <?= View::e((string) ($row['endpoint_path'] ?? '—')) ?></code><?php endif; ?></td>
            <td data-label="Mensaje"><?= View::e((string) ($row['message'] ?? '—')) ?></td>
            <td data-label="Interpretación"><details class="log-meaning"><summary>¿Qué significa?</summary><div><strong><?= View::e((string) $meaning['title']) ?></strong><p><?= View::e((string) $meaning['meaning']) ?></p><dl><div><dt>Llegó a Mercado Libre</dt><dd><?= !empty($meaning['reached_remote']) ? 'Sí' : 'No' ?></dd></div><div><dt>Posibilidad de bloqueo</dt><dd><?= !empty($meaning['blocking']) ? 'Sí' : 'No' ?></dd></div><div><dt>Acción</dt><dd><?= View::e((string) $meaning['action']) ?></dd></div></dl></div></details></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($result['pages'] > 1): ?><nav class="pagination" aria-label="Páginas de logs"><?php if ($result['page'] > 1): ?><a class="btn" href="?<?= View::e($query(['page' => $result['page'] - 1])) ?>">Anterior</a><?php endif; ?><span>Página <?= (int) $result['page'] ?> de <?= (int) $result['pages'] ?></span><?php if ($result['page'] < $result['pages']): ?><a class="btn" href="?<?= View::e($query(['page' => $result['page'] + 1])) ?>">Siguiente</a><?php endif; ?></nav><?php endif; ?>
  </section>
<?php endif; ?>

<details class="panel mt-2 api-log-guide">
  <summary>Cómo interpretar estos eventos</summary>
  <div class="api-log-guide-body"><p>Un código HTTP no determina por sí solo el riesgo. Un 404 suele indicar que el recurso no existe y no implica bloqueo. El ERP también evalúa la operación, repetición, cuenta y si la consulta llegó realmente a Mercado Libre.</p><div class="api-risk-legend" aria-label="Niveles de interpretación"><span><i class="risk-dot is-neutral"></i><strong>Informativo</strong> Dato opcional ausente o consulta correcta.</span><span><i class="risk-dot is-warning"></i><strong>Atención</strong> Conviene revisar; sin riesgo inmediato.</span><span><i class="risk-dot is-protected"></i><strong>Pausa preventiva</strong> El ERP aplazó la consulta.</span><span><i class="risk-dot is-critical"></i><strong>Crítico</strong> Señal real de autorización o exceso.</span></div></div>
</details>
<?php else: ?>
<section class="panel filter-bar">
  <form class="filters" method="get" action="<?= View::e($base) ?>/logs"><div class="field"><label for="log-type">Tipo</label><select class="input" id="log-type" name="type"><?php foreach (['system'=>'Sistema','sync'=>'Sincronización','cron'=>'Cron','questions'=>'Preguntas'] as $key=>$label): ?><option value="<?= View::e($key) ?>" <?= $filters['type']===$key?'selected':'' ?>><?= View::e($label) ?></option><?php endforeach; ?></select></div><div class="field"><label for="log-level">Nivel o estado</label><input class="input" id="log-level" name="level" value="<?= View::e($filters['level']) ?>"></div><button class="btn primary" type="submit">Aplicar</button></form>
</section>
<section class="panel table-panel"><header class="panel-head"><div><h2>Eventos recientes</h2><p>Más recientes primero</p></div></header><div class="table-scroll"><table class="data-table"><caption>Eventos técnicos del sistema</caption><thead><tr><th>Fecha</th><th>Tipo</th><th>Cuenta</th><th>Estado</th><th>Operación</th><th>Mensaje</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= View::e(DateTimePresenter::format($row['created_at'] ?? null)) ?></td><td><?= View::e((string) ($row['log_type'] ?? '')) ?></td><td><?= View::e((string) ($row['account_name'] ?? '—')) ?></td><td><?= View::e((string) ($row['level'] ?? '—')) ?></td><td><?= View::e((string) ($row['endpoint_path'] ?? '—')) ?></td><td><?= View::e((string) ($row['message'] ?? '—')) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>


