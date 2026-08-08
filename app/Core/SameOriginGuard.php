<?php

declare(strict_types=1);

namespace App\Core;

final class SameOriginGuard
{
    public static function assertRequest(bool $requireSource = true): void
    {
        $source = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($source === '') {
            $source = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        }
        if ($source === '') {
            if ($requireSource) {
                throw new HttpException(403, 'No se pudo comprobar el origen de la solicitud.');
            }
            return;
        }

        $expected = self::expectedOrigin();
        $actual = self::normalizeOrigin($source);
        if ($expected === null || $actual === null || !hash_equals($expected, $actual)) {
            throw new HttpException(403, 'La solicitud no proviene de esta instalación.');
        }
    }

    public static function expectedOrigin(): ?string
    {
        // APP_URL is the canonical public origin. On shared hosting PHP can
        // receive an internal HTTP_HOST/HTTPS pair from LiteSpeed or another
        // reverse proxy even though the browser submitted the form through
        // the configured HTTPS URL. Trusting the request Host first both
        // rejects that legitimate request and makes Host-header poisoning
        // possible when the proxy does not validate Host.
        //
        // A non-empty but malformed APP_URL must fail closed. It must never
        // silently fall back to request headers.
        $configured = trim((string) Env::get('APP_URL', ''));
        if ($configured !== '') {
            return self::normalizeOrigin($configured);
        }

        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (self::trustedProxy()) {
            $forwardedHost = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
            if ($forwardedHost !== '') {
                $host = $forwardedHost;
            }
        }
        if ($host !== '') {
            return self::normalizeOrigin(self::requestScheme() . '://' . $host);
        }

        return null;
    }

    public static function requestScheme(): string
    {
        if (self::trustedProxy()) {
            $forwarded = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            if (in_array($forwarded, ['http', 'https'], true)) {
                return $forwarded;
            }
        }
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            ? 'https'
            : 'http';
    }

    private static function trustedProxy(): bool
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $configured = trim((string) Env::get('TRUSTED_PROXY_IPS', ''));
        if ($remote === '' || $configured === '') {
            return false;
        }
        $allowed = array_filter(array_map('trim', explode(',', $configured)));
        return in_array($remote, $allowed, true);
    }

    private static function normalizeOrigin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        return $scheme . '://' . $host . ':' . $port;
    }
}
