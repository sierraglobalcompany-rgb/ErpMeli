<?php
$safeMessage = \App\Core\View::e($errorMessage ?? 'Ocurrió un error interno controlado.');
$safeReference = \App\Core\View::e($errorReference ?? '');
$base = rtrim(\App\Core\Env::get('APP_URL', ''), '/');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>No se pudo completar la solicitud · ERP Meli</title>
  <style>
    :root{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#10213d;background:#f4f7fb}
    *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px}
    main{width:min(680px,100%);background:#fff;border:1px solid #dbe4f0;border-radius:18px;padding:36px;box-shadow:0 12px 36px rgba(16,33,61,.08)}
    .mark{width:52px;height:52px;display:grid;place-items:center;border-radius:14px;background:#fff3df;color:#9a5b00;font-weight:800;font-size:24px}
    h1{margin:20px 0 10px;font-size:clamp(28px,5vw,40px);line-height:1.08}p{color:#5c6d87;line-height:1.6}
    code{display:inline-block;padding:6px 9px;border-radius:8px;background:#f0f4fa;color:#31415c}
    nav{display:flex;gap:12px;flex-wrap:wrap;margin-top:24px}a{min-height:44px;display:inline-flex;align-items:center;padding:0 18px;border-radius:10px;text-decoration:none;font-weight:700;border:1px solid #cbd7e6;color:#164ea6}
    a.primary{background:#1769e0;color:white;border-color:#1769e0}
  </style>
</head>
<body><main><div class="mark" aria-hidden="true">!</div><h1>No se pudo cargar esta página</h1><p><?= $safeMessage ?></p>
<?php if ($safeReference !== ''): ?><p>Comparta este identificador con soporte: <code><?= $safeReference ?></code></p><?php endif; ?>
<nav><a class="primary" href="<?= \App\Core\View::e($base) ?>/">Volver al inicio</a><a href="<?= \App\Core\View::e($base) ?>/actualizar.php">Abrir actualizador seguro</a></nav></main></body></html>
