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
$isRemote429 = (int) ($filters['http_status'] ?? 0) === 429 && (string) ($filters['origin'] ?? '') === 'remote';
$nowTs = time();
$remote429Windows = ['60m' => 0, '24h' => 0, '7d' => 0, '30d' => 0, 'last_event' => null];
if ($isRemote429) {
    if (isset($remote429WindowSummary) && is_array($remote429WindowSummary)) {
        foreach (['60m', '24h', '7d', '30d', 'last_event'] as $key) {
            $remote429Windows[$key] = $remote429WindowSummary[$key] ?? $remote429Windows[$key];
        }
    } else {
        foreach ($incidents as $incident) {
            $seen = strtotime((string) ($incident['last_seen_at'] ?? '') . ' UTC') ?: 0;
            $repetitions = max(1, (int) ($incident['repetitions'] ?? 1));
            if ($seen > 0 && ($remote429Windows['last_event'] === null || $seen > (strtotime((string) $remote429Windows['last_event'] . ' UTC') ?: 0))) {
                $remote429Windows['last_event'] = $incident['last_seen_at'] ?? null;
            }
            if ($seen >= $nowTs - 3600) $remote429Windows['60m'] += $repetitions;
            if ($seen >= $nowTs - 86400) $remote429Windows['24h'] += $repetitions;
            if ($seen >= $nowTs - 604800) $remote429Windows['7d'] += $repetitions;
            if ($seen >= $nowTs - 2592000) $remote429Windows['30d'] += $repetitions;
        }
    }
}
$incidentLink = static function (array $overrides) use ($base, $filters): string {
    $query = array_merge($filters, $overrides);
    $query = array_filter($query, static fn($value): bool => $value !== '' && $value !== null && $value !== 0 && $value !== '0');
    return $base . '/settings/api-health/incidents?' . http_build_query($query);
};
$apiHealthSection = 'incidents';
$apiHealthHours = (int) ($filters['hours'] ?? 24);
$apiHealthCheckedAt = gmdate('Y-m-d H:i:s');
$operatorCheckedAtLabel = DateTimePresenter::formatQueue($apiHealthCheckedAt, 'd/m H:i');
require __DIR__ . '/_api_health_header.php';
require __DIR__ . '/_api_health_nav.php';
?>
<div class="api-subpage-intro"><div><span class="eyebrow">Incidentes</span><h2><?= $isRemote429 ? '429 REMOTOS' : 'Incidentes de integración' ?></h2><p><?= $isRemote429 ? 'Primero muestra si hay 429 activo ahora; el histórico queda como contexto secundario.' : 'Causas agrupadas, cuentas afectadas y recomendaciones sin repetir miles de registros iguales.' ?></p></div></div>

<?php if ($isRemote429): ?>
<section class="api-command-section" aria-labelledby="remote429-now-title">
  <header>
    <div>
      <span class="eyebrow">Estado actual</span>
      <h2 id="remote429-now-title"><?= (int) $remote429Windows['60m'] > 0 ? '429 activo ahora' : 'Sin 429 activo ahora' ?></h2>
      <p>Datos verificados · última actualización <?= View::e($operatorCheckedAtLabel) ?></p>
    </div>
  </header>
  <div class="api-essential-metrics">
    <article><span>Últimos 60m</span><strong><?= (int) $remote429Windows['60m'] ?></strong><p>Alarma actual</p></article>
    <article><span>Últimas 24h</span><strong><?= (int) $remote429Windows['24h'] ?></strong><p>Contexto reciente</p></article>
    <article><span>Últimos 7 días</span><strong><?= (int) $remote429Windows['7d'] ?></strong><p>Tendencia corta</p></article>
    <article><span>30d histórico</span><strong><?= (int) $remote429Windows['30d'] ?></strong><p>No mostrar como alarma actual</p></article>
    <article><span>Último evento</span><strong><?= $remote429Windows['last_event'] ? View::e(DateTimePresenter::formatQueue($remote429Windows['last_event'], 'd/m H:i')) : '—' ?></strong><p>Hora Bogotá</p></article>
  </div>
  <div class="page-actions mt-2" aria-label="Filtros rápidos 429 remoto">
    <a class="btn small" href="<?= View::e($incidentLink(['hours' => 1, 'http_status' => 429, 'origin' => 'remote', 'severity' => '', 'status' => ''])) ?>">Ahora</a>
    <a class="btn small" href="<?= View::e($incidentLink(['hours' => 24, 'http_status' => 429, 'origin' => 'remote', 'severity' => '', 'status' => ''])) ?>">24h</a>
    <a class="btn small" href="<?= View::e($incidentLink(['hours' => 168, 'http_status' => 429, 'origin' => 'remote', 'severity' => '', 'status' => ''])) ?>">7d</a>
    <a class="btn small" href="<?= View::e($incidentLink(['hours' => 720, 'http_status' => 429, 'origin' => 'remote', 'severity' => '', 'status' => ''])) ?>">30d</a>
  </div>
