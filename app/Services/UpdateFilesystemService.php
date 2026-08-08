<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class UpdateFilesystemService
{
    private const PRESERVED = [
        'config.env',
        '.env',
        'storage',
        'shared',
        'releases',
        'vendor',
        '.git',
        'PAUSE_MELI_API',
        'PAUSE_ERP_AUTOMATION',
    ];

    public function ensureDirectories(): void
    {
        foreach ([
            AppPaths::updateInbox(),
            AppPaths::updateStaging(),
            AppPaths::updateBackups(),
            AppPaths::releases(),
            AppPaths::sharedRoot() . '/storage',
        ] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new RuntimeException('No fue posible preparar el directorio de actualización: ' . basename($directory));
            }
        }
    }

    public function assertInside(string $path, string $root): string
    {
        $rootReal = realpath($root);
        $pathReal = realpath($path);
        if ($rootReal === false || $pathReal === false) {
            throw new RuntimeException('No fue posible validar una ruta del actualizador.');
        }
        $rootNormalized = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
        $pathNormalized = str_replace('\\', '/', $pathReal);
        if ($pathNormalized !== rtrim($rootNormalized, '/') && !str_starts_with($pathNormalized . '/', $rootNormalized)) {
            throw new RuntimeException('La ruta solicitada está fuera del área segura del actualizador.');
        }
        return $pathReal;
    }

    public function safeReleaseId(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || preg_match('/^[a-z0-9][a-z0-9._-]{2,119}$/', $value) !== 1) {
            throw new RuntimeException('El identificador de release no es válido.');
        }
        return $value;
    }

    public function copyTree(string $source, string $destination, bool $preserveSensitive = true): int
    {
        if (!is_dir($source)) {
            throw new RuntimeException('La carpeta fuente de la actualización no existe.');
        }
        if (!is_dir($destination) && !mkdir($destination, 0770, true) && !is_dir($destination)) {
            throw new RuntimeException('No fue posible crear la carpeta destino.');
        }

        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen(rtrim($source, '/\\')) + 1));
            $first = explode('/', $relative, 2)[0];
            if ($preserveSensitive && in_array($first, self::PRESERVED, true)) {
                continue;
            }
            if ($entry->isLink()) {
                throw new RuntimeException('El paquete contiene enlaces simbólicos y fue bloqueado.');
            }
            $target = $destination . '/' . $relative;
            if ($entry->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0770, true) && !is_dir($target)) {
                    throw new RuntimeException('No fue posible crear un directorio de la release.');
                }
                continue;
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
                throw new RuntimeException('No fue posible preparar un directorio de la release.');
            }
            if (!copy($entry->getPathname(), $target)) {
                throw new RuntimeException('No fue posible copiar un archivo de la release.');
            }
            $count++;
        }
        return $count;
    }

    public function removeTree(string $path, string $allowedRoot): void
    {
        if (!file_exists($path)) {
            return;
        }
        $path = $this->assertInside($path, $allowedRoot);
        if (is_file($path)) {
            if (!unlink($path)) {
                throw new RuntimeException('No fue posible eliminar un archivo temporal.');
            }
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                @unlink($entry->getPathname());
            } else {
                @rmdir($entry->getPathname());
            }
        }
        if (!@rmdir($path) && is_dir($path)) {
            throw new RuntimeException('No fue posible limpiar una carpeta temporal.');
        }
    }

    public function directorySize(string $path): int
    {
        if (!is_dir($path)) {
            return is_file($path) ? (int) filesize($path) : 0;
        }
        $total = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && !$entry->isLink()) {
                $total += $entry->getSize();
            }
        }
        return $total;
    }

    /** @return list<string> */
    public function preservedEntries(): array
    {
        return self::PRESERVED;
    }
}
