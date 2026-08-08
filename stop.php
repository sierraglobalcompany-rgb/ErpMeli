<?php

declare(strict_types=1);

try {
    require_once __DIR__ . '/launcher/entrypoint.php';
    if (erp_dispatch_active_entrypoint(__DIR__, 'stop.php', true)) {
        return;
    }
    require __DIR__ . '/app/Recovery/EmergencyControlKernel.php';
    \App\Recovery\EmergencyControlKernel::run(__DIR__);
} catch (\Throwable $error) {
    $reference = 'STOP-' . gmdate('Ymd-His') . '-'
        . substr(hash('sha256', $error::class . '|' . microtime(true)), 0, 8);
    error_log(
        'ERP_STOP_FAILURE reference=' . $reference
        . ' class=' . preg_replace('/[^A-Za-z0-9_\\\\-]/', '_', $error::class)
    );
    $status = $error instanceof \App\Core\HttpException ? $error->status : 503;
    http_response_code(max(400, min(599, $status)));
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    $installationRoot = defined('ERP_INSTALLATION_ROOT')
        ? rtrim((string) constant('ERP_INSTALLATION_ROOT'), '/\\')
        : __DIR__;
    $apiStopped = is_file($installationRoot . '/PAUSE_MELI_API');
    $automationStopped = is_file($installationRoot . '/PAUSE_ERP_AUTOMATION');
    $state = static fn (bool $stopped): string => $stopped ? 'Detenida' : 'No se pudo comprobar';
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Control seguro · ERP Meli</title><style>'
        . ':root{font-family:Inter,system-ui,sans-serif;color:#0b1f3a;background:#eef3f9}'
        . '*{box-sizing:border-box}body{margin:0;min-height:100vh;padding:20px;display:grid;place-items:center}'
        . 'main{width:min(620px,100%);background:#fff;border:1px solid #d8e2ef;border-radius:18px;padding:28px}'
        . 'h1{margin:.25rem 0 1rem;font-size:clamp(28px,5vw,40px)}p{color:#536780;line-height:1.5}'
        . '.state{display:grid;grid-template-columns:1fr auto;gap:12px;padding:14px 0;border-top:1px solid #e2e9f2}'
        . '.state strong{color:#a62e36}.note{background:#fff6df;border:1px solid #efd28b;border-radius:10px;padding:12px}'
        . '</style></head><body><main><small>CONTROL DE EMERGENCIA</small>'
        . '<h1>El panel necesita revisión</h1>'
        . '<p class="note">El ERP no cargó el panel completo. Las paradas físicas se comprobaron sin abrir MariaDB ni consultar Mercado Libre.</p>'
        . '<div class="state"><span>Mercado Libre</span><strong>' . $state($apiStopped) . '</strong></div>'
        . '<div class="state"><span>Automatización</span><strong>' . $state($automationStopped) . '</strong></div>'
        . '<p>Conserve los marcadores de parada y complete la actualización desde el acceso normal de administración.</p>'
        . '<p><small>Referencia segura: ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') . '</small></p>'
        . '</main></body></html>';
    exit;
}
