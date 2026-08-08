<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
?>
<div class="page-head">
  <div>
    <a class="muted" href="<?= View::e($base) ?>/catalogs">← Volver a catálogos</a>
    <h1>Categorías de catálogo</h1>
    <p>Los cambios son locales: no modifican categorías ni publicaciones en Mercado Libre.</p>
  </div>
</div>

<section class="panel filter-bar">
  <form class="inline-form" method="get">
    <div class="field">
      <label>Catálogo</label>
      <select class="input" name="catalog_id">
        <?php foreach ($catalogs as $option): ?>
          <option value="<?= (int) $option['id'] ?>" <?= (int) $catalogId === (int) $option['id'] ? 'selected' : '' ?>><?= View::e($option['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn">Ver</button>
  </form>
</section>

<section class="panel table-panel">
  <div class="panel-head"><h2><?= $catalog ? View::e($catalog['name']) : 'Sin catálogo' ?></h2></div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Categoría</th><th>Nombre visible</th><th>Productos</th><th>Visible</th><th>Destacada</th><th>Orden</th><th>Acción</th></tr></thead>
      <tbody>
      <?php foreach ($categories as $cat): ?>
        <tr>
          <form method="post" action="<?= View::e($base) ?>/catalogs/categories/update">
            <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
            <input type="hidden" name="catalog_id" value="<?= (int) $catalogId ?>">
            <input type="hidden" name="category_id" value="<?= (int) $cat['id'] ?>">
            <td><strong><?= View::e($cat['name']) ?></strong><small class="muted"><?= View::e($cat['external_category_id'] ?: 'sin-id') ?></small></td>
            <td><input class="input" name="display_name" value="<?= View::e($cat['display_name']) ?>"></td>
            <td><?= (int) $cat['product_count'] ?></td>
            <td><label class="check-row"><input type="checkbox" name="is_visible" value="1" <?= (int) $cat['is_visible'] === 1 ? 'checked' : '' ?>> Visible</label></td>
            <td><label class="check-row"><input type="checkbox" name="is_featured" value="1" <?= (int) $cat['is_featured'] === 1 ? 'checked' : '' ?>> Destacada</label></td>
            <td><input class="input compact-input" name="sort_order" type="number" value="<?= (int) $cat['sort_order'] ?>"></td>
            <td><button class="btn small primary">Guardar</button></td>
          </form>
        </tr>
      <?php endforeach; ?>
      <?php if (!$categories): ?><tr><td colspan="7" class="empty">No hay categorías detectadas. Refresque el catálogo desde datos locales.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
