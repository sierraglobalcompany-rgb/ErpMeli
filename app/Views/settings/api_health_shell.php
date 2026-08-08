<?php

use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$apiHealthSection = 'overview';
$apiHealthHours = max(1, (int) ($hours ?? 24));
$apiHealthCheckedAt = null;
require __DIR__ . '/_api_health_header.php';
require __DIR__ . '/_api_health_nav.php';
$query = http_build_query(array_filter([
    'hours' => $apiHealthHours,
    'account_id' => ((int) ($accountId ?? 0)) > 0 ? (int) $accountId : null,
], static fn (mixed $value): bool => $value !== null));
?>
<div class="progressive-section-shell" data-api-health-shell
     data-section-url="<?= View::e($base) ?>/settings/api-health/section.html?<?= View::e($query) ?>"
     data-operational-url="<?= View::e($base) ?>/settings/api-health/operational-snapshot.json?<?= View::e($query) ?>"
     aria-live="polite" aria-busy="true">
  <section class="api-health-triad is-loading" aria-label="Cargando Salud API">
    <?php foreach (['Mercado Libre', 'Protecciones preventivas', 'Automatización'] as $label): ?>
      <article><span><?= View::e($label) ?></span><strong>Comprobando…</strong><p>Esta sección se cargará sin bloquear el resto del ERP.</p></article>
    <?php endforeach; ?>
  </section>
  <section class="api-command-section is-loading">
    <header><div><span class="eyebrow">ESTADO DEL DIAGNÓSTICO</span><h2>Cargando evidencia reciente</h2></div></header>
    <p>Puede seguir usando otra sección. No se consultará Mercado Libre desde esta página.</p>
  </section>
  <noscript>
    <section class="alert warning">
      <strong>Salud API necesita cargar secciones progresivas.</strong>
      Abra la lectura completa si el navegador no ejecuta JavaScript:
      <a class="btn" href="<?= View::e($base) ?>/settings/api-health/section.html?<?= View::e($query) ?>">Abrir Salud API completa</a>
    </section>
  </noscript>
</div>
<script src="<?= View::e($base) ?>/assets/api-health.js?v=2.34.1" defer></script>
