<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$money = static fn($v) => '$ ' . number_format((float) $v, 0, ',', '.');
$syncJob = is_array($syncJob ?? null) ? $syncJob : null;
?>
<div class="page-head">
  <div>
    <h1>Productos Mercado Libre</h1>
    <p>Publicaciones importadas por cuenta. Solo lectura hacia Mercado Libre.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/products/meli/reviews">
      Revisiones<?= (int) ($pendingReviews ?? 0) > 0 ? ' (' . (int) $pendingReviews . ')' : '' ?>
    </a>
    <a class="btn primary" href="<?= View::e($base) ?>/settings/manual-processing?scope=products&amp;origin=products<?= $accountId > 0 ? '&amp;account_id=' . (int) $accountId : '' ?>">Procesar ahora</a>
  </div>
</div>

<section class="operation-explainer" aria-label="Estado de publicaciones">
  <div><span>Qué está pasando</span><strong><?= $syncJob ? View::e((int) $syncJob['processed_count'] . ' de ' . (int) $syncJob['discovered_count'] . ' publicaciones procesadas.') : 'Se muestran publicaciones guardadas en el ERP.' ?></strong></div>
  <div><span>Qué hará el ERP</span><strong>La importación continuará por recursos sin modificar Mercado Libre.</strong></div>
  <div><span>Qué puede hacer ahora</span><strong><?= $syncJob && (string) $syncJob['phase'] === 'error' ? 'Abra Revisiones para resolver la causa exacta.' : 'Filtre por cuenta, estado o vínculo.' ?></strong></div>
</section>

<?php if ($syncJob): ?>
  <section class="panel">
    <div class="panel-head">
      <div>
        <span class="eyebrow">Última importación</span>
        <h2><?= View::e((string) $syncJob['account_name']) ?></h2>
        <p>
          <?= (int) $syncJob['processed_count'] ?> de <?= (int) $syncJob['discovered_count'] ?> publicaciones procesadas.
          <?= (int) $syncJob['error_count'] ?> con error.
        </p>
      </div>
      <span class="badge <?= (string) $syncJob['phase'] === 'complete' ? 'green' : ((string) $syncJob['phase'] === 'error' ? 'red' : 'blue') ?>">
        <?= View::e(match ((string) $syncJob['phase']) {
          'discovering' => 'Descubriendo',
          'details' => 'Actualizando',
          'complete' => 'Completa',
          'partial' => 'Continuará',
          'error' => 'Requiere atención',
          default => 'Pendiente',
        }) ?>
      </span>
    </div>
  </section>
<?php endif; ?>

<section class="panel filter-bar">
  <form class="inline-form js-async-filter" method="get" action="<?= View::e($base) ?>/products/meli" data-section-target="products-meli-list">
    <div class="field">
      <label>Cuenta</label>
      <select class="input" name="account_id">
        <option value="0">Todas</option>
        <?php foreach ($accounts as $account): ?>
          <option value="<?= (int) $account['id'] ?>" <?= $accountId === (int) $account['id'] ? 'selected' : '' ?>><?= View::e($account['account_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Buscar</label>
      <input class="input" name="q" value="<?= View::e($q) ?>" placeholder="ID, SKU o título">
    </div>
    <div class="field">
      <label>Resultados</label>
      <select class="input" name="per_page">
        <?php foreach ([25, 50, 100] as $size): ?><option value="<?= $size ?>" <?= (int) $filters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?> por página</option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Estado</label>
      <select class="input" name="status">
        <option value="">Todos</option>
        <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Activas</option>
        <option value="paused" <?= $filters['status'] === 'paused' ? 'selected' : '' ?>>Pausadas</option>
        <option value="under_review" <?= $filters['status'] === 'under_review' ? 'selected' : '' ?>>En revisión</option>
      </select>
    </div>
    <div class="field">
      <label>Vínculo</label>
      <select class="input" name="link_state">
        <option value="">Todos</option>
        <option value="linked" <?= $filters['link_state'] === 'linked' ? 'selected' : '' ?>>Vinculadas</option>
        <option value="unlinked" <?= $filters['link_state'] === 'unlinked' ? 'selected' : '' ?>>Sin vínculo</option>
      </select>
    </div>
    <button class="btn">Filtrar</button>
  </form>

  <form class="inline-form mt-2" method="post" action="<?= View::e($base) ?>/products/meli/sync">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <div class="field">
      <label>Sincronizar cuenta</label>
      <select class="input" name="account_id" required>
        <?php foreach ($accounts as $account): ?>
          <option value="<?= (int) $account['id'] ?>"><?= View::e($account['account_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn primary">Importar publicaciones</button>
    <span class="muted">La tarea quedó preparada para el lanzador único. Puede cerrar esta página.</span>
  </form>
</section>

<?php
$sectionQuery = $_GET;
unset($sectionQuery['full']);
$sectionUrl = $base . '/products/meli/section.json' . ($sectionQuery ? '?' . http_build_query($sectionQuery) : '');
?>
<section class="async-section" data-async-section="products-meli-list" data-url="<?= View::e($sectionUrl) ?>" aria-live="polite" aria-busy="<?= $progressive ? 'true' : 'false' ?>">
  <div class="async-section-status" data-async-status hidden></div>
  <?php if ($progressive): ?>
    <div class="panel table-panel async-skeleton" data-async-skeleton aria-hidden="true"><div class="skeleton-table product-skeleton"><?php for ($row = 0; $row < 10; $row++): ?><span></span><?php endfor; ?></div></div>
    <div data-async-content></div>
    <noscript><div class="panel empty-state"><a class="btn primary" href="<?= View::e($base) ?>/products/meli?<?= View::e(http_build_query(array_merge($sectionQuery, ['full' => 1]))) ?>">Cargar publicaciones sin JavaScript</a></div></noscript>
  <?php else: ?>
    <div data-async-content><?php View::render('products/meli/_table', compact('pageData', 'filters'), false); ?></div>
  <?php endif; ?>
</section>
