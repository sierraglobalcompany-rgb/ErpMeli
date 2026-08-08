<?php

use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;
use App\Services\ContextHelpPresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$run = is_array($auditRun ?? null) ? $auditRun : null;
$legacy = is_array($audit ?? null) ? $audit : null;
$hasSnapshot = $run !== null && in_array((string) ($run['status'] ?? ''), ['complete', 'partial'], true);
$isProcessing = $run !== null && in_array((string) ($run['job_status'] ?? ''), ['pending', 'running', 'waiting_budget'], true);
$missing = (int) ($run['missing_total'] ?? 0);
$withoutDate = (int) ($run['missing_normalized_total'] ?? 0);
$shifted = (int) ($run['shifted_total'] ?? 0);
$otherAccount = (int) ($run['other_account_total'] ?? 0);
$extra = (int) ($run['extra_total'] ?? 0);
$remote = (int) ($run['remote_unique_total'] ?? 0);
$local = (int) ($run['local_period_total'] ?? 0);
$ready = $hasSnapshot
    && ($run['remote_coverage'] ?? '') === 'complete'
    && ($run['reconciliation_status'] ?? '') === 'ready';
$statusLabel = $isProcessing
    ? 'Comprobación en progreso'
    : ($ready ? 'Periodo comprobado' : ($run ? 'Requiere revisión' : 'Aún no se ha comprobado este mes'));
$statusClass = $isProcessing ? 'blue' : ($ready ? 'green' : ($run ? 'amber' : 'gray'));
$summary = $isProcessing
    ? 'Mercado Libre se está consultando por páginas. El progreso queda guardado y continuará mediante el lanzador único.'
    : ($ready
        ? 'Las órdenes remotas están presentes localmente y sus fechas normalizadas corresponden al periodo.'
        : ($run
            ? 'La información disponible todavía no permite considerar el periodo completamente conciliado.'
            : ($legacy
                ? 'Existe un resultado anterior, pero no es suficientemente confiable para descargar órdenes. Cree una auditoría exacta.'
                : 'Cree una auditoría exacta para conocer el estado real del periodo.')));
$formBase = '<input type="hidden" name="_token" value="' . View::e(Csrf::token()) . '">'
    . '<input type="hidden" name="account_id" value="' . (int) $accountId . '">'
    . '<input type="hidden" name="year" value="' . (int) $year . '">'
    . '<input type="hidden" name="month" value="' . (int) $month . '">';
$classificationLabels = [
    'missing_remote' => 'Faltan por descargar',
    'missing_normalized_date' => 'Sin fecha normalizada',
    'shifted_date' => 'Existen en otro día',
    'other_account' => 'Pertenecen a otra cuenta',
    'extra_local' => 'Sobran localmente',
    'outside_range' => 'Fuera del periodo',
    'unknown' => 'No se pudo comprobar',
];
$classifications = [
    ['key' => 'missing_remote', 'label' => 'Faltan por descargar', 'count' => $missing, 'tone' => $missing > 0 ? 'red' : 'green',
        'help' => 'Mercado Libre reportó estas órdenes, pero todavía no existen localmente.'],
    ['key' => 'missing_normalized_date', 'label' => 'Sin fecha normalizada', 'count' => $withoutDate, 'tone' => $withoutDate > 0 ? 'amber' : 'green',
        'help' => 'Las órdenes existen localmente; falta calcular la fecha usada por reportes y conciliación.'],
    ['key' => 'shifted_date', 'label' => 'En otro día', 'count' => $shifted, 'tone' => $shifted > 0 ? 'amber' : 'green',
        'help' => 'Las órdenes existen, pero su fecha local corresponde a otro día.'],
    ['key' => 'other_account', 'label' => 'En otra cuenta', 'count' => $otherAccount, 'tone' => $otherAccount > 0 ? 'amber' : 'green',
        'help' => 'El mismo ID existe localmente asociado a una cuenta diferente.'],
    ['key' => 'extra_local', 'label' => 'Sobran localmente', 'count' => $extra, 'tone' => $extra > 0 ? 'amber' : 'green',
        'help' => 'Existen órdenes locales del periodo que no aparecieron en el snapshot remoto.'],
];
?>

