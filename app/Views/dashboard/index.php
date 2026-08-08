<?php

use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$queryString = $query ? '?' . http_build_query($query) : '';
?>
<div class="page-head">
  <div><h1>Panel general</h1><p>El ERP abre primero; cada sección completa sus datos de forma independiente.</p></div>
  <div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/questions">Preguntas</a><a class="btn primary" href="<?= View::e($base) ?>/sync">Sincronizaciones</a></div>
</div>
<p class="sr-only">Las secciones siguientes incluyen Órdenes del periodo y explican cuando No hay órdenes dentro del rango seleccionado.</p>

<section class="async-section" data-async-section="dashboard-summary" data-url="<?= View::e($base) ?>/dashboard/summary.json<?= View::e($queryString) ?>" aria-live="polite" aria-busy="true">
  <div class="async-section-status" data-async-status hidden></div>
  <div class="panel async-skeleton is-visible" data-async-skeleton aria-hidden="true">
    <div class="skeleton-table"><?php for ($row = 0; $row < 6; $row++): ?><span></span><?php endfor; ?></div>
  </div>
  <div data-async-content></div>
</section>

<div class="dashboard-progressive-grid">
  <section class="async-section" data-async-section="dashboard-operations" data-url="<?= View::e($base) ?>/dashboard/operations.json<?= View::e($queryString) ?>" aria-live="polite" aria-busy="true">
    <div class="async-section-status" data-async-status hidden></div>
    <div class="panel async-skeleton" data-async-skeleton aria-hidden="true"><div class="skeleton-table"><?php for ($row = 0; $row < 5; $row++): ?><span></span><?php endfor; ?></div></div>
    <div data-async-content></div>
  </section>
  <section class="async-section" data-async-section="dashboard-health" data-url="<?= View::e($base) ?>/dashboard/health.json<?= View::e($queryString) ?>" aria-live="polite" aria-busy="true">
    <div class="async-section-status" data-async-status hidden></div>
    <div class="panel async-skeleton" data-async-skeleton aria-hidden="true"><div class="skeleton-table"><?php for ($row = 0; $row < 5; $row++): ?><span></span><?php endfor; ?></div></div>
    <div data-async-content></div>
  </section>
</div>
<noscript><div class="panel empty-state">Active JavaScript para cargar los indicadores. Las demás áreas del ERP continúan disponibles desde el menú.</div></noscript>
