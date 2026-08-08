<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class InstallerService
{
    public function __construct(private readonly string $root)
    {
    }

    /** @param array<string,string> $database */
    public function testConnection(array $database): string
    {
        $pdo = $this->connect($database);
        return (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    }

    /** @param array<string,string> $data */
    public function install(array $data): void
    {
        $processLock = $this->root . '/storage/cache/installer-process.lock';
        $handle = fopen($processLock, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('No fue posible bloquear el instalador. Revise los permisos de storage/cache.');
        }

        try {
            if (is_file($this->root . '/config.env') || is_file($this->root . '/.env') || is_file($this->root . '/storage/install.lock')) {
                throw new RuntimeException('El instalador ya fue utilizado.');
            }

            $pdo = $this->connect($data);
            (new Migrator($pdo, $this->root . '/database/migrations'))->run();
            $this->createAdministrator($pdo, $data);

            $environment = $this->environmentContents($data);
            $temporary = $this->root . '/config.env.installing-' . bin2hex(random_bytes(6));
            if (file_put_contents($temporary, $environment, LOCK_EX) === false) {
                throw new RuntimeException('No fue posible escribir el archivo privado config.env.');
            }
            @chmod($temporary, 0600);
            if (!rename($temporary, $this->root . '/config.env')) {
                @unlink($temporary);
                throw new RuntimeException('No fue posible activar el archivo privado config.env.');
            }
            @chmod($this->root . '/config.env', 0600);

            $marker = "Instalación completada: " . gmdate('c') . "\nNo elimine este archivo.\n";
            if (file_put_contents($this->root . '/storage/install.lock', $marker, LOCK_EX) === false) {
                throw new RuntimeException('La configuración se guardó, pero no se pudo crear el bloqueo del instalador.');
            }
            @chmod($this->root . '/storage/install.lock', 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string,string> $database */
    private function connect(array $database): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $database['db_host'],
            $database['db_port'],
            $database['db_name']
        );
        $pdo = new PDO($dsn, $database['db_user'], $database['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 8,
        ]);
        $pdo->query('SELECT 1')->fetchColumn();
        return $pdo;
    }

    /** @param array<string,string> $data */
    private function createAdministrator(PDO $pdo, array $data): void
    {
        $statement = $pdo->prepare(
            "INSERT INTO users (name, email, password_hash, role, status)
             VALUES (:name, :email, :password_hash, 'admin', 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), role = 'admin', status = 1"
        );
        $statement->execute([
            'name' => $data['admin_name'],
            'email' => strtolower($data['admin_email']),
            'password_hash' => password_hash($data['admin_password'], PASSWORD_DEFAULT),
        ]);
    }

    /** @param array<string,string> $data */
    private function environmentContents(array $data): string
    {
        $appUrl = rtrim($data['app_url'], '/');
        $values = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $appUrl,
            'APP_TIMEZONE' => 'America/Bogota',
            'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
            'SESSION_SECURE' => 'true',
            'DB_HOST' => $data['db_host'],
            'DB_PORT' => $data['db_port'],
            'DB_NAME' => $data['db_name'],
            'DB_USER' => $data['db_user'],
            'DB_PASS' => $data['db_pass'],
            'MELI_CLIENT_ID' => $data['meli_client_id'],
            'MELI_CLIENT_SECRET' => $data['meli_client_secret'],
            'MELI_REDIRECT_URI' => $appUrl . '/meli_callback.php',
            'MELI_WEBHOOK_URL' => $appUrl . '/webhook_mercadolibre.php',
            'MELI_API_BASE' => 'https://api.mercadolibre.com',
            'MELI_AUTH_URL' => 'https://auth.mercadolibre.com.co/authorization',
            'ML_WRITE_ENABLED' => 'false',
            'RAW_RETENTION_DAYS' => '365',
            'LOG_LEVEL' => 'warning',
        ];

        $lines = [];
        foreach ($values as $key => $value) {
            $lines[] = $key . '=' . self::encodeEnvValue($value);
        }
        return implode("\n", $lines) . "\n";
    }

    public static function encodeEnvValue(string $value): string
    {
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new RuntimeException('Los valores de configuración no pueden contener saltos de línea.');
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
