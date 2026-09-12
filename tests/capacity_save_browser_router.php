<?php
declare(strict_types=1);

// Focused extension of calls_entrypoints_router's local real-controller harness.
// No Auth, View, policy, telemetry, or authorization doubles.
$root = str_replace('\\', '/', (string) getenv('CAPACITY_SAVE_BROWSER_ROOT'));
if (PHP_SAPI !== 'cli-server' || getenv('CAPACITY_SAVE_BROWSER_QA') !== '1'
    || !(str_starts_with($root, 'D:/Codex/') || str_starts_with($root, 'C:/codex/capacity-save-kiss/'))
    || str_contains($root, '..')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403); exit('Local fixture only.');
}
define('ERP_INSTALLATION_ROOT', (string) getenv('CAPACITY_SAVE_BROWSER_INSTALL'));
define('ERP_SHARED_ROOT', $root . '/shared');
require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/calls_browser_step_wire_fixture.php';
K1dSafeTestDatabase::assertGuard((string) getenv('APP_ENV'), (string) getenv('ML_WRITE_ENABLED'), (string) getenv('DB_HOST'), (string) getenv('DB_NAME'));
K1dSafeTestDatabase::connectExistingFromEnvironment()->pdo();
ini_set('session.save_path', $root . '/sessions');
App\Core\Session::start();
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
register_shutdown_function(static function () use ($root, $path): void {
    // Never log query strings, POST data, cookies, CSRF, or HTML.
    file_put_contents($root . '/requests.jsonl', json_encode([
        'method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'status' => http_response_code(),
    ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
});
if ($path === '/asset.php') {
    $asset = (string) ($_GET['path'] ?? '');
    if (!in_array($asset, ['app.css','catalog.css','ux.css','performance.css','app.js','catalog.js','ux.js','performance.js','icons.svg'], true)) {
        http_response_code(404); exit;
    }
    header('Content-Type: ' . (str_ends_with($asset, '.js') ? 'application/javascript' : (str_ends_with($asset, '.svg') ? 'image/svg+xml' : 'text/css')));
    readfile(dirname(__DIR__) . '/public/assets/' . $asset); exit;
}
// Unrelated shell polling is local-only, as in calls_entrypoints_router.
if ($path === '/shell/snapshot.json') {
    header('Content-Type: application/json'); echo '{"ok":true,"state":"not_attached"}'; exit;
}
if ($path === '/performance/metrics') {
    header('Content-Type: application/json'); echo '{"ok":true}'; exit;
}
App\Services\CallsBrowserStepWire::$responder = static function () use ($root): array {
    file_put_contents($root . '/wire.jsonl', "{\"unexpected_transport\":true}\n", FILE_APPEND | LOCK_EX);
    throw new RuntimeException('CAPACITY_SAVE_UNEXPECTED_TRANSPORT');
};
$router = new App\Core\Router(null, new App\Repositories\RouteMetadataRepository());
$_SERVER['SCRIPT_NAME'] = '/index.php';
$wanted = ['/login', '/settings/cron/rhythm', '/settings/cron/call-budget',
    '/settings/manual-processing', '/settings/manual-processing/call-budget'];
foreach (file(dirname(__DIR__) . '/public/index.php') as $line) {
    if (preg_match("~^\\\$router->(get|post)\\('([^']+)', \\[(SettingsController|AuthController)::class, '([^']+)'\\]\\);~", trim($line), $match)
        && in_array($match[2], $wanted, true)) {
        $router->{$match[1]}($match[2], ['App\\Controllers\\' . $match[3], $match[4]]);
    }
}
// AuthController's real successful-login redirect has a harmless local landing.
$router->get('/', static function (): void {
    App\Core\Auth::requireLogin(); header('Location: /settings/cron/rhythm');
});
try {
    $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (App\Core\HttpException $error) {
    http_response_code($error->status); echo App\Core\View::e($error->publicMessage);
} catch (Throwable $error) {
    http_response_code(500); echo 'No fue posible completar la solicitud.';
    error_log('capacity_browser_error_class=' . get_class($error));
}
