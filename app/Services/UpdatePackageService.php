<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use RuntimeException;
use ZipArchive;

final class UpdatePackageService
{
    public function acceptUpload(array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No fue posible recibir el paquete de actualización.');
        }
        $size = (int) ($file['size'] ?? 0);
        $max = max(10, (int) (new AppSettingsService())->get('update.max_package_mb', '512')) * 1024 * 1024;
        if ($size <= 0 || $size > $max) {
            throw new RuntimeException('El paquete supera el tamaño permitido o está vacío.');
        }
        $name = basename((string) ($file['name'] ?? 'update.erpupd'));
        if (!str_ends_with(strtolower($name), '.erpupd')) {
            throw new RuntimeException('Solo se aceptan paquetes con extensión .erpupd.');
        }
        (new UpdateFilesystemService())->ensureDirectories();
        $destination = AppPaths::updateInbox() . '/' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.erpupd';
        if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
            throw new RuntimeException('No fue posible guardar el paquete en el área segura.');
        }
        @chmod($destination, 0600);
        return $destination;
    }

    /** @return array{directory:string,manifest:array<string,mixed>} */
    public function extract(string $package, string $runUuid): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extensión PHP zip es necesaria para abrir paquetes .erpupd.');
        }
        $filesystem = new UpdateFilesystemService();
        $filesystem->ensureDirectories();
        $package = $filesystem->assertInside($package, AppPaths::updateInbox());
        $destination = AppPaths::updateStaging() . '/' . preg_replace('/[^a-z0-9-]/i', '-', $runUuid) . '/source';
        if (is_dir($destination)) {
            $filesystem->removeTree(dirname($destination), AppPaths::updateStaging());
        }
        if (!mkdir($destination, 0770, true) && !is_dir($destination)) {
            throw new RuntimeException('No fue posible preparar el área temporal del paquete.');
        }

        $zip = new ZipArchive();
        if ($zip->open($package) !== true) {
            throw new RuntimeException('El paquete .erpupd no es un ZIP válido.');
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                $normalized = str_replace('\\', '/', $entry);
                if (
                    $normalized === ''
                    || str_starts_with($normalized, '/')
                    || preg_match('#^[A-Za-z]:/#', $normalized) === 1
                    || in_array('..', explode('/', $normalized), true)
                ) {
                    throw new RuntimeException('El paquete contiene una ruta insegura.');
                }
                $operationsSystem = 0;
                $externalAttributes = 0;
                $hasAttributes = $zip->getExternalAttributesIndex($i, $operationsSystem, $externalAttributes);
                if ($hasAttributes && (($externalAttributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('El paquete contiene enlaces simbólicos.');
                }
            }
            if (!$zip->extractTo($destination)) {
                throw new RuntimeException('No fue posible extraer el paquete.');
            }
        } finally {
            $zip->close();
        }

        $manifestService = new UpdateManifestService();
        $manifest = $manifestService->fromDirectory($destination);
        $manifest['signature_status'] = $manifestService->verifySignature($manifest);
        $manifestService->verifyFiles($manifest, $destination);
        return ['directory' => $destination, 'manifest' => $manifest];
    }
}
