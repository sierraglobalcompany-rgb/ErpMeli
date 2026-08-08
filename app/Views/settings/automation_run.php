<?php
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;
$base = rtrim(Env::get('APP_URL', ''), '/');
$automationTab = 'history';
require __DIR__ . '/_automation_nav.php';
$items = (array) ($run['items'] ?? []);
?>
<div class="page-head"><div><span class="eyebrow">Automatización · Ciclo</span><h1>Detalle resoluble</h1><p><?= View::e(DateTimePresenter::formatQueue((string) ($run['started_at'] ?? ''))) ?> · <?= View::e((string) ($run['origin'] ?? 'Cron')) ?></p></div><div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/settings/cron/history">Volver al historial</a><a class="btn" href="<?= View::e((string) $run['json_url']) ?>">Ver JSON</a></div></div>

<section class="panel">
  <header class="panel-head"><div><h2>Qué ocurrió</h2><p><?= (int) ($run['selected_count'] ?? 0) ?> funciones reclamadas · <?= (int) ($run['started_count'] ?? 0) ?> funciones iniciadas · <?= (int) ($run['remote_call_count'] ?? 0) ?> transportes HTTP iniciados · <?= (int) ($run['completed_count'] ?? 0) ?> recursos finalizados · <?= (int) ($run['real_error_count'] ?? 0) ?> errores reales.</p></div></header>
  <?php if ($items === []): ?><div class="empty-state"><h3>El ciclo no registró funciones</h3><p>No hay un error ni un aplazamiento que resolver.</p></div>
  <?php else: ?><div class="table-scroll"><table class="data-table responsive-table"><caption>Funciones y recursos del ciclo</caption><thead><tr><th>Estado</th><th>Función y recurso</th><th>Cuenta</th><th>Qué ocurrió</th><th>Transporte</th><th>Diagnóstico</th><th>Próxima oportunidad</th><th>Acción</th></tr></thead><tbody>
  <?php foreach ($items as $item):
      $realError = !empty($item['real_error']);
      $automatic = !empty($item['automatic']);
      $state = $realError ? 'Necesita atención' : ($automatic ? 'Aplazado automáticamente' : 'Procesado');
  ?><tr>
    <td data-label="Estado"><span class="badge <?= $realError ? 'red' : ($automatic ? 'amber' : 'green') ?>"><?= View::e($state) ?></span></td>
    <td data-label="Función y recurso"><strong><?= View::e((string) $item['function_label']) ?></strong><br><small><?= View::e((string) $item['resource_label']) ?></small></td>
    <td data-label="Cuenta"><?= View::e((string) $item['account_label']) ?></td>
    <td data-label="Qué ocurrió"><?= View::e((string) $item['what_happened']) ?></td>
    <td data-label="Transporte"><?= ($item['transport_started'] ?? null) === null ? 'No confirmado' : ((int) $item['transport_started'] === 1 ? 'Sí' : 'No') ?></td>
    <td data-label="Diagnóstico"><code><?= View::e((string) $item['diagnostic_label']) ?></code></td>
    <td data-label="Próxima oportunidad"><?= !empty($item['next_opportunity']) ? View::e(DateTimePresenter::formatQueue((string) $item['next_opportunity'])) : ($automatic ? 'Siguiente ciclo seguro' : '—') ?></td>
    <td data-label="Acción"><a class="btn <?= $realError ? 'primary' : '' ?>" href="<?= View::e((string) $item['detail_url']) ?>"><?= $realError ? 'Ver y resolver' : 'Ver cola' ?></a></td>
  </tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>

<details class="panel mt-2 technical-details"><summary>Ver identificador técnico del ciclo</summary><code><?= View::e((string) ($run['run_token'] ?? '')) ?></code><p class="muted">No se muestran tokens de Mercado Libre, credenciales ni rutas privadas.</p></details>
