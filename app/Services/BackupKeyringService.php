<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use RuntimeException;

final class BackupKeyringService
{
    private const KEY_BYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES;

    /** @return array{id:string,key:string,created_at:string} */
    public function active(): array
    {
        $keyring = $this->read();
        $activeId = (string) ($keyring['active'] ?? '');
        $entry = is_array($keyring['keys'][$activeId] ?? null) ? $keyring['keys'][$activeId] : null;
        if ($activeId === '' || $entry === null) {
            return $this->rotate();
        }

        return [
            'id' => $activeId,
            'key' => $this->decode((string) ($entry['material'] ?? '')),
            'created_at' => (string) ($entry['created_at'] ?? ''),
        ];
    }

    public function key(string $id): string
    {
        $keyring = $this->read();
        $entry = is_array($keyring['keys'][$id] ?? null) ? $keyring['keys'][$id] : null;
        if ($entry === null) {
            throw new RuntimeException('La copia requiere una clave de recuperación que no está disponible.');
        }
        return $this->decode((string) ($entry['material'] ?? ''));
    }

    /** @return array{id:string,key:string,created_at:string} */
    public function rotate(): array
    {
        $keyring = $this->read();
        $id = 'bk-' . gmdate('Ymd') . '-' . bin2hex(random_bytes(4));
        $key = random_bytes(self::KEY_BYTES);
        $createdAt = gmdate(DATE_ATOM);
        $keyring['format'] = 1;
        $keyring['active'] = $id;
        $keyring['keys'][$id] = [
            'material' => base64_encode($key),
            'created_at' => $createdAt,
            'retired_at' => null,
        ];
        $this->write($keyring);
        return ['id' => $id, 'key' => $key, 'created_at' => $createdAt];
    }

    public function recoveryPackage(string $password): string
    {
        if (strlen($password) < 12) {
            throw new RuntimeException('La contraseña del paquete debe tener al menos 12 caracteres.');
        }
        $keyring = $this->read();
        if (($keyring['keys'] ?? []) === []) {
            $this->active();
            $keyring = $this->read();
        }
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $password,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $plain = json_encode($keyring, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, 'ERP-MELI-KEYRING-1', $nonce, $key);
        sodium_memzero($key);
        return "ERP-MELI-KEYRING-1\n" . base64_encode($salt . $nonce . $cipher) . "\n";
    }

    public function importRecoveryPackage(string $payload, string $password): int
    {
        if (strlen($password) < 12) {
            throw new RuntimeException('La contraseña del paquete debe tener al menos 12 caracteres.');
        }
        if (strlen($payload) < 80 || strlen($payload) > 1048576) {
            throw new RuntimeException('El paquete de recuperación no tiene un tamaño válido.');
        }
        $lines = preg_split('/\R/', trim($payload));
        if (!is_array($lines) || ($lines[0] ?? '') !== 'ERP-MELI-KEYRING-1' || !isset($lines[1])) {
            throw new RuntimeException('El paquete de recuperación no es compatible.');
        }
        $binary = base64_decode(trim((string) $lines[1]), true);
        $prefix = SODIUM_CRYPTO_PWHASH_SALTBYTES
            + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (!is_string($binary) || strlen($binary) <= $prefix + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new RuntimeException('El paquete de recuperación está incompleto.');
        }
        $salt = substr($binary, 0, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = substr(
            $binary,
            SODIUM_CRYPTO_PWHASH_SALTBYTES,
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
        );
        $cipher = substr($binary, $prefix);
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $password,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $cipher,
            'ERP-MELI-KEYRING-1',
            $nonce,
            $key
        );
        sodium_memzero($key);
        $package = is_string($plain) ? json_decode($plain, true) : null;
        if (
            !is_array($package)
            || (int) ($package['format'] ?? 0) !== 1
            || !is_array($package['keys'] ?? null)
        ) {
            throw new RuntimeException('La contraseña no es correcta o el paquete fue alterado.');
        }

        $current = $this->read();
        $imported = 0;
        foreach ($package['keys'] as $id => $entry) {
            if (
                !is_string($id)
                || preg_match('/^bk-[0-9]{8}-[a-f0-9]{8}$/', $id) !== 1
                || !is_array($entry)
            ) {
                throw new RuntimeException('El paquete contiene una referencia de clave no válida.');
            }
            $material = (string) ($entry['material'] ?? '');
            $this->decode($material);
            if (isset($current['keys'][$id])) {
                if (!hash_equals((string) $current['keys'][$id]['material'], $material)) {
                    throw new RuntimeException('Una clave existente no coincide con el paquete importado.');
                }
                continue;
            }
            $current['keys'][$id] = [
                'material' => $material,
                'created_at' => (string) ($entry['created_at'] ?? ''),
                'retired_at' => $entry['retired_at'] ?? null,
            ];
            $imported++;
        }
        if (($current['active'] ?? null) === null && is_string($package['active'] ?? null)) {
            $current['active'] = $package['active'];
        }
        $this->write($current);
        return $imported;
    }

    public function hasExternalKey(): bool
    {
        try {
            return $this->active()['id'] !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return ['format' => 1, 'active' => null, 'keys' => []];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (!is_array($decoded) || (int) ($decoded['format'] ?? 0) !== 1 || !is_array($decoded['keys'] ?? null)) {
            throw new RuntimeException('El keyring de copias no es válido.');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $keyring */
    private function write(array $keyring): void
    {
        $path = $this->path();
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        $payload = json_encode($keyring, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (@file_put_contents($temporary, $payload, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible guardar el keyring privado.');
        }
        @chmod($path, 0600);
    }

    private function path(): string
    {
        $directory = AppPaths::backupKeys();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar el directorio privado de claves.');
        }
        @chmod($directory, 0700);
        return $directory . '/keyring.json';
    }

    private function decode(string $encoded): string
    {
        $key = base64_decode($encoded, true);
        if (!is_string($key) || strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException('La clave privada de la copia no es válida.');
        }
        return $key;
    }
}
