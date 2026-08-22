<?php

use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$stateLabel = static fn(string $state): string => match ($state) {
    'active' => 'Activo',
    'reviewed' => 'Revisado',
    'historical' => 'Histórico',
    default => 'Recuperado',
};
$severityClass = static fn(string $severity): string => match ($severity) {
    'critical' => 'red',
    'high', 'medium' => 'amber',
    default => 'green',
};
$severitySummary = [
    'critical' => count(array_filter($incidents, static fn(array $incident): bool => (string) ($incident['severity'] ?? '') === 'critical')),
    'rate_limit' => count(array_filter($incidents, static fn(array $incident): bool => ($incident['transport_class'] ?? '') === 'REMOTE_HTTP_429')),
    'permission' => count(array_filter($incidents, static fn(array $incident): bool => in_array((int) ($incident['http_status'] ?? 0), [401, 403], true))),
    'remote' => count(array_filter($incidents, static fn(array $incident): bool => !empty($incident['reached_remote']) && (int) ($incident['http_status'] ?? 0) >= 500)),
    'internal' => count(array_filter($incidents, static fn(array $incident): bool => empty($incident['reached_remote']) && (string) ($incident['outcome_class'] ?? '') !== 'policy_delay')),
];
$apiHealthSection = 'incidents';
$apiHealthHours = (int) ($filters['hours'] ?? 24);
$apiHealthCheckedAt = gmdate('Y-m-d H:i:s');
require __DIR__ . '/_api_health_header.php';
require __DIR__ . '/_api_health_nav.php';
?>
<div class="api-subpage-intro"><div><span class="eyebrow">Incidentes</span><h2>Incidentes de integración</h2><p>Causas agrupadas, cuentas afectadas y recomendaciones sin repetir miles de registros iguales.</p></div></div>

<?php if (($incidentReadMode ?? '') === 'degraded_direct'): ?>
  <section class="alert warning" aria-live="polite">
    <strong>Telemetría directa no agrupada.</strong>
    El catálogo local de incidentes está atrasado. Esta vista muestra como máximo 50 resultados de los últimos 30 días con una ruta indexada certificada; no permite reconocer ni modificar incidentes.
  </section>
<?php endif; ?>

<section class="api-essential-metrics" aria-label="Resumen de gravedad de incidentes">
  <a href="<?= View::e($base) ?>/settings/api-health/incidents?severity=critical"><span>Críticos</span><strong><?= (int) $severitySummary['critical'] ?></strong><p>Bloqueo, OAuth o autorización</p></a>
  <a href="<?= View::e($base) ?>/settings/api-health/incidents?http_status=429&amp;origin=remote"><span>Rate limit 429</span><strong><?= (int) $severitySummary['rate_limit'] ?></strong><p>Reducir ritmo y respetar espera</p></a>
  <a href="<?= View::e($base) ?>/settings/api-health/incidents?severity=high"><span>Permisos / OAuth</span><strong><?= (int) $severitySummary['permission'] ?></strong><p>401/403 por cuenta u operación</p></a>
  <a href="<?= View::e($base) ?>/settings/api-health/incidents?origin=remote"><span>Fallos Mercado Libre</span><strong><?= (int) $severitySummary['remote'] ?></strong><p>5xx o respuesta remota</p></a>
  <a href="<?= View::e($base) ?>/settings/api-health/incidents?origin=internal"><span>Fallos internos</span><strong><?= (int) $severitySummary['internal'] ?></strong><p>No llegaron a Mercado Libre</p></a>
</section>

