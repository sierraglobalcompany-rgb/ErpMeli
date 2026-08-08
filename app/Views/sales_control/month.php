<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
$base = rtrim(Env::get('APP_URL', ''), '/');
$accountId = (int) $account['id'];
$companyId = (int) $account['company_id'];
$active = 'summary';
$query = 'account_id=' . $accountId . '&year=' . (int) $year . '&month=' . (int) $month;
$runStatus = (string) ($run['status'] ?? '');
$runActive = in_array($runStatus, ['pending', 'running', 'waiting_budget', 'paused'], true);
$automationStopped = !empty($automationStopped);
$evidenceReady = $runStatus === 'complete'
  && (string) ($run['remote_coverage'] ?? '') === 'complete'
  && (string) ($run['coverage_validation_state'] ?? '') === 'valid'
  && (string) ($run['temporal_coverage_state'] ?? '') === 'full'
  && (int) ($run['coverage_contract_version'] ?? 0) >= 2;
?>
<header class="sales-control-header compact">
  <div>
    <a class="muted" href="<?= View::e($base) ?>/sales-control?account_id=<?= $accountId ?>&year=<?= (int) $year ?>">← Evidencia anual</a>
    <div class="eyebrow">Evidencia avanzada · <?= View::e($account['account_name']) ?></div>
    <h1><?= View::e($monthRow['name']) ?> de <?= (int) $year ?></h1>
    <p><?= View::e($monthRow['summary']) ?></p>
  </div>
  <span class="status-pill tone-<?= View::e($monthRow['tone']) ?>"><?= View::e($monthRow['label']) ?></span>
</header>
<ol class="sales-flow" aria-label="Progreso del mes">
  <li class="<?= $evidenceReady ? 'done' : 'current' ?>"><span>1</span> Ventas</li>
  <li class="<?= $evidenceReady && (int) $run['missing_total'] === 0 ? 'done' : ($evidenceReady ? 'current' : '') ?>"><span>2</span> Correcciones</li>
  <li class="<?= $evidenceReady && $fiscal['total'] > 0 ? 'current' : '' ?>"><span>3</span> Datos fiscales</li>
  <li class="<?= $monthRow['state'] === 'closed' ? 'done' : '' ?>"><span>4</span> Cierre</li>
</ol>

<?php if (!$run): ?>
  <section class="sales-conclusion">
    <div><div class="eyebrow">Siguiente paso</div><h2>Comprobar las ventas del mes</h2>
      <p>Mercado Libre todavía no se ha comparado con el ERP mediante una comprobación exacta. No mostramos ceros porque aún no hay evidencia.</p>
      <p class="operation-note">Esta acción solo crea un trabajo. No modifica Mercado Libre.</p></div>
    <form method="post" action="<?= View::e($base) ?>/sales-control/check-month">
      <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
      <input type="hidden" name="account_id" value="<?= $accountId ?>"><input type="hidden" name="year" value="<?= (int) $year ?>"><input type="hidden" name="month" value="<?= (int) $month ?>">
      <button class="btn primary" type="submit">Comprobar este mes</button>
    </form>
  </section>
<?php elseif ($runActive): ?>
  <section class="sales-conclusion tone-active">
    <div>
      <div class="eyebrow"><?= $automationStopped ? 'Trabajo preparado' : 'Verificando ventas' ?></div>
      <h2><?= $automationStopped ? 'Esperando reactivación de la automatización' : 'Los resultados todavía están en proceso' ?></h2>
      <p><?= $automationStopped
          ? 'El trabajo y su avance están guardados. No se iniciarán consultas mientras el freno de mano permanezca activo.'
          : 'El ERP conservará el avance página por página. Hasta terminar, ventas encontradas, faltantes y datos fiscales permanecen por confirmar.' ?></p>
      <p class="operation-note"><?= $automationStopped
          ? 'No necesita crear otra comprobación.'
          : 'No se creó otra auditoría. Puede priorizar este trabajo sin perder su checkpoint.' ?></p>
    </div>
    <?php if (!$automationStopped): ?>
      <a class="btn primary" href="<?= View::e($base) ?>/settings/manual-processing?scope=audits&account_id=<?= $accountId ?>">Priorizar en Procesar ahora</a>
    <?php endif; ?>
  </section>
  <section class="sales-kpi-strip" aria-label="Progreso de la comprobación">
    <div><span>Ventas encontradas</span><strong>Por comprobar</strong></div>
    <div><span>Faltantes</span><strong>Por comprobar</strong></div>
    <div><span>Páginas procesadas</span><strong><?= number_format((int) ($run['processed_pages'] ?? 0), 0, ',', '.') ?></strong></div>
    <div><span>Preparación fiscal</span><strong>Todavía no evaluada</strong></div>
  </section>
