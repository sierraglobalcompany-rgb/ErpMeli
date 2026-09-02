<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/** Autoridad local, sin red ni mutaciones, para el transporte OAuth por CLI. */
final class MeliCliRuntimeCapabilityService
{
    /** @var (\Closure():array<string,bool|string>)|null */
    private readonly ?\Closure $probe;

    public function __construct(?callable $probe = null)
    {
        $this->probe = $probe === null ? null : \Closure::fromCallable($probe);
    }

    /** @return array<string,bool|string> */
    public function inspect(): array
    {
        if ($this->probe !== null) {
            return $this->normalize(($this->probe)());
        }

        $requiredCurlConstants = [
            'CURLOPT_URL', 'CURLOPT_RETURNTRANSFER', 'CURLOPT_TIMEOUT',
            'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_CUSTOMREQUEST', 'CURLOPT_HTTPHEADER',
            'CURLOPT_HEADERFUNCTION', 'CURLOPT_POSTFIELDS', 'CURLINFO_HTTP_CODE',
        ];
        $curlConstants = true;
        foreach ($requiredCurlConstants as $constant) {
            $curlConstants = $curlConstants && defined($constant);
        }

        return $this->normalize([
            'php_version' => PHP_VERSION,
            'cli_sapi' => PHP_SAPI === 'cli',
            'curl_available' => extension_loaded('curl')
                && function_exists('curl_init')
                && function_exists('curl_setopt_array')
                && function_exists('curl_exec')
                && function_exists('curl_getinfo')
                && function_exists('curl_error')
                && $curlConstants,
            'pdo_mysql_available' => extension_loaded('pdo')
                && in_array('mysql', PDO::getAvailableDrivers(), true),
            'json_available' => extension_loaded('json')
                && function_exists('json_encode')
                && function_exists('json_decode'),
            'crypto_available' => extension_loaded('openssl')
                && function_exists('openssl_encrypt')
                && function_exists('openssl_decrypt')
                && function_exists('random_bytes'),
            'fsync_available' => function_exists('fsync'),
        ]);
    }

    /** @param array<string,bool|string> $capabilities */
    public function oauthReady(array $capabilities): bool
    {
        foreach (['cli_sapi', 'curl_available', 'pdo_mysql_available', 'json_available', 'crypto_available', 'fsync_available'] as $key) {
            if (($capabilities[$key] ?? false) !== true) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,bool|string> */
    private function normalize(array $source): array
    {
        return [
            'php_version' => (string) ($source['php_version'] ?? PHP_VERSION),
            'cli_sapi' => ($source['cli_sapi'] ?? false) === true,
            'curl_available' => ($source['curl_available'] ?? false) === true,
            'pdo_mysql_available' => ($source['pdo_mysql_available'] ?? false) === true,
            'json_available' => ($source['json_available'] ?? false) === true,
            'crypto_available' => ($source['crypto_available'] ?? false) === true,
            'fsync_available' => ($source['fsync_available'] ?? false) === true,
        ];
    }
}
