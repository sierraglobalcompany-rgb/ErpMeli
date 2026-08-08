<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Crypto;
use RuntimeException;

final class RestoreSecretStoreService
{
    /** @param array<string,string> $credentials */
    public function put(string $publicId, array $credentials): string
    {
        $name = 'restore-' . preg_replace('/[^a-zA-Z0-9-]/', '', $publicId) . '.secret';
        $path = $this->directory() . '/' . $name;
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        $payload = Crypto::encrypt(json_encode($credentials, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (@file_put_contents($temporary, $payload, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible proteger las credenciales temporales de restauración.');
        }
        @chmod($path, 0600);
        return $name;
    }

    /** @return array<string,string> */
    public function get(string $name): array
    {
        $safeName = basename($name);
        $path = $this->directory() . '/' . $safeName;
        $encoded = @file_get_contents($path);
        if (!is_string($encoded) || $encoded === '') {
            throw new RuntimeException('Las credenciales temporales de restauración ya no están disponibles.');
        }
        $decoded = json_decode(Crypto::decrypt($encoded), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Las credenciales temporales no son válidas.');
        }
        return array_map('strval', $decoded);
    }

    public function delete(string $name): void
    {
        @unlink($this->directory() . '/' . basename($name));
    }

    public function directory(): string
    {
        $directory = AppPaths::restoreSecrets();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar el almacenamiento privado de restauración.');
        }
        @chmod($directory, 0700);
        return $directory;
    }
}
