<?php

use App\Core\Env;
use App\Core\Csrf;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$stateLabel = match ((string) ($incident['state'] ?? '')) {
    'active' => 'Activo',
    'reviewed' => 'Revisado',
    'historical' => 'Histórico',
    default => 'Recuperado',
};
$severityClass = (string) $incident['state'] !== 'active' ? 'green' : match ((string) $incident['severity']) {
    'critical' => 'red',
    'high', 'medium' => 'amber',
    default => 'green',
};
$apiHealthSection = 'incidents';
?>
<div class="page-head">
  <div><span class="eyebrow">Incidente de integración</span><h1><?= View::e((string) $incident['title']) ?></h1><p><?= View::e((string) $incident['operation_label']) ?></p></div>
  <div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/settings/api-health/incidents">Volver a incidentes</a><a class="btn" href="<?= View::e($base) ?>/settings/api-health">Salud API</a></div>
</div>
<?php require __DIR__ . '/_api_health_nav.php'; ?>

<section class="incident-detail-hero">
  <div class="incident-title-row"><span class="badge <?= View::e($severityClass) ?>"><?= View::e($stateLabel) ?></span><strong><?= number_format((int) $incident['repetitions'], 0, ',', '.') ?> repeticiones</strong></div>
  <div class="incident-answer-grid">
    <article><span>¿Llegó a Mercado Libre?</span><strong><?= !empty($incident['reached_remote']) ? 'Sí' : 'No' ?></strong></article>
    <article><span>Activo ahora</span><strong><?= !empty($incident['active_now']) ? 'Sí' : 'No' ?></strong></article>
    <article><span>Tipo de señal</span><strong><?= View::e((string) ($incident['signal_label'] ?? 'Sin clasificar')) ?></strong></article>
    <article><span>Cuentas afectadas</span><strong><?= (int) $incident['account_count'] ?></strong></article>
    <article><span>Última ocurrencia</span><strong><?= View::e(DateTimePresenter::formatQueue($incident['last_seen_at'])) ?></strong></article>
  </div>
</section>

<section class="dashboard-grid incident-detail-grid mt-2">
  <article class="panel">
    <header class="panel-head"><h2>Qué ocurrió</h2></header>
    <p><?= View::e((string) $incident['impact']) ?></p>
    <dl class="definition-list">
      <div><dt>Primera ocurrencia</dt><dd><?= View::e(DateTimePresenter::formatQueue($incident['first_seen_at'])) ?></dd></div>
      <div><dt>Última ocurrencia</dt><dd><?= View::e(DateTimePresenter::formatQueue($incident['last_seen_at'])) ?></dd></div>
      <div><dt>Origen</dt><dd><?= !empty($incident['reached_remote']) ? 'Respuesta de Mercado Libre' : 'Dentro del ERP' ?></dd></div>
      <div><dt>Operación</dt><dd><?= View::e((string) $incident['operation_label']) ?></dd></div>
      <div><dt>Señal operativa</dt><dd><?= View::e((string) ($incident['risk_explanation'] ?? 'No se pudo clasificar la señal.')) ?></dd></div>
    </dl>
  </article>
  <article class="panel">
    <header class="panel-head"><h2>Qué debe hacer</h2></header>
    <p class="recommendation-box"><?= View::e((string) $incident['recommendation']) ?></p>
    <?php if (!empty($resolution)): ?>
      <p><strong><?= View::e((string) $resolution['headline']) ?></strong></p>
      <p><?= View::e((string) $resolution['administrator_action']) ?></p>
      <div class="page-actions">
        <?php foreach (($resolution['actions'] ?? []) as $action): ?>
          <a class="btn <?= !empty($action['primary']) ? 'primary' : '' ?>" href="<?= View::e($base . (string) $action['url']) ?>"><?= View::e((string) $action['label']) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if (!empty($incident['rate_limit_signal'])): ?>
      <p class="muted">No fuerce reintentos ni suba el ritmo por este incidente. Mantenga la protección de cuenta/endpoint hasta tener estabilidad.</p>
    <?php elseif (empty($incident['signal_requires_protection'])): ?>
      <p class="muted">No pause las cuentas únicamente por este incidente.</p>
    <?php endif; ?>
  </article>
