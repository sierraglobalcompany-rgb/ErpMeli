<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
?>
<div class="page-head">
  <div>
    <h1>Catálogos</h1>
    <p>Catálogos públicos o privados generados desde publicaciones Mercado Libre sincronizadas localmente.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/settings/manual-processing?scope=descriptions&amp;origin=descriptions">Procesar descripciones</a>
    <a class="btn" href="<?= View::e($base) ?>/catalogs/private">Catálogo privado</a>
    <a class="btn" href="<?= View::e($base) ?>/catalogs/categories">Categorías</a>
    <a class="btn primary" href="<?= View::e($base) ?>/catalogs/create">Crear catálogo</a>
  </div>
</div>

<section class="panel filter-bar">
  <form class="inline-form" method="get">
    <div class="field">
      <label>Buscar</label>
      <input class="input" name="q" value="<?= View::e($q ?? '') ?>" placeholder="Nombre o slug">
    </div>
    <button class="btn">Filtrar</button>
  </form>
</section>

<section class="catalog-admin-grid">
  <?php if (!$catalogs): ?>
    <div class="panel empty">Aún no hay catálogos creados.</div>
  <?php endif; ?>
  <?php foreach ($catalogs as $catalog): ?>
    <article class="panel catalog-admin-card">
      <div>
        <span class="badge <?= (int) $catalog['is_enabled'] === 1 ? 'green' : 'red' ?>"><?= (int) $catalog['is_enabled'] === 1 ? 'Habilitado' : 'Deshabilitado' ?></span>
        <span class="badge blue"><?= View::e($catalog['visibility']) ?></span>
      </div>
      <h2><?= View::e($catalog['name']) ?></h2>
      <p class="muted">/catalogo/<?= View::e($catalog['slug']) ?></p>
      <div class="mini-grid catalog-mini">
        <div><span>Productos</span><strong><?= (int) ($catalog['total_items'] ?? 0) ?></strong></div>
        <div><span>Visibles</span><strong><?= (int) ($catalog['visible_items'] ?? 0) ?></strong></div>
        <div><span>Cuenta</span><strong><?= View::e($catalog['account_name'] ?? 'Todas') ?></strong></div>
      </div>
      <div class="quick-actions mt-2">
        <a class="btn small" href="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>">Administrar</a>
        <a class="btn small" href="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>/edit">Editar</a>
        <?php if (($catalog['visibility'] ?? '') === 'public'): ?>
          <a class="btn small" href="<?= View::e($base) ?>/catalogo/<?= View::e($catalog['slug']) ?>" target="_blank" rel="noopener">Ver público</a>
        <?php elseif (($catalog['visibility'] ?? '') === 'private_token'): ?>
          <a class="btn small" href="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>/edit">Enlace privado</a>
        <?php else: ?>
          <a class="btn small" href="<?= View::e($base) ?>/catalogs/<?= (int) $catalog['id'] ?>">Vista interna</a>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</section>
