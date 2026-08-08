<?php

use App\Core\Env;
use App\Core\View;
use App\Services\UiLabelPresenter;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$diagnostic = $healthData['diagnostic'] ?? ['migrations' => [], 'file_version' => '—', 'installed_version' => '—', 'ml_write_enabled' => 'false'];
$apiHealth = $healthData['api_health'] ?? [];
$openCircuits = $healthData['open_circuits'] ?? [];
$notificationHealth = $healthData['notification_health'] ?? [];
$notificationSummary = $healthData['notification_summary'] ?? [];
$apiErrorGroups = $healthData['api_error_groups'] ?? [];
$maintenance = !empty($healthData['maintenance']);
$pendingMigrations = count(array_filter($diagnostic['migrations'] ?? [], static fn ($migration) => empty($migration['applied'])));
$risk = (string) ($apiHealth['risk'] ?? 'low');
?>
<?php if ($risk === 'critical' || $risk === 'high'): ?><section class="alert <?= $risk === 'critical' ? 'danger' : 'warning' ?>">Salud API Mercado Libre en riesgo <?= $risk === 'critical' ? 'crítico' : 'alto' ?>. <a class="link" href="<?= View::e($base) ?>/settings/api-health">Ver Salud API</a>.</section><?php endif; ?>
<section class="metrics status-metrics">
  <?php foreach ([
    ['Versión', $diagnostic['file_version'] ?? '—', 'Instalada: ' . ($diagnostic['installed_version'] ?? '—')],
    ['Migraciones pendientes', (string) $pendingMigrations, $pendingMigrations ? 'Completar actualización' : 'Al día'],
    ['Salud API Mercado Libre', UiLabelPresenter::apiHealth($risk), (int) ($apiHealth['active_incident_count'] ?? 0) . ' activos · ' . (int) ($apiHealth['recovered_incident_count'] ?? 0) . ' recuperados'],
    ['Notificaciones ML', $notificationHealth['label'] ?? 'Sin datos', $maintenance
        ? 'Los eventos entrantes se conservan sin consultar Mercado Libre'
        : 'No leídas: ' . (int) ($notificationSummary['unread'] ?? 0) . ' · Pendientes: ' . (int) ($notificationHealth['pending'] ?? 0)],
  ] as [$label, $value, $note]): ?><article class="metric-card compact"><div><div class="metric-label"><?= View::e($label) ?></div><div class="metric-value"><?= View::e($value) ?></div><div class="metric-change"><?= View::e($note) ?></div></div></article><?php endforeach; ?>
</section>
<section class="dashboard-grid dashboard-section-grid">
  <article class="panel">
    <header class="panel-head"><h2>Protección API</h2><a class="link" href="<?= View::e($base) ?>/settings/api-health">Ver Salud API</a></header>
    <div class="dashboard-operation-list" aria-label="Estado de protección API">
      <div class="dashboard-operation-row"><span class="badge <?= in_array($risk, ['low','recovered'], true) ? 'green' : ($risk === 'medium' || $risk === 'paused' ? 'amber' : 'red') ?>"><?= View::e(UiLabelPresenter::apiHealth($risk)) ?></span><span><?= $maintenance ? 'Cero consultas remotas durante el mantenimiento' : ((int) ($apiHealth['sent'] ?? $apiHealth['requests'] ?? 0) . ' enviadas · ' . (int) ($apiHealth['remote_errors'] ?? $apiHealth['errors'] ?? 0) . ' errores remotos · ' . count($openCircuits) . ' pausas') ?></span></div>
      <?php if ($maintenance): ?><div class="dashboard-operation-row"><span class="badge amber">Mantenimiento preventivo</span><span>El ERP local permanece disponible y Mercado Libre no será consultado.</span></div>
      <?php elseif (!$openCircuits): ?><div class="dashboard-operation-row"><span class="badge green">Sin pausas activas</span><span>La integración puede trabajar dentro de su presupuesto.</span></div><?php endif; ?>
      <?php foreach (array_slice($openCircuits, 0, 3) as $circuit): ?><div class="dashboard-operation-row"><span class="badge amber">Pausa automática</span><span><?= View::e((string) ($circuit['account_name'] ?? 'Integración Mercado Libre')) ?></span></div><?php endforeach; ?>
    </div>
  </article>
  <article class="panel">
    <header class="panel-head"><h2>Incidentes API recientes</h2><a class="link" href="<?= View::e($base) ?>/settings/api-health/incidents">Ver incidentes</a></header>
    <div class="dashboard-operation-list" aria-label="Incidencias API recientes">
      <?php if (!$apiErrorGroups): ?><div class="empty">No hay incidencias que requieran atención.</div><?php endif; ?>
      <?php foreach ($apiErrorGroups as $group): ?><div class="dashboard-operation-row"><a class="link" href="<?= View::e($base) ?>/settings/api-health/incidents/show?key=<?= View::e((string) ($group['incident_key'] ?? '')) ?>"><?= View::e((string) ($group['title'] ?? UiLabelPresenter::apiOperation($group['method'] ?? null, $group['endpoint_path'] ?? null))) ?></a><span class="badge <?= ($group['state'] ?? '') === 'active' ? 'amber' : 'green' ?>"><?= (int) $group['repetitions'] ?> <?= (int) $group['repetitions'] === 1 ? 'repetición' : 'repeticiones' ?></span></div><?php endforeach; ?>
    </div>
  </article>
</section>