</section>

<section class="panel mt-2">
  <header class="panel-head"><div><h2>Alcances afectados</h2><p>Cada cuenta, empresa o evento de aplicación se revisa por separado. Marcar uno no oculta los demás.</p></div></header>
  <div class="table-scroll"><table class="data-table responsive-table"><caption>Alcances afectados por el incidente</caption><thead><tr><th>Alcance</th><th>Repeticiones</th><th>Primera vez</th><th>Última vez</th><th>Revisión</th></tr></thead><tbody>
    <?php foreach (($incident['accounts'] ?? []) as $account): ?>
      <tr>
        <td data-label="Alcance"><?= View::e((string) $account['account_name']) ?></td>
        <td data-label="Repeticiones"><?= number_format((int) $account['repetitions'], 0, ',', '.') ?></td>
        <td data-label="Primera vez"><?= View::e(DateTimePresenter::formatQueue($account['first_seen_at'])) ?></td>
        <td data-label="Última vez"><?= View::e(DateTimePresenter::formatQueue($account['last_seen_at'])) ?></td>
        <td data-label="Revisión">
          <?php if (!empty($account['acknowledged_all'])): ?>
            <span class="badge green">Revisado hasta esta ocurrencia</span>
          <?php else: ?>
            <form method="post" action="<?= View::e($base) ?>/settings/api-health/incidents/acknowledge" data-confirm="¿Marcar únicamente este alcance como revisado? Una ocurrencia posterior volverá a abrirlo.">
              <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
              <input type="hidden" name="incident_key" value="<?= View::e((string) $incident['incident_key']) ?>">
              <input type="hidden" name="scope_key" value="<?= View::e((string) $account['scope_key']) ?>">
              <button class="btn" type="submit">Marcar este alcance como revisado</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div>
</section>

<details class="technical-details mt-2">
  <summary><span>Ver detalles técnicos sanitizados</span><span aria-hidden="true">⌄</span></summary>
  <div class="technical-details-body">
    <dl class="definition-list">
      <div><dt>Identificador</dt><dd><code><?= View::e((string) $incident['incident_key']) ?></code></dd></div>
      <div><dt>Clasificación</dt><dd><code><?= View::e((string) $incident['outcome_class']) ?></code></dd></div>
      <div><dt>Tipo</dt><dd><code><?= View::e((string) ($incident['error_type'] ?: '—')) ?></code></dd></div>
      <div><dt>HTTP</dt><dd><?= !empty($incident['http_status']) ? (int) $incident['http_status'] : 'No se envió' ?></dd></div>
      <div><dt>Mensaje seguro</dt><dd><?= View::e((string) ($incident['safe_message'] ?: '—')) ?></dd></div>
    </dl>
    <div class="table-scroll mt-2"><table class="data-table"><caption>Muestra de las últimas ocurrencias sanitizadas</caption><thead><tr><th>Fecha</th><th>Cuenta</th><th>Operación</th><th>HTTP</th><th>Diagnóstico</th></tr></thead><tbody>
      <?php foreach (($incident['samples'] ?? []) as $sample): ?><tr><td><?= View::e(DateTimePresenter::formatQueue($sample['created_at'])) ?></td><td><?= View::e((string) ($sample['account_name'] ?: 'Aplicación')) ?></td><td><?= View::e((string) $sample['method']) ?> <code><?= View::e((string) $sample['endpoint_path']) ?></code></td><td><?= !empty($sample['http_status']) ? (int) $sample['http_status'] : '—' ?></td><td><code><?= View::e((string) ($sample['request_id'] ?: '—')) ?></code></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
</details>
