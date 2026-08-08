<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;

final class SessionGenerationService
{
    private static ?string $cachedGeneration = null;

    public function current(): string
    {
        if (self::$cachedGeneration !== null) {
            return self::$cachedGeneration;
        }
        $path = AppPaths::storage('session-generation');
        $value = trim((string) @file_get_contents($path));
        return self::$cachedGeneration = preg_match('/^[a-f0-9]{32}$/', $value) === 1 ? $value : '';
    }

    public function rotate(): string
    {
        $directory = AppPaths::storage();
        if (!is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }
        $value = bin2hex(random_bytes(16));
        $path = AppPaths::storage('session-generation');
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($temporary, $value, LOCK_EX) !== false && @rename($temporary, $path)) {
            @chmod($path, 0660);
            self::$cachedGeneration = $value;
            return $value;
        }
        @unlink($temporary);
        return '';
    }
}
