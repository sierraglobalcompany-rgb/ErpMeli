<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$statusLabel = static fn(string $status): string => match ($status) {
    'scanning' => 'Preparando',
    'ready' => 'Lista para revisar',
    'applying' => 'Aplicando',
    'partial' => 'Atención pendiente',
    'applied' => 'Aplicada',
    'error' => 'Con error',
    default => ucfirst(str_replace('_', ' ', $status)),
};
?>
<div class="page-head">
  <div>
    <h1>Revisiones de publicaciones</h1>
    <p>Cambios de identidad comercial que requieren una decisión antes de actualizar el snapshot local.</p>
  </div>
  <div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/products/meli">Volver a publicaciones</a></div>
</div>

<section class="panel table-panel">
  <div class="panel-head"><div><h2><?= number_format(count($reviews), 0, ',', '.') ?> revisiones recientes</h2><p>SKU, variaciones e identidad de catálogo no se aplican automáticamente.</p></div></div>
  <div class="table-scroll">
    <table class="data-table" data-responsive="cards">
      <caption class="sr-only">Revisiones de publicaciones Mercado Libre</caption>
      <thead><tr><th>Cuenta</th><th>Estado</th><th>Nuevas</th><th>Cambios</th><th>Errores</th><th>Fecha</th><th></th></tr></thead>
      <tbody>
      <?php if ($reviews === []): ?><tr><td colspan="7"><div class="empty">No hay cambios sensibles pendientes.</div></td></tr><?php endif; ?>
      <?php foreach ($reviews as $review): ?>
        <tr>
          <td data-label="Cuenta"><strong><?= View::e((string) $review['account_name']) ?></strong></td>
          <td data-label="Estado"><span class="badge <?= in_array((string) $review['status'], ['error', 'partial'], true) ? 'red' : ((string) $review['status'] === 'applied' ? 'green' : 'blue') ?>"><?= View::e($statusLabel((string) $review['status'])) ?></span></td>
          <td data-label="Nuevas"><?= (int) $review['new_count'] ?></td>
          <td data-label="Cambios"><?= (int) $review['changed_count'] ?></td>
          <td data-label="Errores"><?= (int) $review['error_count'] ?></td>
          <td data-label="Fecha"><?= View::e((string) $review['created_at']) ?></td>
          <td><a class="btn small" href="<?= View::e($base) ?>/products/meli/reviews/show?id=<?= (int) $review['id'] ?>">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
