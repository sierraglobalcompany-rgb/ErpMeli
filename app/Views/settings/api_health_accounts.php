<?php

use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$apiHealthSection = 'accounts';
$apiHealthHours = (int) ($overview['hours'] ?? 24);
$apiHealthCheckedAt = $overview['checked_at'] ?? null;
$allRows = $overview['accounts']['rows'] ?? [];
require __DIR__ . '/_api_health_header.php';
require __DIR__ . '/_api_health_nav.php';
?>

<section class="api-command-section">
  <header><div><span class="eyebrow">Conexiones</span><h2>Estado por cuenta</h2><p>Consulte primero el estado actual; el historial queda disponible en el detalle.</p></div></header>
  <form class="api-compact-filters" method="get" action="<?= View::e($base) ?>/settings/api-health/accounts">
    <div class="field"><label for="api-account-filter">Cuenta</label><select class="input" id="api-account-filter" name="account_id"><option value="0">Todas las cuentas</option><?php foreach ($allRows as $row): ?><option value="<?= (int) $row['id'] ?>" <?= (int) ($overview['account_id'] ?? 0) === (int) $row['id'] ? 'selected' : '' ?>><?= View::e((string) $row['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="api-state-filter">Estado</label><select class="input" id="api-state-filter" name="state"><option value="">Todos los estados</option><?php foreach (['available' => 'Disponible', 'aging' => 'Evidencia antigua', 'unverified' => 'No comprobada recientemente', 'attention' => 'Revisar', 'paused' => 'Pausada', 'disconnected' => 'Desconectada'] as $value => $label): ?><option value="<?= $value ?>" <?= $state === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="api-hours-filter">Periodo</label><select class="input" id="api-hours-filter" name="hours"><option value="24" <?= $apiHealthHours === 24 ? 'selected' : '' ?>>24 horas</option><option value="168" <?= $apiHealthHours === 168 ? 'selected' : '' ?>>7 días</option><option value="720" <?= $apiHealthHours === 720 ? 'selected' : '' ?>>30 días</option></select></div>
    <button class="btn primary" type="submit">Aplicar filtros</button>
  </form>

  <div class="api-account-detail-list">
    <?php if ($accounts === []): ?><p class="api-inline-empty">No hay cuentas que coincidan con estos filtros.</p><?php endif; ?>
    <?php foreach ($accounts as $row): ?>
      <article>
        <header>
          <div><h3><?= View::e((string) $row['name']) ?></h3><span class="api-state-badge is-<?= View::e((string) $row['state']) ?>"><?= View::e((string) $row['state_label']) ?></span></div>
          <div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/settings/api-health/incidents?account_id=<?= (int) $row['id'] ?>">Ver incidentes</a><a class="btn" href="<?= View::e($base) ?>/logs?type=api&amp;account_id=<?= (int) $row['id'] ?>">Ver operaciones</a></div>
        </header>
        <div class="api-account-detail-grid">
          <div><span>Consultas enviadas</span><strong><?= number_format((int) $row['sent'], 0, ',', '.') ?></strong></div>
          <div><span>Consultas correctas</span><strong><?= number_format((int) $row['successful'], 0, ',', '.') ?></strong></div>
          <div><span>Porcentaje de éxito</span><strong><?= $row['success_rate'] !== null ? View::e(number_format((float) $row['success_rate'], 1, ',', '.')) . ' %' : 'Sin datos' ?></strong></div>
          <div><span>Incidentes activos</span><strong><?= (int) $row['active_incidents'] ?></strong></div>
          <div><span>Última actividad</span><strong><?= $row['last_activity_at'] ? View::e(DateTimePresenter::formatQueue($row['last_activity_at'], 'd/m/Y H:i')) : 'Sin actividad' ?></strong></div>
          <div><span>Próxima hora segura</span><strong><?= $row['next_safe_at'] ? View::e(DateTimePresenter::formatQueue($row['next_safe_at'], 'd/m/Y H:i')) : 'Ahora' ?></strong></div>
        </div>
        <details class="api-account-history">
          <summary>Ver información histórica</summary>
          <div><span>Errores remotos: <strong><?= (int) $row['remote_errors'] ?></strong></span><span>Fallos internos registrados: <strong><?= (int) $row['local_failures'] ?></strong></span></div>
        </details>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<?php $pauseAccounts = $allRows; require __DIR__ . '/_api_health_pause_dialog.php'; ?>
