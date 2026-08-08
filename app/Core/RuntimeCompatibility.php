<?php

declare(strict_types=1);

namespace App\Core;

final class RuntimeCompatibility
{
    public const MINIMUM_VERSION = '8.3.0';
    public const MAXIMUM_EXCLUSIVE_VERSION = '8.6.0';

    /** @return list<string> */
    public static function requiredExtensions(): array
    {
        return ['curl', 'iconv', 'json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'session'];
    }

    /** @return list<string> */
    public static function missingRequiredExtensions(): array
    {
        return array_values(array_filter(
            self::requiredExtensions(),
            static fn(string $extension): bool => !extension_loaded($extension)
        ));
    }

    /** @return array<string,mixed> */
    public static function snapshot(): array
    {
        $version = self::runtimeVersion();
        $missing = self::missingRequiredExtensions();
        $status = 'ok';
        $message = 'PHP compatible con el rango soportado 8.3.x–8.5.x.';

        if (version_compare($version, self::MINIMUM_VERSION, '<')) {
            $status = 'error';
            $message = 'PHP no soportado. Seleccione PHP 8.3, 8.4 o 8.5.';
        } elseif (version_compare($version, self::MAXIMUM_EXCLUSIVE_VERSION, '>=')) {
            $status = 'warning';
            $message = 'PHP superior al rango probado. Valide la aplicación antes de usar esta versión.';
        } elseif ($missing !== []) {
            $status = 'error';
            $message = 'Faltan extensiones PHP requeridas: ' . implode(', ', $missing) . '.';
        }

        $opcache = 'no disponible';
        if (function_exists('opcache_get_status')) {
            $statusData = @opcache_get_status(false);
            $opcache = is_array($statusData) && !empty($statusData['opcache_enabled']) ? 'activo' : 'inactivo';
        }

        return [
            'version' => $version,
            'version_id' => self::runtimeVersionId($version),
            'sapi' => PHP_SAPI,
            'binary' => PHP_BINARY,
            'loaded_ini' => php_ini_loaded_file() ?: 'ninguno',
            'scanned_ini' => php_ini_scanned_files() ?: 'ninguno',
            'extension_dir' => (string) ini_get('extension_dir'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'supported_range' => '>=8.3 <8.6',
            'status' => $status,
            'message' => $message,
            'required_extensions' => self::requiredExtensions(),
            'missing_required_extensions' => $missing,
            'opcache' => $opcache,
        ];
    }

    public static function assertRunnable(): void
    {
        $runtime = self::snapshot();
        if (($runtime['status'] ?? 'error') !== 'error') {
            return;
        }

        $message = 'ERP Meli no puede iniciar: ' . (string) ($runtime['message'] ?? 'runtime PHP incompatible.');
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message . PHP_EOL);
        } else {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Runtime PHP incompatible</title>';
            echo '<body><h1>ERP Meli no puede iniciar</h1><p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></body></html>';
        }
        exit(78);
    }

    private static function runtimeVersion(): string
    {
        $version = phpversion();
        return $version !== '' ? $version : '0.0.0';
    }

    private static function runtimeVersionId(string $version): int
    {
        $parts = array_map('intval', explode('.', $version));
        return ($parts[0] * 10000) + (($parts[1] ?? 0) * 100) + ($parts[2] ?? 0);
    }
}
