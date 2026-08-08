<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$money = static fn($v) => '$ ' . number_format((float) $v, 0, ',', '.');
$searchTypes = [
    'all' => 'Todo',
    'order' => 'Número de orden',
    'product' => 'Producto',
    'sku' => 'SKU',
    'buyer' => 'Comprador',
];
?>
<div class="page-head">
  <div><h1>Órdenes</h1><p>Consulta y sincroniza ventas por cuenta y rango.</p></div>
</div>

<section class="panel filter-bar">
  <form class="filters js-async-filter" method="get" action="<?= View::e($base) ?>/orders" data-section-target="orders-list">
    <div class="field">
      <label for="orders-account">Cuenta</label>
      <select class="input" id="orders-account" name="account_id">
        <option value="">Todas</option>
        <?php foreach ($accounts as $a): ?>
          <option value="<?= (int) $a['id'] ?>" <?= $params['account'] == $a['id'] ? 'selected' : '' ?>><?= View::e($a['account_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="orders-status">Estado</label>
      <select class="input" id="orders-status" name="status">
        <option value="">Todos</option>
        <option value="paid" <?= $params['status'] === 'paid' ? 'selected' : '' ?>>Pagada</option>
        <option value="cancelled" <?= $params['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelada</option>
      </select>
    </div>
    <div class="field"><label for="orders-from">Desde</label><input class="input" id="orders-from" type="date" name="from" value="<?= View::e($params['from']) ?>"></div>
    <div class="field"><label for="orders-to">Hasta</label><input class="input" id="orders-to" type="date" name="to" value="<?= View::e($params['to']) ?>"></div>
    <div class="field">
      <label for="orders-search-type">Buscar por</label>
      <select class="input" id="orders-search-type" name="search_type">
        <?php foreach ($searchTypes as $value => $label): ?>
          <option value="<?= View::e($value) ?>" <?= ($params['search_type'] ?? 'all') === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label for="orders-query">Texto</label><input class="input" id="orders-query" name="q" value="<?= View::e($params['q']) ?>" placeholder="Orden, producto, SKU o comprador"></div>
    <div class="field">
      <label for="orders-per-page">Resultados</label>
      <select class="input" id="orders-per-page" name="per_page">
        <?php foreach ([25, 50, 100] as $size): ?><option value="<?= $size ?>" <?= (int) $params['per_page'] === $size ? 'selected' : '' ?>><?= $size ?> por página</option><?php endforeach; ?>
      </select>
    </div>
    <div class="field field-action"><button class="btn primary" type="submit">Aplicar filtros</button></div>
  </form>
</section>

<section class="panel filter-bar">
  <form class="inline-form" method="post" action="<?= View::e($base) ?>/orders/sync">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <div class="field">
      <label for="orders-sync-account">Cuenta</label>
      <select class="input" id="orders-sync-account" name="account_id" required>
        <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= View::e($a['account_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label for="orders-sync-from">Desde</label><input class="input" id="orders-sync-from" type="date" name="from" value="<?= date('Y-m-d', strtotime('-6 days')) ?>" required></div>
    <div class="field"><label for="orders-sync-to">Hasta</label><input class="input" id="orders-sync-to" type="date" name="to" value="<?= date('Y-m-d') ?>" required></div>
    <label class="check-row"><input type="checkbox" name="confirm_large_range" value="1"> Confirmo rango mayor al límite</label>
    <button class="btn primary" type="submit"><svg><use href="<?= View::e(View::asset($base, 'icons.svg')) ?>#refresh"></use></svg>Sincronizar rango</button>
    <small class="muted">Para evitar saturación, use rangos pequeños. El límite inicial es 7 días.</small>
  </form>
</section>

<?php
$sectionQuery = $_GET;
unset($sectionQuery['full']);
$sectionUrl = $base . '/orders/section.json' . ($sectionQuery ? '?' . http_build_query($sectionQuery) : '');
?>
<section
  class="async-section"
  data-async-section="orders-list"
  data-url="<?= View::e($sectionUrl) ?>"
  aria-live="polite"
  aria-busy="<?= $progressive ? 'true' : 'false' ?>"
>
  <div class="async-section-status" data-async-status hidden></div>
  <?php if ($progressive): ?>
    <div class="panel table-panel async-skeleton" data-async-skeleton aria-hidden="true">
      <div class="skeleton-table">
        <?php for ($row = 0; $row < 10; $row++): ?><span></span><?php endfor; ?>
      </div>
    </div>
    <div data-async-content></div>
    <noscript>
      <div class="panel empty-state">
        <p>Active JavaScript para carga progresiva o abra la lista completa.</p>
        <a class="btn primary" href="<?= View::e($base) ?>/orders?<?= View::e(http_build_query(array_merge($sectionQuery, ['full' => 1]))) ?>">Cargar lista completa</a>
      </div>
    </noscript>
  <?php else: ?>
    <div data-async-content><?php View::render('orders/_table', compact('pageData', 'params'), false); ?></div>
  <?php endif; ?>
</section>
