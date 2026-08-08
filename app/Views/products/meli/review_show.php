<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$itemLabel = static fn(string $status): string => match ($status) {
    'new' => 'Nueva',
    'changed' => 'Cambio pendiente',
    'approved' => 'Aprobada',
    'rejected' => 'Rechazada',
    'applied' => 'Aplicada',
    'unchanged' => 'Sin cambios',
    'error' => 'Con error',
    default => ucfirst(str_replace('_', ' ', $status)),
};
?>
<div class="page-head">
  <div>
    <h1>Revisión de publicaciones</h1>
    <p><?= View::e((string) $review['account_name']) ?> · revisión #<?= (int) $review['id'] ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/products/meli/reviews">Todas las revisiones</a>
    <?php if ((int) ($review['approved_count'] ?? 0) > 0): ?>
      <form method="post" action="<?= View::e($base) ?>/products/meli/reviews/apply">
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
        <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
        <button class="btn primary" type="submit">Aplicar aprobadas</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($items === []): ?>
  <section class="panel empty"><strong>No hay publicaciones para este filtro.</strong></section>
<?php endif; ?>

<?php foreach ($items as $item): ?>
  <?php $itemChanges = $changes[(int) $item['id']] ?? []; ?>
  <section class="panel">
    <div class="panel-head">
      <div>
        <span class="eyebrow"><?= View::e((string) $item['external_item_id']) ?></span>
        <h2><?= View::e((string) (($item['summary'] ?? '') ?: 'Publicación Mercado Libre')) ?></h2>
        <?php if (!empty($item['safe_error_message'])): ?><p><?= View::e((string) $item['safe_error_message']) ?></p><?php endif; ?>
      </div>
      <span class="badge <?= (string) $item['item_status'] === 'applied' ? 'green' : (in_array((string) $item['item_status'], ['error', 'rejected'], true) ? 'red' : 'amber') ?>"><?= View::e($itemLabel((string) $item['item_status'])) ?></span>
    </div>
    <?php if ($itemChanges !== []): ?>
      <div class="table-scroll">
        <table class="data-table" data-responsive="cards">
          <caption class="sr-only">Cambios detectados en <?= View::e((string) $item['external_item_id']) ?></caption>
          <thead><tr><th>Campo</th><th>Actual</th><th>Mercado Libre</th></tr></thead>
          <tbody>
          <?php foreach ($itemChanges as $change): ?>
            <tr><td data-label="Campo"><strong><?= View::e((string) $change['field_label']) ?></strong></td><td data-label="Actual"><?= View::e((string) $change['old_value']) ?></td><td data-label="Mercado Libre"><?= View::e((string) $change['new_value']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?php if (in_array((string) $item['item_status'], ['new', 'changed', 'approved', 'rejected'], true)): ?>
      <div class="page-actions mt-2">
        <form method="post" action="<?= View::e($base) ?>/products/meli/reviews/approve">
          <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
          <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
          <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
          <button class="btn primary" type="submit">Aprobar cambio</button>
        </form>
        <form method="post" action="<?= View::e($base) ?>/products/meli/reviews/reject">
          <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
          <input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
          <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
          <button class="btn" type="submit">Rechazar</button>
        </form>
      </div>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
