<?php
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$rows = is_array($parked['rows'] ?? null) ? $parked['rows'] : [];
$total = max(0, (int) ($parked['total'] ?? 0));
$page = max(1, (int) ($parked['page'] ?? 1));
$perPage = max(1, (int) ($parked['per_page'] ?? 50));
$pages = max(1, (int) ceil($total / $perPage));
$status = (string) ($parked['status'] ?? '');
$labels = [
  'waiting_identity' => 'Falta identidad',
  'waiting_capability' => 'Falta capacidad V3',
  'waiting_rate' => 'Esperando ritmo',
  'waiting_budget' => 'Esperando presupuesto',
  'waiting_api' => 'Esperando API',
  'review' => 'Revisión segura',
  'dead' => 'Cerrado técnico',
];
?>
<div class="page-head">
  <div>
    <span class="eyebrow">CRON V3 · FIFO</span>
    <h1>Trabajos parqueados</h1>
    <p>Estos trabajos no bloquean la fila FIFO. Cron seguirá tomando el trabajo listo más antiguo mientras se revisan estas causas.</p>
  </div>
  <a class="btn" href="<?= View::e($base) ?>/settings/cron">Volver a Cron</a>
</div>

<section class="operation-explainer">
  <div><span>Qué pasó</span><strong>El trabajo no era ejecutable ahora.</strong></div>
  <div><span>Qué hará el ERP</span><strong>Lo mantiene parqueado y continúa con otros trabajos listos.</strong></div>
  <div><span>Qué puede hacer ahora</span><strong>Filtrar por causa y abrir solo recursos accionables.</strong></div>
</section>

<section class="panel filter-bar">
  <form method="get" action="<?= View::e($base) ?>/settings/cron/parked" class="automation-filters">
    <div class="field"><label for="parked-status">Causa</label><select id="parked-status" name="status" class="input">
      <option value="">Todas</option>
      <?php foreach ($labels as $key => $label): ?>
        <option value="<?= View::e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= View::e($label) ?></option>
      <?php endforeach; ?>
    </select></div>
    <div class="field"><label for="parked-page-size">Resultados</label><select id="parked-page-size" name="per_page" class="input">
      <?php foreach ([25,50,100] as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?> por página</option><?php endforeach; ?>
    </select></div>
    <button class="btn primary" type="submit">Filtrar</button>
  </form>
</section>

<section class="panel">
  <header class="panel-head"><div><h2><?= number_format($total, 0, ',', '.') ?> parqueados</h2><p>Mostrando <?= count($rows) ?>. Ningún GET de esta pantalla ejecuta trabajos ni consulta Mercado Libre.</p></div></header>
  <?php if ($rows === []): ?>
    <div class="empty-state"><h3>No hay trabajos parqueados con este filtro</h3><p>Si la fila ready tiene trabajo, Cron puede seguir drenando sin intervención.</p></div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="data-table responsive-table">
        <thead><tr><th>ID</th><th>Trabajo</th><th>Cuenta</th><th>Causa</th><th>Referencia</th><th>Disponible</th><th>Acción recomendada</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): $rowStatus = (string) ($row['status'] ?? ''); ?>
          <tr>
            <td data-label="ID">#<?= (int) $row['id'] ?></td>
            <td data-label="Trabajo"><strong><?= View::e((string) $row['work_type']) ?></strong><small><?= View::e((string) $row['lane']) ?></small></td>
            <td data-label="Cuenta"><?= View::e((string) ($row['account_name'] ?? '')) ?></td>
            <td data-label="Causa"><span class="badge warning"><?= View::e($labels[$rowStatus] ?? $rowStatus) ?></span><small><?= View::e((string) ($row['last_error_code'] ?? '')) ?></small></td>
            <td data-label="Referencia"><?= View::e((string) ($row['source_ref'] ?? '—')) ?></td>
            <td data-label="Disponible"><?= !empty($row['available_at']) ? View::e(DateTimePresenter::formatQueue($row['available_at'], 'd/m H:i')) : 'Ahora' ?></td>
            <td data-label="Acción"><?= View::e(match ($rowStatus) {
                'waiting_identity' => 'Diagnosticar localmente o reparar identidad.',
                'waiting_capability' => 'Esperar handler/productor V3 confirmado.',
                'waiting_rate' => 'Esperar ventana de ritmo.',
                'waiting_budget' => 'Esperar presupuesto seguro.',
                'waiting_api' => 'Esperar recuperación API/OAuth.',
                'review' => 'Revisar evidencia antes de reintentar.',
                default => 'Conservar como evidencia técnica.',
            }) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <?php if ($pages > 1): ?>
    <nav class="pagination" aria-label="Páginas de parqueados">
      <?php $query = ['status' => $status, 'per_page' => $perPage]; ?>
      <?php if ($page > 1): $query['page'] = $page - 1; ?><a class="btn" rel="prev" href="<?= View::e($base . '/settings/cron/parked?' . http_build_query($query)) ?>">Anterior</a><?php endif; ?>
      <span>Página <?= $page ?> de <?= $pages ?></span>
      <?php if ($page < $pages): $query['page'] = $page + 1; ?><a class="btn" rel="next" href="<?= View::e($base . '/settings/cron/parked?' . http_build_query($query)) ?>">Siguiente</a><?php endif; ?>
    </nav>
  <?php endif; ?>
</section>
