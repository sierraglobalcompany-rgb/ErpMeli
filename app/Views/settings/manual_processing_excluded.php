<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$definition = $definitions[$state] ?? $definitions['automatic_only'];
$pages = max(1, (int) ceil($total / max(1, $perPage)));
?>
<div class="page-head compact-head">
  <div>
    <p class="eyebrow">PROCESAMIENTO MANUAL · RESULTADO DEL CÁLCULO</p>
    <h1><?= View::e($definition['label']) ?></h1>
    <p><?= View::e($definition['explanation']) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base . '/settings/manual-processing?preview=' . rawurlencode($previewToken) . '#resultado-calculo') ?>">Volver al cálculo</a>
  </div>
</div>

<?php if (!empty($preview['review_only'])): ?>
<div class="alert info"><strong>Este cálculo es informativo.</strong><p>La selección venció o ya fue utilizada. Vuelva a calcular antes de comenzar una campaña; esta lista se conserva para explicar el resultado.</p></div>
<?php endif; ?>

<nav class="manual-exclusion-tabs" aria-label="Motivos por los que un trabajo no entró">
  <?php foreach ($groups as $group): ?>
    <a class="<?= $group['state'] === $state ? 'active' : '' ?>" href="<?= View::e($base . $group['url']) ?>">
      <?= View::e($group['label']) ?> <span><?= (int) $group['count'] ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<section class="panel manual-exclusion-help">
  <div class="panel-body">
    <strong>¿Por qué no se ejecutan todos desde aquí?</strong>
    <p><em>Procesamiento manual</em> acelera consultas exactas que pueden reservarse y repetirse con seguridad. No salta errores, horarios, locks ni trabajos por etapas: hacerlo podría duplicar consultas o perder un checkpoint.</p>
  </div>
</section>

<section class="panel">
  <header class="panel-head">
    <div><h2><?= $total ?> trabajos</h2><p>Cálculo realizado <?= View::e((string) ($preview['calculated_at'] ?? '')) ?> UTC.</p></div>
  </header>
  <?php if ($items === []): ?>
    <div class="empty-state"><strong>No hay trabajos en este grupo</strong><p>Regrese al cálculo para revisar las demás situaciones.</p></div>
  <?php else: ?>
  <div class="table-scroll">
    <table class="data-table responsive-table human-table">
      <caption>Trabajos excluidos de la campaña y su siguiente acción</caption>
      <thead><tr><th>Trabajo</th><th>Cuenta</th><th>Por qué no entró</th><th>Siguiente paso</th></tr></thead>
      <tbody>
      <?php foreach ($items as $item): ?>
        <tr>
          <td data-label="Trabajo"><strong><?= View::e((string) ($item['label'] ?? 'Trabajo')) ?></strong><small><?= View::e((string) ($item['queue_key'] ?? '')) ?></small></td>
          <td data-label="Cuenta"><?= View::e((string) ($item['account_name'] ?? '') ?: 'Todas') ?></td>
          <td data-label="Por qué no entró">
            <?= View::e((string) ($item['reason'] ?? $definition['explanation'])) ?>
            <?php if (!empty($item['next_eligible_at'])): ?><small>Disponible: <?= View::e((string) $item['next_eligible_at']) ?> UTC</small><?php endif; ?>
          </td>
          <td data-label="Siguiente paso"><div class="manual-exclusion-actions">
            <?php if (!empty($item['work_url'])): ?><a class="btn small" href="<?= View::e($base . $item['work_url']) ?>">Abrir trabajo</a><?php endif; ?>
            <a class="text-link" href="<?= View::e($base . $item['domain_url']) ?>">Ir al módulo</a>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php if ($pages > 1): ?>
  <nav class="pagination" aria-label="Páginas">
    <?php if ($page > 1): ?><a class="btn" href="<?= View::e($base . '/settings/manual-processing/excluded?' . http_build_query(['preview'=>$previewToken,'state'=>$state,'page'=>$page-1,'per_page'=>$perPage])) ?>">Anterior</a><?php endif; ?>
    <span>Página <?= $page ?> de <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn" href="<?= View::e($base . '/settings/manual-processing/excluded?' . http_build_query(['preview'=>$previewToken,'state'=>$state,'page'=>$page+1,'per_page'=>$perPage])) ?>">Siguiente</a><?php endif; ?>
  </nav>
  <?php endif; ?>
</section>
