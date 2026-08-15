<?php
use App\Core\Env; use App\Core\View;
$base=rtrim(Env::get('APP_URL',''),'/'); $accountId=(int)$account['id']; $companyId=(int)$account['company_id']; $active='fiscal';
?>
<header class="sales-control-header compact"><div><a class="muted" href="<?= View::e($base) ?>/sales-control/month?account_id=<?= $accountId ?>&year=<?= (int)$year ?>&month=<?= (int)$month ?>">← Volver al mes</a><div class="eyebrow">Datos fiscales</div><h1><?= View::e($monthRow['name']) ?> de <?= (int)$year ?></h1><p>El ERP organiza la información para revisión. No emite facturas ni declara cumplimiento legal.</p></div></header>
<section class="sales-kpi-strip fiscal" aria-label="Preparación fiscal">
 <a href="?account_id=<?= $accountId ?>&year=<?= (int)$year ?>&month=<?= (int)$month ?>&status=ready"><span>Listas o justificadas</span><strong><?= (int)$fiscal['ready'] ?></strong></a>
 <a href="?account_id=<?= $accountId ?>&year=<?= (int)$year ?>&month=<?= (int)$month ?>&status=missing"><span>Faltan datos fiscales</span><strong><?= (int)$fiscal['missing'] ?></strong></a>
 <a href="?account_id=<?= $accountId ?>&year=<?= (int)$year ?>&month=<?= (int)$month ?>&status=credit"><span>Posibles notas crédito</span><strong><?= (int)$fiscal['credit_note'] ?></strong></a>
 <a href="?account_id=<?= $accountId ?>&year=<?= (int)$year ?>&month=<?= (int)$month ?>&status=reconciliation"><span>Requieren conciliación</span><strong><?= (int)$fiscal['reconciliation'] ?></strong></a>
</section>
<?php if (!$run): ?><section class="empty-state"><h2>Primero debe comprobar las ventas</h2><p>Los datos fiscales solo se solicitan para ventas confirmadas dentro de una comprobación exacta.</p></section>
<?php elseif ((int)$fiscal['missing'] > 0): ?>
<section class="sales-conclusion"><div><h2>Datos fiscales pendientes</h2><p>La preparación automática está retirada porque no tiene consumidor vigente. No se crearán trabajos nuevos.</p></div></section>
<?php else: ?><p class="success-note">No hay consultas fiscales pendientes para este mes.</p><?php endif; ?>
<section class="privacy-note"><strong>Protección de datos</strong><p>Esta vista solo muestra cantidades. Los datos personales no se incluyen en logs, ayudas, diagnósticos ni HTML general.</p></section>
