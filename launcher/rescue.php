<?php

declare(strict_types=1);

http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rescate retirado · ERP Meli</title><style>body{font:16px system-ui;background:#f4f7fb;color:#12233f;padding:5vh 1rem}.box{max-width:650px;margin:auto;background:#fff;border:1px solid #dbe4f0;border-radius:16px;padding:2rem}a{display:inline-flex;min-height:44px;align-items:center;padding:0 18px;border-radius:9px;background:#1769e0;color:#fff;font-weight:800;text-decoration:none}</style></head>
<body><main class="box"><h1>Panel heredado retirado</h1><p>Este acceso ya no modifica releases ni mantenimiento mediante una llave aislada. Ingrese normalmente como administrador y use el actualizador seguro, que exige sesión, origen, CSRF y reautenticación.</p><a href="../actualizar.php">Abrir actualizador seguro</a></main></body></html>
