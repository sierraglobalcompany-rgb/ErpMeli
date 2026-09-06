<?php
declare(strict_types=1);

// Local-only real Router/metadata/Auth/CSRF/controller harness. No guard doubles.
require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/calls_browser_step_wire_fixture.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Controllers\SettingsController;
use App\Services\CallsBrowserStepWire;

K1dSafeTestDatabase::assertGuard((string) getenv('APP_ENV'), (string) getenv('ML_WRITE_ENABLED'), (string) getenv('DB_HOST'), (string) getenv('DB_NAME'));
$root = 'D:/Codex/tmp/erp-meli/calls-20260906/entrypoints';
if (PHP_SAPI !== 'cli-server' || getenv('DB_PORT') !== '33079'
    || getenv('CALLS_ENTRYPOINTS_QA') !== '1'
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Local fixture only.');
}
define('ERP_INSTALLATION_ROOT', (string) getenv('CALLS_ENTRYPOINTS_INSTALL'));
ini_set('session.save_path', $root . '/sessions');
$pdo = K1dSafeTestDatabase::connectExistingFromEnvironment()->pdo();
Session::start();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$browserFixture = getenv('CALLS_READINESS_BROWSER') === '1';
if ($browserFixture && $path === '/asset.php') {
    $asset = (string) ($_GET['path'] ?? '');
    if (!in_array($asset, ['app.css','catalog.css','ux.css','performance.css','app.js','catalog.js','ux.js','performance.js','icons.svg'], true)) {
        http_response_code(404); exit;
    }
    header('Content-Type: ' . (str_ends_with($asset, '.js') ? 'application/javascript' : (str_ends_with($asset, '.svg') ? 'image/svg+xml' : 'text/css')));
    readfile(dirname(__DIR__) . '/public/assets/' . $asset); exit;
}
if ($browserFixture && $path === '/__fixture/expire-readiness') {
    if (isset($_SESSION['_queue_v4_clean_readiness'])) $_SESSION['_queue_v4_clean_readiness']['expires_at'] = time() - 1;
    header('Content-Type: application/json'); echo '{"ok":true}'; exit;
}
if ($browserFixture && $path === '/__fixture/wire-count') {
    $lines = file($root . '/wire.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    header('Content-Type: application/json'); echo json_encode(['count' => count($lines ?: [])]); exit;
}
if ($path === '/__fixture/session') {
    $_SESSION = [];
    $kind = (string) ($_GET['kind'] ?? 'admin');
    $id = match ($kind) { 'operator' => 9017, 'temporary' => 9018, 'foreign' => 9008, default => 9007 };
    if ($kind !== 'anonymous') {
        $query = $pdo->prepare('SELECT id,name,email,role,is_temporary,expires_at FROM users WHERE id=?');
        $query->execute([$id]);
        $_SESSION['user'] = $query->fetch() + ['session_generation' => (new App\Services\SessionGenerationService())->current()];
    }
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    header('Content-Type: application/json');
    echo json_encode(['csrf' => $_SESSION['_csrf']]);
    exit;
}

// Fake physical boundary records even an unexpected call before returning.
CallsBrowserStepWire::$responder = static function (string $url, string $path) use ($root): array {
    file_put_contents($root . '/wire.jsonl', json_encode(['path' => $path]) . "\n", FILE_APPEND | LOCK_EX);
    if ($path === '/users/me' && getenv('CALLS_READINESS_BROWSER') === '1') {
        return [200, ['id' => [1 => 99011, 2 => 99013, 3 => 99012][(int) ($_POST['step_no'] ?? 0)] ?? 0]];
    }
    return [200, ['id' => 78101, 'status' => 'UNANSWERED', 'seller_id' => 99011]];
};
$router = new Router(null, new App\Repositories\RouteMetadataRepository());
// The built-in server supplies the requested virtual path as SCRIPT_NAME;
// production rewrites to the actual front controller before Router dispatch.
$_SERVER['SCRIPT_NAME'] = '/index.php';
// Read the actual route declarations: drift in production registration is tested.
$wanted = ['/settings/manual-processing', '/settings/manual-processing/preview', '/settings/manual-processing/start', '/settings/cron/test', '/settings/cron/work'];
if ($browserFixture) $wanted = array_merge($wanted, ['/settings/cron','/settings/cron/queue-v4.json','/settings/cron/api-risks.json',
    '/settings/cron/queue-v4/readiness','/settings/cron/queue-v4/activate','/settings/cron/queue-v4/stop']);
foreach (file(dirname(__DIR__) . '/public/index.php') as $line) {
    if (preg_match("~^\\\$router->(get|post)\\('([^']+)', \\[SettingsController::class, '([^']+)'\\]\\);~", trim($line), $match)
        && in_array($match[2], $wanted, true)) {
        $router->{$match[1]}($match[2], [SettingsController::class, $match[3]]);
    }
}
try {
    $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (HttpException $error) {
    http_response_code($error->status);
    echo View::e($error->publicMessage);
} catch (Throwable $error) {
    // Same fail-closed HTTP semantics as public/index.php; never expose SQL/token.
    http_response_code(500);
    echo 'No fue posible completar la solicitud.';
    error_log('entrypoints_error_class=' . get_class($error));
}
