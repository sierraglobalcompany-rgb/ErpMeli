<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
$base = rtrim(Env::get('APP_URL', ''), '/');
$progressive = !empty($progressive);
if ($progressive) {
  $query = http_build_query(array_filter([
    'account_id' => (int) ($accountId ?? 0) ?: null,
    'year' => (int) ($year ?? date('Y')),
  ], static fn (mixed $value): bool => $value !== null));
  $sectionUrl = $base . '/sales-control/overview.json?' . $query;
  ?>
  <section class="async-section" data-async-section="sales-control-overview" data-url="<?= View::e($sectionUrl) ?>" aria-live="polite" aria-busy="true">
    <div data-async-skeleton class="panel async-skeleton is-visible">
      <header class="sales-control-header"><div><div class="eyebrow">Ventas · Evidencia avanzada</div><h1>Evidencia avanzada de ventas</h1><p>La pantalla ya abrió. Estamos preparando los meses y su evidencia por separado.</p></div></header>
      <div class="skeleton-table"><span></span><span></span><span></span><span></span></div>
    </div>
    <div data-async-status class="async-section-status" hidden></div>
    <div data-async-content></div>
  </section>
  <noscript><a class="btn primary" href="<?= View::e($base) ?>/sales-control?<?= View::e($query) ?>&amp;full=1">Cargar evidencia completa</a></noscript>
  <?php return;
}
$active = 'summary';
$schemaStatus = is_array($schemaStatus ?? null)
  ? $schemaStatus
  : ['ready' => false, 'base_ready' => false, 'missing' => [], 'message' => 'No se pudo comprobar la instalación.'];
?>
<header class="sales-control-header">
  <div>
    <div class="eyebrow">Ventas · Evidencia avanzada</div>
    <h1>Evidencia avanzada de ventas</h1>
    <p>Revise auditorías, diferencias y cierres cuando necesite soporte fiscal o diagnóstico técnico.</p>
  </div>
  <?php if ($overview): ?>
  <form method="post" action="<?= View::e($base) ?>/sales-control/check-year">
    <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
    <input type="hidden" name="account_id" value="<?= (int) $accountId ?>">
    <input type="hidden" name="year" value="<?= (int) $year ?>">
    <button class="btn primary" type="submit">Reverificar año</button>
  </form>
  <?php endif; ?>
</header>

<section class="operation-explainer" aria-label="Cómo leer el control de ventas">
  <div><span>Qué está pasando</span><strong><?= $overview ? View::e((string) $overview['state']['label']) : 'Aún no hay evidencia para este periodo.' ?></strong></div>
  <div><span>Qué hará el ERP</span><strong>Mostrará los meses primero y cargará la evidencia detallada al abrir uno.</strong></div>
  <div><span>Qué puede hacer ahora</span><strong><?= $overview ? View::e((string) $overview['recommendation']['title']) : 'Seleccione una cuenta y un año.' ?></strong></div>
</section>

