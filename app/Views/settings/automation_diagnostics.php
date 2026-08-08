<?php
use App\Core\Env;
use App\Core\View;
$base = rtrim(Env::get('APP_URL', ''), '/');
$automationTab = 'diagnostics';
require __DIR__ . '/_automation_nav.php';
?>
<div class="page-head"><div><span class="eyebrow">Automatización</span><h1>Diagnóstico de colas</h1><p>Comprueba qué fuentes existen y cuáles no pudieron evaluarse, sin reclamar trabajos.</p></div></div>
<section class="metrics">
  <article class="metric-card compact"><div><div class="metric-label">Pendientes</div><div class="metric-value"><?= (int)($summary['pending']??0) ?></div></div></article>
  <article class="metric-card compact"><div><div class="metric-label">Ejecutándose</div><div class="metric-value"><?= (int)($summary['running']??0) ?></div></div></article>
  <article class="metric-card compact"><div><div class="metric-label">Esperando presupuesto</div><div class="metric-value"><?= (int)($summary['waiting_budget']??0) ?></div></div></article>
  <article class="metric-card compact"><div><div class="metric-label">Errores activos</div><div class="metric-value"><?= (int)($summary['errors']??0) ?></div></div></article>
</section>
<?php if (!empty($adapterHealth)): ?>
<section class="panel">
  <header class="panel-head"><div><h2>Lectura de cada cola</h2><p>Indica si el ERP pudo comprobarla. Un fallo aquí no significa que la cola esté vacía.</p></div></header>
  <div class="human-list">
    <?php foreach ($adapterHealth as $health): $healthy = ($health['status'] ?? '') === 'healthy'; ?>
      <article class="human-list-item">
        <div>
          <strong><?= View::e($definitions[$health['queue_key']]['label'] ?? $health['queue_key']) ?></strong>
          <p><?= $healthy ? 'Comprobada correctamente.' : View::e((string) ($health['safe_error_message'] ?? 'No se pudo comprobar.')) ?></p>
          <?php if (!$healthy && !empty($health['diagnostic_id'])): ?><small>Diagnóstico: <code><?= View::e((string) $health['diagnostic_id']) ?></code></small><?php endif; ?>
        </div>
        <span class="badge <?= $healthy ? 'green' : 'red' ?>"><?= $healthy ? 'Correcta' : 'No comprobable' ?></span>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<section class="panel"><header class="panel-head"><div><h2>Inventario de tareas</h2><p>Una cola desconocida se muestra como “No se pudo comprobar”; nunca como vacía.</p></div></header><div class="human-list">
<?php foreach($availability as $key=>$state): $definition=$definitions[$key]??null; ?>
  <article class="human-list-item"><div><strong><?= View::e($definition['label']??$key) ?></strong><p><?= !empty($state['known']) ? ((int)$state['work_count']).' trabajos elegibles' : 'No se pudo comprobar automáticamente' ?><?= !empty($state['oldest_due_at']) ? ' · más antiguo: '.View::e($state['oldest_due_at']) : '' ?></p></div><span class="badge <?= !empty($state['known']) ? ((int)$state['work_count']>0?'amber':'green') : 'gray' ?>"><?= !empty($state['known']) ? ((int)$state['work_count']>0?'Con trabajo':'Sin trabajo vencido') : 'Sin confirmar' ?></span></article>
<?php endforeach; ?></div></section>
