<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Env;
use RuntimeException;

final class RestoreMaintenanceRequestService
{
    public function publish(int $restoreId, string $publicId): void
    {
        $directory = AppPaths::storage('cache');
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar la señal local de restauración.');
        }
        $payload = [
            'restore_id' => $restoreId,
            'public_id' => $publicId,
            'issued_at' => time(),
            'expires_at' => time() + 604800,
            'nonce' => bin2hex(random_bytes(16)),
        ];
        ksort($payload);
        $payload['signature'] = hash_hmac(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $this->key()
        );
        $lock = $this->requestLock();
        try {
            $temporary = $this->path() . '.tmp-' . bin2hex(random_bytes(4));
            if (
                @file_put_contents($temporary, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false
                || !@rename($temporary, $this->path())
            ) {
                @unlink($temporary);
                throw new RuntimeException('No fue posible activar el carril local de restauración.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{restore_id:int,public_id:string}|null */
    public function valid(): ?array
    {
        $payload = json_decode((string) @file_get_contents($this->path()), true);
        if (!is_array($payload)) {
            return null;
        }
        $signature = (string) ($payload['signature'] ?? '');
        unset($payload['signature']);
        ksort($payload);
        $expected = hash_hmac(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $this->key()
        );
        if (
            $signature === ''
            || !hash_equals($expected, $signature)
            || (int) ($payload['expires_at'] ?? 0) < time()
        ) {
            $this->clear();
            return null;
        }
        return ['restore_id' => (int) $payload['restore_id'], 'public_id' => (string) $payload['public_id']];
    }

    public function clear(): void
    {
        @unlink($this->path());
    }

    public function clearIfMatches(int $restoreId, string $publicId): void
    {
        $lock = $this->requestLock();
        try {
            $current = $this->valid();
            if (
                $current !== null
                && $current['restore_id'] === $restoreId
                && hash_equals($current['public_id'], $publicId)
            ) {
                $this->clear();
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource */
    private function requestLock()
    {
        $lock = fopen($this->path() . '.lock', 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('No fue posible bloquear la señal local de restauración.');
        }
        return $lock;
    }

    private function path(): string
    {
        return AppPaths::storage('cache/restore-maintenance-request.json');
    }

    private function key(): string
    {
        $key = (string) Env::get('APP_KEY', '');
        if ($key === '') {
            throw new RuntimeException('APP_KEY no está configurada.');
        }
        return hash('sha256', 'restore-maintenance|' . $key, true);
    }
}
