<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
?>
<div class="page-head">
  <div>
    <span class="eyebrow">SOPORTE</span>
    <h1>Diagnóstico del sistema</h1>
    <p>La página ya está disponible. Las comprobaciones profundas se ejecutan de forma independiente.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= View::e($base) ?>/settings/update">Actualizaciones</a>
    <a class="btn" href="<?= View::e($base) ?>/stop/">Freno de mano</a>
  </div>
</div>
<section class="panel">
  <strong>Estado de seguridad leído desde archivos</strong>
  <p>Mercado Libre: <?= (string) ($safety['api'] ?? 'unknown') === 'stopped' ? 'bloqueado' : 'por comprobar' ?> · Automatización: <?= (string) ($safety['automation'] ?? 'unknown') === 'stopped' ? 'detenida' : 'por comprobar' ?>.</p>
</section>
<section data-async-section="system-diagnostic"
         data-url="<?= View::e($base) ?>/settings/diagnostics/section.json"
         aria-live="polite">
  <div data-async-skeleton class="panel async-skeleton is-visible">
    <strong>Comprobando base, migraciones y permisos…</strong>
    <p>Si una comprobación tarda demasiado, se detendrá sin inmovilizar el ERP.</p>
  </div>
  <div data-async-status hidden></div>
  <div data-async-content></div>
</section>