<section class="api-command-section api-filter-section">
  <form class="filters api-incident-filters" method="get" action="<?= View::e($base) ?>/settings/api-health/incidents">
    <?php if ((int) ($filters['http_status'] ?? 0) > 0): ?><input type="hidden" name="http_status" value="<?= (int) $filters['http_status'] ?>"><?php endif; ?>
    <div class="field"><label for="incidentStatus">Estado</label><select class="input" id="incidentStatus" name="status"><option value="">Todos</option><option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Activos</option><option value="recovered" <?= $filters['status'] === 'recovered' ? 'selected' : '' ?>>Recuperados</option><option value="reviewed" <?= $filters['status'] === 'reviewed' ? 'selected' : '' ?>>Revisados</option><option value="historical" <?= $filters['status'] === 'historical' ? 'selected' : '' ?>>Históricos</option></select></div>
    <div class="field"><label for="incidentAccount">Cuenta</label><select class="input" id="incidentAccount" name="account_id"><option value="0">Todas</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) $filters['account_id'] === (int) $account['id'] ? 'selected' : '' ?>><?= View::e((string) $account['account_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="incidentOrigin">Origen</label><select class="input" id="incidentOrigin" name="origin"><option value="">Todos</option><option value="internal" <?= $filters['origin'] === 'internal' ? 'selected' : '' ?>>Dentro del ERP</option><option value="remote" <?= $filters['origin'] === 'remote' ? 'selected' : '' ?>>Mercado Libre</option></select></div>
    <div class="field"><label for="incidentSeverity">Severidad</label><select class="input" id="incidentSeverity" name="severity"><option value="">Todas</option><option value="critical" <?= $filters['severity'] === 'critical' ? 'selected' : '' ?>>Crítica</option><option value="high" <?= $filters['severity'] === 'high' ? 'selected' : '' ?>>Alta</option><option value="medium" <?= $filters['severity'] === 'medium' ? 'selected' : '' ?>>Media</option><option value="low" <?= $filters['severity'] === 'low' ? 'selected' : '' ?>>Baja</option></select></div>
    <div class="field"><label for="incidentHours">Periodo</label><select class="input" id="incidentHours" name="hours"><option value="24" <?= (int) $filters['hours'] === 24 ? 'selected' : '' ?>>24 horas</option><option value="168" <?= (int) $filters['hours'] === 168 ? 'selected' : '' ?>>7 días</option><option value="720" <?= (int) $filters['hours'] === 720 ? 'selected' : '' ?>>30 días</option></select></div>
    <div class="field"><label for="incidentOperation">Operación</label><input class="input" id="incidentOperation" name="operation" value="<?= View::e((string) $filters['operation']) ?>" placeholder="/orders o autorización"></div>
    <div class="field"><label for="incidentPageSize">Resultados</label><select class="input" id="incidentPageSize" name="per_page"><?php foreach ([25, 50, 100] as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?> por página</option><?php endforeach; ?></select></div>
    <div class="field filter-action"><button class="btn primary" type="submit">Filtrar</button></div>
  </form>
  <div class="page-actions mt-2" aria-label="Filtros rápidos de incidentes">
    <a class="btn small" href="<?= View::e($base) ?>/settings/api-health/incidents?http_status=429&amp;origin=remote">429</a>
    <a class="btn small" href="<?= View::e($base) ?>/settings/api-health/incidents?status=active">Activos</a>
    <a class="btn small" href="<?= View::e($base) ?>/settings/api-health/incidents?hours=24">Últimas 24 h</a>
    <a class="btn small" href="<?= View::e($base) ?>/settings/api-health/incidents?origin=remote">Por cuenta remota</a>
  </div>
</section>

<section class="api-command-section">
  <?php $firstShown = $total > 0 ? (($page - 1) * $perPage) + 1 : 0; $lastShown = min($total, $page * $perPage); $directDegraded = ($incidentReadMode ?? '') === 'degraded_direct'; ?>
  <header class="panel-head"><div><h2><?= $directDegraded ? number_format($total, 0, ',', '.') . ' eventos directos recientes' : number_format($total, 0, ',', '.') . ' ' . ($total === 1 ? 'incidente agrupado' : 'incidentes agrupados') ?></h2><p><?= $directDegraded ? 'Lectura directa limitada a esta página. El total agrupado se certificará cuando el catálogo local se ponga al día.' : 'Mostrando ' . number_format($firstShown, 0, ',', '.') . '–' . number_format($lastShown, 0, ',', '.') . '. Cada tarjeta puede representar varias repeticiones de la misma causa.' ?></p></div></header>
  <div class="incident-list">
    <?php if ($incidents === []): ?><div class="empty-state"><strong>No hay incidentes para estos filtros</strong><span>Pruebe otro periodo o elimine alguno de los filtros.</span></div><?php endif; ?>
    <?php foreach ($incidents as $incident): ?>
      <article class="incident-card">
        <div class="incident-card-main">
          <div class="incident-title-row"><span class="badge <?= View::e(in_array((string) $incident['state'], ['recovered', 'reviewed', 'historical'], true) ? 'green' : $severityClass((string) $incident['severity'])) ?>"><?= View::e($stateLabel((string) $incident['state'])) ?></span><strong><?= View::e((string) $incident['title']) ?></strong></div>
          <p><?= View::e((string) $incident['impact']) ?></p>
          <div class="incident-meta">
            <span><?= number_format((int) $incident['repetitions'], 0, ',', '.') ?> repeticiones</span>
            <span><?= View::e((string) ($incident['account_names'] ?: 'Aplicación')) ?></span>
            <span><?= View::e((string) $incident['operation_label']) ?></span>
            <span>Última vez: <?= View::e(DateTimePresenter::formatQueue($incident['last_seen_at'])) ?></span>
            <span>Activo ahora: <strong><?= !empty($incident['active_now']) ? 'Sí' : 'No' ?></strong></span>
            <span>Transporte: <strong><?= View::e((string) ($incident['transport_label'] ?? 'Sin clasificar')) ?></strong></span>
            <span>Señal: <strong><?= View::e((string) ($incident['signal_label'] ?? 'Sin clasificar')) ?></strong></span>
          </div>
        </div>
        <?php if ($directDegraded): ?>
          <span class="btn disabled" aria-disabled="true">Detalle al recuperar catálogo</span>
        <?php else: ?>
          <a class="btn" href="<?= View::e($base) ?>/settings/api-health/incidents/show?key=<?= View::e((string) $incident['incident_key']) ?>">Ver qué ocurrió</a>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if ($pages > 1): $pageQuery = $filters + ['per_page' => $perPage]; ?>
    <nav class="pagination" aria-label="Páginas de incidentes">
      <?php if ($page > 1): $pageQuery['page'] = $page - 1; ?><a class="btn" rel="prev" href="<?= View::e($base . '/settings/api-health/incidents?' . http_build_query($pageQuery)) ?>">Anterior</a><?php endif; ?>
      <span>Página <?= $page ?> de <?= $pages ?></span>
      <?php if ($page < $pages): $pageQuery['page'] = $page + 1; ?><a class="btn" rel="next" href="<?= View::e($base . '/settings/api-health/incidents?' . http_build_query($pageQuery)) ?>">Siguiente</a><?php endif; ?>
    </nav>
  <?php endif; ?>
</section>
