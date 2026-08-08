<?php

declare(strict_types=1);

namespace App\Core;

final class SecurityHeaders
{
    public static function apply(bool $recovery = false): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        // Same-origin POSTs use Referer as a standards-based fallback when a
        // browser does not emit Origin. SameOriginGuard still compares only
        // the canonical origin, so the path/query are never trusted. No
        // referrer is disclosed to another origin.
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header(
            $recovery
                ? "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
                : "Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'"
        );
        if (SameOriginGuard::requestScheme() === 'https') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