<form class="sales-control-filter" method="get" action="<?= View::e($base) ?>/sales-control">
  <label for="sales-account">Cuenta</label>
  <select id="sales-account" name="account_id">
    <?php foreach ($accounts as $account): ?>
      <option value="<?= (int) $account['id'] ?>" <?= (int) $account['id'] === (int) $accountId ? 'selected' : '' ?>>
        <?= View::e($account['account_name'] . ' · ' . $account['company_name']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <label for="sales-year">Año</label>
  <input id="sales-year" type="number" name="year" min="2020" max="<?= (int) date('Y') ?>" value="<?= (int) $year ?>">
  <button class="btn" type="submit">Ver periodo</button>
</form>

<?php if (empty($schemaStatus['ready'])): ?>
  <section class="empty-state">
    <h2>Falta completar la actualización</h2>
    <p><?= View::e((string) $schemaStatus['message']) ?> No se consultó Mercado Libre ni se modificaron ventas.</p>
    <a class="btn primary" href="<?= View::e($base) ?>/settings/update">Completar actualización</a>
    <?php if (!empty($schemaStatus['missing'])): ?>
      <details class="mt-2">
        <summary>Ver comprobación técnica</summary>
        <p>Componentes pendientes: <code><?= View::e(implode(', ', array_slice($schemaStatus['missing'], 0, 12))) ?></code></p>
      </details>
    <?php endif; ?>
  </section>
<?php elseif (!$accounts): ?>
  <section class="empty-state">
    <h2>Primero conecte una cuenta de Mercado Libre</h2>
    <p>Control de ventas necesita una cuenta para mantener separadas empresa, ventas y evidencia.</p>
    <a class="btn primary" href="<?= View::e($base) ?>/accounts">Conectar cuenta</a>
  </section>
<?php else: ?>
  <section class="sales-year-status tone-<?= View::e($overview['state']['key']) ?>">
    <div>
      <span class="status-dot" aria-hidden="true"></span>
      <div class="eyebrow">Estado general · <?= View::e($overview['account']['account_name']) ?> · <?= (int) $year ?></div>
      <h2><?= View::e($overview['state']['label']) ?></h2>
      <p><?= View::e($overview['state']['message']) ?></p>
    </div>
    <div class="sales-coverage">
      <strong>Periodo solicitado</strong>
      <span><?= View::e($overview['coverage']['from']) ?> → <?= View::e($overview['coverage']['to']) ?></span>
      <small><?= View::e($overview['coverage']['message']) ?></small>
    </div>
  </section>

  <section class="sales-kpi-strip" aria-label="Resumen avanzado">
    <div><span>Ventas confirmadas</span><strong><?= (int) $overview['totals']['checked'] > 0 ? number_format((int) $overview['totals']['sales_confirmed'], 0, ',', '.') : 'Por comprobar' ?></strong></div>
    <div><span>Faltan por descargar</span><strong><?= $overview['totals']['missing'] === null ? 'Por comprobar' : number_format((int) $overview['totals']['missing_confirmed'], 0, ',', '.') ?></strong></div>
    <div><span>Historial por confirmar</span><strong><?= (int) $overview['totals']['unknown'] ?> <?= (int) $overview['totals']['unknown'] === 1 ? 'mes' : 'meses' ?></strong></div>
    <div><span>Datos fiscales pendientes</span><strong><?= $overview['totals']['fiscal_missing'] === null ? 'Por comprobar' : number_format((int) $overview['totals']['fiscal_missing_confirmed'], 0, ',', '.') ?></strong></div>
  </section>

  <div class="sales-control-layout">
    <section class="sales-month-list" aria-labelledby="months-title">
      <div class="section-heading">
        <div><h2 id="months-title">Evidencia mensual de <?= (int) $year ?></h2><p>Las acciones críticas quedan separadas para auditoría y cierre.</p></div>
      </div>
      <?php foreach ($overview['months'] as $row): ?>
        <article class="sales-month-row tone-<?= View::e($row['tone']) ?>">
          <div class="month-name"><strong><?= View::e($row['name']) ?></strong><span><?= View::e($row['label']) ?></span></div>
          <div class="month-summary">
            <strong><?= $row['remote_total'] === null ? 'Sin información comprobada' : number_format((int) $row['remote_total'], 0, ',', '.') . ' ventas' ?></strong>
            <span><?= View::e($row['summary']) ?></span>
          </div>
          <div class="month-meta"><?= !empty($row['checked_at']) ? 'Comprobado ' . View::e((string) $row['checked_at']) : 'Sin comprobación exacta' ?></div>
          <?php if ($row['action']): ?>
            <a class="btn small" href="<?= View::e($base) ?>/sales-control/month?account_id=<?= (int) $accountId ?>&year=<?= (int) $year ?>&month=<?= (int) $row['month'] ?>"><?= View::e($row['action']) ?></a>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>

    <aside class="sales-next-step">
      <div class="eyebrow">Qué hacer ahora</div>
      <h2><?= View::e($overview['recommendation']['title']) ?></h2>
      <p><?= View::e($overview['recommendation']['message']) ?></p>
      <?php if ($overview['recommendation']['month']): ?>
        <p class="operation-note"><?= !empty($overview['automation_stopped'])
            ? 'Automatización está detenida. El trabajo permanecerá guardado.'
            : ($overview['recommendation']['uses_api'] ? 'Consulta Mercado Libre en un trabajo seguro.' : 'No requiere una consulta inmediata.') ?></p>
        <a class="btn primary" href="<?= View::e($base) ?>/sales-control/month?account_id=<?= (int) $accountId ?>&year=<?= (int) $year ?>&month=<?= (int) $overview['recommendation']['month'] ?>">Continuar</a>
      <?php endif; ?>
    </aside>
  </div>
<?php endif; ?>
