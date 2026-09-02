<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$money = static fn(mixed $value, string $currency = 'COP'): string =>
    ($currency === 'COP' ? '$' : $currency . ' ') . number_format((float) $value, 0, ',', '.');
$statusLabel = static fn(string $status): string => match ($status) {
    'paid' => 'Pagada',
    'confirmed' => 'Confirmada',
    'cancelled' => 'Cancelada',
    'mixed' => 'Estados diferentes',
    'payment_required' => 'Pendiente de pago',
    default => $status !== '' ? ucfirst(str_replace('_', ' ', $status)) : 'Sin comprobar',
};
$integrityLabel = static fn(?string $status): array => match ($status) {
    'complete' => ['Completa', 'green'],
    'partial' => ['Falta una orden', 'amber'],
    'review' => ['Requiere revisión', 'red'],
    'pending' => ['Verificando el paquete', 'blue'],
    'provisional' => ['Reconstruida localmente', 'amber'],
    default => ['Venta individual', ''],
};
$importYear = (int) ($importYear ?? date('Y'));
$importAccountId = (int) ($importAccountId ?? 0);
$importStatus = is_array($importStatus ?? null) ? $importStatus : null;
$importStatusUrl = (string) ($importStatusUrl ?? '/sales/import-status.json');
$commercialStateLabel = static fn(string $state): string => match ($state) {
    'not_started' => 'Sin preparar',
    'queued' => 'Preparado',
    'running' => 'En ejecución',
    'needs_repair' => 'Faltantes',
    'repairing' => 'Descargando',
    'date_repair_needed' => 'Fechas',
    'date_repairing' => 'Corrigiendo',
    'review_needed' => 'Revisar',
    'verified' => 'Verificado',
    'partial_history' => 'Parcial',
    'blocked' => 'Pausado',
    'future' => 'Futuro',
    default => ucfirst(str_replace('_', ' ', $state)),
};
$financialLabel = static fn(string $status): string => match ($status) {
    'reconciled' => 'Conciliada con cargos oficiales',
    'review' => 'Requiere revisión financiera',
    'error' => 'Información financiera con error',
    'partial' => 'Mercado Libre aún completa los cargos',
    'capturing', 'queued' => 'Finanzas en automatización',
    default => 'Finanzas por completar',
};
?>
<div class="page-head sales-page-head">
  <div>
    <h1>Ventas de Mercado Libre</h1>
    <p>Importe, verifique y consulte ventas por cuenta sin exponer la evidencia técnica al flujo diario.</p>
  </div>
  <div class="page-actions">
    <?php if ($importStatus): ?>
      <a class="btn" href="<?= View::e($base . (string) $importStatus['advanced_url']) ?>">Evidencia avanzada</a>
    <?php endif; ?>
    <a class="btn" href="<?= View::e($base) ?>/sales/integrity">Revisar integridad</a>
  </div>
</div>

