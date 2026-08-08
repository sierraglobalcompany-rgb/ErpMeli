<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$ready = array_values(array_filter($archives, static fn(array $archive): bool => ($archive['status'] ?? '') === 'ready'));
$labels = [
    'prepared'=>'Preparada','validated'=>'Validada','queued'=>'Preparada para restaurar','restoring'=>'Restaurando',
    'verifying'=>'Verificando','ready_to_switch'=>'Validada; lista para cambiar','completed'=>'Clon verificado y listo','switched'=>'Activa',
    'rolled_back'=>'Revertida','failed'=>'Fallida','cancelled'=>'Cancelada',
];
?>
<div class="page-head">
  <div><span class="eyebrow">Copias · recuperación</span><h1>Restaurar en una base nueva</h1><p>La base activa nunca se sobrescribe. Las dos paradas físicas deben permanecer activas.</p></div>
  <div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/settings/backups">Volver a Copias</a></div>
</div>

<section class="panel notice warning">
  <div><strong>Prepare primero la base vacía en Hostinger</strong><p>Cree una base, un usuario exclusivo y asigne permisos únicamente sobre esa base. Las credenciales se cifran fuera de la carpeta pública y se eliminan al finalizar.</p></div>
</section>

<section class="panel">
  <header class="panel-head"><div><h2>1. Elegir copia y destino</h2><p>La comprobación no modifica la base activa ni consulta Mercado Libre.</p></div></header>
  <?php if (!$ready): ?>
    <div class="empty-state"><strong>No hay una copia verificada disponible.</strong><p>Regrese al Centro y cree una copia completa.</p></div>
  <?php else: ?>
  <form method="post" action="<?= View::e($base) ?>/settings/backups/restore/prepare" class="form-grid">
    <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
    <label>Copia<select class="input" name="backup_id" required><?php foreach ($ready as $archive): ?><option value="<?= (int)$archive['id'] ?>"><?= View::e((string)$archive['requested_at']) ?> · ERP <?= View::e((string)$archive['erp_version']) ?> · <?= number_format((int)$archive['row_count'],0,',','.') ?> filas</option><?php endforeach; ?></select></label>
    <label>Modo<select class="input" name="mode" required><option value="production_recovery">Recuperación de producción</option><option value="diagnostic_clone">Clon de diagnóstico sin tokens</option></select></label>
    <label>Servidor<input class="input" name="db_host" required value="127.0.0.1"></label>
    <label>Puerto<input class="input" name="db_port" required value="3306" inputmode="numeric"></label>
    <label>Base nueva<input class="input" name="db_name" required autocomplete="off"></label>
    <label>Usuario exclusivo<input class="input" name="db_user" required autocomplete="off"></label>
    <label>Contraseña de la base nueva<input class="input" type="password" name="db_password" required autocomplete="new-password"></label>
    <label>Su contraseña administrativa<input class="input" type="password" name="admin_password" required autocomplete="current-password"></label>
    <div><button class="btn primary">Comprobar y preparar restauración</button></div>
  </form>
  <?php endif; ?>
</section>

<section class="panel">
  <header class="panel-head"><div><h2>Restauraciones</h2><p>Los checkpoints permiten continuar después de una interrupción del hosting.</p></div></header>
  <?php if (!$plans): ?><div class="empty-state"><strong>No hay restauraciones.</strong></div><?php else: ?>
  <div class="table-wrap"><table><caption>Planes de restauración</caption><thead><tr><th>Solicitud</th><th>Modo</th><th>Estado</th><th>Progreso</th><th>Acción</th></tr></thead><tbody>
  <?php foreach ($plans as $plan): ?>
    <tr>
      <td>#<?= (int)$plan['id'] ?><br><small><?= View::e((string)$plan['requested_at']) ?></small></td>
      <td><?= $plan['mode']==='diagnostic_clone' ? 'Clon diagnóstico' : 'Producción' ?></td>
      <td><strong><?= View::e($labels[(string)$plan['status']] ?? (string)$plan['status']) ?></strong><?php if ($plan['safe_error_message']): ?><br><small><?= View::e((string)$plan['safe_error_message']) ?></small><?php endif; ?></td>
      <td><?= number_format((int)$plan['rows_restored'],0,',','.') ?> sentencias aprobadas</td>
      <td>
        <?php if (in_array($plan['status'], ['queued','restoring'], true)): ?><form method="post" action="<?= View::e($base) ?>/settings/backups/restore/start"><input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="restore_id" value="<?= (int)$plan['id'] ?>"><label>Confirme su contraseña<input class="input compact" type="password" name="admin_password" autocomplete="current-password" required></label><button class="btn small">Continuar por CLI</button></form><?php endif; ?>
        <?php if ($plan['status']==='ready_to_switch' && $plan['mode']==='production_recovery'): ?>
        <details><summary class="btn primary small">Cambiar ERP</summary><form method="post" action="<?= View::e($base) ?>/settings/backups/restore/switch"><input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><input type="hidden" name="restore_id" value="<?= (int)$plan['id'] ?>"><label>Motivo<input class="input" name="reason" required></label><label>Contraseña administrativa<input class="input" type="password" name="password" required></label><button class="btn primary">Confirmar cambio reversible</button></form></details>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>

<section class="panel">
  <header class="panel-head"><div><h2>Rollback de configuración</h2><p>También está disponible desde <code>recuperar.php</code> si la base nueva no permite abrir el ERP.</p></div></header>
  <details><summary class="btn danger">Volver a la configuración anterior</summary>
    <form method="post" action="<?= View::e($base) ?>/settings/backups/restore/rollback"><input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>"><label>Contraseña administrativa<input class="input" type="password" name="password" required></label><button class="btn danger">Restaurar configuración anterior</button></form>
  </details>
</section>
