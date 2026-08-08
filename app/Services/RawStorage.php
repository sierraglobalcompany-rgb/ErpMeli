<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use RuntimeException;

final class RawStorage
{
    public function store(int $accountId, string $type, string|int $externalId, array $payload): string
    {
        $relative = sprintf('raw/%s/%d/%s', date('Y/m'), $accountId, preg_replace('/[^a-z0-9_-]/i', '_', $type));
        $directory = AppPaths::storage($relative);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible crear el directorio de datos crudos.');
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $payloadHash = hash('sha256', $json);
        $name = preg_replace('/[^a-z0-9_-]/i', '_', (string) $externalId) . '-' . substr($payloadHash, 0, 20) . '.json.gz';
        $absolute = $directory . '/' . $name;
        if (is_file($absolute)) {
            return 'storage/' . $relative . '/' . $name;
        }
        if (file_put_contents($absolute, gzencode($json, 6), LOCK_EX) === false) {
            throw new RuntimeException('No fue posible guardar la respuesta cruda.');
        }
        return 'storage/' . $relative . '/' . $name;
    }
}
