<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use Throwable;

final class ReadModelCacheService
{
    private const ENVELOPE_VERSION = 2;

    /**
     * @template T of array
     * @param callable():T $resolver
     * @return array{value:T,cache:string}
     */
    public function rememberArray(string $namespace, string $key, int $ttlSeconds, callable $resolver): array
    {
        $ttlSeconds = max(1, min(3600, $ttlSeconds));
        $staleSeconds = max($ttlSeconds + 5, min(7200, $ttlSeconds * 6));
        $cacheKey = 'erp_meli:' . $namespace . ':' . hash('sha256', $key);

        if (function_exists('apcu_fetch') && filter_var((string) ini_get('apc.enabled'), FILTER_VALIDATE_BOOL)) {
            $success = false;
            $stored = apcu_fetch($cacheKey, $success);
            $cached = $success ? $this->unpack($stored, time()) : null;
            if (is_array($cached) && $cached['fresh']) {
                return ['value' => $cached['value'], 'cache' => 'hit-apcu'];
            }

            $lockKey = $cacheKey . ':refresh-lock';
            $ownsLock = function_exists('apcu_add') && apcu_add($lockKey, 1, max(5, min(30, $ttlSeconds)));
            if (!$ownsLock && is_array($cached)) {
                return ['value' => $cached['value'], 'cache' => 'stale-apcu'];
            }
            if (!$ownsLock) {
                $deadline = microtime(true) + 0.75;
                do {
                    usleep(25_000);
                    $ready = false;
                    $waitingValue = apcu_fetch($cacheKey, $ready);
                    $waitingCached = $ready ? $this->unpack($waitingValue, time()) : null;
                    if (is_array($waitingCached)) {
                        return ['value' => $waitingCached['value'], 'cache' => 'hit-after-wait-apcu'];
                    }
                } while (microtime(true) < $deadline);
            }
            try {
                $resolved = $resolver();
                apcu_store($cacheKey, $this->pack($resolved, $ttlSeconds, $staleSeconds), $staleSeconds);
                return ['value' => $resolved, 'cache' => is_array($cached) ? 'refresh-apcu' : 'miss-apcu'];
            } catch (Throwable $e) {
                if (is_array($cached)) {
                    return ['value' => $cached['value'], 'cache' => 'stale-error-apcu'];
                }
                throw $e;
            } finally {
                if ($ownsLock && function_exists('apcu_delete')) {
                    apcu_delete($lockKey);
                }
            }
        }

        $directory = AppPaths::storage('cache/read-models');
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }
        $namespacePrefix = substr(hash('sha256', $namespace), 0, 16);
        $path = $directory . '/' . $namespacePrefix . '-' . hash('sha256', $cacheKey) . '.json';
        $cached = null;
        try {
            if (is_file($path)) {
                $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                $cached = $this->unpack($decoded, time(), (int) (filemtime($path) ?: 0), $ttlSeconds, $staleSeconds);
                if (is_array($cached) && $cached['fresh']) {
                    return ['value' => $cached['value'], 'cache' => 'hit-file'];
                }
            }
        } catch (Throwable) {
            // Un fallo de caché nunca debe impedir la consulta real.
        }

        $lockPath = $path . '.refresh.lock';
        $lock = $this->acquireFileLock($lockPath, max(10, min(60, $ttlSeconds * 2)));
        if ($lock === null && is_array($cached)) {
            return ['value' => $cached['value'], 'cache' => 'stale-file'];
        }
        if ($lock === null) {
            $waited = $this->waitForFileValue($path, $ttlSeconds, $staleSeconds);
            if (is_array($waited)) {
                return ['value' => $waited, 'cache' => 'hit-after-wait-file'];
            }
        }
        try {
            $resolved = $resolver();
            $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
            file_put_contents(
                $temporary,
                json_encode($this->pack($resolved, $ttlSeconds, $staleSeconds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                LOCK_EX
            );
            @rename($temporary, $path);
            return ['value' => $resolved, 'cache' => is_array($cached) ? 'refresh-file' : 'miss-file'];
        } catch (Throwable $e) {
            if (is_array($cached)) {
                return ['value' => $cached['value'], 'cache' => 'stale-error-file'];
            }
            throw $e;
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
                @unlink($lockPath);
            }
        }
    }

