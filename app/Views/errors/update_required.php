<?php

use App\Core\View;

$safeUrl = View::e((string) ($updateUrl ?? 'actualizar.php'));
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Actualización pendiente · ERP Meli</title>
</head>
<body style="margin:0;background:#f3f6fb;color:#0b1f3a;font-family:system-ui,sans-serif">
  <main style="max-width:720px;margin:8vh auto;padding:32px;background:#fff;border:1px solid #d9e2ef;border-radius:16px">
    <p style="font-size:12px;font-weight:800;letter-spacing:.08em;color:#9a6700">ACTUALIZACIÓN PENDIENTE</p>
    <h1>Un administrador debe completar la actualización</h1>
    <p>El ERP no cargará procesos comerciales con archivos y base de datos de versiones diferentes.</p>
    <p>Mercado Libre permanece bloqueado y no se perdió información.</p>
    <a href="<?= $safeUrl ?>" style="display:inline-block;margin-top:12px;padding:12px 18px;border:1px solid #b9c7da;border-radius:9px;color:#1755a9;text-decoration:none;font-weight:800">Abrir actualizador</a>
  </main>
</body>
</html>
