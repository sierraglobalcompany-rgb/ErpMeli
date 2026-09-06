<?php
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;
$base = rtrim(Env::get('APP_URL', ''), '/');
$automationTab = 'history';
require __DIR__ . '/_automation_nav.php';
$originLabels = ['scheduled_cli' => 'Lanzador automático', 'manual_cli' => 'Ejecución administrativa', 'manual_web' => 'Comprobación desde el ERP'];
$pages = max(1, (int) ceil(max(0, (int) $total) / max(1, (int) $perPage)));
?>
<div class="page-head"><div><span class="eyebrow">ARCHIVO HISTÓRICO</span><h1>Historial heredado V2/V3</h1><p>Estos registros no certifican el estado ni la capacidad actuales V4.</p><a class="btn" href="<?= View::e($base) ?>/settings/cron/history">Volver al historial actual V4</a></div></div>
<?php if ((int) ($legacyNeedsDiagnosis ?? 0) > 0): ?>
<section class="alert warning">
  <strong><?= number_format((int) $legacyNeedsDiagnosis, 0, ',', '.') ?> eventos legacy agrupados para diagnóstico local.</strong>
  Son registros anteriores al corte V3 y no se repetirán como errores rojos por ciclo. Revise el centro de intervención para diagnosticarlos sin consultar Mercado Libre.
  <a class="btn" href="<?= View::e($base) ?>/settings/cron/queue?group=attention&amp;resolution=legacy_needs_diagnosis">Revisar grupo</a>
</section>
<?php endif; ?>
<section class="panel">
  <header class="panel-head"><div><h2><?= number_format((int) $total, 0, ',', '.') ?> ciclos</h2><p>Página <?= (int) $page ?> de <?= $pages ?>.</p></div></header>
  <?php if (!$runs): ?><div class="empty-state"><h3>Todavía no hay ejecuciones proyectadas</h3><p>El historial comenzará a llenarse después de ejecutar Cron.</p></div>
  <?php else: ?><div class="table-scroll"><table class="data-table responsive-table"><caption>Historial resoluble de ciclos de automatización</caption><thead><tr><th>Inicio</th><th>Origen</th><th>Resultado</th><th>Función o recurso</th><th>Transporte</th><th>Próxima oportunidad</th><th>Conteos</th><th>Acción</th></tr></thead><tbody>
  <?php foreach ($runs as $run):
      $error = is_array($run['primary_error'] ?? null) ? $run['primary_error'] : null;
      $hasRealError = (int) ($run['real_error_count'] ?? 0) > 0 && $error !== null;
      $completed = max(0, (int) ($run['completed_count'] ?? 0));
      $automaticDeferred = !$hasRealError && ((int) ($run['deferred_count'] ?? 0) > 0 || (int) ($run['not_started_count'] ?? 0) > 0);
      $statusLabel = $hasRealError ? 'Necesita atención' : ($completed > 0 && $automaticDeferred ? 'Avanzó con aplazamientos' : ($completed > 0 ? 'Avanzó' : ($automaticDeferred ? 'Aplazado automáticamente' : match ((string) ($run['status'] ?? '')) {
          'completed', 'complete', 'success' => 'Completada', 'empty' => 'Sin trabajo listo',
          'running' => 'En ejecución', default => 'Completada parcialmente',
      })));
      $tone = $hasRealError ? 'red' : ($automaticDeferred ? 'amber' : ((string) ($run['status'] ?? '') === 'running' ? 'blue' : 'green'));
  ?><tr>
    <td data-label="Inicio"><?= View::e(DateTimePresenter::formatQueue((string) ($run['started_at'] ?? ''))) ?></td>
    <td data-label="Origen"><?= View::e($originLabels[(string) ($run['origin'] ?? '')] ?? 'Automatización') ?></td>
    <td data-label="Resultado"><span class="badge <?= View::e($tone) ?>"><?= View::e($statusLabel) ?></span></td>
    <td data-label="Función o recurso"><?php if ($error): ?><strong><?= View::e((string) $error['function_label']) ?></strong><br><small><?= View::e((string) $error['resource_label']) ?> · <?= View::e((string) $error['account_label']) ?></small><br><small><?= View::e((string) $error['what_happened']) ?> · Diagnóstico: <?= View::e((string) $error['diagnostic_label']) ?></small><?php else: ?>Sin error real enlazable<?php endif; ?></td>
    <td data-label="Transporte"><?php if ($error): ?><?= ($error['transport_started'] ?? null) === null ? 'No confirmado' : ((int) $error['transport_started'] === 1 ? 'Sí' : 'No') ?><?php else: ?><?= (int) ($run['remote_call_count'] ?? 0) ?> iniciados<?php endif; ?></td>
    <td data-label="Próxima oportunidad"><?= $error && !empty($error['next_opportunity']) ? View::e(DateTimePresenter::formatQueue((string) $error['next_opportunity'])) : ($automaticDeferred ? 'Siguiente ciclo seguro' : '—') ?></td>
    <td data-label="Conteos"><?= (int) ($run['selected_count'] ?? 0) ?> funciones reclamadas · <?= (int) ($run['started_count'] ?? 0) ?> funciones iniciadas · <?= (int) ($run['remote_call_count'] ?? 0) ?> transportes HTTP iniciados · <?= $completed ?> recursos finalizados</td>
    <td data-label="Acción"><a class="btn <?= $hasRealError ? 'primary' : '' ?>" href="<?= View::e((string) $run['run_url']) ?>"><?= $hasRealError ? 'Ver y resolver' : 'Ver ciclo' ?></a></td>
  </tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <?php if ($pages > 1): ?><nav class="pagination" aria-label="Páginas del historial">
    <?php if ($page > 1): ?><a class="btn" href="<?= View::e($base) ?>/settings/cron/history?<?= View::e(http_build_query(['page' => $page - 1, 'per_page' => $perPage])) ?>">Anterior</a><?php endif; ?>
    <span>Página <?= (int) $page ?> de <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn" href="<?= View::e($base) ?>/settings/cron/history?<?= View::e(http_build_query(['page' => $page + 1, 'per_page' => $perPage])) ?>">Siguiente</a><?php endif; ?>
  </nav><?php endif; ?>
</section>
