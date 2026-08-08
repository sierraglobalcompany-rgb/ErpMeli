<?php
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$money = static fn($v) => '$ ' . number_format((float) $v, 0, ',', '.');
$query = http_build_query(array_filter($filters, static fn($v) => $v !== '' && $v !== 0));
$returnTo = '/shipments' . ($query ? '?' . $query : '');
$today = date('Y-m-d');
$presetUrl = static function (array $extra) use ($base, $filters): string {
    $merged = array_merge($filters, $extra);
    $query = http_build_query(array_filter($merged, static fn($v) => $v !== '' && $v !== 0));
    return $base . '/shipments' . ($query ? '?' . $query : '');
};
$logisticsLabel = static function (?string $type): string {
    return match ($type) {
        'fulfillment' => 'Full',
        'self_service' => 'Flex',
        'cross_docking' => 'Colecta',
        'xd_drop_off' => 'Places',
        'drop_off' => 'Drop off',
        default => $type ?: '—',
    };
};
$canPrintLabel = static function (array $s): bool {
    return ($s['shipping_mode'] ?? '') === 'me2'
        && ($s['status'] ?? '') === 'ready_to_ship'
        && in_array(($s['substatus'] ?? ''), ['ready_to_print', 'printed'], true)
        && ($s['logistic_type'] ?? '') !== 'fulfillment';
};
?>
<div class="page-head">
  <div>
    <h1>Envíos</h1>
    <p>Resumen filtrable por cuenta, logística, estado y fecha.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/shipments/export?<?= View::e($query) ?>">Exportar CSV</a>
  </div>
</div>

<section class="panel filter-bar">
  <div class="quick-actions">
    <a class="btn small <?= ($filters['preset'] ?? '') === 'pending_today' ? 'primary' : '' ?>" href="<?= View::e($presetUrl(['preset' => 'pending_today', 'date_field' => 'estimated', 'from' => $today, 'to' => $today])) ?>">Pendientes hoy</a>
    <a class="btn small" href="<?= View::e($presetUrl(['logistics_group' => 'self_service'])) ?>">Flex</a>
    <a class="btn small" href="<?= View::e($presetUrl(['logistics_group' => 'cross_docking'])) ?>">Colecta</a>
    <a class="btn small" href="<?= View::e($presetUrl(['logistics_group' => 'xd_drop_off'])) ?>">Places</a>
    <a class="btn small" href="<?= View::e($presetUrl(['logistics_group' => 'fulfillment'])) ?>">Full</a>
    <a class="btn small" href="<?= View::e($base) ?>/shipments">Limpiar</a>
  </div>
</section>

<section class="panel filter-bar">
  <form class="filters js-async-filter" method="get" action="<?= View::e($base) ?>/shipments" data-section-target="shipments-list">
    <input type="hidden" name="preset" value="<?= View::e($filters['preset'] ?? '') ?>">
    <div class="field">
      <label>Cuenta</label>
      <select class="input" name="account_id">
        <option value="0">Todas</option>
        <?php foreach ($options['accounts'] as $a): ?>
          <option value="<?= (int) $a['id'] ?>" <?= (int) $filters['account_id'] === (int) $a['id'] ? 'selected' : '' ?>><?= View::e($a['account_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Grupo logística</label>
      <select class="input" name="logistics_group">
        <option value="">Todos</option>
        <?php foreach ($options['logistics_groups'] as $key => $group): ?>
          <option value="<?= View::e($key) ?>" <?= ($filters['logistics_group'] ?? '') === $key ? 'selected' : '' ?>><?= View::e($group['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Logística API</label>
      <select class="input" name="logistic_type">
        <option value="">Todas</option>
        <?php foreach ($options['logistics'] as $v): ?>
          <option value="<?= View::e($v) ?>" <?= $filters['logistic_type'] === $v ? 'selected' : '' ?>><?= View::e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Estado</label>
      <select class="input" name="status">
        <option value="">Todos</option>
        <?php foreach ($options['statuses'] as $v): ?>
          <option value="<?= View::e($v) ?>" <?= $filters['status'] === $v ? 'selected' : '' ?>><?= View::e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Subestado</label>
      <select class="input" name="substatus">
        <option value="">Todos</option>
        <?php foreach ($options['substatuses'] as $v): ?>
          <option value="<?= View::e($v) ?>" <?= $filters['substatus'] === $v ? 'selected' : '' ?>><?= View::e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Fecha</label>
      <select class="input" name="date_field">
        <option value="synced" <?= $filters['date_field'] === 'synced' ? 'selected' : '' ?>>Sincronización</option>
        <option value="estimated" <?= $filters['date_field'] === 'estimated' ? 'selected' : '' ?>>Entrega / despacho estimado</option>
      </select>
    </div>
    <div class="field"><label>Desde</label><input class="input" type="date" name="from" value="<?= View::e($filters['from']) ?>"></div>
    <div class="field"><label>Hasta</label><input class="input" type="date" name="to" value="<?= View::e($filters['to']) ?>"></div>
    <div class="field">
      <label>Resultados</label>
      <select class="input" name="per_page">
        <?php foreach ([25, 50, 100] as $size): ?><option value="<?= $size ?>" <?= (int) $filters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?> por página</option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>&nbsp;</label><button class="btn primary">Filtrar</button></div>
  </form>
</section>

<section class="alert warning">
  Guías PDF: la base queda preparada, pero la descarga está desactivada hasta confirmar soporte real para Colombia/cuenta. El PDF de Mercado Envíos 2 exige modo <code>me2</code>, estado <code>ready_to_ship</code>, subestado <code>ready_to_print</code>/<code>printed</code> y excluye fulfillment como etiqueta de venta.
</section>

<?php
$sectionQuery = $_GET;
unset($sectionQuery['full']);
$sectionUrl = $base . '/shipments/section.json' . ($sectionQuery ? '?' . http_build_query($sectionQuery) : '');
?>
<section class="async-section" data-async-section="shipments-list" data-url="<?= View::e($sectionUrl) ?>" aria-live="polite" aria-busy="<?= $progressive ? 'true' : 'false' ?>">
  <div class="async-section-status" data-async-status hidden></div>
  <?php if ($progressive): ?>
    <div class="panel table-panel async-skeleton" data-async-skeleton aria-hidden="true"><div class="skeleton-table"><?php for ($row = 0; $row < 10; $row++): ?><span></span><?php endfor; ?></div></div>
    <div data-async-content></div>
    <noscript><div class="panel empty-state"><a class="btn primary" href="<?= View::e($base) ?>/shipments?<?= View::e(http_build_query(array_merge($sectionQuery, ['full' => 1]))) ?>">Cargar envíos sin JavaScript</a></div></noscript>
  <?php else: ?>
    <div data-async-content><?php View::render('sales/shipments/_table', compact('pageData', 'filters'), false); ?></div>
  <?php endif; ?>
</section>
