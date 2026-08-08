<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Env;
use Throwable;

final class InstalledVersionMarkerService
{
    /** @return array{valid:bool,version:string,last_migration:string,completed_at:string} */
    public function read(): array
    {
        $empty = ['valid' => false, 'version' => '', 'last_migration' => '', 'completed_at' => ''];
        $path = AppPaths::storage('installed-release.json');
        if (!is_file($path)) {
            return $empty;
        }
        try {
            $document = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
            $payload = is_array($document['payload'] ?? null) ? $document['payload'] : [];
            $signature = (string) ($document['signature'] ?? '');
            $key = trim((string) Env::get('APP_KEY', ''));
            if ($payload === [] || $signature === '' || $key === '') {
                return $empty;
            }
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (!hash_equals(hash_hmac('sha256', $encoded, $key), $signature)) {
                return $empty;
            }
            return [
                'valid' => true,
                'version' => (string) ($payload['version'] ?? ''),
                'last_migration' => (string) ($payload['last_migration'] ?? ''),
                'completed_at' => (string) ($payload['completed_at'] ?? ''),
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    public function write(string $version, string $lastMigration): bool
    {
        $key = trim((string) Env::get('APP_KEY', ''));
        if ($key === '') {
            return false;
        }
        $payload = [
            'version' => $version,
            'last_migration' => $lastMigration,
            'completed_at' => gmdate(DATE_ATOM),
        ];
        try {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $document = json_encode([
                'payload' => $payload,
                'signature' => hash_hmac('sha256', $encoded, $key),
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $directory = AppPaths::storage();
            if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
                return false;
            }
            $path = AppPaths::storage('installed-release.json');
            $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
            if (@file_put_contents($temporary, $document, LOCK_EX) === false) {
                return false;
            }
            if (!@rename($temporary, $path)) {
                @unlink($temporary);
                return false;
            }
            @chmod($path, 0660);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function requiresUpdate(?string $fileVersion = null): bool
    {
        $marker = $this->read();
        $version = $fileVersion ?? AppVersionService::fileVersion();
        return !$marker['valid'] || !hash_equals($version, $marker['version']);
    }
}
