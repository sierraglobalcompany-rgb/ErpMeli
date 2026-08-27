<?php

use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
?>
<div class="page-head">
  <div>
    <p class="eyebrow">PROCESAMIENTO MANUAL</p>
    <h1>Campañas dirigidas</h1>
    <p>La pestaña calcula y confirma un paso exacto. La automatización V4 normal permanece separada.</p>
  </div>
</div>

<section class="panel manual-engine-card is-ready">
  <div class="panel-body">
    <strong>Procesamiento dirigido disponible</strong>
    <p>No necesita instalar nada en su computador ni crear un segundo Cron. Cada confirmación ejecuta un solo paso idempotente y termina sin dejar un proceso en segundo plano.</p>
    <a class="btn primary" href="<?= View::e($base . '/settings/manual-processing') ?>">Abrir procesamiento manual</a>
  </div>
</section>
