<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;

final class RequestPerformanceFileLogger
{
    private const MAX_BYTES = 524288;

    /** @param array<string,mixed> $context */
    public static function record(float $durationMs, array $context = []): void
    {
        $directory = AppPaths::storage('logs');
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            return;
        }
        $path = $directory . '/slow-web.jsonl';
        $handle = @fopen($path, 'c+b');
        if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            return;
        }
        try {
            $stat = @fstat($handle);
            $size = is_array($stat) ? (int) $stat['size'] : 0;
            if ($size > self::MAX_BYTES) {
                @ftruncate($handle, 0);
                @rewind($handle);
            } else {
                @fseek($handle, 0, SEEK_END);
            }
            $route = (string) (parse_url((string) ($context['route'] ?? ''), PHP_URL_PATH) ?: '');
            $routeKey = preg_replace('/\/\d+(?=\/|$)/', '/:id', $route) ?: '/';
            $routeKey = preg_replace('/[^A-Za-z0-9_\/.:-]/', '', $routeKey) ?: '/';
            $record = [
                'at' => gmdate(DATE_ATOM),
                'duration_ms' => (int) round($durationMs),
                'route_key' => mb_substr($routeKey, 0, 160),
                'route_hash' => hash('sha256', $route),
                'status' => (int) ($context['status'] ?? 0),
                'memory_bytes' => (int) ($context['memory_bytes'] ?? 0),
            ];
            @fwrite($handle, (json_encode($record, JSON_UNESCAPED_SLASHES) ?: '{}') . PHP_EOL);
            @fflush($handle);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }
}
