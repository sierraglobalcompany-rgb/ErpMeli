<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use Throwable;

final class CacheInvalidationService
{
    /** @var list<string> */
    private const KNOWN_CACHE_FILES = [
        'api-health-overview.json',
        'dashboard-summary.json',
        'shell-context.json',
        'shell-status.json',
    ];

    public function invalidate(string $reason = 'manual', ?string $release = null): int
    {
        $deleted = $this->clearDirectory(AppPaths::storage('cache'));
        AppSettingsService::clearCache();
        SchemaInspectorService::clearCache();
        (new ReadModelCacheService())->clear();

        $marker = [
            'release' => $release ?: AppVersionService::fileVersion(),
            'reason' => mb_substr($reason, 0, 120),
            'created_at' => gmdate(DATE_ATOM),
            'nonce' => bin2hex(random_bytes(8)),
        ];
        try {
            file_put_contents(
                AppPaths::storage('web-cache-invalidation.json'),
                json_encode($marker, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                LOCK_EX
            );
        } catch (Throwable) {
        }

        if (PHP_SAPI !== 'cli' && function_exists('opcache_reset')) {
            @opcache_reset();
            @unlink(AppPaths::storage('web-cache-invalidation.json'));
        }
        return $deleted;
    }

    public static function consumeWebMarker(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $path = AppPaths::storage('web-cache-invalidation.json');
        if (!is_file($path)) {
            return;
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        @unlink($path);
        AppSettingsService::clearCache();
        SchemaInspectorService::clearCache();
    }

    public static function invalidateKnown(string $reason = 'update', ?string $release = null): int
    {
        $deleted = 0;
        foreach (self::KNOWN_CACHE_FILES as $relative) {
            $path = AppPaths::storage('cache/' . $relative);
            if (is_file($path) && @unlink($path)) {
                $deleted++;
            }
        }
        AppSettingsService::clearCache();
        SchemaInspectorService::clearCache();
        RequestContextService::clear();
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
        try {
            file_put_contents(
                AppPaths::storage('web-cache-invalidation.json'),
                json_encode([
                    'release' => $release ?: AppVersionService::fileVersion(),
                    'reason' => mb_substr($reason, 0, 120),
                    'created_at' => gmdate(DATE_ATOM),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                LOCK_EX
            );
        } catch (Throwable) {
        }
        return $deleted;
    }

    private function clearDirectory(string $directory): int
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
            return 0;
        }
        $deleted = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && @unlink($entry->getPathname())) {
                $deleted++;
            } elseif ($entry->isDir()) {
                @rmdir($entry->getPathname());
            }
        }
        return $deleted;
    }
}
