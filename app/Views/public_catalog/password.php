<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Acceso privado · <?= View::e($catalog['name']) ?></title>
  <link rel="stylesheet" href="<?= View::e(View::asset($base, 'app.css')) ?>&amp;v=2.11.2">
  <link rel="stylesheet" href="<?= View::e(View::asset($base, 'catalog.css')) ?>&amp;v=2.11.2">
</head>
<body class="catalog-public-body">
  <main class="catalog-access-box">
    <h1><?= View::e($catalog['name']) ?></h1>
    <p>Este catálogo está protegido con clave para empleados.</p>
    <?php if ($error = Session::flash('error')): ?><div class="alert danger"><?= View::e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= View::e($base) ?>/catalogo/<?= View::e($catalog['slug']) ?>/password">
      <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
      <input class="input" type="password" name="password" placeholder="Clave de acceso" autofocus>
      <button class="btn primary mt-2">Entrar</button>
    </form>
  </main>
</body>
</html>