<div class="page-head">
  <div>
    <a class="muted" href="<?= View::e($base) ?>/sync">← Volver a sincronizaciones</a>
    <div class="eyebrow">Control de ventas</div>
    <h1>Auditoría de ventas</h1>
    <p>Compruebe presencia, fechas y conciliación usando un único snapshot mensual deduplicado.</p>
  </div>
  <div class="page-actions">
    <a class="btn primary" href="<?= View::e($base) ?>/settings/manual-processing?scope=audits&amp;origin=audits&amp;account_id=<?= (int) $accountId ?>&amp;year=<?= (int) $year ?>&amp;month=<?= (int) $month ?>">Procesar auditorías ahora</a>
    <a class="btn" href="<?= View::e($base) ?>/settings/cron/queue?type=sales_audit">Ver en automatización</a>
  </div>
</div>

<section class="panel filter-bar">
  <form class="inline-form" method="get" action="<?= View::e($base) ?>/sync/audit">
    <div class="field">
      <label for="audit-account">Cuenta</label>
      <select id="audit-account" class="input" name="account_id">
        <?php foreach ($accounts as $account): ?>
          <option value="<?= (int) $account['id'] ?>" <?= $accountId === (int) $account['id'] ? 'selected' : '' ?>><?= View::e($account['account_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label for="audit-year">Año</label><input id="audit-year" class="input" type="number" min="2020" max="2100" name="year" value="<?= (int) $year ?>"></div>
    <div class="field"><label for="audit-month">Mes</label><input id="audit-month" class="input" type="number" min="1" max="12" name="month" value="<?= (int) $month ?>"></div>
    <button class="btn" type="submit">Consultar periodo</button>
  </form>
</section>

<nav class="audit-stepper" aria-label="Etapas de la auditoría">
  <span class="<?= !$hasSnapshot ? 'active' : 'done' ?>"><b><?= $hasSnapshot ? '✓' : '1' ?></b> Comprobar</span>
  <span class="<?= $hasSnapshot ? 'active' : '' ?>"><b>2</b> Revisar</span>
  <span><b>3</b> Reparar</span>
  <span><b>4</b> Verificar</span>
</nav>

<section class="status-hero <?= View::e($statusClass) ?>" aria-live="polite">
  <div class="status-hero-icon" aria-hidden="true"><?= $ready ? '✓' : ($isProcessing ? '↻' : '!') ?></div>
  <div>
    <div class="eyebrow">Confiabilidad de la auditoría</div>
    <h2><?= View::e($statusLabel) ?></h2>
    <p><?= View::e($summary) ?></p>
    <?php if ($run): ?>
      <small class="muted">
        Snapshot #<?= (int) $run['id'] ?> ·
        <?= View::e(DateTimePresenter::formatQueue($run['completed_at'] ?? $run['updated_at'] ?? null)) ?>
      </small>
    <?php endif; ?>
  </div>
  <?php if (!$isProcessing): ?>
    <form method="post" action="<?= View::e($base) ?>/sync/audit/compare-month" data-confirm="Se creará un trabajo reanudable. Consultará IDs de órdenes, sin modificar Mercado Libre.">
      <?= $formBase ?>
      <button class="btn primary" type="submit"><?= $hasSnapshot ? 'Comprobar nuevamente' : 'Comprobar el mes' ?></button><?= ContextHelpPresenter::button('sales.audit.exact') ?>
    </form>
  <?php endif; ?>
</section>

<?php if ($isProcessing): ?>
  <?php
    $reported = max(0, (int) ($run['job_remote_total'] ?? 0));
    $offset = max(0, (int) ($run['next_offset'] ?? 0));
    $progress = $reported > 0 ? min(100, (int) floor(($offset / $reported) * 100)) : 0;
  ?>
  <section class="panel">
    <header class="panel-head"><h2>Progreso de la comprobación</h2><span><?= $progress ?> %</span></header>
    <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $progress ?>"><span style="width:<?= $progress ?>%"></span></div>
    <p class="muted"><?= $offset ?> de <?= $reported > 0 ? $reported : 'total todavía desconocido' ?> IDs revisados. Próxima oportunidad: <?= View::e(DateTimePresenter::formatQueue($run['next_run_at'] ?? null)) ?>.</p>
    <?php if (!empty($run['job_error'])): ?><div class="alert warning"><?= View::e((string) $run['job_error']) ?></div><?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($run): ?>
<section class="metrics audit-summary-metrics">
  <article class="metric-card compact"><div><div class="metric-label">Órdenes remotas únicas</div><div class="metric-value"><?= $remote ?></div><small><?= $hasSnapshot ? 'Snapshot deduplicado' : 'Sin snapshot exacto' ?></small></div></article>
  <article class="metric-card compact"><div><div class="metric-label">Presentes localmente</div><div class="metric-value"><?= (int) ($run['present_total'] ?? 0) ?></div><small>Cuenta y fecha esperadas</small></div></article>
  <article class="metric-card compact"><div><div class="metric-label">Faltantes reales</div><div class="metric-value"><?= $missing ?></div><small>Requieren descarga</small></div></article>
  <article class="metric-card compact"><div><div class="metric-label">Pendientes de normalización</div><div class="metric-value"><?= $withoutDate ?></div><small>Reparación exclusivamente local</small></div></article>
  <article class="metric-card compact"><div><div class="metric-label">Total local del periodo</div><div class="metric-value"><?= $local ?></div><small>Usando fecha normalizada</small></div></article>
</section>
<?php endif; ?>

<?php if ($run): ?>
<section class="panel">
  <header class="panel-head">
    <div><h2>Qué necesita revisión</h2><p class="muted">Las diferencias se agrupan por causa para evitar cientos de alarmas repetidas.</p></div>
  </header>
  <div class="issue-summary-grid">
    <?php foreach ($classifications as $item): ?>
      <a class="issue-summary-card" href="<?= View::e($base) ?>/sync/audit?account_id=<?= (int) $accountId ?>&year=<?= (int) $year ?>&month=<?= (int) $month ?>&classification=<?= View::e($item['key']) ?>#audit-details">
        <span class="badge <?= View::e($item['tone']) ?>"><?= (int) $item['count'] ?></span>
        <strong><?= View::e($item['label']) ?></strong>
        <small><?= View::e($item['help']) ?></small>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<section class="panel">
  <header class="panel-head"><div><h2>Acción recomendada</h2><p class="muted">Cada proceso indica si trabaja localmente o consulta Mercado Libre.</p></div></header>
  <div class="guided-actions">
    <?php if ($withoutDate > 0): ?>
      <article><strong>1. Recalcular fechas normalizadas</strong><p>Proceso local. No consume API ni modifica Mercado Libre.</p>
        <form method="post" action="<?= View::e($base) ?>/sync/audit/recalculate-dates" data-confirm="Se creará un trabajo local para recalcular fechas.">
          <?= $formBase ?><button class="btn primary" type="submit">Crear reparación local</button>
        </form>
      </article>
    <?php elseif ($missing > 0 && $hasSnapshot): ?>
      <article><strong>1. Descargar faltantes reales</strong><p>Consultará únicamente las órdenes que no existen localmente y respetará el presupuesto API.</p>
        <a class="btn primary" href="<?= View::e($base) ?>/sync/audit/repair/preview?run_id=<?= (int) $run['id'] ?>">Revisar antes de reparar</a>
      </article>
    <?php elseif (!$ready): ?>
      <article><strong>Repetir comprobación exacta</strong><p>Actualiza el snapshot antes de tomar decisiones financieras.</p></article>
    <?php else: ?>
      <article><strong>No se requiere reparación</strong><p>El periodo está listo para continuar con conciliación y reportes.</p></article>
    <?php endif; ?>
  </div>
  <details class="advanced-panel">
    <summary>Otras herramientas</summary>
    <div class="page-actions">
      <form method="post" action="<?= View::e($base) ?>/sync/audit/diagnose-dates"><?= $formBase ?><button class="btn" type="submit">Diagnosticar fechas</button></form>
      <form method="post" action="<?= View::e($base) ?>/sync/audit/financial-month"><?= $formBase ?><input type="hidden" name="mode" value="pending"><button class="btn" type="submit">Recalcular financiero pendiente</button></form>
    </div>
  </details>
</section>
<?php endif; ?>

<?php if ($run && !empty($run['days'])): ?>
<section class="panel table-panel">
  <header class="panel-head"><h2>Resumen por día</h2><span class="muted">Derivado del mismo snapshot mensual</span></header>
  <div class="table-scroll"><table class="data-table responsive-table">
    <caption>Totales diarios de la auditoría mensual</caption>
    <thead><tr><th>Fecha</th><th>Remotas</th><th>Locales</th><th>Correctas</th><th>Faltantes</th><th>Sin fecha</th><th>Otro día</th><th>Sobrantes</th><th>Estado</th></tr></thead>
    <tbody>
      <?php foreach ($run['days'] as $day): ?>
        <tr>
          <td data-label="Fecha"><?= View::e($day['audit_date']) ?></td>
          <td data-label="Remotas"><?= (int) $day['remote_unique_total'] ?></td>
          <td data-label="Locales"><?= (int) $day['local_total'] ?></td>
          <td data-label="Correctas"><?= (int) $day['present_total'] ?></td>
          <td data-label="Faltantes"><?= (int) $day['missing_total'] ?></td>
          <td data-label="Sin fecha"><?= (int) $day['missing_normalized_total'] ?></td>
          <td data-label="Otro día"><?= (int) $day['shifted_total'] ?></td>
          <td data-label="Sobrantes"><?= (int) $day['extra_total'] ?></td>
          <td data-label="Estado"><span class="badge <?= $day['status'] === 'complete' ? 'green' : 'amber' ?>"><?= $day['status'] === 'complete' ? 'Correcto' : 'Revisar' ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>

<?php if ($run && (int) ($run['difference_total'] ?? 0) > 0): ?>
<section id="audit-details" class="panel table-panel">
  <header class="panel-head">
    <div><h2>Detalle de diferencias</h2><p class="muted"><?= (int) $run['difference_total'] ?> registros. Solo los faltantes reales se descargan automáticamente.</p></div>
    <form class="inline-form" method="get" action="<?= View::e($base) ?>/sync/audit">
      <input type="hidden" name="account_id" value="<?= (int) $accountId ?>">
      <input type="hidden" name="year" value="<?= (int) $year ?>">
      <input type="hidden" name="month" value="<?= (int) $month ?>">
      <div class="field"><label for="audit-classification">Causa</label><select id="audit-classification" class="input" name="classification"><option value="">Todas</option><?php foreach ($classificationLabels as $key => $label): ?><option value="<?= View::e($key) ?>" <?= $classification === $key ? 'selected' : '' ?>><?= View::e($label) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="audit-page-size">Por página</label><select id="audit-page-size" class="input" name="per_page"><?php foreach ([25,50,100] as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></div>
      <button class="btn" type="submit">Filtrar</button>
    </form>
  </header>
  <div class="table-scroll"><table class="data-table responsive-table">
    <caption>Diferencias individuales de la auditoría exacta</caption>
    <thead><tr><th>Día</th><th>Orden</th><th>Causa</th><th>Explicación</th><th>Fecha remota</th><th>Fecha local</th></tr></thead>
    <tbody>
      <?php foreach ($run['differences'] as $row): ?>
        <tr>
          <td data-label="Día"><?= View::e($row['audit_date'] ?? '—') ?></td>
          <td data-label="Orden"><?= View::e($row['external_order_id']) ?></td>
          <td data-label="Causa"><span class="badge <?= $row['classification'] === 'missing_remote' ? 'red' : 'amber' ?>"><?= View::e($classificationLabels[$row['classification']] ?? $row['classification']) ?></span></td>
          <td data-label="Explicación"><?= View::e($row['safe_explanation'] ?? 'No se pudo determinar la causa.') ?></td>
          <td data-label="Fecha remota"><?= View::e($row['remote_date_created'] ?? '—') ?></td>
          <td data-label="Fecha local"><?= View::e($row['local_date_created_local'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ((int) $run['pages'] > 1): ?>
    <nav class="pagination" aria-label="Páginas del detalle">
      <?php for ($p = 1; $p <= (int) $run['pages']; $p++): ?>
        <a class="btn small <?= $p === (int) $run['page'] ? 'primary' : '' ?>" href="<?= View::e($base) ?>/sync/audit?account_id=<?= (int) $accountId ?>&year=<?= (int) $year ?>&month=<?= (int) $month ?>&classification=<?= View::e($classification) ?>&per_page=<?= (int) $perPage ?>&page=<?= $p ?>#audit-details"><?= $p ?></a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!$run && $legacy): ?>
  <section class="panel legacy-audit-card">
    <header class="panel-head"><div><span class="badge gray">Solo consulta</span><h2>Resultado anterior</h2></div></header>
    <p>Estas cifras se conservan como historial, pero no pueden usarse para descargar órdenes ni filtrar diferencias exactas.</p>
    <dl class="definition-list">
      <div><dt>Órdenes informadas anteriormente</dt><dd><?= number_format((int) ($legacy['remote_total'] ?? 0), 0, ',', '.') ?></dd></div>
      <div><dt>Órdenes locales informadas</dt><dd><?= number_format((int) ($legacy['local_total'] ?? 0), 0, ',', '.') ?></dd></div>
      <div><dt>Siguiente paso</dt><dd>Comprobar el mes con el método exacto.</dd></div>
    </dl>
  </section>
<?php endif; ?>
