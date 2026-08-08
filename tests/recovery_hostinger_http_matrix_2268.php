<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Csrf;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\SameOriginGuard;
use App\Core\Session;

$originalServer = $_SERVER;
$originalEnv = [
    'APP_URL' => $_ENV['APP_URL'] ?? null,
    'SESSION_SECURE' => $_ENV['SESSION_SECURE'] ?? null,
    'TRUSTED_PROXY_IPS' => $_ENV['TRUSTED_PROXY_IPS'] ?? null,
];
$sessionDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-meli-http-matrix-' . bin2hex(random_bytes(6));
if (!mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
    throw new RuntimeException('No se pudo preparar el almacén de sesión temporal.');
}

/** @param callable():void $callback */
$expect403 = static function (callable $callback, string $label): void {
    try {
        $callback();
    } catch (HttpException $error) {
        if ($error->status === 403) {
            return;
        }
    }
    throw new RuntimeException('FAIL ' . $label);
};

try {
    $_ENV['APP_URL'] = 'https://www.bodegadigitalmedellin.com/erp-meli';
    $_ENV['SESSION_SECURE'] = 'true';
    unset($_ENV['TRUSTED_PROXY_IPS']);

    // Hostinger/LiteSpeed can expose its internal HTTP endpoint to PHP while
    // the browser is operating on the canonical public HTTPS URL.
    $_SERVER = [
        'REQUEST_METHOD' => 'POST',
        'SCRIPT_NAME' => '/erp-meli/actualizar.php',
        'HTTP_HOST' => 'internal-litespeed.local:8080',
        'HTTPS' => 'off',
        'HTTP_X_FORWARDED_PROTO' => 'http',
        'HTTP_X_FORWARDED_HOST' => 'internal-litespeed.local:8080',
        'HTTP_ORIGIN' => 'https://www.bodegadigitalmedellin.com',
        'HTTP_REFERER' => 'https://www.bodegadigitalmedellin.com/erp-meli/actualizar.php',
        'REMOTE_ADDR' => '10.0.0.42',
    ];
    SameOriginGuard::assertRequest(true);

    // Browsers that omit Origin on a same-origin form POST must still work
    // through the Referer permitted by Referrer-Policy: same-origin.
    unset($_SERVER['HTTP_ORIGIN']);
    SameOriginGuard::assertRequest(true);

    $_SERVER['HTTP_REFERER'] = 'https://attacker.example.invalid/form';
    $expect403(
        static fn (): null => SameOriginGuard::assertRequest(true),
        'referer ajeno fue aceptado'
    );

    unset($_SERVER['HTTP_REFERER']);
    $expect403(
        static fn (): null => SameOriginGuard::assertRequest(true),
        'POST sin Origin ni Referer fue aceptado'
    );

    // A forged Host+Origin pair cannot override the configured public origin.
    $_SERVER['HTTP_HOST'] = 'attacker.example.invalid';
    $_SERVER['HTTP_ORIGIN'] = 'https://attacker.example.invalid';
    $expect403(
        static fn (): null => SameOriginGuard::assertRequest(true),
        'Host y Origin manipulados fueron aceptados'
    );

    // CSRF remains bound to one server-side session and survives a normal
    // session id regeneration used by restart_login / Auth::attempt.
    $_SERVER = [
        'REQUEST_METHOD' => 'POST',
        'SCRIPT_NAME' => '/erp-meli/actualizar.php',
        'HTTP_HOST' => 'www.bodegadigitalmedellin.com',
        'HTTPS' => 'on',
        'HTTP_ORIGIN' => 'https://www.bodegadigitalmedellin.com',
        'REMOTE_ADDR' => '198.51.100.40',
    ];
    session_save_path($sessionDirectory);
    session_id('matrix' . bin2hex(random_bytes(10)));
    Session::start();
    $token = Csrf::token();
    Csrf::validate($token);
    Session::regenerate();
    Csrf::validate($token);
    $expect403(
        static fn (): null => Csrf::validate(str_repeat('0', 64)),
        'token CSRF manipulado fue aceptado'
    );
    Session::destroy();

    $securityHeaders = (string) file_get_contents(dirname(__DIR__) . '/app/Core/SecurityHeaders.php');
    if (!str_contains($securityHeaders, "Referrer-Policy: same-origin")) {
        throw new RuntimeException('FAIL la política Referrer no conserva el respaldo same-origin.');
    }
    if (str_contains($securityHeaders, "Referrer-Policy: no-referrer")) {
        throw new RuntimeException('FAIL persiste la política que elimina el Referer del POST.');
    }

    echo json_encode([
        'status' => 'PASS',
        'subpath' => '/erp-meli',
        'tls_termination' => 'accepted_via_canonical_app_url',
        'origin' => 'accepted',
        'referer_fallback' => 'accepted',
        'cross_origin' => 'rejected',
        'missing_source' => 'rejected',
        'csrf_regeneration' => 'preserved',
        'csrf_tamper' => 'rejected',
        'remote_transport' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        Session::destroy();
    }
    foreach (glob($sessionDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($sessionDirectory);
    $_SERVER = $originalServer;
    foreach ($originalEnv as $key => $value) {
        if ($value === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $value;
        }
    }
}
