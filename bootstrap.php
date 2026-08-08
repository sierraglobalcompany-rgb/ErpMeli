<?php

declare(strict_types=1);

use App\Core\Env;
use App\Core\AppPaths;
use App\Core\Database;
use App\Core\RuntimeCompatibility;
use App\Core\Session;
use App\Core\HttpException;
use App\Core\SecurityHeaders;
use App\Core\View;
use App\Services\SafeErrorPresenter;
use App\Services\CacheInvalidationService;
use App\Services\RequestPerformanceFileLogger;
use App\Services\FileQueryPerformanceCollector;

$root = defined('ERP_RELEASE_ROOT') ? (string) constant('ERP_RELEASE_ROOT') : __DIR__;
$requestStartedAt = microtime(true);
$vendor = $root . '/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

/*
 * Protección anterior al bootstrap completo.
 *
 * Si falla la compatibilidad, el entorno o el storage, esta respuesta no
 * intenta abrir MariaDB ni cargar el layout general. El manejador completo se
 * instala más abajo, cuando el entorno y el perfil web ya están disponibles.
 */
set_exception_handler(static function (Throwable $error): void {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'El ERP no pudo completar el arranque local.' . PHP_EOL);
        return;
    }
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $basePath = str_ends_with($scriptName, '/public/index.php')
        ? substr($scriptName, 0, -strlen('/public/index.php'))
        : rtrim(str_replace('\\', '/', dirname($scriptName)), '/.');
    $updateUrl = htmlspecialchars(($basePath !== '' ? $basePath : '') . '/actualizar.php', ENT_QUOTES, 'UTF-8');
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    SecurityHeaders::apply(true);
    error_log('ERP_BOOT_FAILURE class=' . $error::class);
    $root = defined('ERP_RELEASE_ROOT') ? (string) constant('ERP_RELEASE_ROOT') : __DIR__;
    $apiStopped = is_file($root . '/PAUSE_MELI_API');
    $automationStopped = is_file($root . '/PAUSE_ERP_AUTOMATION');
    $safety = $apiStopped && $automationStopped
        ? 'Las paradas físicas de Mercado Libre y automatización están activas.'
        : 'No se pudo confirmar una parada completa. Abra el freno de mano antes de continuar.';
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Recuperación local · ERP Meli</title></head>'
        . '<body style="margin:0;background:#f3f6fb;color:#0b1f3a;font-family:system-ui,sans-serif">'
        . '<main style="max-width:720px;margin:8vh auto;padding:32px;background:#fff;border:1px solid #d9e2ef;border-radius:16px">'
        . '<p style="font-weight:800;color:#9a6700">ARRANQUE LOCAL DETENIDO</p>'
        . '<h1>No se pudo cargar el ERP</h1>'
        . '<p>' . htmlspecialchars($safety, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="' . $updateUrl . '" style="display:inline-block;padding:12px 18px;border-radius:9px;background:#1769e0;color:#fff;text-decoration:none;font-weight:800">Abrir actualizador seguro</a></p>'
        . '</main></body></html>';
});

RuntimeCompatibility::assertRunnable();

$configPath = AppPaths::configFile();
Env::load($configPath);
SecurityHeaders::apply();
CacheInvalidationService::consumeWebMarker();
$timezone = Env::get('APP_TIMEZONE', 'America/Bogota');
$timezone = is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
    ? $timezone
    : 'America/Bogota';
date_default_timezone_set($timezone);

if (PHP_SAPI !== 'cli') {
    Database::useProfile('web');
    @ini_set('default_socket_timeout', '3');
    Session::start();
    if (
        strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
        && is_array(Session::get('user'))
    ) {
        Session::releaseReadOnlySnapshot();
    }
    register_shutdown_function(static function () use ($requestStartedAt): void {
        $durationMs = (microtime(true) - $requestStartedAt) * 1000;
        if ($durationMs < 1200) {
            return;
        }
        RequestPerformanceFileLogger::record($durationMs, [
            'route' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'memory_bytes' => memory_get_peak_usage(true),
            'status' => http_response_code(),
        ]);
    });
    if (Env::bool('ERP_QUERY_PROFILE_ENABLED', false)) {
        $queryCollector = new FileQueryPerformanceCollector();
        $queryTrace = $queryCollector->begin((string) ($_SERVER['REQUEST_URI'] ?? ''));
        register_shutdown_function(static function () use ($queryCollector, $queryTrace): void {
            $queryCollector->finish($queryTrace);
        });
    }
}

set_exception_handler(static function (Throwable $error): void {
    $status = $error instanceof HttpException ? $error->status : 500;
    $safeMessage = $error instanceof HttpException && $error->safe
        ? $error->getMessage()
        : 'No fue posible completar la solicitud.';
    $reported = SafeErrorPresenter::report($error, $safeMessage, [
        'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? PHP_SAPI),
        'route' => (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''),
    ]);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $reported['message'] . PHP_EOL);
        return;
    }

    http_response_code(max(400, min(599, $status)));
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
    if (str_contains($accept, 'application/json') || str_ends_with($requestPath, '.json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $reported['message'], 'reference' => $reported['reference']], JSON_UNESCAPED_UNICODE);
        return;
    }

    View::render('errors/500', [
        'errorMessage' => $reported['message'],
        'errorReference' => $reported['reference'],
    ], false);
});

return $root;
