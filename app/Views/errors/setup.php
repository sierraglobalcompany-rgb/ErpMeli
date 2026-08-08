<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$value = static fn (string $key, string $default = ''): string => $escape((string) ($values[$key] ?? $default));
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Instalar ERP Meli</title>
  <link rel="stylesheet" href="<?= $escape($assetBase) ?>/assets/installer.css">
  <link rel="stylesheet" href="<?= $escape($assetBase) ?>/assets/installer-responsive.css?v=2">
  <style>.installer-header>div:last-child,.field{min-width:0}.field-grid{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}@media(max-width:650px){.field-grid{grid-template-columns:minmax(0,1fr)}.installer-header h1,.installer-header p,.security-note,.section-heading p{overflow-wrap:anywhere}}</style>
</head>
<body>
<main class="installer-shell">
  <header class="installer-header">
    <div class="mark" aria-hidden="true">M</div>
    <div>
      <span class="eyebrow">Configuración inicial</span>
      <h1>Preparemos ERP Meli</h1>
      <p>Pruebe la conexión y complete la instalación sin editar archivos manualmente.</p>
    </div>
  </header>

  <?php if ($locked): ?>
    <section class="locked-card" role="alert">
      <span class="status-icon">!</span>
      <div><h2>Instalador bloqueado</h2><p><?= $escape((string) $error) ?></p></div>
    </section>
  <?php else: ?>
    <div class="security-note"><span>✓</span> Los datos se envían por HTTPS. Las contraseñas nunca se vuelven a mostrar.</div>
    <?php if ($error): ?><div class="alert alert-error" role="alert"><?= $escape($error) ?></div><?php endif; ?>

    <form id="installer-form" method="post" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
      <input type="hidden" name="action" id="installer-action" value="install">

      <section class="form-section">
        <div class="section-heading"><span>1</span><div><h2>Base de datos MySQL</h2><p>Ingrese únicamente los datos creados en el panel de Hostinger.</p></div></div>
        <div class="field-grid">
          <label class="field"><span>Nombre de la base</span><input name="db_name" required value="<?= $value('db_name') ?>" placeholder="u123456789_erp_meli"></label>
          <label class="field"><span>Usuario</span><input name="db_user" required value="<?= $value('db_user') ?>" placeholder="u123456789_erp"></label>
          <label class="field field-wide"><span>Contraseña de MySQL</span><input type="password" name="db_pass" autocomplete="new-password" placeholder="Contraseña del usuario MySQL"></label>
        </div>
        <?php $customDatabaseServer = ($values['db_host'] ?? 'localhost') !== 'localhost' || ($values['db_port'] ?? '3306') !== '3306'; ?>
        <label class="advanced-toggle">
          <input type="checkbox" id="toggle-database-advanced" <?= $customDatabaseServer ? 'checked' : '' ?> aria-controls="database-advanced-fields">
          <span><strong>Usar servidor o puerto diferente</strong><small>Normalmente no es necesario: se utilizará localhost y el puerto 3306.</small></span>
        </label>
        <div class="field-grid advanced-fields" id="database-advanced-fields" <?= $customDatabaseServer ? '' : 'hidden' ?>>
          <label class="field"><span>Servidor MySQL</span><input name="db_host" required value="<?= $value('db_host', 'localhost') ?>" placeholder="localhost"></label>
          <label class="field"><span>Puerto</span><input name="db_port" inputmode="numeric" required value="<?= $value('db_port', '3306') ?>" placeholder="3306"></label>
        </div>
        <div class="connection-row">
          <button class="button button-secondary" id="test-connection" type="button"><span class="button-icon">↔</span> Probar conexión</button>
          <output id="connection-status" class="connection-status" aria-live="polite">Aún no se ha probado.</output>
        </div>
      </section>

      <section class="form-section">
        <div class="section-heading"><span>2</span><div><h2>Administrador</h2><p>Será el primer usuario con acceso completo al ERP.</p></div></div>
        <div class="field-grid">
          <label class="field"><span>Nombre</span><input name="admin_name" required value="<?= $value('admin_name') ?>" autocomplete="name" placeholder="Administrador"></label>
          <label class="field"><span>Correo</span><input type="email" name="admin_email" required value="<?= $value('admin_email') ?>" autocomplete="email" placeholder="admin@empresa.com"></label>
          <label class="field"><span>Contraseña</span><input type="password" name="admin_password" minlength="6" required autocomplete="new-password" placeholder="Mínimo 6 caracteres"><small>Incluya una mayúscula, un número y un carácter especial.</small></label>
          <label class="field"><span>Confirmar contraseña</span><input type="password" name="admin_password_confirm" minlength="6" required autocomplete="new-password" placeholder="Repita la contraseña"></label>
        </div>
      </section>

      <details class="optional-section">
        <summary><span>3</span><div><strong>Mercado Libre</strong><small>Opcional: también puede configurarlo después.</small></div><i>+</i></summary>
        <div class="field-grid optional-fields">
          <label class="field"><span>Client ID</span><input name="meli_client_id" value="<?= $value('meli_client_id') ?>" inputmode="numeric" placeholder="ID de la aplicación"></label>
          <label class="field"><span>Client Secret</span><input type="password" name="meli_client_secret" autocomplete="new-password" placeholder="Se guardará cifrado en el entorno"></label>
        </div>
      </details>

      <footer class="install-footer">
        <div><strong>Instalación detectada automáticamente</strong><span><?= $escape($detectedUrl) ?></span></div>
        <button class="button button-primary" type="submit" id="install-button">Instalar ERP <span>→</span></button>
      </footer>
    </form>
  <?php endif; ?>
</main>
<?php if (!$locked): ?><script src="<?= $escape($assetBase) ?>/assets/installer.js" defer></script><?php endif; ?>
</body>
</html>
