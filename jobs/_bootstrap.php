<?php

declare(strict_types=1);

use App\Core\AppPaths;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';

function job_try_lock(string $name)
{
    $path = AppPaths::storage('cache/' . preg_replace('/[^a-z0-9_-]/i', '_', $name) . '.lock');
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("No se pudo preparar el directorio privado de locks.");
    }
    $handle = fopen($path, 'c+');
    if (!$handle) {
        throw new RuntimeException("No se pudo abrir el lock del job {$name}.");
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }
    ftruncate($handle, 0);
    fwrite($handle, json_encode([
        'pid' => getmypid(),
        'started_at' => gmdate('c'),
        'job' => $name,
    ], JSON_UNESCAPED_SLASHES) ?: '');
    fflush($handle);
    return $handle;
}

function job_lock(string $name)
{
    $handle = job_try_lock($name);
    if ($handle === null) {
        throw new RuntimeException("El job {$name} ya está en ejecución.");
    }
    return $handle;
}

function job_flush_output(): void
{
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}

function job_completion_state(?bool $set = null): bool
{
    static $completed = false;
    if ($set !== null) {
        $completed = $set;
    }
    return $completed;
}

function job_arg(array $argv, string $name, mixed $default = null): mixed
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--' . $name . '=')) return substr($arg, strlen($name) + 3);
    }
    return $default;
}
