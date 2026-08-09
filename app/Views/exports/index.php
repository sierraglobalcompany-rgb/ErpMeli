<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$activeReportTab = 'exports';
?>
<div class="page-head"><div><h1>Exportaciones</h1><p>CSV completo y Excel compatible sin librerías externas. PDF real queda para una versión posterior; por ahora usa vista imprimible.</p></div></div>
<?php require dirname(__DIR__) . '/reports/_tabs.php'; ?>
<section class="panel">
  <div class="panel-body">
    <div class="mini-grid">
      <div class="mini-card"><span>Facturación por fechas</span><strong>CSV / Excel</strong><p class="muted">Genere o abra una facturación por fechas y use los botones de exportación.</p><a class="btn" href="<?= View::e($base) ?>/billing/date">Ir a facturación</a></div>
      <div class="mini-card"><span>Vista PDF administrativa</span><strong>Imprimir</strong><p class="muted">Abra el borrador guardado y use “Vista imprimible”.</p></div>
      <div class="mini-card"><span>Seguridad</span><strong>Sin datos runtime</strong><p class="muted">Los exports generados desde navegador no se guardan con tokens ni secretos.</p></div>
    </div>
  </div>
</section>


