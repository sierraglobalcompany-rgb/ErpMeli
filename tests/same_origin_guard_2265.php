<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\HttpException;
use App\Core\SameOriginGuard;

$previousAppUrl = $_ENV['APP_URL'] ?? null;
$previousTrustedProxies = $_ENV['TRUSTED_PROXY_IPS'] ?? null;
unset($_ENV['APP_URL'], $_ENV['TRUSTED_PROXY_IPS']);

$assertRejected = static function (callable $callback, string $message): void {
    try {
        $callback();
    } catch (HttpException $error) {
        if ($error->status === 403) {
            return;
        }
    }
    throw new RuntimeException($message);
};

$_SERVER = [
    'HTTPS' => 'on',
    'HTTP_HOST' => 'erp.example.test',
    'HTTP_ORIGIN' => 'https://erp.example.test',
    'REMOTE_ADDR' => '198.51.100.10',
];
SameOriginGuard::assertRequest();

$_SERVER['HTTP_ORIGIN'] = 'http://erp.example.test';
$assertRejected(
    static fn () => SameOriginGuard::assertRequest(),
    'El guard aceptó un esquema distinto.'
);

$_SERVER['HTTP_ORIGIN'] = 'https://erp.example.test:8443';
$assertRejected(
    static fn () => SameOriginGuard::assertRequest(),
    'El guard aceptó un puerto distinto.'
);

$_ENV['TRUSTED_PROXY_IPS'] = '127.0.0.1';
$_SERVER = [
    'HTTPS' => 'on',
    'HTTP_HOST' => 'erp.example.test',
    'HTTP_X_FORWARDED_PROTO' => 'http',
    'HTTP_X_FORWARDED_HOST' => 'attacker.example.invalid',
    'HTTP_ORIGIN' => 'http://attacker.example.invalid',
    'REMOTE_ADDR' => '198.51.100.20',
];
$assertRejected(
    static fn () => SameOriginGuard::assertRequest(),
    'El guard confió cabeceras reenviadas desde un proxy no autorizado.'
);

$_ENV['TRUSTED_PROXY_IPS'] = '127.0.0.1';
$_SERVER = [
    'HTTP_HOST' => '127.0.0.1',
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTP_X_FORWARDED_HOST' => 'erp.example.test:8443',
    'HTTP_ORIGIN' => 'https://erp.example.test:8443',
    'REMOTE_ADDR' => '127.0.0.1',
];
SameOriginGuard::assertRequest();

// Hostinger/LiteSpeed can terminate TLS before PHP. The public APP_URL is
// authoritative even when PHP sees an internal host and plain HTTP.
$_ENV['APP_URL'] = 'https://www.bodegadigitalmedellin.com/erp-meli';
unset($_ENV['TRUSTED_PROXY_IPS']);
$_SERVER = [
    'HTTP_HOST' => 'internal-litespeed.local:8080',
    'HTTPS' => 'off',
    'HTTP_X_FORWARDED_PROTO' => 'http',
    'HTTP_X_FORWARDED_HOST' => 'internal-litespeed.local:8080',
    'HTTP_ORIGIN' => 'https://www.bodegadigitalmedellin.com',
    'HTTP_REFERER' => 'https://www.bodegadigitalmedellin.com/erp-meli/actualizar.php',
    'REMOTE_ADDR' => '10.0.0.42',
];
SameOriginGuard::assertRequest();

// The canonical APP_URL also prevents accepting a forged Host+Origin pair.
$_SERVER['HTTP_HOST'] = 'attacker.example.invalid';
$_SERVER['HTTP_ORIGIN'] = 'https://attacker.example.invalid';
$assertRejected(
    static fn () => SameOriginGuard::assertRequest(),
    'El guard aceptó Host y Origin manipulados aunque APP_URL era canónico.'
);

// A malformed configured origin fails closed instead of trusting headers.
$_ENV['APP_URL'] = 'not-a-public-origin';
$_SERVER['HTTP_HOST'] = 'www.bodegadigitalmedellin.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_ORIGIN'] = 'https://www.bodegadigitalmedellin.com';
$assertRejected(
    static fn () => SameOriginGuard::assertRequest(),
    'El guard ignoró un APP_URL inválido y confió en cabeceras de la solicitud.'
);

if ($previousAppUrl === null) {
    unset($_ENV['APP_URL']);
} else {
    $_ENV['APP_URL'] = $previousAppUrl;
}
if ($previousTrustedProxies === null) {
    unset($_ENV['TRUSTED_PROXY_IPS']);
} else {
    $_ENV['TRUSTED_PROXY_IPS'] = $previousTrustedProxies;
}
echo "PASS same_origin_guard_2265\n";
