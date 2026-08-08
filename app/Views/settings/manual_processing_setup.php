<?php

use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
?>
<div class="page-head">
  <div>
    <p class="eyebrow">PROCESAR AHORA</p>
    <h1>Campañas dirigidas</h1>
    <p>La pestaña configura y supervisa. El lanzador normal del ERP ejecuta cada paso y conserva el último resultado aprobado.</p>
  </div>
</div>

<section class="panel manual-engine-card is-ready">
  <div class="panel-body">
    <strong>Procesamiento dirigido disponible</strong>
    <p>No necesita instalar nada en su computador ni crear un segundo cron. La campaña utiliza la automatización normal de Hostinger y puede recuperarse si una ejecución es interrumpida.</p>
    <a class="btn primary" href="<?= View::e($base . '/settings/manual-processing') ?>">Abrir Procesar ahora</a>
  </div>
</section>
