<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use RuntimeException;
use Throwable;

final class UpdateReleaseService
{
    /** @param array<string,mixed> $manifest */
    public function stage(string $sourceDirectory, array $manifest): string
    {
        $filesystem = new UpdateFilesystemService();
        $filesystem->ensureDirectories();
        $releaseId = $filesystem->safeReleaseId((string) $manifest['release_id']);
        $final = AppPaths::releases() . '/' . $releaseId;
        if (is_dir($final)) {
            $this->verifyReleaseAgainstManifest($final, $manifest);
            return $final;
        }
        $temporary = $final . '.staging-' . bin2hex(random_bytes(5));
        $filesystem->copyTree($sourceDirectory, $temporary, true);
        try {
            $this->verifyReleaseAgainstManifest($temporary, $manifest);
        } catch (Throwable $error) {
            $filesystem->removeTree($temporary, AppPaths::releases());
            throw $error;
        }
        if (!rename($temporary, $final)) {
            $filesystem->removeTree($temporary, AppPaths::releases());
            throw new RuntimeException('No fue posible convertir el staging en una release instalable.');
        }
        return $final;
    }

    /** @return array<string,mixed> */
    public function activate(string $releaseDirectory, array $manifest): array
    {
        $this->verifyReleaseAgainstManifest($releaseDirectory, $manifest);
        $root = AppPaths::installationRoot();
        $shared = $root . '/shared';
        $releaseId = (new UpdateFilesystemService())->safeReleaseId((string) $manifest['release_id']);
        $pointerPath = $shared . '/current-release.json';
        $previous = $this->pointer();
        if ($previous === []) {
            $previous = $this->snapshotLegacyRelease();
        }

        $this->prepareSharedLayout();
        $pointer = [
            'release_id' => $releaseId,
            'version' => (string) $manifest['version'],
            'path' => 'releases/' . $releaseId,
            'previous_release_id' => $previous['release_id'] ?? null,
            'previous_version' => $previous['version'] ?? AppVersionService::fileVersion(),
            'activated_at' => gmdate('c'),
        ];
        $this->writePointerAtomically($pointerPath, $pointer);
        (new CacheInvalidationService())->invalidate('release_activated', (string) $manifest['version']);
        return $pointer;
    }

    /** @return array<string,mixed> */
    public function rollback(): array
    {
        $current = $this->pointer();
        $previousId = (string) ($current['previous_release_id'] ?? '');
        if ($previousId === '') {
            throw new RuntimeException('No hay una release anterior registrada para rollback.');
        }
        $previousPath = AppPaths::releases() . '/' . (new UpdateFilesystemService())->safeReleaseId($previousId);
        $this->verifyReleaseAgainstManifest($previousPath, []);
        $version = trim((string) file_get_contents($previousPath . '/VERSION'));
        $manifest = [
            'release_id' => $previousId,
            'version' => $version,
        ];
        return $this->activate($previousPath, $manifest);
    }

    public function classicOverwrite(string $sourceDirectory): int
    {
        throw new RuntimeException(
            'La sobrescritura clásica fue retirada porque no puede activarse ni revertirse de forma atómica.'
        );
    }

    /** @return array<string,mixed> */
    public function healthCheck(string $releaseDirectory): array
    {
        $checks = [
            'bootstrap' => is_file($releaseDirectory . '/bootstrap.php'),
            'front_controller' => is_file($releaseDirectory . '/public/index.php'),
            'version' => is_file($releaseDirectory . '/VERSION') && trim((string) file_get_contents($releaseDirectory . '/VERSION')) !== '',
            'migrations' => is_dir($releaseDirectory . '/database/migrations'),
            'storage' => is_dir(AppPaths::storage()) && is_writable(AppPaths::storage()),
        ];
        try {
            $checks['database'] = Database::connectionFresh()->query('SELECT 1')->fetchColumn() === 1;
        } catch (Throwable) {
            $checks['database'] = false;
        }
        if ($this->requiresRuntimeManifest($releaseDirectory)) {
            $runtimeIntegrity = (new ReleaseIntegrityService())->inspectDirectory(
                $releaseDirectory,
                true,
                false
            );
            $checks['runtime_integrity'] = (bool) $runtimeIntegrity['ok'];
        }
        return ['ok' => !in_array(false, $checks, true), 'checks' => $checks];
    }

