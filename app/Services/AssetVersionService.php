<?php

declare(strict_types=1);

namespace App\Services;

final class AssetVersionService
{
    /** @var array<string,string> */
    private static array $fingerprints = [];

    public static function fingerprint(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        if (isset(self::$fingerprints[$relativePath])) {
            return self::$fingerprints[$relativePath];
        }
        $path = dirname(__DIR__, 2) . '/public/' . ltrim($relativePath, '/');
        $version = AppVersionService::fileVersion();
        $mtime = is_file($path) ? (int) filemtime($path) : 0;
        return self::$fingerprints[$relativePath] = substr(
            hash('sha256', $version . '|' . $relativePath . '|' . $mtime),
            0,
            16
        );
    }
}
