<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= \App\Core\View::e($title ?? 'Mantenimiento local') ?> · ERP Meli</title>
  <style>
    body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f3f6fb;color:#10233f;font:16px/1.5 system-ui,sans-serif}
    main{width:min(680px,100%);box-sizing:border-box;padding:32px;background:#fff;border:1px solid #dbe4ef;border-radius:16px;box-shadow:0 16px 50px rgba(16,35,63,.08)}
    strong{color:#9a6700;letter-spacing:.06em;font-size:12px}h1{margin:8px 0 12px;font-size:30px}p{margin:0;color:#5d6d84}
    .actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:24px}
    .btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 16px;border:1px solid #c8d6ea;border-radius:10px;color:#0b4aad;text-decoration:none;font-weight:700;background:#fff}
    .btn.primary{background:#2563eb;border-color:#2563eb;color:#fff}
    .hint{margin-top:16px;padding:12px 14px;border-radius:12px;background:#fff7e6;color:#6f4a00;font-size:14px}
  </style>
</head>
<body><main>
  <strong>PROTECCIÓN DE DATOS</strong>
  <h1><?= \App\Core\View::e($title ?? 'Mantenimiento local') ?></h1>
  <p><?= \App\Core\View::e($message ?? 'Intente nuevamente en unos momentos.') ?></p>
  <?php $actions = is_array($actions ?? null) ? $actions : []; ?>
  <?php if ($actions): ?>
    <nav class="actions" aria-label="Acciones de recuperación">
      <?php foreach ($actions as $action): ?>
        <?php
          $label = (string) ($action['label'] ?? '');
          $url = (string) ($action['url'] ?? '#');
          if ($label === '') {
              continue;
          }
        ?>
        <a class="btn <?= !empty($action['primary']) ? 'primary' : '' ?>" href="<?= \App\Core\View::e($url) ?>"><?= \App\Core\View::e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <p class="hint">No se consultó Mercado Libre. Esta pantalla solo protege la base mientras usted decide cómo continuar.</p>
  <?php endif; ?>
</main></body>
</html>