    /** @return array<string,mixed> */
    public function pointer(): array
    {
        $path = AppPaths::installationRoot() . '/shared/current-release.json';
        if (!is_file($path)) {
            return [];
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    public function cleanup(): int
    {
        $retention = max(2, (int) (new AppSettingsService())->get('update.retention_releases', '3'));
        $pointer = $this->pointer();
        $protected = array_filter([
            $pointer['release_id'] ?? null,
            $pointer['previous_release_id'] ?? null,
        ], 'is_string');
        $directories = array_values(array_filter(glob(AppPaths::releases() . '/*') ?: [], 'is_dir'));
        usort($directories, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $deleted = 0;
        $kept = 0;
        foreach ($directories as $directory) {
            if (in_array(basename($directory), $protected, true) || $kept < $retention) {
                $kept++;
                continue;
            }
            (new UpdateFilesystemService())->removeTree($directory, AppPaths::releases());
            $deleted++;
        }
        return $deleted;
    }

    private function prepareSharedLayout(): void
    {
        $root = AppPaths::installationRoot();
        $shared = $root . '/shared';
        if (!is_dir($shared) && !mkdir($shared, 0770, true) && !is_dir($shared)) {
            throw new RuntimeException('No fue posible crear la carpeta compartida.');
        }
        foreach (['update-inbox', 'update-staging', 'update-backups'] as $directory) {
            if (!is_dir($shared . '/' . $directory)) {
                @mkdir($shared . '/' . $directory, 0770, true);
            }
        }
        if (!is_dir($shared . '/storage')) {
            if (is_dir($root . '/storage')) {
                // La instalación clásica debe seguir siendo arrancable hasta
                // que el puntero de release haya sido confirmado. Copiar evita
                // dejarla sin storage si una fase posterior falla.
                (new UpdateFilesystemService())->copyTree($root . '/storage', $shared . '/storage', false);
            } elseif (!mkdir($shared . '/storage', 0770, true) && !is_dir($shared . '/storage')) {
                throw new RuntimeException('No fue posible preparar el storage compartido.');
            }
        }
        if (!is_file($shared . '/config.env')) {
            $source = is_file($root . '/config.env') ? $root . '/config.env' : $root . '/.env';
            if (is_file($source) && !copy($source, $shared . '/config.env')) {
                throw new RuntimeException('No fue posible copiar la configuración privada a shared.');
            }
            @chmod($shared . '/config.env', 0600);
        }
    }

    /** @return array<string,mixed> */
    private function snapshotLegacyRelease(): array
    {
        $root = AppPaths::installationRoot();
        $version = AppVersionService::fileVersion();
        $releaseId = (new UpdateFilesystemService())->safeReleaseId(
            'legacy-' . preg_replace('/[^a-z0-9._-]/i', '-', strtolower($version)) . '-' . gmdate('Ymdhis')
        );
        $destination = AppPaths::releases() . '/' . $releaseId;
        if (!is_dir(AppPaths::releases())) {
            @mkdir(AppPaths::releases(), 0770, true);
        }
        if (!mkdir($destination, 0770, true) && !is_dir($destination)) {
            throw new RuntimeException('No fue posible preparar la copia de recuperación de la instalación actual.');
        }
        $filesystem = new UpdateFilesystemService();
        foreach ([
            '.htaccess', 'app', 'bin', 'database', 'jobs', 'launcher', 'public',
            'resources', 'actualizar.php', 'asset.php', 'index.php', 'login.php', 'mantenimiento.php',
            'recuperar.php', 'stop.php', 'bootstrap.php', 'composer.json', 'composer.lock',
            'config.env.example', 'VERSION',
        ] as $entry) {
            $source = $root . '/' . $entry;
            $target = $destination . '/' . $entry;
            if (is_dir($source)) {
                $filesystem->copyTree($source, $target, false);
            } elseif (is_file($source) && !copy($source, $target)) {
                throw new RuntimeException('No fue posible copiar un archivo de la release de recuperación.');
            }
        }
        $this->assertReleaseShape($destination);
        return [
            'release_id' => $releaseId,
            'version' => $version,
            'path' => 'releases/' . $releaseId,
            'activated_at' => gmdate('c'),
        ];
    }

    /** @param array<string,mixed> $manifest */
    private function verifyReleaseAgainstManifest(string $directory, array $manifest): void
    {
        $this->assertReleaseShape($directory);
        foreach ([
            'PAUSE_MELI_API',
            'PAUSE_ERP_AUTOMATION',
            'config.env',
            '.env',
            'graphify-out',
            'app/graphify-out',
            'bin',
            'tests',
            'docs',
            'shared',
            'storage',
            'vendor',
            'phpstan.neon',
        ] as $forbidden) {
            if (file_exists(rtrim($directory, '/\\') . '/' . $forbidden)) {
                throw new RuntimeException(
                    'La release contiene estado persistente o archivos de desarrollo no permitidos.'
                );
            }
        }
        if (isset($manifest['files']) && is_array($manifest['files'])) {
            (new UpdateManifestService())->verifyFiles($manifest, $directory);
        }
        if (!$this->requiresRuntimeManifest($directory)) {
            return;
        }
        $runtimeIntegrity = (new ReleaseIntegrityService())->inspectDirectory($directory, false, false);
        if (!$runtimeIntegrity['ok']) {
            throw new RuntimeException(
                'La release contiene componentes de runtime ausentes o con checksums diferentes.'
            );
        }
    }

    /** @param array<string,mixed> $pointer */
    private function writePointerAtomically(string $pointerPath, array $pointer): void
    {
        $temporary = $pointerPath . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode(
            $pointer,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('No fue posible escribir el puntero temporal de release.');
        }
        if (!rename($temporary, $pointerPath)) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible activar atómicamente el puntero de release.');
        }
        @chmod($pointerPath, 0640);
    }

    public function startMaintenance(string $targetVersion): void
    {
        $shared = AppPaths::installationRoot() . '/shared';
        if (!is_dir($shared)) {
            @mkdir($shared, 0770, true);
        }
        $payload = [
            'enabled' => true,
            'target_version' => $targetVersion,
            'started_at' => gmdate('c'),
            'message' => 'Actualización segura en progreso.',
        ];
        file_put_contents($shared . '/maintenance.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    }

    public function stopMaintenance(): void
    {
        @unlink(AppPaths::installationRoot() . '/shared/maintenance.json');
    }

    private function assertReleaseShape(string $directory): void
    {
        foreach (['bootstrap.php', 'VERSION', 'app', 'public', 'database/migrations'] as $required) {
            if (!file_exists(rtrim($directory, '/\\') . '/' . $required)) {
                throw new RuntimeException('La release está incompleta; falta: ' . $required . '.');
            }
        }
        if ($this->requiresRuntimeManifest($directory) && !is_file(rtrim($directory, '/\\') . '/resources/runtime-manifest.json')) {
            throw new RuntimeException('La release está incompleta; falta: resources/runtime-manifest.json.');
        }
        if ($this->requiresManagedEntrypoints($directory)) {
            foreach ([
                'asset.php',
                'index.php',
                'login.php',
                'actualizar.php',
                'stop.php',
                'mantenimiento.php',
                'recuperar.php',
                'launcher/entrypoint.php',
            ] as $entrypoint) {
                if (!is_file(rtrim($directory, '/\\') . '/' . $entrypoint)) {
                    throw new RuntimeException(
                        'La release no contiene el despachador atómico requerido: ' . $entrypoint . '.'
                    );
                }
            }
        }
    }

    private function requiresRuntimeManifest(string $directory): bool
    {
        $versionFile = rtrim($directory, '/\\') . '/VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
        return $version !== '' && version_compare($version, '2.11.6', '>=');
    }

    private function requiresManagedEntrypoints(string $directory): bool
    {
        $versionFile = rtrim($directory, '/\\') . '/VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
        return $version !== '' && version_compare($version, '2.26.5', '>=');
    }
}
