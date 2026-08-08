<?php

use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$run = $preview['run'];
$missing = (int) $preview['missing_count'];
?>
<div class="page-head">
  <div>
    <a class="muted" href="<?= View::e($base) ?>/sync/audit?account_id=<?= (int) $run['meli_account_id'] ?>&year=<?= (int) $run['period_year'] ?>&month=<?= (int) $run['period_month'] ?>">← Volver a la auditoría</a>
    <div class="eyebrow">Paso 3 de 4 · Reparar</div>
    <h1>Confirmar descarga de faltantes</h1>
    <p>Revise el impacto antes de crear el trabajo.</p>
  </div>
</div>

<nav class="audit-stepper" aria-label="Progreso de la auditoría">
  <span class="done"><b>✓</b> Comprobar</span><span class="done"><b>✓</b> Revisar</span><span class="active"><b>3</b> Reparar</span><span><b>4</b> Verificar</span>
</nav>

<?php if (!empty($preview['existing_job'])): ?>
  <section class="status-hero blue">
    <div class="status-hero-icon" aria-hidden="true">↻</div>
    <div><div class="eyebrow">Trabajo existente</div><h2>Esta reparación ya está en automatización</h2><p>No se creará un trabajo duplicado.</p></div>
    <a class="btn primary" href="<?= View::e($base) ?>/sync/audit/repair?job_id=<?= (int) $preview['existing_job']['id'] ?>">Ver progreso</a>
  </section>
<?php else: ?>
  <section class="status-hero amber">
    <div class="status-hero-icon" aria-hidden="true">!</div>
    <div>
      <div class="eyebrow">Confirmación</div>
      <h2>Se encolarán <?= number_format($missing, 0, ',', '.') ?> órdenes</h2>
      <p>La automatización las consultará gradualmente, respetando pausas y presupuesto preventivo.</p>
    </div>
  </section>

  <section class="panel mt-2">
    <header class="panel-head"><h2>Qué ocurrirá</h2></header>
    <dl class="definition-list">
      <div><dt>Empresa</dt><dd><?= View::e((string) $run['company_name']) ?></dd></div>
      <div><dt>Cuenta</dt><dd><?= View::e((string) $run['account_name']) ?></dd></div>
      <div><dt>Comprobación utilizada</dt><dd>#<?= (int) $run['id'] ?> · <?= View::e(DateTimePresenter::formatQueue($run['completed_at'] ?? null)) ?></dd></div>
      <div><dt>Consultas máximas estimadas</dt><dd><?= number_format((int) $preview['estimated_calls'], 0, ',', '.') ?></dd></div>
      <div><dt>Ciclos aproximados</dt><dd><?= (int) $preview['estimated_cycles'] ?></dd></div>
      <div><dt>Tiempo orientativo</dt><dd><?= (int) $preview['estimated_minutes_min'] ?>–<?= (int) $preview['estimated_minutes_max'] ?> minutos</dd></div>
      <div><dt>Próxima hora segura</dt><dd><?= View::e(DateTimePresenter::formatQueue($preview['next_safe_at'] ?? null)) ?></dd></div>
      <div><dt>Cambios en Mercado Libre</dt><dd>Ninguno. Solo lectura.</dd></div>
    </dl>
    <div class="alert info mt-2">Puede cerrar el navegador después de confirmar. El cron conservará el progreso y no repetirá órdenes completadas.</div>
    <div class="page-actions mt-2">
      <form method="post" action="<?= View::e($base) ?>/sync/audit/repair-real-missing">
        <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
        <input type="hidden" name="run_id" value="<?= (int) $run['id'] ?>">
        <input type="hidden" name="account_id" value="<?= (int) $run['meli_account_id'] ?>">
        <input type="hidden" name="year" value="<?= (int) $run['period_year'] ?>">
        <input type="hidden" name="month" value="<?= (int) $run['period_month'] ?>">
        <button class="btn primary" type="submit">Encolar <?= number_format($missing, 0, ',', '.') ?> órdenes</button>
      </form>
      <a class="btn" href="<?= View::e($base) ?>/sync/audit?account_id=<?= (int) $run['meli_account_id'] ?>&year=<?= (int) $run['period_year'] ?>&month=<?= (int) $run['period_month'] ?>">Cancelar</a>
    </div>
  </section>
<?php endif; ?>
