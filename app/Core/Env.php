<?php

declare(strict_types=1);

namespace App\Core;

final class Env
{
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = self::decodeValue($value);
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return $value !== false ? (string) $value : (self::$values[$key] ?? $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return filter_var(self::get($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOL);
    }

    private static function decodeValue(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2 && $value[0] === "'" && $value[$length - 1] === "'") {
            return substr($value, 1, -1);
        }
        if ($length >= 2 && $value[0] === '"' && $value[$length - 1] === '"') {
            $value = substr($value, 1, -1);
            return (string) preg_replace_callback('/\\\\([\\\\"])/', static fn (array $match): string => $match[1], $value);
        }
        return $value;
    }
}
