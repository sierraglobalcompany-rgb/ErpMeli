<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Env;
use RuntimeException;

final class BackupMaintenanceRequestService
{
    public function publish(int $backupId, string $publicId): void
    {
        $directory = AppPaths::storage('cache');
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar la señal local de mantenimiento.');
        }
        $payload = [
            'backup_id' => $backupId,
            'public_id' => $publicId,
            'issued_at' => time(),
            // La solicitud debe sobrevivir una actualización o varios ciclos
            // suspendidos del hosting. La base sigue siendo la autoridad del
            // estado queued/running y evita reutilizar trabajos terminados.
            'expires_at' => time() + 604800,
            'nonce' => bin2hex(random_bytes(16)),
        ];
        $payload['signature'] = hash_hmac('sha256', $this->canonical($payload), $this->signingKey());
        $lock = $this->requestLock();
        try {
            $temporary = $this->path() . '.tmp-' . bin2hex(random_bytes(4));
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (@file_put_contents($temporary, $json, LOCK_EX) === false || !@rename($temporary, $this->path())) {
                @unlink($temporary);
                throw new RuntimeException('No fue posible activar el carril local de copias.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{backup_id:int,public_id:string}|null */
    public function consumeValid(): ?array
    {
        $raw = @file_get_contents($this->path());
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($payload)) {
            return null;
        }
        $signature = (string) ($payload['signature'] ?? '');
        unset($payload['signature']);
        $issuedAt = (int) ($payload['issued_at'] ?? 0);
        $expiresAt = (int) ($payload['expires_at'] ?? 0);
        $now = time();
        if (
            $signature === ''
            || !hash_equals(hash_hmac('sha256', $this->canonical($payload), $this->signingKey()), $signature)
            || $issuedAt < $now - 604800
            || $issuedAt > $now + 300
            || $expiresAt < $now
            || $expiresAt > $issuedAt + 604800
            || (int) ($payload['backup_id'] ?? 0) < 1
        ) {
            $this->clear();
            return null;
        }
        return [
            'backup_id' => (int) $payload['backup_id'],
            'public_id' => (string) ($payload['public_id'] ?? ''),
        ];
    }

    public function clear(): void
    {
        @unlink($this->path());
    }

    public function clearIfMatches(int $backupId, string $publicId): void
    {
        $lock = $this->requestLock();
        try {
            $current = $this->consumeValid();
            if (
                $current !== null
                && $current['backup_id'] === $backupId
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
            throw new RuntimeException('No fue posible bloquear la señal local de copias.');
        }
        return $lock;
    }

    public static function markerExistsBeforeBootstrap(): bool
    {
        require_once dirname(__DIR__, 2) . '/jobs/_prebootstrap_runtime_paths.php';
        return erp_prebootstrap_marker_exists(
            'storage/cache/backup-maintenance-request.json'
        );
    }

    private function path(): string
    {
        return AppPaths::storage('cache/backup-maintenance-request.json');
    }

    /** @param array<string,mixed> $payload */
    private function canonical(array $payload): string
    {
        ksort($payload);
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function signingKey(): string
    {
        $key = (string) Env::get('APP_KEY', '');
        if ($key === '') {
            throw new RuntimeException('APP_KEY no está configurada.');
        }
        return hash('sha256', 'backup-maintenance|' . $key, true);
    }
}