</section>
<?php endif; ?>

<?php if (($incidentReadMode ?? '') === 'degraded_direct'): ?>
  <section class="alert warning" aria-live="polite">
    <strong>Telemetría directa no agrupada.</strong>
    El catálogo local de incidentes está atrasado. Esta vista muestra como máximo 50 resultados de los últimos 30 días con una ruta indexada certificada; no permite reconocer ni modificar incidentes.
  </section>
<?php endif; ?>
<?php if (($incidentReadMode ?? '') === 'unavailable_summary_only'): ?>
  <section class="alert warning" aria-live="polite">
    <strong>Catálogo no certificado.</strong>
    <?= View::e((string) ($incidentUnavailableMessage ?? 'La tabla de incidentes no está disponible para estos filtros.')) ?>
    Se muestra la superficie operativa sin tratar el resultado como cero y sin habilitar acciones sobre incidentes.
  </section>
<?php endif; ?>

<?php if (!$isRemote429): ?>
  <section class="api-essential-metrics" aria-label="Resumen de gravedad de incidentes">
    <a href="<?= View::e($incidentLink(['severity' => 'critical', 'http_status' => 0, 'origin' => ''])) ?>"><span>Críticos</span><strong><?= (int) $severitySummary['critical'] ?></strong><p>Bloqueo, OAuth o autorización</p></a>
    <a href="<?= View::e($incidentLink(['http_status' => 429, 'origin' => 'remote', 'severity' => ''])) ?>"><span>Rate limit 429</span><strong><?= (int) $severitySummary['rate_limit'] ?></strong><p>Reducir ritmo y respetar espera</p></a>
    <a href="<?= View::e($incidentLink(['severity' => 'high', 'http_status' => 0, 'origin' => ''])) ?>"><span>Permisos / OAuth</span><strong><?= (int) $severitySummary['permission'] ?></strong><p>401/403 por cuenta u operación</p></a>
    <a href="<?= View::e($incidentLink(['origin' => 'remote', 'http_status' => 0, 'severity' => ''])) ?>"><span>Fallos Mercado Libre</span><strong><?= (int) $severitySummary['remote'] ?></strong><p>5xx o respuesta remota</p></a>
    <a href="<?= View::e($incidentLink(['origin' => 'internal', 'http_status' => 0, 'severity' => ''])) ?>"><span>Fallos internos</span><strong><?= (int) $severitySummary['internal'] ?></strong><p>No llegaron a Mercado Libre</p></a>
  </section>
<?php endif; ?>

