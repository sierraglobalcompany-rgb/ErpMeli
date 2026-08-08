<?php

use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
?>
<div class="page-head">
  <div><h1>Cuentas Mercado Libre</h1><p>OAuth independiente, estado operativo y señales por cuenta.</p></div>
</div>

<section class="panel filter-bar">
  <div class="panel-head">
    <h2>Aplicación de Mercado Libre</h2>
    <span class="badge <?= $oauthConfigured ? 'green' : 'amber' ?>"><?= $oauthConfigured ? 'Configurada' : 'Pendiente' ?></span>
  </div>
  <div class="panel-body">
    <p class="muted">Ingrese las credenciales de la aplicación creada en Mercado Libre Developers. El Client Secret se guarda en <code>config.env</code> y nunca se vuelve a mostrar.</p>
    <form method="post" action="<?= View::e($base) ?>/accounts/settings" class="form-grid mt-2" autocomplete="off">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <div class="field"><label for="meli-client-id">Client ID</label><input class="input" id="meli-client-id" name="client_id" value="<?= View::e($meliClientId) ?>" required autocomplete="off" placeholder="ID de la aplicación"></div>
      <div class="field"><label for="meli-client-secret">Client Secret</label><input class="input" id="meli-client-secret" name="client_secret" type="password" required autocomplete="new-password" placeholder="<?= $oauthConfigured ? 'Ingrese nuevamente para cambiar' : 'Secret de la aplicación' ?>"></div>
      <div class="field"><label for="meli-redirect-uri">URL de redirección</label><input class="input" id="meli-redirect-uri" value="<?= View::e($redirectUri) ?>" readonly></div>
      <div class="field field-action"><button class="btn primary" type="submit"><?= $oauthConfigured ? 'Actualizar configuración' : 'Guardar configuración' ?></button></div>
    </form>
  </div>
</section>

<section class="panel filter-bar">
  <?php if (!$oauthConfigured): ?>
    <div class="alert danger">Antes de conectar una cuenta, guarde el Client ID y el Client Secret de su aplicación.</div>
  <?php endif; ?>
  <form method="post" action="<?= View::e($base) ?>/accounts/connect" class="inline-form">
    <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
    <div class="field"><label for="meli-company">Empresa</label><select class="input" id="meli-company" name="company_id" required><?php foreach($companies as $company):?><option value="<?= (int) $company['id'] ?>"><?= View::e($company['name']) ?></option><?php endforeach;?></select></div>
    <div class="field"><label for="meli-account-name">Nombre interno de la cuenta</label><input class="input" id="meli-account-name" name="account_name" placeholder="Cuenta principal" required></div>
    <button class="btn primary" type="submit" <?= $oauthConfigured ? '' : 'disabled' ?>>Conectar cuenta Mercado Libre</button>
  </form>
</section>

<section class="panel table-panel">
  <div class="table-scroll"><table class="data-table" data-responsive="cards"><caption class="sr-only">Cuentas de Mercado Libre conectadas</caption><thead><tr><th>Cuenta</th><th>Empresa</th><th>Usuario ML</th><th>Nombre ML</th><th>Token vence</th><th>Estado</th><th>Última sincronización</th><th>Órdenes mes</th><th>Sin vincular</th><th>Notificaciones pendientes</th><th>Errores 7 días</th></tr></thead><tbody>
  <?php if (!$accounts): ?><tr><td colspan="11"><div class="empty">No hay cuentas conectadas.</div></td></tr><?php endif; ?>
  <?php foreach ($accounts as $account): ?><tr>
    <td data-label="Cuenta"><strong><?= View::e($account['account_name']) ?></strong></td>
    <td data-label="Empresa"><?= View::e($account['company_name']) ?></td>
    <td data-label="Usuario ML"><?= View::e($account['meli_user_id'] ?: 'Pendiente') ?></td>
    <td data-label="Nombre ML"><?= View::e($account['nickname'] ?: '—') ?></td>
    <td data-label="Token vence"><?= View::e($account['expires_at'] ?: '—') ?></td>
    <td data-label="Estado"><span class="badge <?= $account['status'] === 'conectado' ? 'green' : ($account['status'] === 'error' ? 'red' : 'amber') ?>"><?= View::e($account['status']) ?></span></td>
    <td data-label="Última sincronización"><?= View::e($account['last_sync_at'] ?: 'Nunca') ?></td>
    <td data-label="Órdenes mes"><?= (int) ($account['orders_period'] ?? 0) ?></td>
    <td data-label="Sin vincular"><a class="link" href="<?= View::e($base) ?>/products/unlinked?account_id=<?= (int) $account['id'] ?>"><?= (int) ($account['unlinked_products'] ?? 0) ?></a></td>
    <td data-label="Notificaciones"><?= (int) ($account['pending_webhooks'] ?? 0) ?></td>
    <td data-label="Errores 7 días"><span class="badge <?= (int) ($account['recent_errors'] ?? 0) > 0 ? 'amber' : 'green' ?>"><?= (int) ($account['recent_errors'] ?? 0) ?></span></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
</section>
