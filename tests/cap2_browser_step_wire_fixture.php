<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Test-only physical cURL boundary for the browser-step fixture.
 *
 * The responder receives the real effective URL so paginated responses can
 * echo the requested cursor. Production admission, dispatch and handlers are
 * not replaced.
 */
final class Cap2BrowserStepWire
{
    public static ?\Closure $responder = null;
    public static array $calls = [];
    public static int $status = 0;
    public static string $raw = '';
    public static ?\Closure $onWire = null;
}

function curl_exec(\CurlHandle $handle): string
{
    $url = (string) \curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
    $path = (string) parse_url($url, PHP_URL_PATH);
    if (Cap2BrowserStepWire::$responder === null) {
        throw new \RuntimeException('BROWSER_STEP_WIRE_RESPONDER_MISSING');
    }
    Cap2BrowserStepWire::$calls[] = [
        'path' => $path,
        'meta' => ApiExecutionMetadataContext::current(),
    ];
    $response = (Cap2BrowserStepWire::$responder)($url, $path);
    if (!is_array($response) || count($response) !== 2 || !is_array($response[1] ?? null)) {
        throw new \RuntimeException('BROWSER_STEP_WIRE_RESPONSE_INVALID:' . $path);
    }
    Cap2BrowserStepWire::$status = (int) $response[0];
    Cap2BrowserStepWire::$raw = json_encode($response[1], JSON_THROW_ON_ERROR);
    if (Cap2BrowserStepWire::$onWire !== null) {
        (Cap2BrowserStepWire::$onWire)();
    }
    return Cap2BrowserStepWire::$raw;
}

function curl_getinfo(\CurlHandle $handle, ?int $option = null): mixed
{
    return match ($option) {
        CURLINFO_HTTP_CODE => Cap2BrowserStepWire::$status,
        CURLINFO_SIZE_DOWNLOAD_T => strlen(Cap2BrowserStepWire::$raw),
        default => $option === null ? \curl_getinfo($handle) : \curl_getinfo($handle, $option),
    };
}

function curl_error(\CurlHandle $handle): string
{
    return '';
}