    /** @param array<mixed> $value @return array<string,mixed> */
    private function pack(array $value, int $ttlSeconds, int $staleSeconds): array
    {
        $now = time();
        return [
            '_read_model_cache' => self::ENVELOPE_VERSION,
            'fresh_until' => $now + $ttlSeconds,
            'stale_until' => $now + $staleSeconds,
            'value' => $value,
        ];
    }

    /**
     * @return array{value:array<mixed>,fresh:bool}|null
     */
    private function unpack(
        mixed $stored,
        int $now,
        int $legacyMtime = 0,
        int $legacyTtl = 0,
        int $legacyStale = 0
    ): ?array {
        if (!is_array($stored)) {
            return null;
        }
        if (($stored['_read_model_cache'] ?? null) === self::ENVELOPE_VERSION
            && is_array($stored['value'] ?? null)) {
            if ((int) ($stored['stale_until'] ?? 0) < $now) {
                return null;
            }
            return [
                'value' => $stored['value'],
                'fresh' => (int) ($stored['fresh_until'] ?? 0) >= $now,
            ];
        }
        // Compatibilidad con archivos/APCu creados por releases anteriores.
        if ($legacyMtime > 0 && $legacyMtime < $now - max(1, $legacyStale)) {
            return null;
        }
        return [
            'value' => $stored,
            'fresh' => $legacyMtime === 0 || $legacyMtime >= $now - max(1, $legacyTtl),
        ];
    }

    /** @return resource|null */
    private function acquireFileLock(string $path, int $staleAfterSeconds)
    {
        if (is_file($path) && (int) (filemtime($path) ?: 0) < time() - $staleAfterSeconds) {
            @unlink($path);
        }
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) {
            return null;
        }
        if (@flock($handle, LOCK_EX | LOCK_NB)) {
            @ftruncate($handle, 0);
            fwrite($handle, (string) getmypid());
            @fflush($handle);
            return $handle;
        }
        fclose($handle);
        return null;
    }

    /** @return array<mixed>|null */
    private function waitForFileValue(string $path, int $ttlSeconds, int $staleSeconds): ?array
    {
        $deadline = microtime(true) + 0.75;
        do {
            usleep(25_000);
            try {
                if (!is_file($path)) {
                    continue;
                }
                $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                $cached = $this->unpack(
                    $decoded,
                    time(),
                    (int) (filemtime($path) ?: 0),
                    $ttlSeconds,
                    $staleSeconds
                );
                if (is_array($cached)) {
                    return $cached['value'];
                }
            } catch (Throwable) {
            }
        } while (microtime(true) < $deadline);
        return null;
    }

    public function clear(?string $namespace = null): void
    {
        if (function_exists('apcu_cache_info') && function_exists('apcu_delete')) {
            $info = @apcu_cache_info(false);
            foreach ((array) ($info['cache_list'] ?? []) as $entry) {
                $key = (string) ($entry['info'] ?? $entry['key'] ?? '');
                if ($key === '' || !str_starts_with($key, 'erp_meli:')) {
                    continue;
                }
                if ($namespace === null || str_starts_with($key, 'erp_meli:' . $namespace . ':')) {
                    @apcu_delete($key);
                }
            }
        }
        $directory = AppPaths::storage('cache/read-models');
        if (!is_dir($directory)) {
            return;
        }
        $prefix = $namespace === null ? '*' : substr(hash('sha256', $namespace), 0, 16) . '-*';
        foreach (array_merge(glob($directory . '/' . $prefix . '.json') ?: [], glob($directory . '/' . $prefix . '.refresh.lock') ?: []) as $path) {
            @unlink($path);
        }
    }

    public function garbageCollect(int $maxFiles = 100): int
    {
        $directory = AppPaths::storage('cache/read-models');
        if (!is_dir($directory)) {
            return 0;
        }
        $deleted = 0;
        $now = time();
        foreach (array_slice(glob($directory . '/*') ?: [], 0, max(1, min(1000, $maxFiles))) as $path) {
            $mtime = (int) (filemtime($path) ?: 0);
            $expiredLock = str_ends_with($path, '.refresh.lock') && $mtime < $now - 120;
            $expiredValue = str_ends_with($path, '.json') && $mtime < $now - 7200;
            if (($expiredLock || $expiredValue) && @unlink($path)) {
                $deleted++;
            }
        }
        return $deleted;
    }
}
