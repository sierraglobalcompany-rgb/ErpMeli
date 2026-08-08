<?php

use App\Core\Csrf;
use App\Core\View;

$manualPause = is_array($manualPause ?? null) ? $manualPause : ['available' => false, 'active' => false, 'pauses' => []];
$pauseAccounts = is_array($pauseAccounts ?? null) ? $pauseAccounts : [];
$apiPauseBase = (string) ($apiPauseBase ?? '');
?>
<section class="panel api-emergency-panel" aria-labelledby="api-emergency-title">
  <header class="panel-head">
    <div>
      <span class="eyebrow">Control de emergencia</span>
      <h2 id="api-emergency-title">Pausar consultas a Mercado Libre</h2>
      <p>Detiene las salidas API, incluida la renovación automática de autorización. Los webhooks continúan guardándose y las colas conservan su progreso.</p>
    </div>
    <span class="badge <?= !empty($manualPause['active']) ? 'amber' : 'green' ?>">
      <?= !empty($manualPause['active']) ? 'Pausa activa' : 'Consultas habilitadas' ?>
    </span>
  </header>

  <?php if (empty($manualPause['available'])): ?>
    <div class="alert warning">Complete la migración 086 para habilitar el control de emergencia.</div>
  <?php else: ?>
    <?php if (!empty($manualPause['pauses'])): ?>
      <div class="human-list api-active-pauses">
      <?php foreach ($manualPause['pauses'] as $pause): ?>
        <article class="human-list-item">
          <div>
            <strong><?= View::e(($pause['scope'] ?? '') === 'app' ? 'Todas las cuentas' : (string) ($pause['account_name'] ?? 'Cuenta Mercado Libre')) ?></strong>
            <p><?= View::e((string) ($pause['reason'] ?? 'Pausa preventiva')) ?> · <?= empty($pause['paused_until']) ? 'Sin reactivación automática' : 'Se reactiva: ' . View::e((string) $pause['paused_until']) . ' UTC' ?></p>
          </div>
          <form method="post" action="<?= View::e($apiPauseBase) ?>/settings/api-health/resume" data-confirm="¿Reanudar las consultas de este alcance?">
            <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
            <input type="hidden" name="pause_id" value="<?= (int) $pause['id'] ?>">
            <input type="hidden" name="reason" value="Reanudación confirmada por administración">
            <button class="btn primary" type="submit">Reanudar</button>
          </form>
        </article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form class="api-pause-form" method="post" action="<?= View::e($apiPauseBase) ?>/settings/api-health/pause">
      <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
      <div class="field">
        <label for="api-pause-account">Alcance</label>
        <select class="input" id="api-pause-account" name="account_id">
          <option value="0">Todas las cuentas</option>
          <?php foreach ($pauseAccounts as $account): ?>
            <option value="<?= (int) $account['id'] ?>"><?= View::e((string) $account['account_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="api-pause-duration">Duración</label>
        <select class="input" id="api-pause-duration" name="duration">
          <option value="15">15 minutos</option>
          <option value="60">1 hora</option>
          <option value="240">4 horas</option>
          <option value="1440">24 horas</option>
          <option value="indefinite">Indefinidamente</option>
        </select>
      </div>
      <div class="field api-pause-reason">
        <label for="api-pause-reason">Motivo</label>
        <input class="input" id="api-pause-reason" name="reason" maxlength="500" value="Pausa preventiva solicitada por administración">
      </div>
      <button class="btn danger" type="submit" data-confirm="Durante la pausa no se enviarán consultas ni se renovarán tokens. Los eventos entrantes seguirán guardándose.">Pausar consultas</button>
    </form>
  <?php endif; ?>
</section>
