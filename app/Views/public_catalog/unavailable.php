<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Catálogo no disponible</title>
  <link rel="stylesheet" href="<?= View::e(View::asset($base, 'app.css')) ?>&amp;v=2.11.2">
  <link rel="stylesheet" href="<?= View::e(View::asset($base, 'catalog.css')) ?>&amp;v=2.11.2">
</head>
<body class="catalog-public-body">
  <main class="catalog-access-box">
    <h1>Catálogo no disponible</h1>
    <p><?= View::e($message ?? 'El catálogo no está habilitado públicamente o requiere un enlace privado válido.') ?></p>
  </main>
</body>
</html>