<section class="api-command-section api-filter-section">
  <form class="filters api-incident-filters" method="get" action="<?= View::e($base) ?>/settings/api-health/incidents">
    <?php if ((int) ($filters['http_status'] ?? 0) > 0): ?><input type="hidden" name="http_status" value="<?= (int) $filters['http_status'] ?>"><?php endif; ?>
    <div class="field"><label for="incidentStatus">Estado</label><select class="input" id="incidentStatus" name="status"><option value="">Todos</option><option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Activos</option><option value="recovered" <?= $filters['status'] === 'recovered' ? 'selected' : '' ?>>Recuperados</option><option value="reviewed" <?= $filters['status'] === 'reviewed' ? 'selected' : '' ?>>Revisados</option><option value="historical" <?= $filters['status'] === 'historical' ? 'selected' : '' ?>>Históricos</option></select></div>
    <div class="field"><label for="incidentAccount">Cuenta</label><select class="input" id="incidentAccount" name="account_id"><option value="0">Todas</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) $filters['account_id'] === (int) $account['id'] ? 'selected' : '' ?>><?= View::e((string) $account['account_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="incidentOrigin">Origen</label><select class="input" id="incidentOrigin" name="origin"><option value="">Todos</option><option value="internal" <?= $filters['origin'] === 'internal' ? 'selected' : '' ?>>Dentro del ERP</option><option value="remote" <?= $filters['origin'] === 'remote' ? 'selected' : '' ?>>Mercado Libre</option></select></div>
    <div class="field"><label for="incidentSeverity">Severidad</label><select class="input" id="incidentSeverity" name="severity"><option value="">Todas</option><option value="critical" <?= $filters['severity'] === 'critical' ? 'selected' : '' ?>>Crítica</option><option value="high" <?= $filters['severity'] === 'high' ? 'selected' : '' ?>>Alta</option><option value="medium" <?= $filters['severity'] === 'medium' ? 'selected' : '' ?>>Media</option><option value="low" <?= $filters['severity'] === 'low' ? 'selected' : '' ?>>Baja</option></select></div>
    <div class="field"><label for="incidentHours">Periodo</label><select class="input" id="incidentHours" name="hours"><option value="1" <?= (int) $filters['hours'] === 1 ? 'selected' : '' ?>>Ahora · 60 min</option><option value="24" <?= (int) $filters['hours'] === 24 ? 'selected' : '' ?>>24 horas</option><option value="168" <?= (int) $filters['hours'] === 168 ? 'selected' : '' ?>>7 días</option><option value="720" <?= (int) $filters['hours'] === 720 ? 'selected' : '' ?>>30 días</option></select></div>
    <div class="field"><label for="incidentOperation">Operación</label><input class="input" id="incidentOperation" name="operation" value="<?= View::e((string) $filters['operation']) ?>" placeholder="/orders o autorización"></div>
    <div class="field"><label for="incidentPageSize">Resultados</label><select class="input" id="incidentPageSize" name="per_page"><?php foreach ([25, 50, 100] as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?> por página</option><?php endforeach; ?></select></div>
    <div class="field filter-action"><button class="btn primary" type="submit">Filtrar</button></div>
  </form>
  <div class="page-actions mt-2" aria-label="Filtros rápidos de incidentes">
    <a class="btn small" href="<?= View::e($incidentLink(['http_status' => 429, 'origin' => 'remote', 'severity' => '', 'status' => ''])) ?>">429</a>
    <a class="btn small" href="<?= View::e($incidentLink(['status' => 'active'])) ?>">Activos</a>
    <a class="btn small" href="<?= View::e($incidentLink(['hours' => 24])) ?>">Últimas 24 h</a>
    <a class="btn small" href="<?= View::e($incidentLink(['origin' => 'remote', 'http_status' => 0, 'severity' => ''])) ?>">Por cuenta remota</a>
  </div>
</section>

<section class="api-command-section">
  <?php $firstShown = $total > 0 ? (($page - 1) * $perPage) + 1 : 0; $lastShown = min($total, $page * $perPage); $directDegraded = ($incidentReadMode ?? '') === 'degraded_direct'; ?>
  <header class="panel-head"><div><h2><?= $directDegraded ? number_format($total, 0, ',', '.') . ' eventos directos recientes' : number_format($total, 0, ',', '.') . ' ' . ($total === 1 ? 'incidente agrupado' : 'incidentes agrupados') ?></h2><p><?= $directDegraded ? 'Lectura directa limitada a esta página. El total agrupado se certificará cuando el catálogo local se ponga al día.' : 'Mostrando ' . number_format($firstShown, 0, ',', '.') . '–' . number_format($lastShown, 0, ',', '.') . '. Cada tarjeta puede representar varias repeticiones de la misma causa.' ?></p></div></header>
  <div class="incident-list">
    <?php if ($incidents === []): ?><div class="empty-state"><strong><?= ($incidentReadMode ?? '') === 'unavailable_summary_only' ? 'Catálogo no certificado para estos filtros' : 'No hay incidentes para estos filtros' ?></strong><span><?= ($incidentReadMode ?? '') === 'unavailable_summary_only' ? 'No se informa como cero; revise Diagnóstico o reintente cuando el catálogo local termine de actualizarse.' : 'Pruebe otro periodo o elimine alguno de los filtros.' ?></span></div><?php endif; ?>
    <?php foreach ($incidents as $incident): ?>
      <article class="incident-card">
        <div class="incident-card-main">
          <div class="incident-title-row"><span class="badge <?= View::e(in_array((string) $incident['state'], ['recovered', 'reviewed', 'historical'], true) ? 'green' : $severityClass((string) $incident['severity'])) ?>"><?= View::e($stateLabel((string) $incident['state'])) ?></span><strong><?= View::e((string) $incident['title']) ?></strong></div>
          <p><?= View::e((string) $incident['impact']) ?></p>
          <div class="incident-meta">
            <span><?= number_format((int) $incident['repetitions'], 0, ',', '.') ?> repeticiones</span>
            <span><?= View::e((string) ($incident['account_names'] ?: 'Aplicación')) ?></span>
            <span>Operación: <?= View::e((string) $incident['operation_label']) ?></span>
            <span>Última vez: <?= View::e(DateTimePresenter::formatQueue($incident['last_seen_at'])) ?></span>
            <span>Activo ahora: <strong><?= !empty($incident['active_now']) ? 'Sí' : 'No' ?></strong></span>
            <?php if ($isRemote429): ?>
              <span>Qué hizo el ERP: aplicó la política de espera vigente</span>
            <?php endif; ?>
          </div>
          <details class="technical-details">
            <summary><span>Detalles técnicos</span><span aria-hidden="true">⌄</span></summary>
            <div class="technical-details-body">
              <p>Transporte: <strong><?= View::e((string) ($incident['transport_label'] ?? 'Sin clasificar')) ?></strong></p>
              <p>Señal: <strong><?= View::e((string) ($incident['signal_label'] ?? 'Sin clasificar')) ?></strong></p>
            </div>
          </details>
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