<?php else: ?>
  <section class="sales-conclusion tone-<?= View::e($monthRow['tone']) ?>">
    <div><div class="eyebrow">Conclusión</div><h2><?= View::e($monthRow['label']) ?></h2><p><?= View::e($monthRow['summary']) ?></p></div>
    <?php if (!$evidenceReady || $runStatus === 'error'): ?>
    <form method="post" action="<?= View::e($base) ?>/sales-control/check-month">
      <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
      <input type="hidden" name="account_id" value="<?= $accountId ?>"><input type="hidden" name="year" value="<?= (int) $year ?>"><input type="hidden" name="month" value="<?= (int) $month ?>">
      <button class="btn" type="submit">Comprobar de nuevo</button>
    </form>
    <?php endif; ?>
  </section>
  <section class="sales-kpi-strip" aria-label="Resultado de la comprobación">
    <div><span>Encontradas</span><strong><?= number_format((int) $run['remote_unique_total'], 0, ',', '.') ?></strong></div>
    <div><span>En el ERP</span><strong><?= number_format((int) $run['local_period_total'], 0, ',', '.') ?></strong></div>
    <div><span>Faltan por descargar</span><strong><?= number_format((int) $run['missing_total'], 0, ',', '.') ?></strong></div>
    <div><span>Fechas por corregir</span><strong><?= number_format((int) $run['shifted_total'] + (int) $run['missing_normalized_total'] + (int) $run['other_account_total'], 0, ',', '.') ?></strong></div>
  </section>
  <?php if (!empty($run['temporal_coverage_state']) && (string) $run['temporal_coverage_state'] !== 'full'): ?>
    <section class="sales-conclusion tone-warning">
      <div>
        <div class="eyebrow">Cobertura temporal</div>
        <h2><?= (string) $run['temporal_coverage_state'] === 'outside' ? 'Periodo fuera de la ventana remota' : 'Historial parcial' ?></h2>
        <p><?= View::e((string) ($run['temporal_coverage_reason'] ?? 'La ventana histórica remota no permite afirmar cobertura completa del periodo.')) ?></p>
        <?php if (!empty($run['effective_coverage_from_utc']) && !empty($run['effective_coverage_to_utc'])): ?>
          <p class="operation-note">Cobertura efectiva aproximada:
            <?= View::e((string) $run['effective_coverage_from_utc']) ?> UTC →
            <?= View::e((string) $run['effective_coverage_to_utc']) ?> UTC.
          </p>
        <?php else: ?>
          <p class="operation-note">Se conserva evidencia local, pero este mes no se puede cerrar como remoto completo.</p>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>
  <section class="sales-action-list">
    <a href="<?= View::e($base) ?>/sales-control/issues?<?= View::e($query) ?>"><strong>Revisar diferencias</strong><span>Ventas faltantes por descargar, fechas y registros locales adicionales.</span></a>
    <a href="<?= View::e($base) ?>/sales-control/fiscal?<?= View::e($query) ?>"><strong>Datos fiscales</strong><span><?= (int) $fiscal['needs_attention'] ?> registros requieren atención.</span></a>
    <a href="<?= View::e($base) ?>/sales-control/closes?account_id=<?= $accountId ?>&year=<?= (int) $year ?>"><strong>Ver evidencia y cierres</strong><span>Historial inmutable de revisiones cerradas.</span></a>
  </section>
  <?php if (in_array((string)$monthRow['state'], ['sales_verified'], true)): ?>
  <section class="sales-conclusion">
    <div><h2>Cerrar el mes</h2><p>El cierre requiere dos comprobaciones completas e iguales y ninguna diferencia fiscal pendiente.</p></div>
    <form method="post" action="<?= View::e($base) ?>/sales-control/close" data-confirm="El cierre guardará evidencia inmutable. ¿Desea continuar?">
      <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="account_id" value="<?= $accountId ?>"><input type="hidden" name="company_id" value="<?= $companyId ?>"><input type="hidden" name="year" value="<?= (int)$year ?>"><input type="hidden" name="month" value="<?= (int)$month ?>"><button class="btn primary" type="submit">Cerrar con evidencia</button>
    </form>
  </section>
  <?php elseif ((string)$monthRow['state'] === 'closed'): ?>
  <section class="sales-conclusion">
    <div><h2>Mes cerrado</h2><p>Para hacer una corrección se conservará este cierre y se abrirá una nueva revisión.</p></div>
    <form method="post" action="<?= View::e($base) ?>/sales-control/reopen">
      <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="account_id" value="<?= $accountId ?>"><input type="hidden" name="company_id" value="<?= $companyId ?>"><input type="hidden" name="year" value="<?= (int)$year ?>"><input type="hidden" name="month" value="<?= (int)$month ?>">
      <label for="reopen-reason">Motivo de reapertura</label><input id="reopen-reason" name="reason" required minlength="10" maxlength="500"><button class="btn" type="submit">Crear nueva revisión</button>
    </form>
  </section>
  <?php endif; ?>
<?php endif; ?>
