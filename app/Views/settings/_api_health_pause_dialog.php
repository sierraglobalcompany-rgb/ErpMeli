<?php

use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$pauseAccounts = is_array($pauseAccounts ?? null) ? $pauseAccounts : [];
?>
<dialog class="dialog api-pause-dialog" id="apiPauseDialog" aria-labelledby="apiPauseDialogTitle">
  <form method="post" action="<?= View::e($base) ?>/settings/api-health/pause">
    <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
    <header class="dialog-head">
      <div><span class="eyebrow">Protección manual</span><h2 id="apiPauseDialogTitle">Pausar consultas</h2></div>
      <button class="icon-button" type="button" data-dialog-close aria-label="Cerrar">×</button>
    </header>
    <div class="dialog-body">
      <fieldset class="api-pause-fieldset">
        <legend>1. Alcance de la pausa</legend>
        <div class="field">
          <label for="api-pause-account">Cuenta</label>
          <select class="input" id="api-pause-account" name="account_id">
            <option value="0">Todas las cuentas</option>
            <?php foreach ($pauseAccounts as $account): ?>
              <option value="<?= (int) ($account['id'] ?? 0) ?>"><?= View::e((string) ($account['name'] ?? $account['account_name'] ?? 'Cuenta Mercado Libre')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </fieldset>
      <fieldset class="api-pause-fieldset">
        <legend>2. Duración</legend>
        <div class="field">
          <label for="api-pause-duration">Tiempo de protección</label>
          <select class="input" id="api-pause-duration" name="duration">
            <option value="15">15 minutos</option>
            <option value="60" selected>1 hora</option>
            <option value="240">4 horas</option>
            <option value="1440">24 horas</option>
            <option value="indefinite">Indefinidamente</option>
          </select>
        </div>
      </fieldset>
      <fieldset class="api-pause-fieldset">
        <legend>3. Motivo</legend>
        <div class="field">
          <label for="api-pause-reason">Explique por qué se pausarán las consultas</label>
          <input class="input" id="api-pause-reason" name="reason" maxlength="500" value="Revisión preventiva solicitada por administración">
        </div>
      </fieldset>
      <div class="api-pause-impact">
        <strong>Qué ocurrirá</strong>
        <p>Se detendrán las consultas salientes y las renovaciones OAuth. Los webhooks seguirán guardándose y las colas conservarán su progreso.</p>
      </div>
    </div>
    <footer class="dialog-actions">
      <button class="btn" type="button" data-dialog-close>Cancelar</button>
      <button class="btn danger" type="submit" data-confirm="¿Confirma que desea pausar las consultas de este alcance?">Confirmar pausa</button>
    </footer>
  </form>
</dialog>
