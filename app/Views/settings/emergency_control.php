<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
$credential = null;
if (is_string($generatedCredential) && $generatedCredential !== '') {
    try {
        $decoded = json_decode($generatedCredential, true, 8, JSON_THROW_ON_ERROR);
        $credential = is_array($decoded) ? $decoded : null;
    } catch (\Throwable) {
        $credential = null;
    }
}
?>
<div class="page-head">
  <div>
    <span class="eyebrow">Seguridad operativa</span>
    <h1>Acceso de emergencia</h1>
    <p>Prepare una entrada independiente para detener Mercado Libre o la automatización cuando el ERP principal no responda.</p>
  </div>
  <a class="btn secondary" href="<?= View::e($base) ?>/stop/">Abrir freno de mano</a>
</div>

<?php if ($credential !== null): ?>
<section class="panel emergency-credential" role="status" aria-live="polite">
  <span class="eyebrow">Se muestra una sola vez</span>
  <h2>Guarde la credencial en un lugar seguro</h2>
  <dl>
    <div><dt>Usuario</dt><dd><code><?= View::e((string) ($credential['username'] ?? '')) ?></code></dd></div>
    <div><dt>Contraseña</dt><dd><code><?= View::e((string) ($credential['password'] ?? '')) ?></code></dd></div>
  </dl>
  <p>Al salir de esta página la contraseña no podrá recuperarse. Si se pierde, genere una nueva.</p>
</section>
<?php endif; ?>

<section class="panel safety-summary">
  <header class="panel-head">
    <div>
      <h2>Estado actual</h2>
      <p>La lectura proviene de archivos locales y funciona aunque MariaDB esté caída.</p>
    </div>
  </header>
  <div class="compact-status-row">
    <span><strong>Mercado Libre</strong><small><?= View::e($safety['api'] === 'stopped' ? 'Bloqueado' : ($safety['api'] === 'canary' ? 'Canario de una consulta' : 'Disponible')) ?></small></span>
    <span><strong>Automatización</strong><small><?= View::e($safety['automation'] === 'stopped' ? 'Detenida' : 'Disponible') ?></small></span>
    <span><strong>Escrituras remotas</strong><small><?= View::e($safety['writes'] === 'disabled' ? 'Bloqueadas' : 'Requieren revisión') ?></small></span>
  </div>
</section>

<section class="panel">
  <header class="panel-head">
    <div>
      <h2><?= $credentialReady ? 'Rotar o revocar el acceso' : 'Crear acceso de emergencia' ?></h2>
      <p>La contraseña se genera aleatoriamente y se almacena fuera de la carpeta pública con hash seguro.</p>
    </div>
  </header>
  <form method="post" action="<?= View::e($base) ?>/settings/emergency-control/provision" class="form-grid">
    <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
    <label>
      <span>Confirme su contraseña actual de administrador</span>
      <input class="input" type="password" name="password" autocomplete="current-password" required>
    </label>
    <div class="form-actions">
      <button class="btn" type="submit"><?= $credentialReady ? 'Generar una contraseña nueva' : 'Crear acceso seguro' ?></button>
    </div>
  </form>
  <?php if ($credentialReady): ?>
  <form method="post" action="<?= View::e($base) ?>/settings/emergency-control/revoke" class="form-grid">
    <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
    <label>
      <span>Contraseña actual de administrador</span>
      <input class="input" type="password" name="password" autocomplete="current-password" required>
    </label>
    <div class="form-actions">
      <button class="btn danger" type="submit">Revocar acceso de emergencia</button>
    </div>
  </form>
  <?php endif; ?>
</section>
