<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim(Env::get('APP_URL', ''), '/');
$accessReviews = [];
foreach ($users as $candidate) {
    $temporary = (int) ($candidate['is_temporary'] ?? 0) === 1;
    $activePermanentAdmin = !$temporary
        && (string) ($candidate['role'] ?? '') === 'admin'
        && !empty($candidate['status'])
        && empty($candidate['revoked_at']);
    if (!$activePermanentAdmin) {
        continue;
    }
    $identity = mb_strtolower(trim((string) ($candidate['name'] ?? '') . ' ' . (string) ($candidate['email'] ?? '')));
    $looksLikeTest = preg_match('/(?:^|[\s@._-])(test|prueba|demo|qa|codex)(?:[\s@._-]|$)/u', $identity) === 1;
    $lastLogin = !empty($candidate['last_login_at']) ? strtotime((string) $candidate['last_login_at']) : false;
    $inactive = $lastLogin === false || $lastLogin < strtotime('-90 days');
    if ($looksLikeTest || $inactive) {
        $accessReviews[(int) $candidate['id']] = $looksLikeTest
            ? 'El nombre o correo parece corresponder a pruebas.'
            : 'No registra actividad reciente.';
    }
}
?>
<div class="page-head"><div><h1>Usuarios</h1><p>Acceso interno, roles y accesos temporales seguros.</p></div></div>

<?php if ($accessReviews !== []): ?>
<section class="human-status-hero is-warning" role="status" aria-labelledby="access-review-title">
  <span class="human-status-mark" aria-hidden="true">!</span>
  <div>
    <h2 id="access-review-title">Revise <?= count($accessReviews) ?> <?= count($accessReviews) === 1 ? 'administrador permanente' : 'administradores permanentes' ?></h2>
    <p>Deshabilite cualquier cuenta que no reconozca. El ERP no modificará estos accesos automáticamente.</p>
  </div>
  <a class="btn" href="#lista-usuarios">Revisar cuentas</a>
</section>
<?php endif; ?>

<?php if (!empty($generatedPassword)): ?>
<section class="panel">
  <div class="alert success">
    Contraseña temporal para <strong><?= View::e($generatedEmail) ?></strong>:
    <code><?= View::e($generatedPassword) ?></code>
    <br><small>Copie este dato ahora. Por seguridad no se volverá a mostrar.</small>
  </div>
</section>
<?php endif; ?>

<section class="panel filter-bar">
  <h2>Crear usuario permanente</h2>
  <form method="post" action="<?= View::e($base) ?>/users">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <div class="form-grid">
      <div class="field"><label>Nombre</label><input class="input" name="name" required></div>
      <div class="field"><label>Correo</label><input class="input" name="email" type="email" required></div>
      <div class="field"><label>Rol</label><select class="input" name="role"><option>consulta</option><option>operador</option><option>admin</option></select></div>
      <div class="field"><label>Contraseña</label><input class="input" name="password" type="password" minlength="6" title="Mínimo 6 caracteres, una mayúscula, un número y un carácter especial" required></div>
      <div class="field"><label>&nbsp;</label><button class="btn primary">Crear usuario</button></div>
    </div>
  </form>
</section>

<section class="panel filter-bar">
  <h2>Accesos temporales</h2>
  <p class="muted">Use estos accesos para soporte o revisión técnica. No pueden ser administradores ni cambiar credenciales sensibles.</p>
  <form method="post" action="<?= View::e($base) ?>/users/temporary">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <div class="form-grid">
      <div class="field"><label>Nombre</label><input class="input" name="name" required></div>
      <div class="field"><label>Correo</label><input class="input" name="email" type="email" required></div>
      <div class="field"><label>Rol temporal</label><select class="input" name="role"><option>consulta</option><option>operador</option></select></div>
      <div class="field"><label>Vence</label><input class="input" name="expires_at" type="datetime-local" required></div>
      <div class="field"><label>Motivo</label><input class="input" name="temporary_reason" maxlength="255" required placeholder="Soporte, revisión técnica..."></div>
      <div class="field"><label>Contraseña opcional</label><input class="input" name="password" type="password" minlength="6" placeholder="Vacío = generar automáticamente"></div>
      <div class="field"><label>&nbsp;</label><button class="btn primary">Crear acceso temporal</button></div>
    </div>
  </form>
</section>

<section class="panel table-panel" id="lista-usuarios">
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Tipo</th><th>Estado</th><th>Vence</th><th>Último ingreso</th><th>Acciones</th></tr></thead><tbody>
  <?php foreach($users as $u): $isTemp=(int)($u['is_temporary']??0)===1; $revoked=!empty($u['revoked_at']); $expired=$isTemp&&!empty($u['expires_at'])&&strtotime((string)$u['expires_at'])<=time(); ?>
    <tr>
      <td><strong><?= View::e($u['name']) ?></strong><?php if($isTemp&&$u['temporary_reason']):?><br><small><?= View::e($u['temporary_reason']) ?></small><?php endif;?><?php if(isset($accessReviews[(int)$u['id']])):?><br><span class="badge amber">Revisar acceso</span><br><small><?= View::e($accessReviews[(int)$u['id']]) ?></small><?php endif;?></td>
      <td><?= View::e($u['email']) ?></td>
      <td><span class="badge blue"><?= View::e($u['role']) ?></span></td>
      <td><span class="badge <?= $isTemp?'amber':'green' ?>"><?= $isTemp?'Temporal':'Permanente' ?></span></td>
      <td><span class="badge <?= (!$u['status']||$revoked||$expired)?'red':'green' ?>"><?= $revoked?'Revocado':($expired?'Expirado':($u['status']?'Activo':'Inactivo')) ?></span></td>
      <td><?= View::e($u['expires_at'] ?: '—') ?></td>
      <td><?= View::e($u['last_login_at'] ?: '—') ?></td>
      <td>
        <?php if($isTemp): ?>
          <form method="post" action="<?= View::e($base) ?>/users/temporary/revoke" class="inline-form">
            <input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="btn small" <?= $revoked?'disabled':'' ?>>Revocar</button>
          </form>
          <form method="post" action="<?= View::e($base) ?>/users/temporary/extend" class="inline-form mt-1">
            <input type="hidden" name="_token" value="<?= Csrf::token() ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input class="input" name="expires_at" type="datetime-local" required>
            <button class="btn small">Extender</button>
          </form>
        <?php else: ?>—<?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>