<section class="panel sales-import-panel tone-muted" aria-labelledby="sales-import-title">
  <div class="sales-import-copy">
    <div class="eyebrow">Importación anual</div>
    <h2 id="sales-import-title">Importar ventas del año</h2>
    <p>Seleccione una cuenta y un año para preparar trabajos locales.</p>
    <small>Este botón no consulta Mercado Libre. Las consultas remotas se realizan después mediante el procesador autorizado y comparten sus protecciones de ritmo.</small>
  </div>
  <form class="sales-import-form" method="post" action="<?= View::e($base) ?>/sales/import-year">
    <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
    <div class="sales-import-fields">
      <div class="field">
        <label for="sales-import-account">Cuenta</label>
        <select class="input" id="sales-import-account" name="account_id" required>
          <?php foreach ($accounts as $account): ?>
            <option value="<?= (int) $account['id'] ?>" <?= (int) $account['id'] === $importAccountId ? 'selected' : '' ?>>
              <?= View::e($account['account_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="sales-import-year">Año</label>
        <input class="input" id="sales-import-year" type="number" name="year" min="2020" max="<?= (int) date('Y') ?>" value="<?= $importYear ?>" required>
      </div>
    </div>
    <button class="btn primary" type="submit" <?= $accounts === [] ? 'disabled' : '' ?>>Importar ventas del año</button>
    <button class="btn" type="submit" formaction="<?= View::e($base) ?>/sales/import-available" <?= $accounts === [] ? 'disabled' : '' ?>>Importar todo lo disponible</button>
    <?php if ($importStatus): ?>
      <a class="btn" href="<?= View::e($base . (string) $importStatus['advanced_url']) ?>">Ver evidencia avanzada</a>
    <?php endif; ?>
  </form>
  <div data-async-section="sales-import-status"
       data-url="<?= View::e($base . $importStatusUrl) ?>"
       aria-live="polite">
    <div data-async-skeleton class="async-skeleton is-visible">
      <strong>Comprobando el estado anual…</strong>
    </div>
    <div data-async-status hidden></div>
    <div data-async-content></div>
  </div>
</section>

<form class="panel filter-bar sales-filter" method="get" action="<?= View::e($base) ?>/sales">
  <div class="filters">
    <div class="field"><label for="sale-account">Cuenta</label><select class="input" id="sale-account" name="account_id"><option value="0">Todas las cuentas autorizadas</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (int) $filters['account_id'] === (int) $account['id'] ? 'selected' : '' ?>><?= View::e($account['account_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field sales-search-field"><label for="sale-query">Buscar venta, orden, envío, pago o producto</label><input class="input" id="sale-query" name="q" value="<?= View::e($filters['q']) ?>" placeholder="Ej. 2000014234269247"></div>
    <div class="field"><label for="sale-financial">Finanzas</label><select class="input" id="sale-financial" name="financial_status"><option value="">Todos</option><option value="complete" <?= $filters['financial_status']==='complete'?'selected':'' ?>>Completas</option><option value="pending" <?= $filters['financial_status']==='pending'?'selected':'' ?>>Pendientes</option><option value="review" <?= $filters['financial_status']==='review'?'selected':'' ?>>Requieren revisión</option></select></div>
    <div class="field"><label for="sale-from">Desde</label><input class="input" id="sale-from" type="date" name="from" value="<?= View::e($filters['from']) ?>"></div>
    <div class="field"><label for="sale-to">Hasta</label><input class="input" id="sale-to" type="date" name="to" value="<?= View::e($filters['to']) ?>"></div>
  </div>
  <div class="sales-filter-actions">
    <button class="btn primary" type="submit">Buscar ventas</button>
    <?php if ($filters['q'] !== '' || $filters['from'] !== '' || $filters['to'] !== '' || $filters['financial_status'] !== '' || (int) $filters['account_id'] > 0): ?><a class="btn" href="<?= View::e($base) ?>/sales">Limpiar</a><?php endif; ?>
  </div>
</form>

<section class="panel sales-list" aria-labelledby="sales-list-title">
  <div class="panel-head">
    <div><h2 id="sales-list-title"><?= number_format((int) $sales['total'], 0, ',', '.') ?> ventas</h2><p>Más recientes primero. Las órdenes API quedan disponibles dentro de cada venta.</p></div>
  </div>
  <?php if ($sales['items'] === []): ?>
    <div class="empty"><strong>No encontramos ventas con estos filtros.</strong><p>Puede buscar el número de venta que aparece en Mercado Libre o una de sus órdenes API.</p></div>
  <?php else: ?>
    <div class="sale-rows">
      <?php foreach ($sales['items'] as $sale): [$integrityText, $integrityTone] = $integrityLabel($sale['integrity_status'] ?? null); ?>
        <a class="sale-row" href="<?= View::e($base) ?>/sales/show?<?= http_build_query(['account_id' => (int) $sale['meli_account_id'], 'sale_id' => (string) $sale['sale_id']]) ?>">
          <div class="sale-row-main">
            <span class="sale-row-id">Venta #<?= View::e($sale['sale_id']) ?></span>
            <strong><?= (int) $sale['products_count'] ?> <?= (int) $sale['products_count'] === 1 ? 'producto' : 'productos' ?> · <?= (int) $sale['units_count'] ?> <?= (int) $sale['units_count'] === 1 ? 'unidad' : 'unidades' ?></strong>
            <small><?= View::e($sale['account_name']) ?> · <?= (int) $sale['orders_count'] ?> <?= (int) $sale['orders_count'] === 1 ? 'orden API' : 'órdenes API' ?></small>
          </div>
          <div class="sale-row-finance">
            <span>Total de productos</span>
            <strong><?= View::e($money($sale['products_amount'], (string) ($sale['currency_id'] ?? 'COP'))) ?></strong>
            <small><?= View::e($financialLabel((string) ($sale['financial_status'] ?? 'not_started'))) ?></small>
          </div>
          <div class="sale-row-state">
            <span class="badge <?= View::e($integrityTone) ?>"><?= View::e($integrityText) ?></span>
            <strong><?= View::e($statusLabel((string) ($sale['status'] ?? ''))) ?></strong>
            <small>Abrir venta →</small>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ((int) $sales['pages'] > 1): ?>
    <nav class="pagination" aria-label="Páginas de ventas">
      <?php
      $currentPage = (int) $sales['page'];
      $totalPages = (int) $sales['pages'];
      $visiblePages = array_values(array_unique(array_filter(
          [1, $currentPage - 2, $currentPage - 1, $currentPage, $currentPage + 1, $currentPage + 2, $totalPages],
          static fn(int $page): bool => $page >= 1 && $page <= $totalPages
      )));
      foreach ($visiblePages as $page): $query = array_merge($filters, ['page' => $page]);
      ?>
        <a class="btn small <?= $page === (int) $sales['page'] ? 'primary' : '' ?>" href="<?= View::e($base) ?>/sales?<?= View::e(http_build_query($query)) ?>"><?= $page ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>
</section>
