<?php
use App\Core\Env;
use App\Core\View;
$base = rtrim(Env::get('APP_URL', ''), '/');
$automationTab = 'attention';
require __DIR__ . '/_automation_nav.php';
?>
<div class="page-head">
  <div><span class="eyebrow">Automatización</span><h1>Trabajos que necesitan solución</h1>
    <p>Las repeticiones iguales aparecen juntas. Las decisiones se aplican únicamente desde el detalle de cada trabajo.</p></div>
  <a class="btn" href="<?= View::e($base) ?>/settings/cron/queue?group=all">Ver cola completa</a>
</div>

<?php if ($groups === []): ?>
  <section class="empty-state"><h2>No hay errores que requieran intervención</h2><p>Los trabajos pendientes podrán continuar cuando corresponda.</p></section>
<?php else: ?>
  <section class="attention-groups" aria-label="Errores agrupados">
  <?php foreach ($groups as $group): ?>
    <article class="attention-group">
      <div class="attention-group-main">
        <div class="eyebrow"><?= number_format((int) $group['affected'], 0, ',', '.') ?> trabajos · <?= View::e((string) $group['queue_label']) ?></div>
        <h2><?= View::e($group['title']) ?></h2>
        <p><strong>Qué ocurrió:</strong> <?= View::e($group['cause']) ?></p>
        <p><strong>Impacto:</strong> <?= View::e($group['impact']) ?></p>
      </div>
      <div class="attention-group-action">
        <span class="status-pill tone-<?= $group['risk'] === 'Ninguno' ? 'success' : 'warning' ?>">Riesgo: <?= View::e($group['risk']) ?></span>
        <a class="btn primary" href="<?= View::e($base . (string) $group['review_url']) ?>"><?= View::e((string) $group['action']) ?></a>
        <small>Abre una lista filtrada. No modifica ni reprograma trabajos.</small>
      </div>
    </article>
  <?php endforeach; ?>
  </section>
<?php endif; ?>
