<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
$base = rtrim(Env::get('APP_URL', ''), '/');
?>
<div class="page-head"><div><p class="eyebrow">CONFIRMAR CONFIGURACIÓN</p><h1>Revisar capacidad <?= $module === 'automation' ? 'automática' : 'manual' ?></h1></div></div>
<section class="panel">
  <div class="panel-body">
    <p>Revise los valores antes de guardar. Esta acción no inicia consultas ni procesamiento.</p>
    <dl>
      <dt>Presupuesto actual · llamadas API físicas</dt><dd><?= (int) $proposal['before']['current'] ?> → <?= (int) $proposal['current'] ?></dd>
      <dt>Techo permitido · llamadas API físicas</dt><dd><?= (int) $proposal['before']['ceiling'] ?> → <?= (int) $proposal['ceiling'] ?></dd>
    </dl>
    <p>Subir sólo el techo no aumenta el presupuesto actual. Guardar la capacidad no inicia procesamiento ni elimina las protecciones de ejecución.</p>
    <form method="post" action="<?= View::e($base . $action) ?>">
      <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
      <input type="hidden" name="confirmation_nonce" value="<?= View::e($proposal['nonce']) ?>">
      <div class="page-actions">
        <button class="btn" type="submit" name="capacity_action" value="cancel">Cancelar</button>
        <button class="btn primary" type="submit" name="capacity_action" value="confirm">Confirmar</button>
      </div>
    </form>
  </div>
</section>
