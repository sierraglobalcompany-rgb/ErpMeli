<?php

use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$apiHealthSection = 'protection';
$apiHealthHours = (int) ($overview['hours'] ?? 24);
$apiHealthCheckedAt = $overview['checked_at'] ?? null;
$manualPause = $technical['manual_pause'] ?? [];
$emergencyStop = $technical['emergency_stop'] ?? [];
$circuits = $technical['circuits'] ?? [];
$erpWaits = is_array($overview['protection']['erp_waits'] ?? null) ? $overview['protection']['erp_waits'] : [];
$budget = $technical['budget'] ?? [];
$windows = $technical['rows'] ?? [];
$accountCapacity = [];
foreach ($windows as $window) {
    if ((string) ($window['scope'] ?? '') !== 'account') {
        continue;
    }
    $key = (int) ($window['meli_account_id'] ?? 0);
    if ($key <= 0) {
        continue;
    }
    $limit = max(1, (int) ($window['request_limit'] ?? $budget['account_limit'] ?? 90));
    $used = (int) ($window['request_count'] ?? 0);
    if (!isset($accountCapacity[$key]) || $used > $accountCapacity[$key]['used']) {
        $accountCapacity[$key] = [
            'name' => (string) ($window['account_name'] ?? 'Cuenta Mercado Libre'),
            'used' => $used,
            'limit' => $limit,
            'percentage' => min(100, round(($used / $limit) * 100)),
            'next_safe_at' => $window['cooldown_until'] ?? null,
        ];
    }
}
require __DIR__ . '/_api_health_header.php';
require __DIR__ . '/_api_health_nav.php';
?>

<section class="api-protection-summary">
  <div>
    <span class="eyebrow">Protección de consultas</span>
    <h2><?= !empty($emergencyStop['active']) || !empty($manualPause['active']) || $circuits !== [] ? 'Hay protecciones activas' : 'Sin pausas activas' ?></h2>
    <p>Las pausas conservan eventos, trabajos y checkpoints; únicamente detienen consultas salientes.</p>
  </div>
  <button class="btn" type="button" data-dialog-open="apiPauseDialog">Pausar consultas</button>
</section>

<?php if (!empty($emergencyStop['active'])): ?>
<section class="api-command-section">
  <header><div><h2>Bloqueo de emergencia</h2><p>Protección local independiente de Cron y de la base de datos.</p></div></header>
  <div class="api-protection-list">
    <article>
      <div>
        <strong>Consultas a Mercado Libre bloqueadas por mantenimiento</strong>
        <p>El ERP seguirá guardando webhooks y permitiendo consultas locales, pero no abrirá conexiones salientes hacia Mercado Libre.</p>
      </div>
    </article>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($manualPause['pauses'])): ?>
<section class="api-command-section">
  <header><div><h2>Pausas manuales</h2><p>Protecciones solicitadas por administración.</p></div></header>
  <div class="api-protection-list">
    <?php foreach ($manualPause['pauses'] as $pause): ?>
      <article><div><strong><?= View::e(($pause['scope'] ?? '') === 'app' ? 'Todas las cuentas' : (string) ($pause['account_name'] ?? 'Cuenta Mercado Libre')) ?></strong><p><?= View::e((string) ($pause['reason'] ?? 'Pausa preventiva')) ?> · <?= empty($pause['paused_until']) ? 'Sin reactivación automática' : 'Hasta ' . View::e(DateTimePresenter::formatQueue($pause['paused_until'])) ?></p></div><form method="post" action="<?= View::e($base) ?>/settings/api-health/resume" data-confirm="¿Reanudar las consultas de este alcance?"><input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="pause_id" value="<?= (int) $pause['id'] ?>"><input type="hidden" name="reason" value="Reanudación confirmada desde Protección"><button class="btn primary" type="submit">Reanudar</button></form></article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($circuits !== []): ?>
<section class="api-command-section">
  <header><div><h2>Pausas automáticas</h2><p>El ERP aplazó operaciones para proteger la integración.</p></div></header>
  <div class="api-protection-list">
    <?php foreach ($circuits as $circuit): ?>
      <article><div><strong><?= View::e((string) ($circuit['account_name'] ?: 'Toda la aplicación')) ?></strong><p><?= View::e((string) ($circuit['reason'] ?? 'Protección preventiva')) ?> · Disponible <?= View::e(DateTimePresenter::formatQueue($circuit['blocked_until'] ?? null)) ?></p></div><form method="post" action="<?= View::e($base) ?>/settings/api-health/circuit/close" data-confirm="Cerrar esta pausa puede reanudar consultas antes del tiempo recomendado."><input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= (int) $circuit['id'] ?>"><input type="hidden" name="reason" value="Cierre manual desde Protección"><button class="btn" type="submit">Cerrar pausa</button></form></article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($erpWaits !== []): ?>
<section class="api-command-section">
  <header><div><h2>Esperas del ERP</h2><p>Son aplazamientos preventivos antes del transporte. No son incidentes de Mercado Libre.</p></div></header>
  <div class="api-protection-list">
    <?php foreach (array_slice($erpWaits, 0, 20) as $wait): ?>
      <article><div><strong><?= View::e((string) ($wait['operation_label'] ?? 'Consulta aplazada')) ?></strong><p><?= View::e((string) ($wait['safe_message'] ?? 'El ERP continuará cuando exista una ventana segura.')) ?></p></div><a class="btn" href="<?= View::e($base) ?>/settings/cron">Ver Cron</a></article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="api-command-section">
  <header><div><h2>Capacidad utilizada</h2><p>Consumo preventivo por cuenta dentro de la ventana actual.</p></div><a class="btn" href="<?= View::e($base) ?>/settings/api-health/technical">Ver detalles técnicos</a></header>
  <?php if ($accountCapacity === []): ?><p class="api-inline-empty">No hay ventanas de capacidad con actividad para mostrar.</p><?php else: ?>
  <div class="api-capacity-list">
    <?php foreach ($accountCapacity as $capacity): ?>
      <article>
        <div><strong><?= View::e($capacity['name']) ?></strong><span><?= (int) $capacity['used'] ?> de <?= (int) $capacity['limit'] ?> consultas utilizadas</span></div>
        <div class="api-capacity-meter"><span style="width:<?= (int) $capacity['percentage'] ?>%"></span></div>
        <b><?= (int) $capacity['percentage'] ?> %</b>
      </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<?php $pauseAccounts = $accounts; require __DIR__ . '/_api_health_pause_dialog.php'; ?>
