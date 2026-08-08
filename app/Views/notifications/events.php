<?php

use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;
use App\Services\UiLabelPresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$page = $page ?? 1;
$statusOptions = [
  'queued' => 'Pendiente', 'processing' => 'En proceso', 'processed' => 'Procesado',
  'ignored' => 'Omitido de forma segura', 'failed' => 'Necesita revisión',
  'waiting_retry' => 'Pendiente de reintento', 'circuit_breaker_wait' => 'Protección temporal activa',
  'unknown_topic' => 'Evento informativo', 'duplicate' => 'Duplicado incorporado',
];
?>
<div class="page-head">
  <div>
    <a class="muted" href="<?= View::e($base) ?>/notifications/health">← Salud y recuperación</a>
    <h1>Eventos técnicos recibidos</h1>
    <p>Auditoría paginada del receptor. Los tópicos informativos no afectan la salud operativa.</p>
  </div>
  <div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/notifications/missed">Recuperar entregas fallidas</a></div>
</div>

<div class="alert warning">Vista técnica para administradores. El valor remoto de <code>resource</code> nunca se ejecuta directamente: el ERP extrae un ID y construye una ruta permitida.</div>

<section class="panel filter-bar">
  <form method="get" class="form-grid">
    <div class="field"><label for="event-account">Cuenta</label><select id="event-account" class="input" name="account_id"><option value="0">Todas</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) $filters['account_id'] === (int) $account['id'] ? 'selected' : '' ?>><?= View::e($account['account_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="event-topic">Tópico recibido</label><input id="event-topic" class="input" name="topic" value="<?= View::e($filters['topic']) ?>"></div>
    <div class="field"><label for="event-status">Estado</label><select id="event-status" class="input" name="status"><option value="">Todos</option><?php foreach ($statusOptions as $status => $label): ?><option value="<?= $status ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>&nbsp;</label><button type="submit" class="btn primary">Filtrar</button></div>
  </form>
</section>

<section class="panel table-panel">
  <div class="table-scroll">
    <table class="data-table">
      <caption class="sr-only">Últimos eventos técnicos recibidos desde Mercado Libre</caption>
      <thead><tr><th>ID</th><th>Cuenta</th><th>Tópico</th><th>Clasificación</th><th>Recurso</th><th>Estado</th><th>Recibido</th><th>Diagnóstico</th></tr></thead>
      <tbody>
      <?php if (!$events): ?><tr><td colspan="8"><div class="empty">No hay eventos con estos filtros.</div></td></tr><?php endif; ?>
      <?php foreach ($events as $event): ?>
        <tr>
          <td><?= (int) $event['id'] ?></td>
          <td><?= View::e($event['account_name'] ?: 'No asociada') ?></td>
          <td><?= View::e($event['topic']) ?></td>
          <td><?= View::e($event['canonical_topic'] ?? 'Pendiente de normalizar') ?></td>
          <td><code><?= View::e($event['resource_type'] ?? '—') ?></code><br><small><?= View::e($event['remote_resource_id'] ?? '—') ?></small></td>
          <td><span class="badge <?= $event['status'] === 'failed' ? 'red' : ($event['status'] === 'processed' ? 'green' : 'amber') ?>"><?= View::e(UiLabelPresenter::status((string) $event['status'])) ?></span></td>
          <td><?= View::e(DateTimePresenter::formatQueue($event['erp_received_at'] ?? null, 'd/m/Y H:i:s')) ?><br><small>hora Bogotá</small></td>
          <td><?= View::e($event['correlation_id'] ?? '—') ?><?php if (!empty($event['error_message'])): ?><br><small><?= View::e(UiLabelPresenter::safeOperationMessage((string) $event['error_message'], isset($event['diagnostic_id']) ? (string) $event['diagnostic_id'] : null)) ?></small><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="pagination">
    <?php if ($page > 1): ?><a class="btn" href="<?= View::e($base) ?>/notifications/technical/events?page=<?= $page - 1 ?>">Anterior</a><?php endif; ?>
    <span>Página <?= (int) $page ?></span>
    <?php if (count($events) === $perPage): ?><a class="btn" href="<?= View::e($base) ?>/notifications/technical/events?page=<?= $page + 1 ?>">Siguiente</a><?php endif; ?>
  </div>
</section>
