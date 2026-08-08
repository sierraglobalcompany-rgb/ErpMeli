<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use RuntimeException;

/**
 * Autoridad física del modo de solo lectura durante tareas que abarcan varias
 * peticiones. A diferencia de GET_LOCK, este marcador sobrevive al cierre de
 * PHP y evita que una sesión reanudable continúe sobre datos que cambiaron.
 */
final class DatabaseMutationFreezeService
{
    private const FILE = 'cache/database-mutation-freeze.json';

    /** @param array<string,mixed> $context */
    public function activate(string $purpose, string $owner, array $context = []): void
    {
        if (preg_match('/^[a-z0-9_-]{3,60}$/', $purpose) !== 1) {
            throw new RuntimeException('El propósito del modo de mantenimiento no es válido.');
        }
        if (preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $owner) !== 1) {
            throw new RuntimeException('El propietario del modo de mantenimiento no es válido.');
        }
        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible activar la protección de la base de datos.');
        }
        $existing = $this->status();
        if (
            !empty($existing['active'])
            && (
                (string) ($existing['purpose'] ?? '') !== $purpose
                || (string) ($existing['owner'] ?? '') !== $owner
            )
        ) {
            throw new RuntimeException('Otra operación mantiene la base de datos en modo lectura.');
        }
        $safeContext = [];
        foreach ($context as $key => $value) {
            if (
                preg_match('/^[a-z0-9_]{1,40}$/', (string) $key) === 1
                && (is_int($value) || is_bool($value) || is_string($value))
            ) {
                $safeContext[(string) $key] = is_string($value)
                    ? mb_substr($value, 0, 160)
                    : $value;
            }
        }
        $payload = [
            'version' => 1,
            'purpose' => $purpose,
            'owner' => $owner,
            'activated_at' => (string) ($existing['activated_at'] ?? gmdate(DATE_ATOM)),
            'heartbeat_at' => gmdate(DATE_ATOM),
            'context' => $safeContext,
        ];
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (
            !is_string($encoded)
            || @file_put_contents($temporary, $encoded, LOCK_EX) === false
        ) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible activar la protección de la base de datos.');
        }
        $renamed = false;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (@rename($temporary, $path)) {
                $renamed = true;
                break;
            }
            if (is_file($path)) {
                @unlink($path);
            }
            if (@rename($temporary, $path)) {
                $renamed = true;
                break;
            }
            usleep(50000);
        }
        if (!$renamed) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible activar la protección de la base de datos.');
        }
        @chmod($path, 0640);
    }

    public function heartbeat(string $purpose, string $owner): void
    {
        $existing = $this->status();
        if (
            empty($existing['active'])
            || (string) ($existing['purpose'] ?? '') !== $purpose
            || (string) ($existing['owner'] ?? '') !== $owner
        ) {
            throw new RuntimeException('La protección ya no pertenece a esta operación.');
        }
        // El contexto identifica la única sesión que puede usar sus controles
        // durante el modo de solo lectura. Perderlo en un heartbeat dejaba la
        // operación activa, pero bloqueaba Pausar, Continuar y Cerrar.
        $this->activate(
            $purpose,
            $owner,
            is_array($existing['context'] ?? null) ? $existing['context'] : []
        );
    }

    public function release(string $purpose, string $owner): void
    {
        $status = $this->status();
        if (empty($status['active'])) {
            return;
        }
        if (
            (string) ($status['purpose'] ?? '') !== $purpose
            || (string) ($status['owner'] ?? '') !== $owner
        ) {
            throw new RuntimeException('La protección pertenece a otra operación.');
        }
        if (!@unlink($this->path()) && is_file($this->path())) {
            throw new RuntimeException('No fue posible retirar el modo de solo lectura.');
        }
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return ['active' => false];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [
                'active' => true,
                'purpose' => 'unknown',
                'owner' => 'unknown',
                'activated_at' => null,
                'heartbeat_at' => null,
            ];
        }
        return [
            'active' => true,
            'purpose' => (string) ($decoded['purpose'] ?? 'unknown'),
            'owner' => (string) ($decoded['owner'] ?? 'unknown'),
            'activated_at' => $decoded['activated_at'] ?? null,
            'heartbeat_at' => $decoded['heartbeat_at'] ?? null,
            'context' => is_array($decoded['context'] ?? null) ? $decoded['context'] : [],
        ];
    }

    public function active(): bool
    {
        return !empty($this->status()['active']);
    }

    private function path(): string
    {
        return AppPaths::storage(self::FILE);
    }
}
