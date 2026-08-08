<?php

declare(strict_types=1);

use App\Core\AppPaths;
use App\Core\Env;
use App\Core\RuntimeCompatibility;
use App\Services\WebhookSpoolService;

if (!defined('ERP_RELEASE_BOOTSTRAPPED')) {
    $installationRoot = dirname(__DIR__);
    if (is_file($installationRoot . '/shared/current-release.json') && is_file($installationRoot . '/launcher/webhook.php')) {
        require $installationRoot . '/launcher/webhook.php';
        return;
    }
}

$root = defined('ERP_RELEASE_ROOT') ? (string) constant('ERP_RELEASE_ROOT') : dirname(__DIR__);
$vendor = $root . '/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $file = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}
RuntimeCompatibility::assertRunnable();
Env::load(AppPaths::configFile());

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['received' => false, 'message' => 'Método no permitido.']);
    exit;
}
$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
if ($contentType !== '' && !str_starts_with($contentType, 'application/json')) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['received' => false, 'message' => 'El webhook requiere contenido JSON.']);
    exit;
}
$raw = file_get_contents('php://input') ?: '';
$spool = new WebhookSpoolService();
$validation = $spool->validateIngress($raw);
if (empty($validation['valid'])) {
    $quarantined = $spool->quarantine($raw, (string) ($validation['reason'] ?? 'invalid_payload'));
    http_response_code((int) ($validation['http_status'] ?? 400));
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'received' => false,
        'terminal' => true,
        'quarantined' => $quarantined,
        'message' => (string) ($validation['message'] ?? 'Notificación inválida.'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$accountValidation = $spool->validateLinkedAccount((int) $validation['user_id']);
if (empty($accountValidation['valid'])) {
    $terminal = !empty($accountValidation['terminal']);
    $quarantined = $terminal
        ? $spool->quarantine($raw, (string) $accountValidation['reason'])
        : false;
    http_response_code((int) $accountValidation['http_status']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'received' => false,
        'terminal' => $terminal,
        'quarantined' => $quarantined,
        'message' => (string) $accountValidation['message'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$spooled = $spool->append($raw);
http_response_code($spooled ? 200 : 503);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'received' => $spooled,
    'duplicate' => false,
    'stored_temporarily' => $spooled,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
