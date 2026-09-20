<?php
declare(strict_types=1);

namespace App\Services;

/** Test-only physical wire boundary. cURL init/options and every production fence stay real. */
final class Cap2DomainsWire
{
    public static array $responses = [];
    public static array $calls = [];
    public static int $status = 0;
    public static string $raw = '';
    public static string $curlError = '';
    public static ?\Closure $onWire = null;
}

function curl_exec(\CurlHandle $handle): string|false
{
    $url = \curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
    $path = (string) parse_url($url, PHP_URL_PATH);
    if (!isset(Cap2DomainsWire::$responses[$path])) {
        throw new \RuntimeException('UNEXPECTED_WIRE_PATH:' . $path);
    }
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    Cap2DomainsWire::$calls[] = [
        'path' => $path,
        'query' => $query,
        'meta' => ApiExecutionMetadataContext::current(),
    ];
    [Cap2DomainsWire::$status, $body, Cap2DomainsWire::$curlError] = array_pad(
        Cap2DomainsWire::$responses[$path],
        3,
        ''
    );
    Cap2DomainsWire::$raw = json_encode($body, JSON_THROW_ON_ERROR);
    if (Cap2DomainsWire::$onWire !== null) { (Cap2DomainsWire::$onWire)(); }
    return Cap2DomainsWire::$curlError === '' ? Cap2DomainsWire::$raw : false;
}

function curl_getinfo(\CurlHandle $handle, ?int $option = null): mixed
{
    return match ($option) {
        CURLINFO_HTTP_CODE => Cap2DomainsWire::$status,
        CURLINFO_SIZE_DOWNLOAD_T => strlen(Cap2DomainsWire::$raw),
        default => $option === null ? \curl_getinfo($handle) : \curl_getinfo($handle, $option),
    };
}

function curl_error(\CurlHandle $handle): string { return Cap2DomainsWire::$curlError; }
