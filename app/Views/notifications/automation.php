<?php

use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$automatic = !empty($health['automatic']);
$pending = (int) ($queue['pending'] ?? 0);
$errors = (int) ($queue['errors'] ?? 0);
$recentFailures = is_array($recentFailures ?? null) ? $recentFailures : [];
?>
<div class="page-head">
  <div>
    <h1>Automatización de notificaciones</h1>
    <p>Revise cómo el lanzador único convierte avisos de Mercado Libre en órdenes y actualizaciones locales.</p>
  </div>
  <div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/notifications/health">Volver a salud</a></div>
</div>

<section class="guided-status <?= $automatic ? 'is-success' : ($pending > 0 ? 'is-danger' : 'is-warning') ?>">
  <div>
    <span class="eyebrow">Estado del lanzador único</span>
    <h2><?= $automatic ? 'Automatización funcionando' : ($pending > 0 ? 'Hay trabajo esperando automatización' : 'Automatización pendiente de configurar') ?></h2>
    <p><?= $automatic
        ? ($errors > 0
            ? 'El lanzador único está enviando señales. Hay recursos con error que requieren diagnóstico o reintento controlado.'
            : 'El lanzador único ha enviado al menos dos señales consecutivas y no hay errores pendientes.')
        : 'Configure el lanzador único en Hostinger. Mientras tanto, los trabajos permanecen guardados.' ?></p>
  </div>
  <div class="guided-status-value"><strong><?= $pending ?></strong><span>pendientes</span></div>
</section>

<?php if ($recentFailures !== []): ?>
<section class="panel table-panel">
  <header class="panel-head">
    <div>
      <h2>Diagnóstico de recursos con error</h2>
      <p>Referencias sanitizadas para identificar la causa sin exponer tokens ni datos privados.</p>
    </div>
  </header>
  <div class="table-scroll">
    <table class="data-table">
      <caption class="sr-only">Últimos recursos que no pudieron completarse</caption>
      <thead><tr><th>Cuenta</th><th>Recurso</th><th>Estado</th><th>Etapa</th><th>Motivo seguro</th><th>Diagnóstico</th></tr></thead>
      <tbody>
      <?php foreach ($recentFailures as $failure): ?>
        <tr>
          <td data-label="Cuenta"><?= View::e($failure['account_name'] ?? '—') ?></td>
          <td data-label="Recurso"><?= View::e($failure['resource_type'] ?? '—') ?></td>
          <td data-label="Estado"><?= View::e($failure['status'] ?? '—') ?></td>
          <td data-label="Etapa"><?= View::e($failure['last_error_stage'] ?? '—') ?></td>
          <td data-label="Motivo seguro"><?= View::e($failure['last_error_message'] ?? '—') ?></td>
          <td data-label="Diagnóstico"><code><?= View::e($failure['last_error_diagnostic_id'] ?? '—') ?></code></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<section class="panel automation-setup">
  <header class="panel-head"><div><h2>1. Configure el lanzador único</h2><p>Frecuencia recomendada: cada minuto. Si Hostinger entrega otra frecuencia, el ERP medirá el intervalo real.</p></div></header>
  <label for="notificationCronCommand">Comando</label>
  <div class="copy-field">
    <input id="notificationCronCommand" class="input" readonly value="<?= View::e($notificationCronCommand ?? 'php jobs/process_sync_queue.php') ?>">
    <button type="button" class="btn" data-copy-target="#notificationCronCommand">Copiar comando</button>
  </div>
</section>

<section class="automation-actions-grid">
  <article class="panel">
    <h2>2. Procesar ahora</h2>
    <p>Revise primero el trabajo pendiente y deje que el lanzador único lo procese sin mantener una petición web abierta.</p>
    <a class="btn primary" href="<?= View::e($base) ?>/settings/manual-processing?scope=sales&amp;origin=notifications">Procesar ventas y notificaciones</a>
  </article>
</section>

<section class="panel" data-launcher-monitor aria-live="polite">
  <header class="panel-head"><div><h2>Estado actual</h2><p>Se actualiza después de cada paso.</p></div></header>
  <div class="notification-health-grid">
    <div><span>Pendientes</span><strong data-launcher-pending><?= $pending ?></strong></div>
    <div><span>En ejecución</span><strong data-launcher-running><?= (int) ($queue['running'] ?? 0) ?></strong></div>
    <div><span>Errores</span><strong data-launcher-errors><?= $errors ?></strong></div>
    <div><span>Última señal</span><strong data-launcher-heartbeat><?= View::e($health['worker_last_heartbeat_at'] ?? 'No registrada') ?></strong></div>
  </div>
  <div class="async-section-status" data-launcher-status hidden></div>
</section>
