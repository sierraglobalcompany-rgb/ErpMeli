<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;

final class UpdateCapabilityService
{
    /** @return array<string,mixed> */
    public function detect(): array
    {
        $shell = function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
        $mysqldump = $shell ? $this->findCommand(PHP_OS_FAMILY === 'Windows' ? 'where mysqldump' : 'command -v mysqldump') : null;
        $symlink = function_exists('symlink') && !in_array('symlink', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
        $storage = AppPaths::sharedRoot();

        return [
            'adapter' => $shell && $mysqldump !== null ? 'vps' : 'shared_hosting',
            'shell' => $shell,
            'mysqldump' => $mysqldump,
            'symlinks' => $symlink,
            'zip' => class_exists(\ZipArchive::class),
            'openssl' => extension_loaded('openssl'),
            'sodium' => extension_loaded('sodium'),
            'disk_free_bytes' => @disk_free_space($storage) ?: 0,
            'disk_total_bytes' => @disk_total_space($storage) ?: 0,
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'php_binary' => PHP_BINARY,
            'opcache' => function_exists('opcache_get_status'),
            'shared_writable' => is_dir($storage) ? is_writable($storage) : is_writable(dirname($storage)),
        ];
    }

    private function findCommand(string $command): ?string
    {
        $output = [];
        $code = 1;
        @exec($command . (PHP_OS_FAMILY === 'Windows' ? ' 2>NUL' : ' 2>/dev/null'), $output, $code);
        if ($code !== 0 || $output === []) {
            return null;
        }
        $value = trim((string) $output[0]);
        return $value !== '' ? $value : null;
    }
}
