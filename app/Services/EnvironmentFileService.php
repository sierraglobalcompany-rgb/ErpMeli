<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use RuntimeException;

final class EnvironmentFileService
{
    public function __construct(private readonly string $root)
    {
    }

    /** @param array<string,string> $updates */
    public function update(array $updates): void
    {
        $path = AppPaths::configFile();
        if (!is_file($path)) {
            $path = is_file($this->root . '/config.env') ? $this->root . '/config.env' : $this->root . '/.env';
        }
        if (!is_file($path)) {
            throw new RuntimeException('No se encontró el archivo privado de configuración.');
        }
        foreach ($updates as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                throw new RuntimeException('La clave de configuración no es válida.');
            }
            $updates[$key] = InstallerService::encodeEnvValue($value);
        }

        $lockPath = AppPaths::storage('cache/environment-writer.lock');
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('No fue posible bloquear el archivo de configuración.');
        }

        try {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                throw new RuntimeException('No fue posible leer el archivo privado de configuración.');
            }
            $written = [];
            $result = [];
            foreach ($lines as $line) {
                if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $match) === 1 && array_key_exists($match[1], $updates)) {
                    if (!isset($written[$match[1]])) {
                        $result[] = $match[1] . '=' . $updates[$match[1]];
                        $written[$match[1]] = true;
                    }
                    continue;
                }
                $result[] = $line;
            }
            foreach ($updates as $key => $encodedValue) {
                if (!isset($written[$key])) {
                    $result[] = $key . '=' . $encodedValue;
                }
            }

            $temporary = $path . '.updating-' . bin2hex(random_bytes(6));
            if (file_put_contents($temporary, implode("\n", $result) . "\n", LOCK_EX) === false) {
                throw new RuntimeException('No fue posible guardar la configuración de Mercado Libre.');
            }
            @chmod($temporary, 0600);
            if (!rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('No fue posible activar la nueva configuración.');
            }
            @chmod($path, 0600);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
